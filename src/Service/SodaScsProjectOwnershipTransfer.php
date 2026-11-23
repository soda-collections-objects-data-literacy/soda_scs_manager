<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectDbAccessHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hands a project with its applications to another account.
 *
 * Applications run with the personal service accounts of their owner (SQL,
 * OpenGDB with token, Nextcloud mount, WebProtégé, Keycloak groups), and
 * deleting that owner deletes those accounts. The transfer first gives the
 * new owner the same access, then points every WissKI at the new accounts,
 * and only then changes the owner fields. Nothing of the previous owner is
 * deleted: they stay a member (with the admin role on the WissKIs) or can be
 * removed afterwards like any member.
 */
class SodaScsProjectOwnershipTransfer {

  /**
   * How long the project stays locked at most; a WissKI redeploy takes minutes.
   */
  protected const LOCK_TIMEOUT_SECONDS = 3600.0;

  protected const SQL_BUNDLE = 'soda_scs_sql_component';
  protected const TRIPLESTORE_BUNDLE = 'soda_scs_triplestore_component';
  protected const WISSKI_BUNDLE = 'soda_scs_wisski_component';

  /**
   * Lock name prefix of SodaScsWisskiUpdateQueueWorker, per component id.
   */
  protected const WISSKI_UPDATE_LOCK_PREFIX = 'soda_scs_manager.wisski_update.';

  /**
   * Constructs the transfer service.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.component.helpers')]
    protected SodaScsComponentHelpers $componentHelpers,
    #[Autowire(service: 'database')]
    protected Connection $database,
    #[Autowire(service: 'soda_scs_manager.project_db_access.helpers')]
    protected SodaScsProjectDbAccessHelpers $dbAccessHelpers,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'lock')]
    protected LockBackendInterface $lock,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerChannelInterface $logger,
    #[Autowire(service: 'soda_scs_manager.nextcloud.helpers')]
    protected SodaScsNextcloudHelpers $nextcloudHelpers,
    #[Autowire(service: 'soda_scs_manager.nextcloud_mount.manager')]
    protected NextcloudMountManager $nextcloudMountManager,
    #[Autowire(service: 'soda_scs_manager.opengdb.helpers')]
    protected SodaScsOpenGdbHelpers $openGdbHelpers,
    #[Autowire(service: 'lock.persistent')]
    protected LockBackendInterface $persistentLock,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    protected SodaScsProjectHelpers $projectHelpers,
    #[Autowire(service: 'soda_scs_manager.service_key.actions')]
    protected SodaScsServiceKeyActionsInterface $serviceKeyActions,
    #[Autowire(service: 'soda_scs_manager.wisski_triplestore_token_sync')]
    protected SodaScsWisskiTriplestoreTokenSync $tokenSync,
    #[Autowire(service: 'soda_scs_manager.triplestore_helpers')]
    protected SodaScsTriplestoreHelpers $triplestoreHelpers,
    #[Autowire(service: 'soda_scs_manager.webprotege.helpers')]
    protected SodaScsWebprotegeHelpers $webprotegeHelpers,
    #[Autowire(service: 'soda_scs_manager.wisski_component.actions')]
    protected SodaScsWisskiComponentActions $wisskiComponentActions,
    #[Autowire(service: 'soda_scs_manager.wisski_database_credentials')]
    protected SodaScsWisskiDatabaseCredentials $wisskiDatabaseCredentials,
  ) {}

  /**
   * Makes another account the owner of a project and of its applications.
   *
   * Applications that a member added to the project keep their owner. A
   * failure before the owner fields change leaves every WissKI as it was.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project.
   * @param \Drupal\user\UserInterface $newOwner
   *   The new owner; needs a Keycloak account.
   * @param bool $keepPreviousOwnerAsMember
   *   Whether the previous owner stays as a member with the admin role on
   *   the WissKIs of the project.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success data['warnings'] lists follow-up steps that failed and can be
   *   repeated (Keycloak group sync).
   */
  public function transfer(SodaScsProjectInterface $project, UserInterface $newOwner, bool $keepPreviousOwnerAsMember = TRUE): SodaScsResult {
    $previousOwner = $project->getOwner();
    if (!$previousOwner instanceof UserInterface) {
      return $this->fail($project, 'The project has no owner.');
    }
    if ((int) $previousOwner->id() === (int) $newOwner->id()) {
      return SodaScsResult::success(message: 'The account already owns the project.', data: ['warnings' => []]);
    }
    if (!$this->projectHelpers->getUserSsoUuid($newOwner)) {
      return $this->fail($project, sprintf('Account %s has no Keycloak account.', $newOwner->id()));
    }

    $lockName = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $project->id();
    if (!$this->persistentLock->acquire($lockName, self::LOCK_TIMEOUT_SECONDS)) {
      return $this->fail($project, 'The project is locked by another job.');
    }
    try {
      return $this->transferLocked($project, $previousOwner, $newOwner, $keepPreviousOwnerAsMember);
    }
    finally {
      $this->persistentLock->release($lockName);
    }
  }

