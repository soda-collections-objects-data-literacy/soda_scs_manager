<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponent;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKey;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshot;
use Drupal\soda_scs_manager\Entity\SodaScsStack;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceUserActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbServiceActions;
use Drupal\soda_scs_manager\ServiceActions\SodaScsSqlServiceActions;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsUserOffboardingInventory;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes a user's own projects and service accounts when the account goes.
 *
 * Projects the user only belongs to stay. The user is taken off them, and
 * their access in Keycloak, Nextcloud, MariaDB and WebProtégé is revoked.
 */
final class SodaScsUserOffboarding {

  /**
   * The logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * Constructs the offboarding service.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.component.actions')]
    private readonly SodaScsComponentActionsInterface $componentActions,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'logger.factory')]
    LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'soda_scs_manager.project_membership.helpers')]
    private readonly SodaScsProjectMembershipHelpers $membershipHelpers,
    #[Autowire(service: 'soda_scs_manager.nextcloud.helpers')]
    private readonly SodaScsNextcloudHelpers $nextcloudHelpers,
    #[Autowire(service: 'soda_scs_manager.nextcloud_mount.manager')]
    private readonly NextcloudMountManager $nextcloudMountManager,
    #[Autowire(service: 'soda_scs_manager.nextcloud_project_mount.manager')]
    private readonly NextcloudProjectMountManager $nextcloudProjectMountManager,
    #[Autowire(service: 'soda_scs_manager.opengdb_service.actions')]
    private readonly SodaScsOpenGdbServiceActions $openGdbServiceActions,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    private readonly SodaScsProjectHelpers $projectHelpers,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    private readonly SodaScsServiceHelpers $serviceHelpers,
    #[Autowire(service: 'soda_scs_manager.sql_service.actions')]
    private readonly SodaScsSqlServiceActions $sqlServiceActions,
    #[Autowire(service: 'soda_scs_manager.stack.actions')]
    private readonly SodaScsStackActionsInterface $stackActions,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.user.actions')]
    private readonly SodaScsKeycloakServiceUserActions $keycloakUserActions,
    #[Autowire(service: 'soda_scs_manager.webprotege.helpers')]
    private readonly SodaScsWebprotegeHelpers $webprotegeHelpers,
  ) {
    $this->logger = $loggerFactory->get('soda_scs_manager');
  }

  /**
   * Classifies the user's projects into owned and membership-only.
   */
  public function inventory(UserInterface $user): SodaScsUserOffboardingInventory {
    $userId = (int) $user->id();
    $ownedAlone = [];
    $ownedShared = [];
    foreach ($this->loadProjects('owner', $userId) as $project) {
      if ($this->hasOtherMembers($project, $userId)) {
        $ownedShared[] = $project;
      }
      else {
        $ownedAlone[] = $project;
      }
    }

    $memberships = [];
    foreach ($this->loadProjects('members', $userId) as $project) {
      if ((int) $project->getOwnerId() !== $userId) {
        $memberships[] = $project;
      }
    }

    return new SodaScsUserOffboardingInventory($memberships, $ownedAlone, $ownedShared);
  }

  /**
   * Deletes owned projects and service accounts, and leaves foreign projects.
   *
   * Runs from hook_user_predelete, while the account and its SSO link still
   * exist. A failing remote call is logged and does not stop the rest.
   */
  public function purge(UserInterface $user): void {
    if ((int) $user->id() <= 1) {
      return;
    }

    $inventory = $this->inventory($user);
    foreach ($inventory->ownedShared() as $project) {
      $this->logger->warning('Deleting owned project @project of @user while other members remain: @members', [
        '@project' => $project->label(),
        '@user' => $user->getAccountName(),
        '@members' => $this->otherMemberLabels($project, (int) $user->id()),
      ]);
    }

    foreach ($inventory->memberships() as $project) {
      $this->runStep('leave project ' . $project->id(), function () use ($project, $user): void {
        $this->membershipHelpers->detachMember($project, $user);
      });
    }

    $this->deleteInvitations($user);

    foreach ($inventory->ownedProjects() as $project) {
      $this->deleteOwnedProject($project);
    }

    $this->purgeLeftovers($user);
    $this->deletePersonalServiceAccounts($user);
  }

