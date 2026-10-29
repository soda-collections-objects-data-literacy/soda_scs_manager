<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Service\SodaScsJupyterHubApiClient;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Project JupyterLab servers on JupyterHub.
 *
 * Every project with JupyterHub has one shared server with real-time
 * collaboration, owned by the hub user project-<gid>. Members open it through
 * the hub launcher /hub/scs/projects/<gid>; the hub grants access by the
 * project role it derives from the member's Keycloak groups.
 */
class SodaScsJupyterHelpers {

  use StringTranslationTrait;

  /**
   * Hub user name prefix of project servers.
   */
  public const PROJECT_USER_PREFIX = 'project-';

  /**
   * DockerSpawner home volume prefix (jupyterhub-user-{username}).
   */
  public const VOLUME_PREFIX = 'jupyterhub-user-';

  /**
   * Constructs a SodaScsJupyterHelpers object.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.jupyterhub.api_client')]
    protected SodaScsJupyterHubApiClient $hubApi,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Hub launcher URL that opens (and if needed starts) the project's Lab.
   *
   * @param string $baseUrl
   *   JupyterHub public base URL, with or without a trailing slash.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project.
   */
  public static function buildProjectLauncherUrl(string $baseUrl, SodaScsProjectInterface $project): string {
    return rtrim($baseUrl, '/') . '/hub/scs/projects/' . rawurlencode(self::projectGid($project));
  }

  /**
   * Hub user that owns the project's server: project-<gid>.
   */
  public static function projectServerUserName(SodaScsProjectInterface $project): string {
    return self::PROJECT_USER_PREFIX . self::projectGid($project);
  }

  /**
   * Docker volume with the project server's home directory.
   */
  public static function projectVolumeName(SodaScsProjectInterface $project): string {
    return self::VOLUME_PREFIX . self::projectServerUserName($project);
  }

  /**
   * Creates the project's hub user unless it exists.
   */
  public function ensureProjectServerUser(SodaScsProjectInterface $project): SodaScsResult {
    return $this->callHub(
      fn () => $this->hubApi->ensureUser(self::projectServerUserName($project)),
      $project,
      (string) $this->t('JupyterHub is ready for project @project.', ['@project' => $project->label()]),
      (string) $this->t('JupyterHub could not be set up for project @project.', ['@project' => $project->label()]),
    );
  }

  /**
   * State of the project's server (SodaScsJupyterHubApiClient::STATE_*).
   *
   * @return string|null
   *   The state, or NULL when the hub cannot be asked.
   */
  public function projectServerState(SodaScsProjectInterface $project): ?string {
    try {
      return $this->hubApi->getServerState(self::projectServerUserName($project));
    }
    catch (\RuntimeException $e) {
      $this->logger->warning('Could not read the JupyterHub server state of project @project: @msg', [
        '@project' => $project->id(),
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Stops the project's server, waits for it, and deletes its hub user.
   */
  public function removeProjectServerUser(SodaScsProjectInterface $project): SodaScsResult {
    $name = self::projectServerUserName($project);
    return $this->callHub(
      function () use ($name): void {
        if (!$this->hubApi->stopServerAndWait($name)) {
          throw new \RuntimeException('The project server did not stop in time.');
        }
        $this->hubApi->deleteUser($name);
      },
      $project,
      (string) $this->t('Removed JupyterHub from project @project.', ['@project' => $project->label()]),
      (string) $this->t('JupyterHub could not be removed from project @project.', ['@project' => $project->label()]),
    );
  }

  /**
   * Restarts the project's server for all members.
   */
  public function restartProjectServer(SodaScsProjectInterface $project): SodaScsResult {
    $name = self::projectServerUserName($project);
    return $this->callHub(
      function () use ($name): void {
        if (!$this->hubApi->stopServerAndWait($name)) {
          throw new \RuntimeException('The project server did not stop in time.');
        }
        $this->hubApi->startServer($name);
      },
      $project,
      (string) $this->t('The project notebook server is restarting.'),
      (string) $this->t('The project notebook server could not be restarted.'),
    );
  }

  /**
   * Starts the project's server.
   */
  public function startProjectServer(SodaScsProjectInterface $project): SodaScsResult {
    return $this->callHub(
      fn () => $this->hubApi->startServer(self::projectServerUserName($project)),
      $project,
      (string) $this->t('The project notebook server is starting.'),
      (string) $this->t('The project notebook server could not be started.'),
    );
  }

  /**
   * Stops the project's server for all members.
   */
  public function stopProjectServer(SodaScsProjectInterface $project): SodaScsResult {
    return $this->callHub(
      fn () => $this->hubApi->stopServer(self::projectServerUserName($project)),
      $project,
      (string) $this->t('The project notebook server is stopping.'),
      (string) $this->t('The project notebook server could not be stopped.'),
    );
  }

  /**
   * Stops the project's server and returns once it is down.
   *
   * Required before the home volume is replaced, because a running server
   * keeps writing into it.
   */
  public function stopProjectServerAndWait(SodaScsProjectInterface $project): SodaScsResult {
    $name = self::projectServerUserName($project);
    return $this->callHub(
      function () use ($name): void {
        if (!$this->hubApi->stopServerAndWait($name)) {
          throw new \RuntimeException('The project server did not stop in time.');
        }
      },
      $project,
      (string) $this->t('The project notebook server is stopped.'),
      (string) $this->t('The project notebook server could not be stopped.'),
    );
  }

  /**
   * Runs a hub call and maps it onto a result.
   */
  protected function callHub(callable $call, SodaScsProjectInterface $project, string $successMessage, string $failureMessage): SodaScsResult {
    try {
      $call();
      return SodaScsResult::success(
        data: ['hubUser' => self::projectServerUserName($project)],
        message: $successMessage,
      );
    }
    catch (\RuntimeException $e) {
      $this->logger->error('JupyterHub call for project @project failed: @msg', [
        '@project' => $project->id(),
        '@msg' => $e->getMessage(),
      ]);
      return SodaScsResult::failure(
        error: $e->getMessage(),
        message: $failureMessage,
        log: FALSE,
      );
    }
  }

  /**
   * Stable project group id (entity id + 10000).
   */
  protected static function projectGid(SodaScsProjectInterface $project): string {
    return (string) ($project->get('groupId')->value ?? '');
  }

}