  /**
   * Gives the new owner access to the services the applications use.
   *
   * Only adds accounts and rights, so a failure needs no undo.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project.
   * @param \Drupal\user\UserInterface $newOwner
   *   The new owner.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface[] $components
   *   The components that change owner.
   *
   * @return string|null
   *   NULL on success, otherwise the reason.
   */
  protected function grantServiceAccess(SodaScsProjectInterface $project, UserInterface $newOwner, array $components): ?string {
    $bundles = array_map(static fn (SodaScsComponentInterface $component): string => $component->bundle(), $components);

    if (in_array(self::SQL_BUNDLE, $bundles, TRUE) && !$this->dbAccessHelpers->ensureProjectDbAccess($newOwner, $project)) {
      return 'Could not give the new owner access to the project databases.';
    }

    $repositories = [];
    foreach ($components as $component) {
      if ($component->bundle() === self::TRIPLESTORE_BUNDLE) {
        $repositories[] = (string) $component->get('machineName')->value;
      }
    }
    if ($repositories !== []) {
      try {
        $this->triplestoreHelpers->ensureUserAccount($newOwner);
        $this->openGdbHelpers->addUserRepositoryGrants($newOwner->getDisplayName(), $repositories);
      }
      catch (\Throwable $e) {
        $this->logger->error('Could not prepare the triplestore account of user @user: @message', [
          '@message' => $e->getMessage(),
          '@user' => $newOwner->id(),
        ]);
        return 'Could not give the new owner access to the triplestore repositories.';
      }
    }

    if (trim((string) ($project->get('webprotegeProjectId')->value ?? '')) !== '') {
      $grant = $this->webprotegeHelpers->grantEdit($project, $newOwner);
      if (!$grant->success) {
        return 'Could not give the new owner access to the WebProtégé project.';
      }
    }

    $groupId = (string) ($project->get('groupId')->value ?? '');
    if ($groupId !== '' && !$this->nextcloudHelpers->addUserToGroup($groupId, $newOwner)) {
      $this->logger->warning('Could not add user @user to the Nextcloud group of project @project; the Team Folder appears at their next Drive login.', [
        '@project' => $project->id(),
        '@user' => $newOwner->id(),
      ]);
    }
    return NULL;
  }

  /**
   * Fails a transfer with a logged reason.
   */
  protected function fail(SodaScsProjectInterface $project, string $reason): SodaScsResult {
    return SodaScsResult::failure(
      error: sprintf('Transfer of project %s: %s', $project->id(), $reason),
      message: $reason,
    );
  }

  /**
   * Loads the stacks and components of the project that the owner owns.
   *
   * @return array{stacks: \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[], components: \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface[]}|string
   *   The applications, or the reason when one is shared with another project.
   */
  protected function ownedApplications(SodaScsProjectInterface $project, UserInterface $previousOwner): array|string {
    $applications = ['stacks' => [], 'components' => []];
    foreach (['stacks' => 'soda_scs_stack', 'components' => 'soda_scs_component'] as $key => $entityTypeId) {
      $entities = $this->entityTypeManager->getStorage($entityTypeId)->loadByProperties(['partOfProjects' => $project->id()]);
      foreach ($entities as $entity) {
        if ((int) $entity->getOwnerId() !== (int) $previousOwner->id()) {
          continue;
        }
        $projectIds = array_map('intval', array_column($entity->get('partOfProjects')->getValue(), 'target_id'));
        if ($projectIds !== [(int) $project->id()]) {
          return sprintf('Application %s %s also belongs to another project.', $entityTypeId, $entity->id());
        }
        $applications[$key][(int) $entity->id()] = $entity;
      }
    }
    return $applications;
  }

