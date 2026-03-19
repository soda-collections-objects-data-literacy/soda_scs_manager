<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs MariaDB access for project members to databases connected to projects.
 *
 * When a user is added to a project, they get MariaDB user (if needed), GRANT
 * on all SQL databases connected to the project, and mariadb_password in
 * Keycloak so phpMyAdmin SSO works.
 */
final class SodaScsProjectDbAccessHelpers {

  /**
   * Constructs the helper.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    protected SodaScsProjectHelpers $projectHelpers,
    #[Autowire(service: 'soda_scs_manager.sql_service.actions')]
    protected \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface $sqlServiceActions,
    #[Autowire(service: 'soda_scs_manager.service_key.actions')]
    protected \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface $serviceKeyActions,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.helpers')]
    protected SodaScsKeycloakHelpers $keycloakHelpers,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Ensures all project members have MariaDB access to the project's SQL databases.
   *
   * Creates MariaDB user if needed, GRANTs on each SQL component's database,
   * and syncs mariadb_password to Keycloak for phpMyAdmin SSO.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project entity.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function syncProjectMembersDbAccess(SodaScsProjectInterface $project): bool {
    $sqlDatabases = $this->getSqlDatabasesFromProject($project);
    if (empty($sqlDatabases)) {
      return TRUE;
    }

    $members = $this->getProjectMembers($project);
    foreach ($members as $user) {
      if (!$this->ensureUserDbAccess($user, $sqlDatabases)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Revokes a user's MariaDB access to a project's SQL databases.
   *
   * Only revokes on databases where the user is not a member of any other
   * project that has that database.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to revoke.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project the user was removed from.
   *
   * @return bool
   *   TRUE on success.
   */
  public function revokeProjectMemberDbAccess(UserInterface $user, SodaScsProjectInterface $project): bool {
    $sqlDatabases = $this->getSqlDatabasesFromProject($project);
    if (empty($sqlDatabases)) {
      return TRUE;
    }

    $dbUser = $user->getDisplayName();
    foreach ($sqlDatabases as $dbName) {
      if ($this->userHasDbAccessViaOtherProject($user, $dbName, $project)) {
        continue;
      }
      $result = $this->sqlServiceActions->revokeServiceRights($dbUser, $dbName);
      if ($result['execStatus'] !== 0) {
        $this->logger->warning('Failed to revoke DB access for @user on @db: @output', [
          '@user' => $dbUser,
          '@db' => $dbName,
          '@output' => implode("\n", $result['output'] ?? []),
        ]);
      }
    }

    return TRUE;
  }

  /**
   * Gets SQL database names from a project's connected components.
   *
   * @return string[]
   *   Database names (machineName of soda_scs_sql_component).
   */
  protected function getSqlDatabasesFromProject(SodaScsProjectInterface $project): array {
    $databases = [];
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $connectedComponents */
    $connectedComponents = $project->get('connectedComponents');
    $components = $connectedComponents->referencedEntities();
    
    foreach ($components as $component) {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
      if ($component->bundle() === 'soda_scs_sql_component') {
        $databases[] = $component->get('machineName')->value;
      }
    }
    return array_unique($databases);
  }

  /**
   * Gets all project members (owner + members field).
   *
   * @return \Drupal\user\UserInterface[]
   *   User entities.
   */
  protected function getProjectMembers(SodaScsProjectInterface $project): array {
    $members = [];
    /** @var \Drupal\user\UserInterface|null $owner */
    $owner = $project->get('owner')->entity;
    if ($owner) {
      $members[$owner->id()] = $owner;
    }
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $membersField */
    $membersField = $project->get('members');
    foreach ($membersField->referencedEntities() as $member) {
      $members[$member->id()] = $member;
    }
    return array_values($members);
  }