  /**
   * Deletes pending and past invitations that name this user.
   */
  private function deleteInvitations(UserInterface $user): void {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project_membership');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $or = $query->orConditionGroup()
      ->condition('requester', $user->id())
      ->condition('recipient', $user->id());
    $ids = $query->condition($or)->execute();
    if ($ids === []) {
      return;
    }
    foreach ($storage->loadMultiple($ids) as $invitation) {
      $this->runStep('delete invitation ' . $invitation->id(), static function () use ($invitation): void {
        $invitation->delete();
      });
    }
  }

  /**
   * Deletes the Keycloak account. A missing SSO link is skipped.
   */
  private function deleteKeycloakAccount(UserInterface $user): void {
    $keycloakUserId = $this->projectHelpers->getUserSsoUuid($user);
    if ($keycloakUserId === NULL || $keycloakUserId === '') {
      $this->logger->notice('User @username has no Keycloak SSO link, skipping Keycloak delete.', [
        '@username' => $user->getAccountName(),
      ]);
      return;
    }

    $settings = $this->serviceHelpers->initKeycloakGeneralSettings();
    $tokenRequest = $this->keycloakUserActions->buildTokenRequest([]);
    $tokenResponse = $this->keycloakUserActions->makeRequest($tokenRequest);
    if (empty($tokenResponse['success'])) {
      $this->logger->error('Failed to get Keycloak token for user deletion: @error', [
        '@error' => $tokenResponse['error'] ?? '',
      ]);
      return;
    }

    $tokenData = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
    $response = $this->keycloakUserActions->makeRequest($this->keycloakUserActions->buildDeleteRequest([
      'type' => 'user',
      'token' => $tokenData['access_token'] ?? '',
      'routeParams' => [
        'realm' => $settings['realm'] ?? '',
        'userId' => $keycloakUserId,
      ],
    ]));
    if (empty($response['success'])) {
      $this->logger->error('Failed to delete user from Keycloak: @error', [
        '@error' => $response['error'] ?? '',
      ]);
    }
  }

  /**
   * Unmounts the personal Drive and deletes the Nextcloud account.
   */
  private function deleteNextcloudAccount(UserInterface $user): void {
    $keycloakUserId = $this->projectHelpers->getUserSsoUuid($user);
    if ($keycloakUserId === NULL || $keycloakUserId === '') {
      return;
    }
    $this->nextcloudMountManager->ensureUnmounted($user);
    $this->nextcloudHelpers->deleteNextcloudAccountForKeycloakUser($keycloakUserId, $user);
  }