  /**
   * Points a WissKI at the database and triplestore accounts of the new owner.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisski
   *   The WissKI component.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project.
   * @param \Drupal\user\UserInterface $previousOwner
   *   The current owner.
   * @param \Drupal\user\UserInterface $newOwner
   *   The new owner.
   * @param callable[] $rollbacks
   *   Undo steps; this method adds its own on success.
   *
   * @return string|null
   *   NULL on success, otherwise the reason. On failure the WissKI is back in
   *   its previous state.
   */
  protected function rebindWisski(SodaScsComponentInterface $wisski, SodaScsProjectInterface $project, UserInterface $previousOwner, UserInterface $newOwner, array &$rollbacks): ?string {
    $undo = [];
    try {
      $reason = $this->rebindWisskiSteps($wisski, $project, $previousOwner, $newOwner, $undo);
    }
    catch (\Throwable $e) {
      $this->logger->error('Rebinding WissKI @wisski to user @user failed: @message', [
        '@message' => $e->getMessage(),
        '@user' => $newOwner->id(),
        '@wisski' => $wisski->id(),
      ]);
      $reason = sprintf('Could not point WissKI %s at the accounts of the new owner.', $wisski->id());
    }
    if ($reason !== NULL) {
      $this->runRollbacks($undo);
      return $reason;
    }
    array_push($rollbacks, ...$undo);
    return NULL;
  }

  /**
   * The steps of rebindWisski(); each change adds its undo step right after it.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisski
   *   The WissKI component.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project.
   * @param \Drupal\user\UserInterface $previousOwner
   *   The current owner.
   * @param \Drupal\user\UserInterface $newOwner
   *   The new owner.
   * @param callable[] $undo
   *   Receives the undo steps of the changes made so far.
   *
   * @return string|null
   *   NULL on success, otherwise the reason.
   */
  protected function rebindWisskiSteps(SodaScsComponentInterface $wisski, SodaScsProjectInterface $project, UserInterface $previousOwner, UserInterface $newOwner, array &$undo): ?string {
    $captured = $this->wisskiComponentActions->captureDeployment($wisski);
    if (!$captured->success) {
      return sprintf('Could not read the deployment of WissKI %s.', $wisski->id());
    }
    $deployment = $captured->data['deployment'];
    $env = array_column(array_filter($deployment['env'] ?? [], 'is_array'), 'value', 'name');
    $connected = $this->componentHelpers->resolveConnectedComponents($wisski);
    $updates = [];

    // Database: settings.php first, the stack environment with the redeploy.
    $oldDbUser = (string) ($env['DB_USER'] ?? '');
    $oldDbPassword = (string) ($env['DB_PASSWORD'] ?? '');
    if (($connected['sql'] ?? NULL) instanceof SodaScsComponentInterface && $oldDbUser !== '') {
      $sqlKey = $this->serviceKeyActions->getServiceKey([
        'bundle' => self::SQL_BUNDLE,
        'type' => 'password',
        'userId' => $newOwner->id(),
      ]);
      $newDbPassword = $sqlKey ? (string) $sqlKey->get('servicePassword')->value : '';
      if ($newDbPassword === '') {
        return 'The new owner has no database password.';
      }
      $newDbUser = $newOwner->getDisplayName();
      $replaced = $this->wisskiDatabaseCredentials->replace($wisski, $oldDbPassword, $oldDbUser, $newDbPassword, $newDbUser);
      if (!$replaced->success) {
        return sprintf('Could not change the database account of WissKI %s.', $wisski->id());
      }
      $undo[] = fn () => $this->wisskiDatabaseCredentials->replace($wisski, $newDbPassword, $newDbUser, $oldDbPassword, $oldDbUser);
      $updates['DB_USER'] = $newDbUser;
      $updates['DB_PASSWORD'] = $newDbPassword;
    }

    $triplestore = ($connected['triplestore'] ?? NULL) instanceof SodaScsComponentInterface ? $connected['triplestore'] : NULL;
    $newToken = '';
    if ($triplestore !== NULL) {
      $passwordKey = $this->serviceKeyActions->getServiceKey([
        'bundle' => self::TRIPLESTORE_BUNDLE,
        'type' => 'password',
        'userId' => $newOwner->id(),
      ]);
      $tokenKey = $this->serviceKeyActions->getServiceKey([
        'bundle' => self::TRIPLESTORE_BUNDLE,
        'type' => 'token',
        'userId' => $newOwner->id(),
      ]);
      $newToken = $tokenKey ? (string) $tokenKey->get('servicePassword')->value : '';
      if (!$passwordKey || $newToken === '') {
        return 'The new owner has no triplestore token.';
      }
      $updates['TS_USERNAME'] = $newOwner->getDisplayName();
      $updates['TS_PASSWORD'] = (string) $passwordKey->get('servicePassword')->value;
      $updates['TS_TOKEN'] = $newToken;
    }

    // The Team Folder is mounted from the owner's Nextcloud mount.
    $mountSource = (string) ($env['NEXTCLOUD_USER_MOUNT_SOURCE'] ?? '');
    $previousMountRoot = $this->nextcloudMountManager->userMountSource($previousOwner) . '/';
    if (str_starts_with($mountSource, $previousMountRoot)) {
      if (!$this->nextcloudMountManager->ensureProjectTeamFolderReady($newOwner, $project)) {
        return 'The Team Folder is not available under the Nextcloud mount of the new owner.';
      }
      $updates['NEXTCLOUD_USER_MOUNT_SOURCE'] = $this->nextcloudMountManager->projectTeamFolderMountSource($newOwner, $project);
    }

    if ($updates !== []) {
      // Registered first: after a failed or interrupted redeploy Portainer
      // may have taken the new environment.
      $undo[] = fn () => $this->wisskiComponentActions->restoreDeployment($wisski, $deployment);
      $redeployed = $this->wisskiComponentActions->redeployWithEnvironment($wisski, $updates);
      if (!$redeployed->success) {
        return sprintf('Could not redeploy WissKI %s with the accounts of the new owner.', $wisski->id());
      }
    }

    if ($triplestore !== NULL) {
      $written = $this->tokenSync->writeToken($wisski, $triplestore, $newToken);
      $oldToken = (string) ($env['TS_TOKEN'] ?? '');
      if ($oldToken !== '') {
        $undo[] = fn () => $this->tokenSync->writeToken($wisski, $triplestore, $oldToken);
      }
      if (!$written->success) {
        return sprintf('Could not write the triplestore token of the new owner into WissKI %s.', $wisski->id());
      }
    }

    return NULL;
  }

