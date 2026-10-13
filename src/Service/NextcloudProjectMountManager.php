<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Traits\SecureLoggingTrait;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mounts project Team Folders through the Nextcloud service account.
 *
 * Every project with JupyterHub gets one sidecar mount at
 * <mounts root>/_projects/<gid>/<label>, independent of the members' personal
 * Drives. The service account only sees Team Folders whose groups include
 * the project mount group (SodaScsProjectHelpers::buildTeamFolderGroups()).
 */
class NextcloudProjectMountManager {

  use SecureLoggingTrait;

  /**
   * Seconds rclone caches directory listings; web changes show up after this.
   */
  protected const DIR_CACHE_SECONDS = 30;

  /**
   * Readiness wait per project during cron, kept short for many projects.
   */
  protected const RECONCILE_TIMEOUT_SECONDS = 15;

  /**
   * Bundle of the stack that marks a project as using JupyterHub.
   */
  protected const JUPYTER_STACK_BUNDLE = 'soda_scs_jupyter_stack';

  /**
   * Constructs the manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.nextcloud_http_client')]
    protected ClientInterface $httpClient,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
    #[Autowire(service: 'soda_scs_manager.nextcloud_mounter.client')]
    protected NextcloudMounterClient $mounterClient,
    #[Autowire(service: 'soda_scs_manager.project_application.helpers')]
    protected SodaScsProjectApplicationHelpers $projectApplicationHelpers,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    protected SodaScsProjectHelpers $projectHelpers,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    protected SodaScsServiceHelpers $serviceHelpers,
  ) {}

  /**
   * Ensures the project's Team Folder is mounted and readable.
   *
   * Adds the project mount group to the Team Folder when the service account
   * cannot see it yet. Never mounts an invisible folder: rclone would create
   * a plain folder of that name in the service account's own Drive.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project.
   * @param int $timeoutSeconds
   *   How long to wait for the mount to become readable.
   *
   * @return bool
   *   TRUE when the mount is listed and readable.
   */
  public function ensureProjectMounted(SodaScsProjectInterface $project, int $timeoutSeconds = 45): bool {
    if (!$this->isConfigured()) {
      $this->logger->warning('Project mount for project @project skipped: NEXTCLOUD_PROJECT_MOUNT_USER or NEXTCLOUD_PROJECT_MOUNT_APP_PASSWORD is not set.', [
        '@project' => $project->id(),
      ]);
      return FALSE;
    }
    $relativePath = $this->relativeMountPath($project);
    if ($relativePath === NULL) {
      return FALSE;
    }

    try {
      $listed = $this->mounterClient->listMounts();
      $this->unmountStaleMounts($project, $listed);

      $isListed = $this->mounterClient->isListed($relativePath, $listed);
      if ($isListed && $this->isReadable($relativePath)) {
        return TRUE;
      }
      if ($isListed) {
        $this->mounterClient->unmount($relativePath);
      }

      if (!$this->ensureTeamFolderVisible($project)) {
        return FALSE;
      }

      $this->mounterClient->mount(
        $relativePath,
        $this->buildWebdavUrl((string) $project->label()),
        $this->mountUser(),
        $this->mountAppPassword(),
        [
          // Members write through the notebook's group 33.
          'Umask' => 2,
          'DirCacheTime' => self::DIR_CACHE_SECONDS * 1_000_000_000,
        ],
      );
      return $this->waitUntilReadable($relativePath, $timeoutSeconds);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not mount the Team Folder of project @project: @msg', [
        '@project' => $project->id(),
        '@msg' => $this->sanitizeForLogging($e->getMessage()),
      ]);
      return FALSE;
    }
  }

  /**
   * Unmounts every mount of the project and removes its directories.
   */
  public function ensureProjectUnmounted(SodaScsProjectInterface $project): void {
    $gid = $this->projectGid($project);
    if ($gid === '') {
      return;
    }
    try {
      $listed = $this->mounterClient->listMounts();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not list mounts to unmount project @project: @msg', [
        '@project' => $project->id(),
        '@msg' => $this->sanitizeForLogging($e->getMessage()),
      ]);
      return;
    }
    foreach ($this->listedProjectMounts($listed) as $relativePath => $mountGid) {
      if ($mountGid === $gid) {
        $this->unmountAndRemove($relativePath);
      }
    }
    $this->mounterClient->removeMountDirectory(NextcloudMounterClient::PROJECTS_DIR . '/' . $gid);
  }

  /**
   * Whether the service account credentials are configured.
   */
  public function isConfigured(): bool {
    return $this->mountUser() !== '' && $this->mountAppPassword() !== '';
  }

  /**
   * Whether the project should have a mount, i.e. has JupyterHub.
   */
  public function needsProjectMount(SodaScsProjectInterface $project): bool {
    return $this->projectApplicationHelpers->projectHasApplication($project->id(), 'soda_scs_stack', self::JUPYTER_STACK_BUNDLE);
  }

  /**
   * Reports the mount state of a project.
   *
   * @return string
   *   One of: mounted, unlisted, broken, invalid.
   */
  public function projectMountState(SodaScsProjectInterface $project, ?array $listed = NULL): string {
    $relativePath = $this->relativeMountPath($project);
    if ($relativePath === NULL) {
      return 'invalid';
    }
    if (!$this->mounterClient->isListed($relativePath, $listed)) {
      return 'unlisted';
    }
    return $this->isReadable($relativePath) ? 'mounted' : 'broken';
  }

  /**
   * Reconciles project mounts against projects that have JupyterHub.
   *
   * @return array{mounted: int, remounted: int, unmounted: int, errors: int}
   *   Counters for logging/Drush.
   */
  public function reconcileProjects(): array {
    $stats = [
      'mounted' => 0,
      'remounted' => 0,
      'unmounted' => 0,
      'errors' => 0,
    ];
    if (!$this->isConfigured()) {
      return $stats;
    }
    if (!$this->mounterClient->ping()) {
      $this->logger->warning('Nextcloud mounter sidecar unreachable; project mount reconcile skipped.');
      return $stats;
    }

    try {
      $listed = $this->mounterClient->listMounts();
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not list Nextcloud mounts: @msg', [
        '@msg' => $this->sanitizeForLogging($e->getMessage()),
      ]);
      return $stats;
    }

    $desired = [];
    foreach ($this->loadProjectsWithJupyter() as $project) {
      $relativePath = $this->relativeMountPath($project);
      if ($relativePath === NULL) {
        $stats['errors']++;
        continue;
      }
      $desired[$relativePath] = TRUE;

      $state = $this->projectMountState($project, $listed);
      if ($state === 'mounted') {
        continue;
      }
      if ($this->ensureProjectMounted($project, self::RECONCILE_TIMEOUT_SECONDS)) {
        $stats[$state === 'unlisted' ? 'mounted' : 'remounted']++;
      }
      else {
        $stats['errors']++;
      }
    }

    foreach (array_keys($this->listedProjectMounts($listed)) as $relativePath) {
      if (!isset($desired[$relativePath])) {
        $this->unmountAndRemove($relativePath);
        $stats['unmounted']++;
      }
    }

    return $stats;
  }

  /**
   * Mount path of a project below the mounts root: _projects/<gid>/<label>.
   *
   * @return string|null
   *   NULL when the project has no gid or its label is unusable as a path.
   */
  public function relativeMountPath(SodaScsProjectInterface $project): ?string {
    $gid = $this->projectGid($project);
    $label = (string) $project->label();
    if ($gid === '' || $label === '' || $label === '.' || $label === '..' || preg_match('#[/\x00]#', $label) === 1) {
      $this->logger->warning('Project @project has no gid or a label that cannot be a mount path.', [
        '@project' => $project->id(),
      ]);
      return NULL;
    }
    return NextcloudMounterClient::PROJECTS_DIR . '/' . $gid . '/' . $label;
  }

  /**
   * WebDAV URL of a Team Folder as seen by the service account.
   */
  protected function buildWebdavUrl(string $label): string {
    $settings = $this->serviceHelpers->initNextcloudSettings();
    $base = rtrim((string) ($settings['baseUrl'] ?? ''), '/');
    if ($base === '') {
      throw new \RuntimeException('Nextcloud base URL is not configured.');
    }
    return $base . '/remote.php/dav/files/' . rawurlencode($this->mountUser()) . '/' . rawurlencode($label) . '/';
  }

  /**
   * Makes the Team Folder visible to the service account if it is not.
   */
  protected function ensureTeamFolderVisible(SodaScsProjectInterface $project): bool {
    if ($this->isTeamFolderVisible($project)) {
      return TRUE;
    }
    $sync = $this->projectHelpers->syncProjectTeamFolderGroups($project, TRUE);
    if (!$sync->success) {
      return FALSE;
    }
    if ($this->isTeamFolderVisible($project)) {
      return TRUE;
    }
    $this->logger->warning('Team Folder of project @project is not visible to the project mount account after adding the mount group.', [
      '@project' => $project->id(),
    ]);
    return FALSE;
  }

  /**
   * Whether the service account sees the Team Folder (WebDAV PROPFIND).
   *
   * @throws \RuntimeException
   *   When Nextcloud answers neither 207 nor 404.
   */
  protected function isTeamFolderVisible(SodaScsProjectInterface $project): bool {
    $response = $this->httpClient->request('PROPFIND', $this->buildWebdavUrl((string) $project->label()), [
      'auth' => [$this->mountUser(), $this->mountAppPassword()],
      'headers' => ['Depth' => '0'],
      'http_errors' => FALSE,
      'timeout' => 30,
    ]);
    $status = $response->getStatusCode();
    if ($status === 207) {
      return TRUE;
    }
    if ($status === 404) {
      return FALSE;
    }
    throw new \RuntimeException(sprintf('PROPFIND on the Team Folder returned HTTP %d.', $status));
  }

  /**
   * Whether the mount answers stat and a directory listing.
   */
  protected function isReadable(string $relativePath): bool {
    if ($this->mounterClient->probeMount($relativePath) !== 'mounted') {
      return FALSE;
    }
    return @scandir($this->mounterClient->hostMountPath($relativePath)) !== FALSE;
  }

  /**
   * Project mounts in a listmounts result, keyed by relative path.
   *
   * @return array<string, string>
   *   Relative path => gid.
   */
  protected function listedProjectMounts(array $listed): array {
    $prefix = $this->mounterClient->sidecarMountPoint(NextcloudMounterClient::PROJECTS_DIR) . '/';
    $mounts = [];
    foreach ($this->mounterClient->listMountPoints($listed) as $point) {
      if (!str_starts_with($point, $prefix)) {
        continue;
      }
      $relativePath = substr($point, strlen(NextcloudMounterClient::SIDECAR_ROOT) + 1);
      $mounts[$relativePath] = explode('/', substr($point, strlen($prefix)), 2)[0];
    }
    return $mounts;
  }

  /**
   * Loads projects that have a JupyterHub stack.
   *
   * @return array<int|string, \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface>
   *   Projects keyed by id.
   */
  protected function loadProjectsWithJupyter(): array {
    $stackStorage = $this->entityTypeManager->getStorage('soda_scs_stack');
    $stackIds = $stackStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', self::JUPYTER_STACK_BUNDLE)
      ->exists('partOfProjects')
      ->execute();

    $projectIds = [];
    foreach ($stackStorage->loadMultiple($stackIds) as $stack) {
      foreach ($stack->get('partOfProjects')->getValue() as $item) {
        $projectIds[(int) $item['target_id']] = TRUE;
      }
    }
    if ($projectIds === []) {
      return [];
    }

    $projects = $this->entityTypeManager->getStorage('soda_scs_project')->loadMultiple(array_keys($projectIds));
    return array_filter($projects, static fn ($project): bool => $project instanceof SodaScsProjectInterface);
  }

  /**
   * App password of the service account.
   */
  protected function mountAppPassword(): string {
    $password = getenv('NEXTCLOUD_PROJECT_MOUNT_APP_PASSWORD') ?: Settings::get('nextcloud_project_mount_app_password', '');
    return is_string($password) ? $password : '';
  }

  /**
   * Nextcloud login of the service account.
   */
  protected function mountUser(): string {
    $user = getenv('NEXTCLOUD_PROJECT_MOUNT_USER') ?: Settings::get('nextcloud_project_mount_user', '');
    return is_string($user) ? $user : '';
  }

  /**
   * Stable project group id (entity id + 10000).
   */
  protected function projectGid(SodaScsProjectInterface $project): string {
    return (string) ($project->get('groupId')->value ?? '');
  }

  /**
   * Unmounts the project's mounts under an outdated label (after a rename).
   */
  protected function unmountStaleMounts(SodaScsProjectInterface $project, array $listed): void {
    $gid = $this->projectGid($project);
    $current = $this->relativeMountPath($project);
    foreach ($this->listedProjectMounts($listed) as $relativePath => $mountGid) {
      if ($mountGid === $gid && $relativePath !== $current) {
        $this->unmountAndRemove($relativePath);
      }
    }
  }

  /**
   * Unmounts a project mount and removes its now empty directory.
   */
  protected function unmountAndRemove(string $relativePath): void {
    $this->mounterClient->unmount($relativePath);
    $this->mounterClient->removeMountDirectory($relativePath);
  }

  /**
   * Waits until a fresh mount answers a directory listing.
   */
  protected function waitUntilReadable(string $relativePath, int $timeoutSeconds): bool {
    $deadline = time() + max(1, $timeoutSeconds);
    do {
      clearstatcache(TRUE, $this->mounterClient->hostMountPath($relativePath));
      if ($this->isReadable($relativePath)) {
        return TRUE;
      }
      usleep(500000);
    } while (time() <= $deadline);

    $this->logger->warning('Project mount @path is not readable after @seconds s.', [
      '@path' => $relativePath,
      '@seconds' => $timeoutSeconds,
    ]);
    return FALSE;
  }

}
