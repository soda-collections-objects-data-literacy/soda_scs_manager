<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Messenger\MessengerInterface;

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

  public function __construct(ConfigFactoryInterface $configFactory, Connection $database, LoggerChannelFactoryInterface $loggerFactory, MessengerInterface $messenger, TranslationInterface $stringTranslation,) {
    $this->settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Creates a new service.
   *
   * @param string $name
   *   The name of the service.
   * @param string $description
   *   The description of the service.
   * @param int $status
   *   The status of the service. 0 = inactive, 1 = active, 2 = changing, 3 =
   *   error.
   *
   * @return bool
   *  Success result.
   */
  public function createService(string $name, string $description, int $status): bool {
    try {
      $this->database->insert('soda_scs_manager__services')
        ->fields([
          'name' => $name,
          'description' => $description,
          'status' => $status,
        ])
        ->execute();
      return TRUE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error('Failed to create service: ' . $e->getMessage());
      $this->messenger->addError($this->stringTranslation->translate('Failed to create service.'));
      return FALSE;
    }
  }

  /**
   * Fetches a service.
   *
   * @param int|string $id
   *   The component ID of the component or UUID of the service.
   *
   * @return array|bool
   *   The service data or false if the service was not found.
   */
  public function getService(string $by, int|string $id): array|bool {
    try {
      $query = $this->database->select('soda_scs_manager__services', 's')
        ->fields('s')
        ->condition($by, $id)
        ->execute();
      return $query->fetchAssoc();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error('Failed to fetch service: ' . $e->getMessage());
      $this->messenger->addError($this->stringTranslation->translate('Failed to fetch service.'));
      return FALSE;
    }
  }

  /**
   * Fetches all services.
   *
   * @param string $serviceUuid
   *  The UUID of the service.
   * @param string|NULL $description
   * The description of the service.
   * @param int|NULL $status
   * The status of the service. 0 = inactive, 1 = active, 2 = changing, 3 =
   *   error.
   *
   * @return bool
   *   Success result.
   */
  public function updateService(string $serviceUuid, string|null $description = NULL, int|null $status = NULL): bool {
    if ($description === NULL && $status === NULL) {
      return FALSE;
    }
    $fieldsArray = [];
    if ($description) {
      $fieldsArray['description'] = $description;
    }
    if ($status) {
      $fieldsArray['status'] = $status;
    }
    try {
      $this->database->update('soda_scs_manager__services')
        ->fields($fieldsArray)
        ->condition('id', $serviceUuid)
        ->execute();
      return TRUE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error('Failed to update service: ' . $e->getMessage());
      $this->messenger->addError($this->stringTranslation->translate('Failed to update service.'));
      return FALSE;
    }
  }

  /**
   * Deletes a service.
   *
   * @param string $serviceUuid
   *   The UUID of the service.
   *
   * @return bool
   *  Success result.
   */
  public function deleteService($serviceUuid): bool {
    try {
      $this->database->delete('soda_scs_manager__services')
        ->condition('id', $serviceUuid)
        ->execute();
      return TRUE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error('Failed to delete service: ' . $e->getMessage());
      $this->messenger->addError($this->stringTranslation->translate('Failed to delete service.'));
      return FALSE;
    }
  }

  /**
   * Creates a new database.
   *
   * @param string $dbName
   *   The name of the database.
   * @param string $dbUser
   *   The name of the database user.
   * @param string $dbUserPassword
   *   The password of the database user.
   *
   * @return array
   *  Success result.
   */
  public function createDb(string $dbName, string $dbUser, string $dbUserPassword): array {
    $dbHost = $this->settings->get('dbHost');
    $dbRootPassword = $this->settings->get('dbRootPassword');

    // Check if the user exists
    $checkUserCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'SELECT EXISTS(SELECT 1 FROM mysql.user WHERE user = \"$dbUser\");' 2>&1";
    $checkUserCommandResult = exec($checkUserCommand, $checkUserCommandOutput, $checkUserReturnVar);

    if ($checkUserReturnVar != 0) {
      // Command failed
      $this->loggerFactory->get('soda_scs_manager')
        ->error('Failed to execute MySQL command to check if user exists. Are the database credentials correct and the select permissions available?');
      $this->messenger->addError($this->stringTranslation->translate('Failed to execute MySQL command to check if the user exists. See logs for more information.'));
      return [
        'message' => t('Could not check if user %s exists', ['@dbUser' => $dbUser]),
        'error' => 'Failed to execute MySQL command to check if the user exists',
        'success' => FALSE,
      ];
    }

    if ($checkUserCommandResult === 1) {
      // User does not exist, create the user and grant privileges
      $createUserCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'CREATE USER \"$dbUser\"@\"%\" IDENTIFIED BY \"$dbUserPassword\"; GRANT ALL PRIVILEGES ON $dbName.* TO \"$dbUser\"@\"%\"; FLUSH PRIVILEGES;' 2>&1";
      $createUserCommandResult = exec($createUserCommand, $createUserCommandOutput, $createUserReturnVar);

      if ($createUserReturnVar != 0) {
        // Command failed
        $this->loggerFactory->get('soda_scs_manager')
          ->error('Failed to execute MySQL command to create user. Are the database credentials correct and the create permissions available?');
        $this->messenger->addError($this->stringTranslation->translate('Failed to execute MySQL command to create the user. See logs for more information.'));
        return [
          'message' => t('Could not create user %s', ['@dbUser' => $dbUser]),
          'error' => 'Failed to execute MySQL command to create the user',
          'success' => FALSE,
        ];
      }
    }

    // Create the database
    $createDbCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'CREATE DATABASE $dbName;'";
    shell_exec($createDbCommand);

    // Check if the database was created
    $checkDbCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'SHOW DATABASES LIKE \"$dbName\";'";
    $databaseCreated = exec($checkDbCommand, $databaseExists, $checkDbReturnVar);

    if ($checkDbReturnVar != 0) {
      // Command failed
      $this->loggerFactory->get('soda_scs_manager')
        ->error('Failed to execute MySQL command to check if database was created. Are the database credentials correct and the select permissions available?');
      $this->messenger->addError($this->stringTranslation->translate('Failed to execute MySQL command to check if the database was created. See logs for more information.'));
      return [
        'message' => t('Could not check if database %s was created', ['@dbName' => $dbName]),
        'error' => 'Failed to execute MySQL command to check if the database was created',
        'success' => FALSE,
      ];
    }
    if ($databaseCreated === $dbName) {
      // Command succeeded
      return [
        'message' => t('Database %s for %s created successfully', [
          '@dbName' => $dbName,
          '@dbUser' => $dbUser,
        ]),
        'error' => NULL,
        'success' => TRUE,
      ];
    }
    else {
      // Command failed
      $this->loggerFactory->get('soda_scs_manager')
        ->error('Database was not created. Are the database credentials correct and the create permissions available?');
      $this->messenger->addError($this->stringTranslation->translate('Failed to create the database. See logs for more information.'));
      return [
        'message' => t('Database %s for %s was not created', [
          '@dbName' => $dbName,
          '@dbUser' => $dbUser,
        ]),
        'error' => 'Database was not created.',
        'success' => FALSE,
      ];
    }
  }

  public function readDb() {}

  public function updateDb() {}

  /**
   * Deletes a database.
   *
   * @param string $dbName
   *   The name of the database.
   * @param string $dbUser
   *   The name of the database user.
   *
   * @return array
   *  Success result.
   */
  public function deleteDb(string $dbName, string $dbUser): array {
    $dbHost = $this->settings->get('db_host');
    $dbRootPassword = $this->settings->get('db_root_password');
    // Delete the database
    $shellResult = shell_exec("mysql -h $dbHost -uroot -p$dbRootPassword -e 'DROP DATABASE $dbName; FLUSH PRIVILEGES;'");

    // Check if the database was created
    $checkDbCommand = "mysql -h $dbHost -uroot -p$dbRootPassword -e 'SHOW DATABASES LIKE \"$dbName\";' 2>&1";
    $checkDBResult = exec($checkDbCommand, $databaseExists, $checkDbReturnVar);


    if ($checkDbReturnVar != 0) {
      // Command failed
      $this->loggerFactory->get('soda_scs_manager')
        ->error('Failed to execute MySQL command to delete database. Are the database credentials correct and the delete permissions set?');
      $this->messenger->addError($this->stringTranslation->translate('Failed to execute MySQL command to delete the database. See logs for more information.'));
      return [
        'message' => t('Could not delete database %s for %s', [
          '@dbName' => $dbName,
          '@dbUser' => $dbUser,
        ]),
        'error' => 'Failed to execute MySQL command to delete the database',
        'success' => FALSE,
      ];
    }
    else {
      // Check if the user owns any databases
      $cleanUserResult = $this->cleanDbUser($dbUser);
      // Command succeeded
      return [
        'message' => t('Database %s for %s deleted successfully. %s', [
          '@dbName' => $dbName,
          '@dbUser' => $dbUser,
          '@cleanUserResult' => $cleanUserResult['message'],
        ]),
        'error' => NULL,
        'success' => TRUE,
      ];
    }
  }

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
      $this->messenger->addError($this->stringTranslation->translate('Failed to execute MySQL command to check if the user owns any databases. See logs for more information.'));
      return [
        'message' => t('Could not check if user %s owns any databases', ['@dbUser' => $dbUser]),
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
        $this->messenger->addError($this->stringTranslation->translate('Failed to execute MySQL command to delete the user. See logs for more information.'));
        return [
          'message' => t('Could not delete user %s. See logs for more.', ['@dbUser' => $dbUser]),
          'error' => 'Failed to execute MySQL command to delete the user',
          'success' => FALSE,
        ];
      }
      else {
        // Command succeeded
        return [
          'message' => t('User %s has no databases left and was deleted.', ['@dbUser' => $dbUser]),
          'error' => NULL,
          'success' => TRUE,
        ];
      }
    }
    else {
      // User owns databases, do not delete
      return [
        'message' => t('User %s owns databases and will not be deleted', ['@dbUser' => $dbUser]),
        'error' => NULL,
        'success' => TRUE,
      ];
    }
  }

}