  /**
   * Points the service key references of a component at the new owner's keys.
   *
   * Keys of other accounts stay. The new owner gets a copy of a key it has no
   * counterpart for (the WissKI admin password), so the stack environment
   * keeps matching. A WissKI admin password exists once per account, so when
   * the new owner already has a different one, the WissKI keeps its own
   * password and the stored key no longer matches it: that is a warning.
   *
   * @return string[]
   *   Warnings about keys whose value differs from the new owner's key.
   */
  protected function remapServiceKeys(SodaScsComponentInterface $component, UserInterface $previousOwner, UserInterface $newOwner): array {
    $warnings = [];
    $references = [];
    foreach ($component->get('serviceKey')->referencedEntities() as $key) {
      if (!$key instanceof SodaScsServiceKeyInterface || (int) $key->getOwnerId() !== (int) $previousOwner->id()) {
        $references[] = $key;
        continue;
      }
      $bundle = (string) $key->get('scsComponentBundle')->value;
      $type = (string) $key->get('type')->value;
      $newKey = $this->serviceKeyActions->getServiceKey([
        'bundle' => $bundle,
        'type' => $type,
        'userId' => $newOwner->id(),
      ]);
      if (!$newKey instanceof SodaScsServiceKeyInterface) {
        $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_component')[$bundle] ?? [];
        $newKey = $this->serviceKeyActions->createServiceKey([
          'bundle' => $bundle,
          'bundleLabel' => (string) ($bundleInfo['label'] ?? $bundle),
          'password' => (string) $key->get('servicePassword')->value,
          'token' => (string) $key->get('servicePassword')->value,
          'type' => $type,
          'userId' => $newOwner->id(),
          'username' => $newOwner->getDisplayName(),
        ]);
      }
      elseif ($bundle === self::WISSKI_BUNDLE && (string) $newKey->get('servicePassword')->value !== (string) $key->get('servicePassword')->value) {
        $warnings[] = sprintf('WissKI %s keeps its admin password, which differs from the stored WissKI password of the new owner.', $component->id());
      }
      $this->moveKeyReference($key, $newKey, (int) $component->id());
      $references[] = $newKey;
    }
    $component->set('serviceKey', $references);
    return $warnings;
  }

