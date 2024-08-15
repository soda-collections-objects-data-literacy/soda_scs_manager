<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsDbActions {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

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
   * The string translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected TranslationInterface $stringTranslation;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  public function __construct(ConfigFactoryInterface $configFactory, Connection $database, LoggerChannelFactoryInterface $loggerFactory, MessengerInterface $messenger, TranslationInterface $stringTranslation) {
    $this->settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Creates a new database.
   *
   * @param string $dbName
   *   The name of the database.
   * @param int $dbUserId
   *   The id of the database user.
   *
   * @return array
   *  Success result.
   *
   * @throws MissingDataException
   */
  public function createDb(string $dbName, int $dbUserId, string $dbUserPassword): array {
    // All settings available?

    // Username.
    $dbUserName = $this->getUserNameById($dbUserId);
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

    // Check if the database exists.
    $checkDbExistsResult = $this->checkDbExists($dbName);

    // Command failed.
    if ($checkDbExistsResult['execStatus'] != 0) {
      return $this->handleCommandFailure($checkDbExistsResult, 'check if database', $dbName);
    }

    if ($checkDbExistsResult['result']) {
      // Database already exists
      $this->messenger->addError($this->stringTranslation->translate('Database already exists. See logs for more details.'));
      return [
        'message' => $this->stringTranslation->translate("Database \"@dbName\" already exists. Pick another name.", ['@dbName' => $dbName]),
        'data' => [],
        'error' => NULL,
        'success' => FALSE,
      ];
    }

    // Check if the user exists
    $checkDbUserExistsResult = $this->checkDbUserExists($dbUserName);

    // Command failed
    if ($checkDbUserExistsResult['execStatus'] != 0) {
      return $this->handleCommandFailure($checkDbUserExistsResult, 'check if user', $dbUserName);
    }

    if ($checkDbUserExistsResult['result'] == 0) {
      // Database user does not exist
      // Create the database user
      $createDbUserResult = $this->createDbUser($dbUserName, $dbUserPassword);

      // Command failed
      if ($createDbUserResult['execStatus'] != 0) {
        return $this->handleCommandFailure($createDbUserResult, 'create user', $dbUserName);
      }

    }

    // Grant rights to the database user
    $grantRights2DbResult = $this->grantRights2DbUser($dbUserName, $dbName, ['ALL']);

    // Command failed
    if ($grantRights2DbResult['execStatus'] != 0) {
      return $this->handleCommandFailure($grantRights2DbResult, 'grant rights to user', 'user', 'dbUser');
    }

    // Create the database
    $createDbCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'CREATE DATABASE $dbName;' 2>&1";
    $dbCreated = exec($createDbCommand, $createDbOutput, $createDbReturnVar);

    // Command failed
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
   * @param string $dbName
   *   The name of the database.
   *
   * @return array
   *  Command, execution status (0 = success >0 = failure) and last line of
   *
   */
  public function checkDbExists(string $dbName): array {
    $dbHost = $this->settings->get('dbHost');
    $dbRootPassword = $this->settings->get('dbRootPassword');

    // Check if the database exists
    $checkDbCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'SHOW DATABASES LIKE \"$dbName\";' 2>&1";
    exec($checkDbCommand, $databaseExists, $checkDbReturnVar);

    // Check if the output contains the database name
    $dbExists = !empty($databaseExists) && in_array($dbName, $databaseExists);

    return [
      'command' => $checkDbCommand,
      'execStatus' => $checkDbReturnVar,
      'output' => $databaseExists,
      'result' => $dbExists,
    ];
  }

  public function updateDb() {}

  /**
   * Deletes a database.
   *
   * @param string $dbName
   *   The name of the database.
   * @param string $dbUsername
   *   The name of the database user.
   *
   * @return array
   *  Success result.
   *
   * @throws MissingDataException
   */
  public function deleteDb(string $dbName, string $dbUsername, string $dbUserPassword): array {
    // Database host.
    $dbHost = $this->settings->get('dbHost');
    if (empty($dbHost)) {
      throw new MissingDataException('Database Host setting missing');
    }

    // Check if the database exists.
    $checkDbExistsResult = $this->checkDbExists($dbName);

    // Command failed.
    if ($checkDbExistsResult['execStatus'] != 0) {
      return $this->handleCommandFailure($checkDbExistsResult, 'check if database', $dbName);
    }

    if (!$checkDbExistsResult['result']) {
      // Database already deleted
      $this->messenger->addError($this->stringTranslation->translate('Database could not be found. See logs for details.'));
      return [
        'message' => $this->stringTranslation->translate("Database \"@dbName\" database could not be found.", ['@dbName' => $dbName]),
        'data' => [],
        'error' => NULL,
        'success' => TRUE,
      ];
    }

    // Get database credentials
    $conditions = [
      'service_host' => $dbHost,
      'service_name' => 'mariadb',
      'service_entity_type' => 'database',
      'service_entity_name' => $dbName,
      'service_username' => $dbUsername,
    ];

    // Delete the database
    $deleteDbCommand = "mysql -h$dbHost -u$dbUsername -p$dbUserPassword -e 'DROP DATABASE $dbName;' 2>&1";
    $dbDeleted = exec($deleteDbCommand, $deleteDbOutput, $deleteDbReturnVar);
    if (is_array($deleteDbOutput)) {
      $deleteDbOutput = implode(", ", $deleteDbOutput);
    }
    // Command failed
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
      'message' =>  $this->stringTranslation->translate('Delete database @dbName.', ['@dbName' => $dbName]),
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
   *  Command, execution status (0 = success >0 = failure) and last line of
   *   output as result.
   *
   */
  public function createDbUser(string $dbUser, string $dbUserPassword): array {
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

  public function getUsersFromDb($uid = NULL): array {
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
        ->addError($this->stringTranslation->translate('Can not communicate with the SCS user manager daemon. Try again later or contact cloud@wiss-ki.eu.'));
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
   *  Command, execution status (0 = success >0 = failure) and last line of
   *   output as result.
   */
  public function checkDbUserExists(string $dbUser): array {
    $dbHost = $this->settings->get('dbHost');
    $dbRootPassword = $this->settings->get('dbRootPassword');

    // Check if the user exists
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
   * @param string $dbName
   * @param array $rights
   *
   * @return array
   */
  public function grantRights2DbUser(string $dbUser, string $dbName, array $rights): array {
    $dbHost = $this->settings->get('dbHost');
    $dbRootPassword = $this->settings->get('dbRootPassword');
    $rights = implode(', ', $rights);

    $grantRightsCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'GRANT $rights PRIVILEGES ON $dbName.* TO \"$dbUser\"@\"%\"; FLUSH PRIVILEGES;' 2>&1";
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
   *
   * @return array
   */
  public function cleanDbUser(string $dbUser) {
    $dbHost = $this->settings->get('dbHost');
    $dbRootPassword = $this->settings->get('dbRootPassword');

    // Check if the user owns any databases
    $checkUserDatabasesCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN (SELECT DISTINCT table_schema FROM information_schema.tables WHERE table_schema NOT IN (\"information_schema\", \"mysql\", \"performance_schema\", \"sys\") AND table_schema = \"$dbUser\");' 2>&1";
    $userDatabases = exec($checkUserDatabasesCommand, $checkUserDatabasesOutput, $checkUserDatabasesReturnVar);

    if ($checkUserDatabasesReturnVar != 0) {
      // Command failed
      $this->loggerFactory->get('soda_scs_manager')
        ->error('Failed to execute MySQL command to check if user owns any databases. Are the database credentials correct and the select permissions set?');
      $this->messenger->addError($this->stringTranslation->translate('Failed to execute MySQL command to check if the user owns any databases. See logs for more details.'));
      return [
        'message' => $this->stringTranslation->translate('Could not check if user %s owns any databases', ['@dbUser' => $dbUser]),
        'error' => 'Failed to execute MySQL command to check if the user owns any databases',
        'success' => FALSE,
      ];
    }

    if ($checkUserDatabasesOutput == 0) {
      // User does not own any databases, delete the user
      $deleteUserCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'DROP USER \"$dbUser\"@\"%\"; FLUSH PRIVILEGES;'";
      $deleteUserResult = exec($deleteUserCommand, $deleteUserOutput, $deleteUserReturnVar);

      if ($deleteUserResult === NULL) {
        // Command failed
        $this->loggerFactory->get('soda_scs_manager')
          ->error('Failed to execute MySQL command to delete user. Are the database credentials correct and the delete permissions set?');
        $this->messenger->addError($this->stringTranslation->translate('Failed to execute MySQL command to delete the user. See logs for more details.'));
        return [
          'message' => $this->stringTranslation->translate('Could not delete user @dbUser. See logs for more.', ['@dbUser' => $dbUser]),
          'error' => 'Failed to execute MySQL command to delete the user',
          'success' => FALSE,
        ];
      }


      else {
        // Command succeeded
        return [
          'message' => $this->stringTranslation->translate('User @dbUser has no databases left and was deleted.', ['@dbUser' => $dbUser]),
          'error' => NULL,
          'success' => TRUE,
        ];
      }
    }
    else {
      // User owns databases, do not delete
      return [
        'message' => $this->stringTranslation->translate('User @dbUser owns databases and will not be deleted', ['@dbUser' => $dbUser]),
        'error' => NULL,
        'success' => TRUE,
      ];
    }
  }

  private function handleCommandFailure(array $commandResult, string $action, string $entityName): array {
    if (is_array($commandResult['output'])) {
      $commandResult['output'] = implode(', ', $commandResult['output']);
    }
    $this->loggerFactory->get('soda_scs_manager')
      ->error($this->stringTranslation->translate("Failed to execute MySQL command \"@command\" to @action \"@entityName\". Error: @error", [
        '@action' => $action,
        '@command' => $commandResult['command'],
        '@entityName' => $entityName,
        '@error' => $commandResult['output'],
      ]));
    $this->messenger->addError($this->stringTranslation->translate("Failed to execute MySQL command to @action. See logs for more details.", ['@action' => $action]));
    return [
      'message' => $this->stringTranslation->translate("Cannot @action \"@$entityName\". See log for details.", [
        '@action' => $action,
        '@entityName' => $entityName]),
      'data' => [],
      'error' => $commandResult['output'],
      'success' => FALSE,
    ];
  }

  public function getUserNameById(int $userId): ?string {
    try {
      $query = $this->database->select('users_field_data', 'ufd')
        ->fields('ufd', ['name'])
        ->condition('uid', $userId)
        ->execute();

      $result = $query->fetchField();

      return $result ?: NULL;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error('Failed to query user name: ' . $e->getMessage());
      return NULL;
    }
  }
}
