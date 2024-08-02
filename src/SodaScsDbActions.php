<?php

namespace Drupal\soda_scs_manager;

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

  public function __construct(Connection $database, LoggerChannelFactoryInterface $loggerFactory, MessengerInterface $messenger, TranslationInterface $stringTranslation,) {
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
    } catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error('Failed to create service: ' . $e->getMessage());
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
    } catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error('Failed to fetch service: ' . $e->getMessage());
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
  public function updateService(string $serviceUuid, string|NULL $description = NULL, int|NULL $status = NULL): bool {
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
    } catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error('Failed to update service: ' . $e->getMessage());
      $this->messenger->addError($this->stringTranslation->translate('Failed to update service.'));
      RETURN FALSE;
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
    } catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error('Failed to delete service: ' . $e->getMessage());
      $this->messenger->addError($this->stringTranslation->translate('Failed to delete service.'));
      return FALSE;
    }
  }

  public function createDb(string $dbName, string $dbUser, string $dbPassword): bool {
    try {
      $this->database->createDatabase($dbName);
      $this->database->query("CREATE USER :user@'%' IDENTIFIED BY :password", [
        ':user' => $dbUser,
        ':password' => $dbPassword,
      ]);

      // Grant privileges to the new user on the specified database
      $this->database->query("GRANT ALL PRIVILEGES ON :db.* TO :user@'%'", [
        ':db' => $dbName,
        ':user' => $dbUser,
      ]);

      // Flush privileges to ensure that the changes take effect
      $this->database->query("FLUSH PRIVILEGES");
      return TRUE;
    } catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error('Failed to create database: ' . $e->getMessage());
      $this->messenger->addError($this->stringTranslation->translate('Failed to create database.'));
      return FALSE;
    }
  }
}
