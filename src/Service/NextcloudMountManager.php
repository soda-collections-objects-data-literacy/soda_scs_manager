<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lifecycle owner for per-user Nextcloud FUSE mounts via the sidecar.
 *
 * Sidecar mounts the whole Drive per user. WissKI stacks bind only the
 * project Team Folder subdirectory into private://nextcloud.
 */
class NextcloudMountManager {

  /**
   * Constructs the manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.nextcloud_mounter.client')]
    protected NextcloudMounterClient $mounterClient,
    #[Autowire(service: 'soda_scs_manager.nextcloud_mount.credentials')]
    protected NextcloudMountCredentialsStore $credentialsStore,
    #[Autowire(service: 'soda_scs_manager.nextcloud.helpers')]
    protected SodaScsNextcloudHelpers $nextcloudHelpers,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    protected SodaScsServiceHelpers $serviceHelpers,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Derives a filesystem-safe machine name for mounts and binds.
   *
   * Prefer Keycloak/Drupal account name when it matches [a-z0-9][a-z0-9_-]{0,62}.
   * Otherwise fall back to u<drupal-uid>.
   */
  public function machineNameForUser(AccountInterface $account): string {
    $candidate = strtolower((string) $account->getAccountName());
    if (preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $candidate) === 1) {
      return $candidate;
    }
    return 'u' . (int) $account->id();
  }

  /**
   * Host root for a user's full Nextcloud FUSE mount.
   */
  public function userMountSource(AccountInterface $account): string {
    return rtrim($this->mounterClient->mountsRootOnHost(), '/') . '/' . $this->machineNameForUser($account);
  }

  /**
   * Host path of a project's Nextcloud Team Folder (for Portainer bind source).
   *
   * Team Folder mount name in Nextcloud equals the project label.
   * WissKI binds this host path to /opt/drupal/private-files/nextcloud.
   */
  public function projectTeamFolderMountSource(AccountInterface $account, SodaScsProjectInterface $project): string {
    return $this->userMountSource($account) . '/' . $project->label();
  }

  /**
   * Same Team Folder as seen inside the Manager container (for readiness probes).
   */
  public function projectTeamFolderPathInContainer(AccountInterface $account, SodaScsProjectInterface $project): string {
    return $this->mounterClient->hostMountPath($this->machineNameForUser($account))
      . '/' . $project->label();
  }

  /**
   * Disabled / empty bind source for stacks without a project folder.
   */
  public function disabledMountSource(): string {
    return rtrim($this->mounterClient->mountsRootOnHost(), '/') . '/_disabled';
  }

  /**
   * Ensures the per-user host directory exists (idempotent).
   */
  public function ensureUserMountDirectory(AccountInterface $account): void {
    $this->mounterClient->ensureMountDirectory($this->machineNameForUser($account));
  }

  /**
   * Mounts the user's Drive and waits until the project Team Folder is visible.
   *
   * Do not mkdir the project folder on the host — it must come from Nextcloud
   * through the FUSE mount. Probe uses the Manager container path; the stack
   * env still gets the host path from projectTeamFolderMountSource().
   */
  public function ensureProjectTeamFolderReady(UserInterface $user, SodaScsProjectInterface $project, int $timeoutSeconds = 45): bool {
    if (!$this->ensureMounted($user)) {
      return FALSE;
    }
    $path = $this->projectTeamFolderPathInContainer($user, $project);
    $deadline = time() + max(1, $timeoutSeconds);
    do {
      clearstatcache(TRUE, $path);
      if (is_dir($path)) {
        return TRUE;
      }
      usleep(500000);
    } while (time() <= $deadline);

    $this->logger->warning('Project Team Folder not visible under mount: @path', [
      '@path' => $path,
    ]);
    return FALSE;
  }

  /**
   * Ensures credentials, mounts (or remounts) the user, updates status.
   *
   * Failures are logged and surfaced as status — never hard-fail user flows.
   */
  public function ensureMounted(UserInterface $user): bool {
    $machineName = $this->machineNameForUser($user);
    try {
      $credentials = $this->nextcloudHelpers->getMountCredentials($user);
      if ($credentials === NULL) {
        $this->credentialsStore->setMountStatus($user, 'disconnected');
        return FALSE;
      }

      $webdavUrl = $this->buildWebdavUrl($credentials['username']);
      $this->mounterClient->ensureMountDirectory($machineName);

      $listed = $this->mounterClient->isListed($machineName);
      $probe = $this->mounterClient->probeMount($machineName);
      if ($listed && $probe === 'mounted') {
        $this->credentialsStore->setMountStatus($user, 'mounted');
        return TRUE;
      }

      // Remount when missing from listmounts or when the host probe is broken.
      if ($listed || $probe === 'broken') {
        $this->mounterClient->unmount($machineName);
      }

      $this->mounterClient->mount(
        $machineName,
        $webdavUrl,
        $credentials['username'],
        $credentials['appPassword'],
      );

      $probe = $this->mounterClient->probeMount($machineName);
      if ($probe !== 'mounted') {
        $this->credentialsStore->setMountStatus($user, 'error:mount probe ' . $probe);
        return FALSE;
      }

      $this->credentialsStore->setMountStatus($user, 'mounted');
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not ensure Nextcloud mount for @user: @msg', [
        '@user' => $user->getAccountName(),
        '@msg' => $e->getMessage(),
      ]);
      $this->credentialsStore->setMountStatus($user, 'error:' . mb_substr($e->getMessage(), 0, 180));
      return FALSE;
    }
  }

  /**
   * Unmounts a user mount (best effort).
   */
  public function ensureUnmounted(UserInterface $user): void {
    $machineName = $this->machineNameForUser($user);
    try {
      $this->mounterClient->unmount($machineName);
      $this->credentialsStore->setMountStatus($user, 'disconnected');
    }
    catch (\Throwable $e) {
      $this->logger->notice('Unmount for @user failed: @msg', [
        '@user' => $user->getAccountName(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Reconciles desired mounts (users with credentials) against sidecar state.
   *
   * @return array{mounted: int, remounted: int, unmounted: int, errors: int}
   *   Counters for logging/Drush.
   */
  public function reconcile(): array {
    $stats = [
      'mounted' => 0,
      'remounted' => 0,
      'unmounted' => 0,
      'errors' => 0,
    ];

    if (!$this->mounterClient->ping()) {
      $this->logger->warning('Nextcloud mounter sidecar unreachable; reconcile skipped.');
      return $stats;
    }

    try {
      $listed = $this->mounterClient->listMounts();
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not list Nextcloud mounts: @msg', ['@msg' => $e->getMessage()]);
      return $stats;
    }

    $desired = [];
    foreach ($this->loadUsersWithCredentials() as $user) {
      $machineName = $this->machineNameForUser($user);
      $desired[$machineName] = $user;

      $isListed = $this->mounterClient->isListed($machineName, $listed);
      $probe = $this->mounterClient->probeMount($machineName);

      if (!$isListed) {
        if ($this->ensureMounted($user)) {
          $stats['mounted']++;
        }
        else {
          $stats['errors']++;
        }
        continue;
      }

      if ($probe === 'broken') {
        if ($this->ensureMounted($user)) {
          $stats['remounted']++;
        }
        else {
          $stats['errors']++;
        }
        continue;
      }

      $this->credentialsStore->setMountStatus($user, 'mounted');
    }

    foreach ($listed as $mount) {
      $point = is_array($mount)
        ? (string) ($mount['MountPoint'] ?? $mount['mountPoint'] ?? '')
        : (string) $mount;
      if ($point === '' || !str_starts_with($point, '/mnt/nextcloud/')) {
        continue;
      }
      $machineName = basename($point);
      if ($machineName === '' || $machineName === '_disabled' || isset($desired[$machineName])) {
        continue;
      }
      try {
        $this->mounterClient->unmount($machineName);
        $stats['unmounted']++;
      }
      catch (\Throwable) {
        $stats['errors']++;
      }
    }

    return $stats;
  }

  /**
   * Builds WebDAV URL for a Nextcloud user.
   */
  protected function buildWebdavUrl(string $ncUsername): string {
    $settings = $this->serviceHelpers->initNextcloudSettings();
    $base = rtrim((string) ($settings['baseUrl'] ?? ''), '/');
    if ($base === '') {
      throw new \RuntimeException('Nextcloud base URL is not configured.');
    }
    return $base . '/remote.php/dav/files/' . rawurlencode($ncUsername) . '/';
  }

  /**
   * Loads Drupal users that currently have Nextcloud credentials.
   *
   * @return list<\Drupal\user\UserInterface>
   */
  protected function loadUsersWithCredentials(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('uid', 0, '>')
      ->execute();

    $users = [];
    foreach ($storage->loadMultiple($ids) as $user) {
      if (!$user instanceof UserInterface) {
        continue;
      }
      if ($this->nextcloudHelpers->getMountCredentials($user) !== NULL) {
        $users[] = $user;
      }
    }
    return $users;
  }

}