  /**
   * Tears down one owned project and the applications that belong to it.
   */
  private function deleteOwnedProject(SodaScsProjectInterface $project): void {
    $projectId = (int) $project->id();
    $stacks = $this->loadLinked('soda_scs_stack', $projectId);
    usort($stacks, static function (SodaScsStackInterface $left, SodaScsStackInterface $right): int {
      $leftJupyter = $left->bundle() === 'soda_scs_jupyter_stack' ? 0 : 1;
      $rightJupyter = $right->bundle() === 'soda_scs_jupyter_stack' ? 0 : 1;
      return $leftJupyter <=> $rightJupyter;
    });
    foreach ($stacks as $stack) {
      $this->runStep('delete stack ' . $stack->id(), function () use ($stack): void {
        $this->stackActions->deleteStack($stack);
      });
      $this->forceDelete($stack);
    }

    foreach ($this->loadLinked('soda_scs_component', $projectId) as $component) {
      if (!$component instanceof SodaScsComponentInterface) {
        continue;
      }
      $this->runStep('delete component ' . $component->id(), function () use ($component): void {
        $this->componentActions->deleteComponent($component);
      });
      $this->forceDelete($component);
    }

    $fresh = $this->entityTypeManager->getStorage('soda_scs_project')->load($projectId);
    if (!$fresh instanceof SodaScsProjectInterface) {
      return;
    }

    $this->runStep('unmount project ' . $projectId, function () use ($fresh): void {
      $this->nextcloudProjectMountManager->ensureProjectUnmounted($fresh);
    });
    $this->runStep('delete team folder ' . $projectId, function () use ($fresh): void {
      $this->projectHelpers->deleteProjectTeamFolder($fresh);
    });
    $this->runStep('trash webprotege ' . $projectId, function () use ($fresh): void {
      $this->webprotegeHelpers->trashOntologyProject($fresh);
    });
    $this->runStep('delete project group ' . $projectId, function () use ($fresh): void {
      $this->projectHelpers->deleteProjectGroup($fresh);
    });

    $remaining = $this->entityTypeManager->getStorage('soda_scs_project')->load($projectId);
    if ($remaining instanceof SodaScsProjectInterface) {
      $this->runStep('delete project ' . $projectId, static function () use ($remaining): void {
        $remaining->delete();
      });
    }
  }

  /**
   * Deletes the accounts that belong to the person, after their projects.
   */
  private function deletePersonalServiceAccounts(UserInterface $user): void {
    $this->runStep('nextcloud account', function () use ($user): void {
      $this->deleteNextcloudAccount($user);
    });
    $this->runStep('sql account', function () use ($user): void {
      $this->deleteSqlAccount($user);
    });
    $this->runStep('triplestore account', function () use ($user): void {
      $this->deleteTriplestoreAccount($user);
    });
    $this->runStep('webprotege account', function () use ($user): void {
      $this->webprotegeHelpers->deleteUserAccount($user);
    });
    $this->runStep('keycloak account', function () use ($user): void {
      $this->deleteKeycloakAccount($user);
    });
  }

  /**
   * Drops the MariaDB account when one exists.
   *
   * Databases of owned projects are already gone. Grants on databases the
   * user only shared were revoked when they left those projects.
   */
  private function deleteSqlAccount(UserInterface $user): void {
    $dbUser = $user->getDisplayName();
    if ($dbUser === '' || $dbUser === 'root') {
      return;
    }
    $exists = $this->sqlServiceActions->existServiceUser($dbUser);
    if ((string) ($exists['result'] ?? '0') !== '1') {
      return;
    }
    $deleted = $this->sqlServiceActions->deleteServiceUser($dbUser);
    if ((int) ($deleted['execStatus'] ?? 1) !== 0) {
      $this->logger->error('Failed to delete database user @user.', [
        '@user' => $user->getAccountName(),
      ]);
    }
  }

  /**
   * Deletes the triplestore account. A missing account is ignored.
   */
  private function deleteTriplestoreAccount(UserInterface $user): void {
    $request = $this->openGdbServiceActions->buildDeleteRequest([
      'type' => 'user',
      'queryParams' => [],
      'routeParams' => [
        'username' => $user->getDisplayName(),
      ],
    ]);
    $response = $this->openGdbServiceActions->makeRequest($request);
    $statusCode = (int) ($response['statusCode'] ?? 0);
    if (empty($response['success']) && $statusCode !== 404) {
      $this->logger->error('Failed to delete triplestore user @user: @error', [
        '@user' => $user->getAccountName(),
        '@error' => $response['error'] ?? '',
      ]);
    }
  }

  /**
   * Deletes an entity that a service action left behind.
   */
  private function forceDelete(EntityInterface $entity): void {
    $id = $entity->id();
    if ($id === NULL || $id === '') {
      return;
    }
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $fresh = $storage->load($id);
    if (!$fresh instanceof EntityInterface) {
      return;
    }
    $this->runStep('force delete ' . $entity->getEntityTypeId() . ' ' . $id, static function () use ($fresh): void {
      $fresh->delete();
    });
  }

