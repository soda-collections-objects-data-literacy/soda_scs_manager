<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Service\NextcloudMountCredentialsStore;
use Drupal\soda_scs_manager\Service\NextcloudMounterClient;
use Drupal\soda_scs_manager\Service\NextcloudMountManager;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for Nextcloud sidecar mount lifecycle.
 */
final class SodaScsNextcloudMountCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.nextcloud_mount.manager')]
    protected NextcloudMountManager $mountManager,
    #[Autowire(service: 'soda_scs_manager.nextcloud_mounter.client')]
    protected NextcloudMounterClient $mounterClient,
    #[Autowire(service: 'soda_scs_manager.nextcloud_mount.credentials')]
    protected NextcloudMountCredentialsStore $credentialsStore,
    #[Autowire(service: 'soda_scs_manager.nextcloud.helpers')]
    protected SodaScsNextcloudHelpers $nextcloudHelpers,
  ) {
    parent::__construct();
  }

  /**
   * Mount Nextcloud for a Drupal user via the sidecar.
   */
  #[CLI\Command(name: 'soda_scs_manager:nextcloud-mount', aliases: ['scs:nextcloud-mount'])]
  #[CLI\Option(name: 'user', description: 'Drupal username or uid.')]
  #[CLI\Usage(name: 'drush scs:nextcloud-mount --user=rnsrk', description: 'Mount Nextcloud for user rnsrk.')]
  public function mount(array $options = ['user' => NULL]): int {
    $user = $this->loadUser($options['user'] ?? NULL);
    if ($user === NULL) {
      return self::EXIT_FAILURE;
    }
    if ($this->mountManager->ensureMounted($user)) {
      $this->logger()->success(dt('Mounted Nextcloud for @name (@machine).', [
        '@name' => $user->getAccountName(),
        '@machine' => $this->mountManager->machineNameForUser($user),
      ]));
      return self::EXIT_SUCCESS;
    }
    $this->logger()->error(dt('Mount failed for @name (status: @status).', [
      '@name' => $user->getAccountName(),
      '@status' => $this->credentialsStore->getMountStatus($user) ?? 'unknown',
    ]));
    return self::EXIT_FAILURE;
  }

  /**
   * Unmount Nextcloud for a Drupal user.
   */
  #[CLI\Command(name: 'soda_scs_manager:nextcloud-unmount', aliases: ['scs:nextcloud-unmount'])]
  #[CLI\Option(name: 'user', description: 'Drupal username or uid.')]
  public function unmount(array $options = ['user' => NULL]): int {
    $user = $this->loadUser($options['user'] ?? NULL);
    if ($user === NULL) {
      return self::EXIT_FAILURE;
    }
    $this->mountManager->ensureUnmounted($user);
    $this->logger()->success(dt('Unmounted Nextcloud for @name.', [
      '@name' => $user->getAccountName(),
    ]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Run one reconcile pass against the sidecar.
   */
  #[CLI\Command(name: 'soda_scs_manager:nextcloud-reconcile', aliases: ['scs:nextcloud-reconcile'])]
  public function reconcile(): int {
    if (!$this->mounterClient->ping()) {
      $this->logger()->error(dt('Sidecar nextcloud-mounter is unreachable.'));
      return self::EXIT_FAILURE;
    }
    $stats = $this->mountManager->reconcile();
    $this->logger()->success(dt('Reconcile done: mounted=@m remounted=@r unmounted=@u errors=@e', [
      '@m' => $stats['mounted'],
      '@r' => $stats['remounted'],
      '@u' => $stats['unmounted'],
      '@e' => $stats['errors'],
    ]));
    return $stats['errors'] > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Show Nextcloud mount status table.
   */
  #[CLI\Command(name: 'soda_scs_manager:nextcloud-status', aliases: ['scs:nextcloud-status'])]
  public function status(): int {
    $ping = $this->mounterClient->ping() ? 'yes' : 'no';
    $this->io()->writeln('Sidecar reachable: ' . $ping);

    try {
      $listed = $this->mounterClient->listMounts();
    }
    catch (\Throwable $e) {
      $listed = [];
      $this->logger()->warning(dt('listmounts failed: @msg', ['@msg' => $e->getMessage()]));
    }

    $rows = [];
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('uid', 0, '>')->execute();
    foreach ($storage->loadMultiple($ids) as $user) {
      if (!$user instanceof UserInterface) {
        continue;
      }
      $creds = $this->nextcloudHelpers->getMountCredentials($user);
      if ($creds === NULL && $this->credentialsStore->getMountStatus($user) === NULL) {
        continue;
      }
      $machine = $this->mountManager->machineNameForUser($user);
      $rows[] = [
        $user->id(),
        $user->getAccountName(),
        $machine,
        $creds ? 'yes' : 'no',
        $this->mounterClient->isListed($machine, $listed) ? 'yes' : 'no',
        $this->mounterClient->probeMount($machine),
        $this->credentialsStore->getMountStatus($user) ?? '',
      ];
    }

    if ($rows === []) {
      $this->io()->writeln('No users with Nextcloud credentials or mount status.');
      return self::EXIT_SUCCESS;
    }

    $this->io()->table(
      ['uid', 'name', 'machine', 'creds', 'listed', 'probe', 'status'],
      $rows,
    );
    return self::EXIT_SUCCESS;
  }

  /**
   * Loads a user by uid or account name.
   */
  protected function loadUser(?string $idOrName): ?UserInterface {
    if ($idOrName === NULL || $idOrName === '') {
      $this->logger()->error(dt('Specify --user=NAME_OR_UID.'));
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('user');
    if (ctype_digit($idOrName)) {
      $user = $storage->load((int) $idOrName);
      if ($user instanceof UserInterface) {
        return $user;
      }
    }
    $users = $storage->loadByProperties(['name' => $idOrName]);
    $user = reset($users) ?: NULL;
    if ($user instanceof UserInterface) {
      return $user;
    }
    $this->logger()->error(dt('User @id not found.', ['@id' => $idOrName]));
    return NULL;
  }

}
