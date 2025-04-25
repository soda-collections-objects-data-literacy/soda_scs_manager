<?php

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Exception\SodaScsSqlServiceException;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LogLevel;
use Drupal\Core\Utility\Error;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsSqlComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface
   */
  protected SodaScsServiceActionsInterface $sodaScsMysqlServiceActions;

  /**
   * The SCS Service Key actions service.
   *
   * @var \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsServiceActionsInterface $sodaScsMysqlServiceActions,
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->logger = $loggerFactory->get('soda_scs_manager');
    $this->messenger = $messenger;
    $this->settings = $settings;
    $this->sodaScsMysqlServiceActions = $sodaScsMysqlServiceActions;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create SQL.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The entity to create.
   *
   * @return array
   *   The result array with the created component.
   */
  public function createComponent(SodaScsStackInterface|SodaScsComponentInterface $entity): array {
    try {
      $sqlComponentBundleInfo = \Drupal::service('entity_type.bundle.info')->getBundleInfo('soda_scs_component')['soda_scs_sql_component'];

      if (!$sqlComponentBundleInfo) {
        throw new \Exception('SQL component bundle info not found');
      }

      $machineName = 'sql-' . $entity->get('machineName')->value;
      $sqlComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create(
        [
          'bundle' => 'soda_scs_sql_component',
          'label' => $entity->get('label')->value . ' (SQL Database)',
          'machineName' => $machineName,
          'owner'  => $entity->getOwnerId(),
          'description' => $sqlComponentBundleInfo['description'],
          'imageUrl' => $sqlComponentBundleInfo['imageUrl'],
          'health' => 'Unknown',
          'partOfProjects' => $entity->get('partOfProjects'),
        ]
      );

      $keyProps = [
        'bundle'  => $sqlComponent->bundle(),
        'bundleLabel' => $sqlComponentBundleInfo['label'],
        'type'  => 'password',
        'userId'  => $entity->getOwnerId(),
        'username' => $entity->getOwner()->getDisplayName(),
      ];

      // Create service key if it does not exist.
      $sqlServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($keyProps) ?? $this->sodaScsServiceKeyActions->createServiceKey($keyProps);

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $sqlComponent */
      $sqlComponent->set('serviceKey', $sqlServiceKeyEntity);

      // All settings available?
      // Username.
      $dbUserName = $entity->getOwner()->getDisplayName();
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

      $dbName = $machineName;
      // Check if the database exists.
      $checkDbExistsResult = $this->sodaScsMysqlServiceActions->existService($dbName);

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

      if ($checkDbExistsResult['result']) {
        // Database already exists.
        $this->messenger->addError($this->t('Database already exists. See logs for more details.'));
        return [];
      }

      // Check if the user exists.
      $checkDbUserExistsResult = $this->sodaScsMysqlServiceActions->existServiceUser($dbUserName);

      // Command failed.
      if ($checkDbUserExistsResult['execStatus'] != 0) {
        throw new SodaScsSqlServiceException(
          "Cannot check if user $dbName exists.",
          $checkDbUserExistsResult['command'],
          'check if user',
          $dbUserName,
          $checkDbUserExistsResult['output']
        );
      }

      if ($checkDbUserExistsResult['result'] == 0) {
        // Database user does not exist
        // Create the database user.
        /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $sqlServiceKeyEntity */
        $userPassword = $sqlServiceKeyEntity->get('servicePassword')->value;
        $createDbUserResult = $this->sodaScsMysqlServiceActions->createServiceUser($dbUserName, $userPassword);

        // Command failed.
        if ($createDbUserResult['execStatus'] != 0) {
          throw new SodaScsSqlServiceException(
            "Cannot create user $dbName.",
            $createDbUserResult['command'],
            'create user',
            $dbUserName,
            $createDbUserResult['output']
          );
        }
      }

      // Grant rights to the database user.
      $grantRights2DbResult = $this->sodaScsMysqlServiceActions->grantServiceRights($dbUserName, $dbName, ['ALL PRIVILEGES']);

      // Command failed.
      if ($grantRights2DbResult['execStatus'] != 0) {
        throw new SodaScsSqlServiceException(
          "Cannot grant rights to user $dbName.",
          $grantRights2DbResult['command'],
          'grant rights to user',
          $dbUserName,
          $grantRights2DbResult['output']
        );
      }

      // Create Drupal database.
      $createDatabaseServiceResult = $this->sodaScsMysqlServiceActions->createService($sqlComponent);

      if ($createDatabaseServiceResult['execStatus'] != 0) {
        throw new SodaScsSqlServiceException(
          "Cannot create database $dbName.",
          $createDatabaseServiceResult['command'],
          'create database',
          $dbName,
          $createDatabaseServiceResult['output']
        );
      }

      // Create database component.
      $sqlComponent->save();

      // Save service key.
      $sqlServiceKeyEntity->scsComponent[] = $sqlComponent->id();
      $sqlServiceKeyEntity->save();

      return [
        'message' => 'Create database component.',
        'data' => [
          'sqlComponent' => $sqlComponent,
        ],
        'success' => TRUE,
        'error' => FALSE,
      ];
    }
    catch (MissingDataException $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot create database: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot create database. See logs for more details."));
      return [
        'message' => 'Cannot create database.',
        'data' => NULL,
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Create SODa SCS Snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   *
   * @return array
   *   Result information with the created snapshot.
   */
  public function createSnapshot(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Get all SQL Components.
   *
   * @return array
   *   The result array with the SQL components.
   */
  public function getComponents(): array {
    return [];
  }

  /**
   * Retrieves a SQL component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SQL component to retrieve.
   *
   * @return array
   *   The result array with the SQL component.
   */
  public function getComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Updates a SQL component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SQL component to update.
   *
   * @return array
   *   The result array with the updated SQL component.
   */
  public function updateComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Deletes a SQL component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SQL component to delete.
   *
   * @return array
   *   The result array with the deleted SQL component.
   */
  public function deleteComponent(SodaScsComponentInterface $component): array {
    try {
      $deleteDbResult = $this->sodaScsMysqlServiceActions->deleteService($component);
      $component->delete();
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot delete database: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot delete database. See logs for more details."));

      return [
        'message' => 'Cannot delete database.',
        'data' => [
          'deleteDbResult' => NULL,
          'cleanDatabaseUsers' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    try {
      // GetServiceKey.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $sqlServiceKey */
      $sqlServiceKey = $this->entityTypeManager->getStorage('soda_scs_service_key')->load($component->get('serviceKey')->target_id);
      $dbUserPassword = $sqlServiceKey->get('servicePassword')->value;
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot load service key: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot load service key. See logs for more details."));
      return [
        'message' => 'Cannot load service key.',
        'data' => NULL,
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    // Clean database users.
    try {
      $cleanDatabaseUsers = $this->sodaScsMysqlServiceActions->cleanServiceUsers($component->getOwner()->getDisplayName(), $dbUserPassword);
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot clean database users: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot clean database users. See logs for more details."));
      return [
        'message' => 'Cannot clean database. users',
        'data' => [
          'deleteDbResult' => $deleteDbResult,
          'cleanDatabaseUsers' => $cleanDatabaseUsers,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    return [
      'message' => 'SQL component deleted, users cleaned',
      'data' => [
        'deleteDbResult' => $deleteDbResult,
        'cleanDatabaseUsers' => $cleanDatabaseUsers,
      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

}