  /**
   * Whether the project lists a member other than its owner.
   */
  private function hasOtherMembers(SodaScsProjectInterface $project, int $ownerId): bool {
    if (!$project->hasField('members')) {
      return FALSE;
    }
    foreach ($project->get('members')->getValue() ?? [] as $item) {
      $memberId = (int) ($item['target_id'] ?? 0);
      if ($memberId > 0 && $memberId !== $ownerId) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Loads components or stacks linked to a project.
   *
   * @return list<\Drupal\Core\Entity\EntityInterface>
   *   The linked entities.
   */
  private function loadLinked(string $entityTypeId, int $projectId): array {
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('partOfProjects', $projectId)
      ->execute();
    if ($ids === []) {
      return [];
    }
    return array_values($storage->loadMultiple($ids));
  }

  /**
   * Loads projects by owner or by membership.
   *
   * @return list<\Drupal\soda_scs_manager\Entity\SodaScsProjectInterface>
   *   The matching projects.
   */
  private function loadProjects(string $field, int $userId): array {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($field, $userId)
      ->execute();
    if ($ids === []) {
      return [];
    }
    $projects = [];
    foreach ($storage->loadMultiple($ids) as $project) {
      if ($project instanceof SodaScsProjectInterface) {
        $projects[] = $project;
      }
    }
    return $projects;
  }

  /**
   * Display names of members other than the owner.
   */
  private function otherMemberLabels(SodaScsProjectInterface $project, int $ownerId): string {
    $labels = [];
    foreach ($project->get('members')->referencedEntities() as $member) {
      if ($member instanceof UserInterface && (int) $member->id() !== $ownerId) {
        $labels[] = $member->getDisplayName();
      }
    }
    return $labels === [] ? '-' : implode(', ', $labels);
  }

  /**
   * Removes owned apps that are not attached to somebody else's project.
   */
  private function purgeLeftovers(UserInterface $user): void {
    $userId = (int) $user->id();
    foreach (SodaScsComponent::loadByOwner($userId) as $component) {
      if ($this->belongsToForeignProject($component, $userId)) {
        continue;
      }
      $this->runStep('delete leftover component ' . $component->id(), function () use ($component): void {
        $this->componentActions->deleteComponent($component);
      });
      $this->forceDelete($component);
    }
    foreach (SodaScsStack::loadByOwner($userId) as $stack) {
      if ($this->belongsToForeignProject($stack, $userId)) {
        continue;
      }
      $this->runStep('delete leftover stack ' . $stack->id(), function () use ($stack): void {
        $this->stackActions->deleteStack($stack);
      });
      $this->forceDelete($stack);
    }
    foreach (SodaScsSnapshot::loadByOwner($userId) as $snapshot) {
      $this->runStep('delete snapshot ' . $snapshot->id(), static function () use ($snapshot): void {
        $snapshot->delete();
      });
    }
    foreach (SodaScsServiceKey::loadByOwner($userId) as $serviceKey) {
      $this->runStep('delete service key ' . $serviceKey->id(), static function () use ($serviceKey): void {
        $serviceKey->delete();
      });
    }
  }

  /**
   * Whether an application belongs to a project this user does not own.
   */
  private function belongsToForeignProject(FieldableEntityInterface $entity, int $userId): bool {
    if (!$entity->hasField('partOfProjects') || $entity->get('partOfProjects')->isEmpty()) {
      return FALSE;
    }
    $project = $entity->get('partOfProjects')->entity;
    return $project instanceof SodaScsProjectInterface
      && (int) $project->getOwnerId() !== $userId;
  }

  /**
   * Runs one cleanup step and keeps going when it fails.
   */
  private function runStep(string $step, callable $callback): void {
    try {
      $callback();
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Account cleanup step "@step" failed: @message', [
        '@step' => $step,
        '@message' => $throwable->getMessage(),
      ]);
    }
  }

}
