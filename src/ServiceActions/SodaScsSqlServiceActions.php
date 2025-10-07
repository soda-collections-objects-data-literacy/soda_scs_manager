<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ServiceActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Exception\SodaScsSqlServiceException;
use Drupal\soda_scs_manager\Traits\SecureLoggingTrait;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

/**
 * Handles the communication with the SCS user manager daemon.
 *
 * @todo Sanitise mysql commands, no create, drop etc.
 */
class SodaScsSqlServiceActions implements SodaScsServiceActionsInterface {

  use StringTranslationTrait;
  use SecureLoggingTrait;

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

  /**
   * The Soda SCS service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    TranslationInterface $stringTranslation,
  ) {
    $this->settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
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
    try {
      // Initialize settings.
      $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();

      $dbHost = str_replace('https://', '', $databaseSettings['host']);
      $dbRootPassword = $databaseSettings['rootPassword'];

      $dbName = $component->get('machineName')->value;

      // Create the database.
      $createDbCommand = sprintf(
        "mysql -h %s -uroot -p%s -e 'CREATE DATABASE `%s`;' 2>&1",
        escapeshellarg($dbHost),
        escapeshellarg($dbRootPassword),
        escapeshellarg($dbName)
      );
      $dbCreated = exec($createDbCommand, $createDbOutput, $createDbReturnVar);

      // Command failed.
      if ($createDbReturnVar != 0) {
        // Log the error with sanitized command.
        $this->secureLog(
          LogLevel::ERROR,
          'Failed to create database @dbName. Command: @command',
          [
            '@dbName' => $dbName,
            '@command' => $createDbCommand,
          ],
          ['@command']
        );

        throw new SodaScsSqlServiceException(
          "Cannot create database $dbName.",
          $this->sanitizeCommandForLogging($createDbCommand),
          'create database',
          $dbName,
          $createDbOutput
        );
      }

      // Log successful database creation.
      $this->secureLog(
        LogLevel::INFO,
        'Successfully created database @dbName',
        ['@dbName' => $dbName]
      );

      return [
        'command' => $this->sanitizeCommandForLogging($createDbCommand),
        'execStatus' => $createDbReturnVar,
        'output' => $createDbOutput,
        'result' => $dbCreated,
      ];
    }
    catch (SodaScsSqlServiceException $e) {
      $this->secureLog(
        LogLevel::ERROR,
        'Database service exception: @message',
        ['@message' => $e->getMessage()]
      );
      return [
        'message' => $e->getMessage(),
        'data' => [],
        'error' => $e->getOutput(),
        'success' => FALSE,
      ];
    }
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
    // Initialize settings.
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();

    $dbHost = str_replace('https://', '', $databaseSettings['host']);
    $dbRootPassword = $databaseSettings['rootPassword'];

    // Check if the database exists.
    $checkDbCommand = sprintf(
      "mysql -h %s -uroot -p%s -e 'SHOW DATABASES LIKE \"%s\";' 2>&1",
      escapeshellarg($dbHost),
      escapeshellarg($dbRootPassword),
      escapeshellarg($name)
    );
    exec($checkDbCommand, $databaseExists, $checkDbReturnVar);

    // Check if the output contains the database name.
    $dbExists = !empty($databaseExists) && in_array($name, $databaseExists);

    // Log the database check operation.
    $this->secureLog(
      LogLevel::DEBUG,
      'Checked if database @dbName exists. Result: @result',
      [
        '@dbName' => $name,
        '@result' => $dbExists ? 'exists' : 'does not exist',
      ]
    );

    return [
      'command' => $this->sanitizeCommandForLogging($checkDbCommand),
      'execStatus' => $checkDbReturnVar,
      'output' => $databaseExists,
      'result' => $dbExists,
    ];
  }

  /**
   * Renews a database user password.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $serviceKey
   *   The SODa SCS service key.
   *
   * @return array
   *   Success result.
   */
  public function renewUserPassword(SodaScsServiceKeyInterface $serviceKey): array {
    // Initialize settings.
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();

    $dbHost = str_replace('https://', '', $databaseSettings['host']);
    $dbRootPassword = $databaseSettings['rootPassword'];

    $dbUser = $serviceKey->get('owner')->target_id;
    /** @var \Drupal\user\Entity\User $dbUser */
    $dbUser = $this->entityTypeManager->getStorage('user')->load($dbUser);
    $dbUsername = $dbUser->getDisplayName();

    $dbUserPassword = $serviceKey->get('servicePassword')->value;

    // Check if the database exists.
    $alterUserCommand = sprintf(
      "mysql -h %s -uroot -p%s -e 'ALTER USER \"%s\"@\"%%\" IDENTIFIED BY \"%s\"; FLUSH PRIVILEGES;' 2>&1",
      escapeshellarg($dbHost),
      escapeshellarg($dbRootPassword),
      escapeshellarg($dbUsername),
      escapeshellarg($dbUserPassword)
    );
    $alterUserResult = exec($alterUserCommand, $alterUserOutput, $alterUserReturnVar);

    return [
      'command' => $alterUserCommand,
      'execStatus' => $alterUserReturnVar,
      'output' => $alterUserOutput,
      'result' => $alterUserResult,
    ];

  }

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
    // Initialize settings.
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();