  /**
   * Moves a component from the reference list of one key to another.
   */
  protected function moveKeyReference(SodaScsServiceKeyInterface $from, SodaScsServiceKeyInterface $to, int $componentId): void {
    $remaining = array_values(array_filter(
      $from->get('scsComponent')->getValue(),
      static fn (array $item): bool => (int) ($item['target_id'] ?? 0) !== $componentId,
    ));
    $from->set('scsComponent', $remaining);
    $from->save();
    $targetIds = array_map('intval', array_column($to->get('scsComponent')->getValue(), 'target_id'));
    if (!in_array($componentId, $targetIds, TRUE)) {
      $to->get('scsComponent')->appendItem($componentId);
      $to->save();
    }
  }

  /**
   * Runs undo steps in reverse order; failures are logged, not thrown.
   *
   * @param callable[] $rollbacks
   *   The undo steps.
   */
  protected function runRollbacks(array $rollbacks): void {
    foreach (array_reverse($rollbacks) as $rollback) {
      try {
        $result = $rollback();
        if ($result instanceof SodaScsResult && !$result->success) {
          $this->logger->error('Undo step of a project transfer failed: @error', ['@error' => (string) ($result->error ?? $result->message)]);
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('Undo step of a project transfer failed: @error', ['@error' => $e->getMessage()]);
      }
    }
  }

  /**
   * Changes owners, members and roles in one database transaction.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project.
   * @param array{stacks: \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[], components: \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface[]} $applications
   *   The applications that change owner.
   * @param \Drupal\user\UserInterface $previousOwner
   *   The current owner.
   * @param \Drupal\user\UserInterface $newOwner
   *   The new owner.
   * @param bool $keepPreviousOwnerAsMember
   *   Whether the previous owner becomes a member.
   *
   * @return string[]
   *   Warnings about service keys that could not be matched.
   */
  protected function saveOwnership(SodaScsProjectInterface $project, array $applications, UserInterface $previousOwner, UserInterface $newOwner, bool $keepPreviousOwnerAsMember): array {
    $warnings = [];
    $transaction = $this->database->startTransaction();
    try {
      foreach ($applications['components'] as $component) {
        array_push($warnings, ...$this->remapServiceKeys($component, $previousOwner, $newOwner));
        $component->setOwnerId((int) $newOwner->id());
        $component->save();
      }
      foreach ($applications['stacks'] as $stack) {
        $stack->setOwnerId((int) $newOwner->id());
        $stack->save();
      }
      $this->transferSnapshots(array_keys($applications['components']), array_keys($applications['stacks']), $previousOwner, $newOwner);

      $newOwnerId = (int) $newOwner->id();
      $previousOwnerId = (int) $previousOwner->id();
      $members = array_values(array_filter(
        array_map('intval', array_column($project->get('members')->getValue(), 'target_id')),
        static fn (int $memberId): bool => $memberId !== $newOwnerId && $memberId !== $previousOwnerId,
      ));
      $roles = array_map(
        static fn (array $rolesOfComponent): array => array_diff_key(
          $rolesOfComponent,
          [$newOwnerId => TRUE, $previousOwnerId => TRUE],
        ),
        $this->projectHelpers->getStoredComponentRoles($project),
      );
      if ($keepPreviousOwnerAsMember) {
        $members[] = $previousOwnerId;
        foreach ($this->projectHelpers->getRoleAssignableComponents($project) as $component) {
          $roles[(string) $component->id()][(string) $previousOwnerId] = SodaScsProjectHelpers::ROLE_ADMIN;
        }
      }
      $project->set('members', $members);
      $this->projectHelpers->setStoredComponentRoles($project, $roles);
      $project->setOwnerId($newOwnerId);
      $project->save();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    return $warnings;
  }

  /**
   * Points the WissKIs at the new owner's accounts and saves the owners.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project.
   * @param array{stacks: \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[], components: \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface[]} $applications
   *   The applications that change owner.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface[] $wisskis
   *   The WissKIs among them.
   * @param \Drupal\user\UserInterface $previousOwner
   *   The current owner.
   * @param \Drupal\user\UserInterface $newOwner
   *   The new owner.
   * @param bool $keepPreviousOwnerAsMember
   *   Whether the previous owner becomes a member.
   *
   * @return string[]|string
   *   The warnings on success, otherwise the reason; on failure every
   *   WissKI is back in its previous state.
   */
  protected function rebindAndSave(SodaScsProjectInterface $project, array $applications, array $wisskis, UserInterface $previousOwner, UserInterface $newOwner, bool $keepPreviousOwnerAsMember): array|string {
    $rollbacks = [];
    foreach ($wisskis as $wisski) {
      $reason = $this->rebindWisski($wisski, $project, $previousOwner, $newOwner, $rollbacks);
      if ($reason !== NULL) {
        $this->runRollbacks($rollbacks);
        return $reason;
      }
    }

    try {
      return $this->saveOwnership($project, $applications, $previousOwner, $newOwner, $keepPreviousOwnerAsMember);
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not save the new owner of project @project: @message', [
        '@message' => $e->getMessage(),
        '@project' => $project->id(),
      ]);
      $this->runRollbacks($rollbacks);
      return 'Could not save the new owner.';
    }
  }