  /**
   * Ensures a user has MariaDB access to the given databases.
   *
   * Creates user if needed, GRANTs on each DB, syncs mariadb_password to Keycloak.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param string[] $dbNames
   *   Database names.
   *
   * @return bool
   *   TRUE on success.
   */
  protected function ensureUserDbAccess(UserInterface $user, array $dbNames): bool {
    $dbUser = $user->getDisplayName();
    if (!$dbUser) {
      return TRUE;
    }

    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_component')['soda_scs_sql_component'] ?? NULL;
    if (!$bundleInfo) {
      return TRUE;
    }

    $keyProps = [
      'bundle' => 'soda_scs_sql_component',
      'bundleLabel' => $bundleInfo['label'] ?? 'SQL',
      'type' => 'password',
      'userId' => $user->id(),
      'username' => $dbUser,
    ];
    $serviceKey = $this->serviceKeyActions->getServiceKey($keyProps)
      ?? $this->serviceKeyActions->createServiceKey($keyProps);
    $userPassword = $serviceKey->get('servicePassword')->value;

    $checkUser = $this->sqlServiceActions->existServiceUser($dbUser);
    if ($checkUser['execStatus'] !== 0) {
      $this->logger->error('Failed to check MariaDB user @user: @output', [
        '@user' => $dbUser,
        '@output' => implode("\n", $checkUser['output'] ?? []),
      ]);
      return FALSE;
    }
    $userExists = !empty($checkUser['result']) && $checkUser['result'] !== '0' && $checkUser['result'] !== 0;
    if (!$userExists) {
      $createResult = $this->sqlServiceActions->createServiceUser($dbUser, $userPassword);
      if ($createResult['execStatus'] !== 0) {
        $this->logger->error('Failed to create MariaDB user @user: @output', [
          '@user' => $dbUser,
          '@output' => implode("\n", $createResult['output'] ?? []),
        ]);
        return FALSE;
      }
    }
    else {
      // User exists: ensure MariaDB password matches service key (and Keycloak).
      $renewResult = $this->sqlServiceActions->renewUserPassword($serviceKey);
      if ($renewResult['execStatus'] !== 0) {
        $this->logger->warning('Failed to sync MariaDB password for @user: @output', [
          '@user' => $dbUser,
          '@output' => implode("\n", $renewResult['output'] ?? []),
        ]);
      }
    }

    foreach ($dbNames as $dbName) {
      $grantResult = $this->sqlServiceActions->grantServiceRights($dbUser, $dbName, ['ALL PRIVILEGES']);
      if ($grantResult['execStatus'] !== 0) {
        $this->logger->warning('Failed to GRANT for @user on @db: @output', [
          '@user' => $dbUser,
          '@db' => $dbName,
          '@output' => implode("\n", $grantResult['output'] ?? []),
        ]);
      }
    }

    $keycloakUserId = $this->projectHelpers->getUserSsoUuid($user);
    if ($keycloakUserId) {
      $this->keycloakHelpers->setKeycloakUserAttributes(
        $keycloakUserId,
        ['mariadb_password' => [$userPassword]]
      );
    }

    return TRUE;
  }

  /**
   * Checks if a user has access to a database via another project.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param string $dbName
   *   Database name (component machineName).
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $excludeProject
   *   Project to exclude (the one the user was removed from).
   *
   * @return bool
   *   TRUE if the user is a member of another project that has this database.
   */
  protected function userHasDbAccessViaOtherProject(
    UserInterface $user,
    string $dbName,
    SodaScsProjectInterface $excludeProject
  ): bool {
    $storage = $this->entityTypeManager->getStorage('soda_scs_component');
    $componentIds = $storage->getQuery()
      ->condition('bundle', 'soda_scs_sql_component')
      ->condition('machineName', $dbName)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($componentIds)) {
      return FALSE;
    }

    $projectStorage = $this->entityTypeManager->getStorage('soda_scs_project');
    $projectIds = $projectStorage->getQuery()
      ->condition('connectedComponents', $componentIds, 'IN')
      ->condition('id', $excludeProject->id(), '<>')
      ->accessCheck(FALSE)
      ->execute();

    foreach ($projectIds as $pid) {
      $project = $projectStorage->load($pid);
      if (!$project instanceof SodaScsProjectInterface) {
        continue;
      }
      if ($project->getOwnerId() === (int) $user->id()) {
        return TRUE;
      }
      foreach ($project->get('members')->getValue() as $ref) {
        if ((int) ($ref['target_id'] ?? 0) === (int) $user->id()) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}