    $dbName = $component->get('machineName')->value;

    // If we delete the user the component are still there.
    // @todo Ask if we delete everything, if the user is deleted.
    $dbUsername = $component->getOwner() !== NULL && $component->getOwner()->getDisplayName() !== NULL ? $component->getOwner()->getDisplayName() : 'deleted user';

    try {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $serviceKey */
      $serviceKey = $this->entityTypeManager->getStorage('soda_scs_service_key')->load($component->get('serviceKey')->target_id);
      if (!$serviceKey) {
        throw new \Exception("Service key not found for component @component" . $component->get('machineName')->value);
      }
      $dbUserPassword = $serviceKey->get('servicePassword')->value;
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Error loading service key: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t('Error loading service key: @error', ['@error' => $e->getMessage()]));
      return [
        'message' => $this->t('Error loading service key: @error', ['@error' => $e->getMessage()]),
        'data' => [],
        'error' => $e->getMessage(),
        'success' => FALSE,
      ];
    }
    // Database host.
    $dbHost = str_replace('https://', '', $databaseSettings['host']);
    if (empty($dbHost)) {
      throw new MissingDataException('Database Host setting missing');
    }

    // Check if the database exists.
    $checkDbExistsResult = $this->existService($dbName);

    // Command failed.
    if ($checkDbExistsResult['execStatus'] != 0) {
      throw new SodaScsSqlServiceException(
        "Cannot check if database $dbName exists.",
        $checkDbExistsResult['command'],
        'check if database',
        $dbName,
        $checkDbExistsResult['output']
      );
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
    $deleteDbCommand = sprintf(
      "mysql -h%s -u%s -p%s -e 'DROP DATABASE `%s`;' 2>&1",
      escapeshellarg($dbHost),
      escapeshellarg($dbUsername),
      escapeshellarg($dbUserPassword),
      escapeshellarg($dbName)
    );
    exec($deleteDbCommand, $deleteDbOutput, $deleteDbReturnVar);
    if (is_array($deleteDbOutput)) {
      $deleteDbOutput = implode(", ", $deleteDbOutput);
    }
    // Command failed.
    if ($deleteDbReturnVar != 0) {
      throw new SodaScsSqlServiceException(
        "Cannot delete database $dbName.",
        $deleteDbCommand,
        'delete database',
        $dbName,
        $deleteDbOutput
      );
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
    // Initialize settings.
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();

    // Replace https:// with empty string.
    $dbHost = str_replace('https://', '', $databaseSettings['host']);
    $dbRootPassword = $databaseSettings['rootPassword'];

    $createDbUserCommand = sprintf(
      "mysql -h %s -uroot -p%s -e 'CREATE USER \"%s\"@\"%%\" IDENTIFIED BY \"%s\"; FLUSH PRIVILEGES;' 2>&1",
      escapeshellarg($dbHost),
      escapeshellarg($dbRootPassword),
      escapeshellarg($dbUser),
      escapeshellarg($dbUserPassword)
    );
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
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Request failed with exception: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
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
    // Initialize settings.
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();

    // Replace https:// with empty string.
    $dbHost = str_replace('https://', '', $databaseSettings['host']);
    $dbRootPassword = $databaseSettings['rootPassword'];

    // Check if the user exists.
    $checkUserCommand = sprintf(
      "mysql -h %s -uroot -p%s -e 'SELECT EXISTS(SELECT 1 FROM mysql.user WHERE user = \"%s\");' 2>&1",
      escapeshellarg($dbHost),
      escapeshellarg($dbRootPassword),
      escapeshellarg($dbUser)
    );
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
    // Initialize settings.
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();

    // Replace https:// with empty string.
    $dbHost = str_replace('https://', '', $databaseSettings['host']);
    $dbRootPassword = $databaseSettings['rootPassword'];
    $rightsString = implode(', ', $rights);

    $grantRightsCommand = sprintf(
      "mysql -h %s -uroot -p%s -e 'GRANT %s ON `%s`.* TO \"%s\"@\"%%\"; FLUSH PRIVILEGES;' 2>&1",
      escapeshellarg($dbHost),
      escapeshellarg($dbRootPassword),
      $rightsString,
      escapeshellarg($name),
      escapeshellarg($dbUser)
    );
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
    // Initialize settings.
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();

    // Replace https:// with empty string.
    $dbHost = str_replace('https://', '', $databaseSettings['host']);
    $dbRootPassword = $databaseSettings['rootPassword'];
    $flushPrivilegesCommand = sprintf(
      "mysql -h %s -uroot -p%s -e 'FLUSH PRIVILEGES;' 2>&1",
      escapeshellarg($dbHost),
      escapeshellarg($dbRootPassword)
    );
    $flushPrivilegesCommandResult = exec($flushPrivilegesCommand, $grantRightsCommandOutput, $grantRightsCommandReturnVar);

    // Log the flush privileges operation.
    if ($grantRightsCommandReturnVar != 0) {
      $this->secureLog(
        LogLevel::ERROR,
        'Failed to flush database privileges. Command: @command',
        ['@command' => $flushPrivilegesCommand],
        ['@command']
      );
    }
    else {
      $this->secureLog(
        LogLevel::DEBUG,
        'Successfully flushed database privileges'
      );
    }

    return [
      'command' => $this->sanitizeCommandForLogging($flushPrivilegesCommand),
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
   * @param string|null $dbUserPassword
   *   The password of the database user.
   *
   * @return array
   *   Result information with command, return
   *   status (>0 = failed or 0 = success), output (array)
   *   and result (last line of output).
   */
  public function cleanServiceUsers(string $dbUser, string|null $dbUserPassword = NULL): array {
    if ($dbUser == 'root') {
      return [
        'message' => $this->t('Can not delete the root user'),
        'data' => [],
        'error' => '',
        'success' => FALSE,
      ];
    }

    $databaseUserExists = $this->existServiceUser($dbUser);
    if ($databaseUserExists['result'] == 0) {
      $this->messenger->addWarning($this->t('User does not exist'));

      return [
        'message' => $this->t('User does not exist'),
        'data' => [],
        'error' => '',
        'success' => FALSE,
      ];
    }

    $databaseUserDeleted = $this->deleteServiceUser($dbUser);

    if ($databaseUserDeleted['execStatus'] != 0) {
      throw new SodaScsSqlServiceException(
        "Cannot delete database user.",
        $this->sanitizeCommandForLogging($databaseUserDeleted['command']),
        'delete database user',
        $dbUser,
        $databaseUserDeleted['output']
      );
    }

    return [
      'message' => $this->t('User owned no databases, and was deleted'),
      'data' => [],
      'error' => '',
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
    // Initialize settings.
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();

    // Replace https:// with empty string.
    $dbHost = str_replace('https://', '', $databaseSettings['host']);

    // Check if the user owns any databases.
    $userOwnsAnyDatabasesCommand = sprintf(
      "mysql -h%s -u%s -p%s -e 'SELECT COUNT(*) FROM information_schema.SCHEMATA;' 2>&1",
      escapeshellarg($dbHost),
      escapeshellarg($dbUser),
      escapeshellarg($userPassword)
    );
    $userOwnsAnyDatabasesCommandResult = exec($userOwnsAnyDatabasesCommand, $userOwnsAnyDatabasesOutput, $userOwnsAnyDatabasesReturnVar);

    // Log the database ownership check.
    $this->secureLog(
      LogLevel::DEBUG,
      'Checked database ownership for user @dbUser',
      ['@dbUser' => $dbUser]
    );

    return [
      'command' => $this->sanitizeCommandForLogging($userOwnsAnyDatabasesCommand),
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
  public function userHasReadWriteAccessToDatabase(string $dbUser, string $dbName, string $dbUserPassword): bool {
    // Initialize settings.
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();

    // Replace https:// with empty string.
    $dbHost = str_replace('https://', '', $databaseSettings['host']);

    // Check if the user has read and write access to the database.
    $checkPrivilegesCommand = sprintf(
      "mysql -h %s -u%s -p%s -e 'SHOW GRANTS FOR \"%s\"@\"%%\";' 2>&1",
      escapeshellarg($dbHost),
      escapeshellarg($dbUser),
      escapeshellarg($dbUserPassword),
      escapeshellarg($dbUser)
    );
    exec($checkPrivilegesCommand, $checkPrivilegesCommandOutput, $checkPrivilegesCommandReturnVar);

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
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();
    // Replace https:// with empty string.
    $dbHost = str_replace('https://', '', $databaseSettings['host']);

    $dbRootPassword = $databaseSettings['rootPassword'];
    // User does not own any databases, delete the user.
    $deleteUserCommand = sprintf(
      "mysql -h %s -uroot -p%s -e 'DROP USER `%s`@`%%`; FLUSH PRIVILEGES;'",
      escapeshellarg($dbHost),
      escapeshellarg($dbRootPassword),
      escapeshellarg($dbUser)
    );
    $deleteUserCommandResult = exec($deleteUserCommand, $deleteUserOutput, $deleteUserReturnVar);
    return [
      'command' => $deleteUserCommand,
      'execStatus' => $deleteUserReturnVar,
      'output' => $deleteUserOutput,
      'result' => $deleteUserCommandResult,
    ];
  }

}
