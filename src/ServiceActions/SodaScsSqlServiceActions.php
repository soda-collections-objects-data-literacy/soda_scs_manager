<?php

namespace Drupal\soda_scs_manager\ServiceActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 *
 * @todo Sanitise mysql commands, no create, drop etc.
 */
class SodaScsSqlServiceActions implements SodaScsServiceActionsInterface {

  use StringTranslationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
  ) {
    $this->settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Creates a new database.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component.
   *
   * @return array
   *   Success result.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function createService(SodaScsComponentInterface $component): array {

    // Username.
    $dbUserName = $component->getOwner()->getDisplayName();
    if (!$dbUserName) {
      throw new MissingDataException('User name not found');
    }
    // Database host.
    $dbHost = $this->settings->get('dbHost');
    if (empty($dbHost)) {
      throw new MissingDataException('Database Host setting missing');
    }

    // Database root password.
    $dbRootPassword = $this->settings->get('dbRootPassword');
    if (empty($dbRootPassword)) {
      throw new MissingDataException('Database root password setting missing');
    }

    $dbName = $component->get('machineName')->value;

    // Create the database.
    $createDbCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'CREATE DATABASE `$dbName`;' 2>&1";
    $dbCreated = exec($createDbCommand, $createDbOutput, $createDbReturnVar);

    // Command failed.
    if ($createDbReturnVar != 0) {
      return $this->handleCommandFailure([
        'command' => $createDbCommand,
        'execStatus' => $createDbReturnVar,
        'output' => $createDbOutput,
        'result' => $dbCreated,
      ], 'create database', $dbName);
    }

    return [
      'command' => $createDbCommand,
      'execStatus' => $createDbReturnVar,
      'output' => $createDbOutput,
      'result' => $dbCreated,
    ];
  }

  /**
   * Checks if a database exists.
   *
   * @param string $name
   *   The name of the database.
   *
   * @return array
   *   Command, execution status (0 = success >0 = failure) and last line of
   */
  public function existService(string $name): array {
    $dbHost = $this->settings->get('dbHost');
    $dbRootPassword = $this->settings->get('dbRootPassword');

    // Check if the database exists.
    $checkDbCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'SHOW DATABASES LIKE \"$name\";' 2>&1";
    exec($checkDbCommand, $databaseExists, $checkDbReturnVar);

    // Check if the output contains the database name.
    $dbExists = !empty($databaseExists) && in_array($name, $databaseExists);

    return [
      'command' => $checkDbCommand,
      'execStatus' => $checkDbReturnVar,
      'output' => $databaseExists,
      'result' => $dbExists,
    ];
  }

  /**
   * Updates a database.
   */
  public function updateService() {}

  /**
   * Deletes a database.
   *
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component.
   *
   * @return array
   *   Success result.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function deleteService(SodaScsComponentInterface $component): array {
    $dbName = $component->get('machineName')->value;
    $dbUsername = $component->getOwner()->getDisplayName();

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $serviceKey */
    $serviceKey = $this->entityTypeManager->getStorage('soda_scs_service_key')->load($component->get('serviceKey')->target_id);
    $dbUserPassword = $serviceKey->get('servicePassword')->value;

    // Database host.
    $dbHost = $this->settings->get('dbHost');
    if (empty($dbHost)) {
      throw new MissingDataException('Database Host setting missing');
    }

    // Check if the database exists.
    $checkDbExistsResult = $this->existService($dbName);

    // Command failed.
    if ($checkDbExistsResult['execStatus'] != 0) {
      return $this->handleCommandFailure($checkDbExistsResult, 'check if database', $dbName);
    }

    if (!$checkDbExistsResult['result']) {
      // Database already deleted.
      $this->messenger->addError($this->t('Database could not be found. See logs for details.'));
      return [
        'message' => $this->t("Database @name database could not be found.", ['@name' => $dbName]),
        'data' => [],
        'error' => NULL,
        'success' => TRUE,
      ];
    }

    // Delete the database.
    $deleteDbCommand = "mysql -h$dbHost -u$dbUsername -p$dbUserPassword -e 'DROP DATABASE `$dbName`;' 2>&1";
    $dbDeleted = exec($deleteDbCommand, $deleteDbOutput, $deleteDbReturnVar);
    if (is_array($deleteDbOutput)) {
      $deleteDbOutput = implode(", ", $deleteDbOutput);
    }
    // Command failed.
    if ($deleteDbReturnVar != 0) {
      return $this->handleCommandFailure([
        'command' => $deleteDbCommand,
        'execStatus' => $deleteDbReturnVar,
        'output' => $deleteDbOutput,
        'result' => $dbDeleted,
      ], 'delete database', $dbName);
    }
    $this->flushPrivileges();

    return [
      'message' => $this->t('Delete database @name.', ['@name' => $dbName]),
      'data' => [],
      'error' => NULL,
      'success' => TRUE,
    ];
  }

  /**
   * Creates a new database user.
   *
   * @param string $dbUser
   *   The name of the database user.
   * @param string $dbUserPassword
   *   The password of the database user.
   *
   * @return array
   *   Command, execution status (0 = success >0 = failure) and last line of
   *   output as result.
   */
  public function createServiceUser(string $dbUser, string $dbUserPassword): array {
    $dbHost = $this->settings->get('dbHost');
    $dbRootPassword = $this->settings->get('dbRootPassword');

    $createDbUserCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'CREATE USER \"$dbUser\"@\"%\" IDENTIFIED BY \"$dbUserPassword\"; FLUSH PRIVILEGES;' 2>&1";
    $dbUserCreated = exec($createDbUserCommand, $createDbUserOutput, $createDbUserReturnVar);

    return [
      'command' => $createDbUserCommand,
      'execStatus' => $createDbUserReturnVar,
      'output' => $createDbUserOutput,
      'result' => $dbUserCreated,
    ];
  }

  /**
   * Retrieves a database user.
   */
  public function getServiceUser($uid = NULL): array {
    try {
      $driver = $this->database->driver();
      $query = $this->database->select('users_field_data', 'ufd');
      $query->fields('ufd', ['uid', 'name', 'mail']);
      $query->join('user__roles', 'ur', 'ufd.uid = ur.entity_id');
      $query->addField('ufd', 'status', 'enabled');
      if ($driver == 'mysql') {
        $query->addExpression('GROUP_CONCAT(ur.roles_target_id)', 'role');
      }
      elseif ($driver == 'pgsql') {
        $query->addExpression('STRING_AGG(ur.roles_target_id, \',\')', 'role');
      }
      $query->groupBy('ufd.uid');
      $query->groupBy('ufd.name');
      $query->groupBy('ufd.mail');
      $query->groupBy('ufd.status');
      $query->orderBy('ufd.name', 'ASC');

      if ($uid) {
        $query->condition('ufd.uid', $uid, '=');
      }

      $users = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      foreach ($users as $index => $user) {
        $users[$index]['role'] = explode(',', $user['role']);
      }

      return $users;
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('soda_scs_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->t('Can not communicate with the SCS user manager daemon.'));
      return [];
    }
  }

  /**
   * Checks if a database user exists.
   *
   * @param string $dbUser
   *   The name of the database user.
   *
   * @return array
   *   Command, execution status (0 = success >0 = failure) and last line of
   *   output as result.
   */
  public function existServiceUser(string $dbUser): array {
    $dbHost = $this->settings->get('dbHost');
    $dbRootPassword = $this->settings->get('dbRootPassword');

    // Check if the user exists.
    $checkUserCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'SELECT EXISTS(SELECT 1 FROM mysql.user WHERE user = \"$dbUser\");' 2>&1";
    $dbUserRead = exec($checkUserCommand, $checkUserCommandOutput, $checkUserReturnVar);
    return [
      'command' => $checkUserCommand,
      'execStatus' => $checkUserReturnVar,
      'output' => $checkUserCommandOutput,
      'result' => $dbUserRead,
    ];
  }

  /**
   * Gets the users from the Drupal database and Distillery.
   *
   * @param int $uid
   * The user ID to get.
   *
   * @return array
   * The users.
   *
   * @throws \Exception
   * If the request fails.
   */

  /**
   * Grants rights to a database user.
   *
   * @param string $dbUser
   *   The name of the database user.
   * @param string $name
   *   The name of the database.
   * @param array $rights
   *   The rights to grant.
   *
   * @return array
   *   Result information with command, return
   *   status (>0 = failed or 0 = success), output (array)
   *   and result (last line of output).
   */
  public function grantServiceRights(string $dbUser, string $name, array $rights): array {
    $dbHost = $this->settings->get('dbHost');
    $dbRootPassword = $this->settings->get('dbRootPassword');
    $rights = implode(', ', $rights);

    $grantRightsCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'GRANT $rights ON `$name`.* TO \"$dbUser\"@\"%\"; FLUSH PRIVILEGES;' 2>&1";
    $grantRightsCommandResult = exec($grantRightsCommand, $grantRightsCommandOutput, $grantRightsCommandReturnVar);
    return [
      'command' => $grantRightsCommand,
      'execStatus' => $grantRightsCommandReturnVar,
      'output' => $grantRightsCommandOutput,
      'result' => $grantRightsCommandResult,
    ];
  }

  /**
   * Flush the database privileges.
   *
   * @return array
   *   Result information with command, return
   *   status (>0 = failed or 0 = success), output (array)
   *   and result (last line of output).
   */
  public function flushPrivileges(): array {
    $dbHost = $this->settings->get('dbHost');
    $dbRootPassword = $this->settings->get('dbRootPassword');

    $flushPrivilegesCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'FLUSH PRIVILEGES;' 2>&1";
    $flushPrivilegesCommandResult = exec($flushPrivilegesCommand, $grantRightsCommandOutput, $grantRightsCommandReturnVar);
    return [
      'command' => $flushPrivilegesCommand,
      'execStatus' => $grantRightsCommandReturnVar,
      'output' => $grantRightsCommandOutput,
      'result' => $flushPrivilegesCommandResult,
    ];
  }

  /**
   * Checks if a database user owns any databases.
   *
   * If the user does not own any databases, the user is deleted.
   *
   * @param string $dbUser
   *   The name of the database user.
   * @param string $dbUserPassword
   *   The password of the database user.
   *
   * @return array
   *   Result information with command, return
   *   status (>0 = failed or 0 = success), output (array)
   *   and result (last line of output).
   */
  public function cleanServiceUsers(string $dbUser, string $dbUserPassword = NULL): array {
    if ($dbUser == 'root') {
      return [
        'message' => $this->t('Can not delete the root user'),
        'error' => NULL,
        'success' => FALSE,
      ];
    }

    // Check if the user owns any databases.
    $userOwnsAnyDatabasesResult = $this->userOwnsAnyDatabases($dbUser, $dbUserPassword);

    if ($userOwnsAnyDatabasesResult['execStatus'] != 0) {
      return $this->handleCommandFailure($userOwnsAnyDatabasesResult, 'check if user owns any databases', $dbUser);
    }

    if ($userOwnsAnyDatabasesResult['result'] > 1) {
      return [
        'message' => $this->t('User owns databases'),
        'error' => NULL,
        'success' => TRUE,
      ];
    }

    $databaseUserDeleted = $this->deleteServiceUser($dbUser);

    if ($databaseUserDeleted['execStatus'] != 0) {
      return $this->handleCommandFailure($databaseUserDeleted, 'delete database user', $dbUser);
    }

    return [
      'message' => $this->t('User owned no databases, and was deleted'),
      'error' => NULL,
      'success' => TRUE,
    ];
  }

  /**
   * Checks if a database user owns any databases.
   *
   * @param string $dbUser
   *   The name of the database user.
   * @param string $userPassword
   *   The password of the database user.
   *
   * @return array
   *   Result information with command, return
   *   status (>0 = failed or 0 = success), output (array)
   *   and result (last line of output).
   */
  public function userOwnsAnyDatabases(string $dbUser, string $userPassword) {
    $dbHost = $this->settings->get('dbHost');

    // Check if the user owns any databases.
    $userOwnsAnyDatabasesCommand = "mysql -h$dbHost -u$dbUser -p$userPassword -e 'SELECT COUNT(*) FROM information_schema.SCHEMATA;' 2>&1";
    $userOwnsAnyDatabasesCommandResult = exec($userOwnsAnyDatabasesCommand, $userOwnsAnyDatabasesOutput, $userOwnsAnyDatabasesReturnVar);
    return [
      'command' => $userOwnsAnyDatabasesCommand,
      'execStatus' => $userOwnsAnyDatabasesReturnVar,
      'output' => $userOwnsAnyDatabasesOutput,
      'result' => $userOwnsAnyDatabasesCommandResult,
    ];
  }

  
  /**
   * Checks if a user has read and write access to a database.
   *
   * @param string $dbUser
   *   The name of the database user.
   * @param string $dbName
   *   The name of the database.
   * @param string $dbUserPassword
   *   The password of the database user.
   *
   * @return bool
   *   TRUE if the user has read and write access to the database.
   */
  /**
   * Checks if a user has read and write access to a database.
   *
   * @param string $dbUser
   *   The name of the database user.
   * @param string $dbName
   *   The name of the database.
   * @param string $dbUserPassword
   *   The password of the database user.
   *
   * @return bool
   *   TRUE if the user has read and write access to the database.
   */
  public function userHasReadWriteAccessToDatabase(string $dbUser, string $dbName, string $dbUserPassword): bool {
    $dbHost = $this->settings->get('dbHost');

    $checkPrivilegesCommand = "mysql -h $dbHost -u$dbUser -p$dbUserPassword -e 'SHOW GRANTS FOR \"$dbUser\"@\"%\";' 2>&1";
    $checkPrivilegesCommandResult = exec($checkPrivilegesCommand, $checkPrivilegesCommandOutput, $checkPrivilegesCommandReturnVar);

    if ($checkPrivilegesCommandReturnVar != 0) {
      throw new \Exception("Failed to check privileges for user $dbUser on database $dbName");
    }

    $grants = [];
    foreach ($checkPrivilegesCommandOutput as $line) {
      if (strpos($line, $dbName) !== FALSE) {
        $grants[] = $line;
      }
    }

    $privileges = implode("\n", $grants);
    $hasAllPrivileges = strpos($privileges, "GRANT ALL") !== FALSE;
    $hasSelectPrivilege = strpos($privileges, "GRANT SELECT") !== FALSE;
    $hasInsertPrivilege = strpos($privileges, "GRANT INSERT") !== FALSE;
    $hasUpdatePrivilege = strpos($privileges, "GRANT UPDATE") !== FALSE;
    $hasDeletePrivilege = strpos($privileges, "GRANT DELETE") !== FALSE;

    return (($hasSelectPrivilege && ($hasInsertPrivilege && $hasUpdatePrivilege && $hasDeletePrivilege))) || $hasAllPrivileges;
  }


  /**
   * Checks handle shell command failure.
   *
   * @param array $commandResult
   *   The command result.
   * @param string $action
   *   The action.
   * @param string $entityName
   *   The entity name.
   *
   * @return array
   *   Result information with
   *   - string message,
   *   - array data
   *   - array error
   *   - boolean success.
   */
  public function handleCommandFailure(array $commandResult, string $action, string $entityName): array {
    if (is_array($commandResult['output'])) {
      $commandResult['output'] = implode(', ', $commandResult['output']);
    }
    $this->loggerFactory->get('soda_scs_manager')
      ->error($this->t("Failed to execute MySQL command @command to @action @entityName. Error: @error", [
        '@action' => $action,
        '@command' => $commandResult['command'],
        '@entityName' => $entityName,
        '@error' => $commandResult['output'],
      ]));
    $this->messenger->addError($this->t("Failed to execute MySQL command to @action. See logs for more details.", ['@action' => $action]));
    return [
      'message' => $this->t("Cannot @action @entityName. See log for details.", [
        '@action' => $action,
        '@entityName' => $entityName,
      ]),
      'data' => [],
      'error' => $commandResult['output'],
      'success' => FALSE,
    ];
  }

  /**
   * Delete database user.
   *
   * @param string $dbUser
   *   The name of the database user.
   *
   * @return array
   *   Result information with command, return
   *   status (>0 = failed or 0 = success), output (array)
   *   and result (last line of output).
   */
  public function deleteServiceUser($dbUser) {
    $dbHost = $this->settings->get('dbHost');
    $dbRootPassword = $this->settings->get('dbRootPassword');
    // User does not own any databases, delete the user.
    $deleteUserCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'DROP USER `$dbUser`@`%`; FLUSH PRIVILEGES;'";
    $deleteUserCommandResult = exec($deleteUserCommand, $deleteUserOutput, $deleteUserReturnVar);
    return [
      'command' => $deleteUserCommand,
      'execStatus' => $deleteUserReturnVar,
      'output' => $deleteUserOutput,
      'result' => $deleteUserCommandResult,
    ];
  }

}