  /**
   * Runs the transfer while the project lock is held.
   */
  protected function transferLocked(SodaScsProjectInterface $project, UserInterface $previousOwner, UserInterface $newOwner, bool $keepPreviousOwnerAsMember): SodaScsResult {
    $applications = $this->ownedApplications($project, $previousOwner);
    if (is_string($applications)) {
      return $this->fail($project, $applications);
    }

    $reason = $this->grantServiceAccess($project, $newOwner, $applications['components']);
    if ($reason !== NULL) {
      return $this->fail($project, $reason);
    }

    $wisskis = array_values(array_filter(
      $applications['components'],
      static fn (SodaScsComponentInterface $component): bool => $component->bundle() === self::WISSKI_BUNDLE,
    ));
    // A WissKI update or rollback redeploys from the environment it read
    // before, which would bring back the accounts of the previous owner.
    $updateLocks = [];
    foreach ($wisskis as $wisski) {
      $updateLockName = self::WISSKI_UPDATE_LOCK_PREFIX . $wisski->id();
      if (!$this->lock->acquire($updateLockName, self::LOCK_TIMEOUT_SECONDS)) {
        array_map([$this->lock, 'release'], $updateLocks);
        return $this->fail($project, sprintf('WissKI %s is being updated; try again when the update is done.', $wisski->id()));
      }
      $updateLocks[] = $updateLockName;
    }

    try {
      $warnings = $this->rebindAndSave($project, $applications, $wisskis, $previousOwner, $newOwner, $keepPreviousOwnerAsMember);
    }
    finally {
      array_map([$this->lock, 'release'], $updateLocks);
    }
    if (is_string($warnings)) {
      return $this->fail($project, $warnings);
    }

    $synced = $this->projectHelpers->syncKeycloakGroupMembers($project);
    if (!$synced->success) {
      $warnings[] = 'Keycloak groups of the project could not be synced; save the project members again.';
    }
    $this->logger->notice('Project @project was transferred from user @previous to user @new.', [
      '@new' => $newOwner->id(),
      '@previous' => $previousOwner->id(),
      '@project' => $project->id(),
    ]);
    return SodaScsResult::success(
      message: sprintf('Project %s now belongs to %s.', $project->label(), $newOwner->getDisplayName()),
      data: ['warnings' => $warnings],
    );
  }

  /**
   * Gives the snapshots of the transferred applications to the new owner.
   *
   * @param int[] $componentIds
   *   The transferred components.
   * @param int[] $stackIds
   *   The transferred stacks.
   * @param \Drupal\user\UserInterface $previousOwner
   *   The current owner.
   * @param \Drupal\user\UserInterface $newOwner
   *   The new owner.
   */
  protected function transferSnapshots(array $componentIds, array $stackIds, UserInterface $previousOwner, UserInterface $newOwner): void {
    $storage = $this->entityTypeManager->getStorage('soda_scs_snapshot');
    $ids = [];
    foreach (['snapshotOfComponent' => $componentIds, 'snapshotOfStack' => $stackIds] as $field => $targets) {
      if ($targets === []) {
        continue;
      }
      $ids += $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition($field, $targets, 'IN')
        ->condition('owner', $previousOwner->id())
        ->execute();
    }
    foreach ($storage->loadMultiple($ids) as $snapshot) {
      $snapshot->setOwnerId((int) $newOwner->id());
      $snapshot->save();
    }
  }

}
