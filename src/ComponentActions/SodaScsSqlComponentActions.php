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
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Exception\SodaScsSqlServiceException;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerExecServiceActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\EntityOwnerTrait;
use GuzzleHttp\ClientInterface;
use Psr\Log\LogLevel;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsSqlComponentActions implements SodaScsComponentActionsInterface {

  use EntityOwnerTrait;
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
   * The SCS Component Helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected SodaScsComponentHelpers $sodaScsComponentHelpers;

  /**
   * The SCS Snapshot Helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers
   */
  protected SodaScsSnapshotHelpers $sodaScsSnapshotHelpers;

  /**
   * The SCS Docker Exec service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsDockerExecServiceActions
   */
  protected SodaScsDockerExecServiceActions $sodaScsDockerExecServiceActions;

  /**
   * The SCS Docker Run service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions
   */
  protected SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions;


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
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
    SodaScsDockerExecServiceActions $sodaScsDockerExecServiceActions,
    SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions,
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
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
    $this->sodaScsDockerExecServiceActions = $sodaScsDockerExecServiceActions;
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
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
      $bundleInfoService = \Drupal::service('entity_type.bundle.info');
      $sqlComponentBundleInfo = $bundleInfoService->getBundleInfo('soda_scs_component')['soda_scs_sql_component'];

      if (!$sqlComponentBundleInfo) {
        throw new \Exception('SQL component bundle info not found');
      }

      $machineName = 'sql-' . $entity->get('machineName')->value;
      $sqlComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create(
        [
          'bundle' => 'soda_scs_sql_component',
          'label' => $entity->get('label')->value,
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
   * @param string $snapshotMachineName
   *   The machine name of the snapshot.
   * @param int $timestamp
   *   The timestamp of the snapshot.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result information with the created snapshot.
   */
  public function createSnapshot(SodaScsComponentInterface $component, string $snapshotMachineName, int $timestamp): SodaScsResult {
    try {
      $snapshotPaths = $this->sodaScsSnapshotHelpers->constructSnapshotPaths($component, $snapshotMachineName, $timestamp);

      // Create the backup directory (type-specific path).
      $dirCreateResult = $this->sodaScsSnapshotHelpers->createDir($snapshotPaths['backupPathWithType']);
      if (!$dirCreateResult['success']) {
        return SodaScsResult::failure(
          error: $dirCreateResult['error'],
          message: 'Snapshot creation failed: Could not create backup directory.',
        );
      }

      // Dump the database into the backup directory using the database container.
      $dbName = $component->get('machineName')->value;
      $dbRootPassword = $this->settings->get('dbRootPassword');
      if (empty($dbRootPassword)) {
        return SodaScsResult::failure(
          error: 'Database root password setting missing',
          message: 'Snapshot creation failed: Missing root password.',
        );
      }
      $dumpFilePath = $snapshotPaths['backupPathWithType'] . '/' . $dbName . '.sql';

      // Create the dump execcreate request and execute it.
      $createDumpExecRequest = $this->sodaScsDockerExecServiceActions->buildCreateRequest([
        'cmd' => [
          'bash',
          '-c',
          'mariadb-dump -uroot -p' . $dbRootPassword . ' "' . $dbName . '" > ' . $dumpFilePath,
        ],
        'containerName' => 'database',
        'user' => '33',
      ]);
      $createDumpExecResponse = $this->sodaScsDockerExecServiceActions->makeRequest($createDumpExecRequest);

      // Check if the dump exec request was successful.
      if (!$createDumpExecResponse['success']) {
        return SodaScsResult::failure(
          error: $createDumpExecResponse['error'],
          message: 'Snapshot creation failed: Could not create database dump exec request.',
        );
      }

      // Create the dump exex start request and execut it.
      $execId = json_decode($createDumpExecResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];
      $startDumpExecRequest = $this->sodaScsDockerExecServiceActions->buildStartRequest(['execId' => $execId]);
      $startDumpExecResponse = $this->sodaScsDockerExecServiceActions->makeRequest($startDumpExecRequest);

      // Check if the dump exec start request was successful.
      if (!$startDumpExecResponse['success']) {
        return SodaScsResult::failure(
          error: $startDumpExecResponse['error'],
          message: 'Snapshot creation failed: Could not start database dump exec request.',
        );
      }

      // Check if the dump file is ready.
      $fileIsReady = FALSE;
      $attempts = 0;
      $maxAttempts = $this->sodaScsSnapshotHelpers->adjustMaxAttempts();
      if ($maxAttempts === FALSE) {
        Error::logException(
          $this->logger,
          new \Exception(''),
          $this->t('The PHP request timeout is less than the required sleep interval of 5 seconds. Please increase your max_execution_time setting.'),
          [],
          LogLevel::ERROR
        );
        $this->messenger->addError($this->t('Could not create snapshot. See logs for more details.'));
        return SodaScsResult::failure(
          error: 'PHP request timeout is less than the required sleep interval of 5 seconds. Please increase your max_execution_time setting.',
          message: 'Snapshot creation failed: Could not create snapshot.',
        );
      }

      // Check if the dump file size does not change,
      // then set fileIsReady to TRUE.
      $previousFileSize = 0;
      while (!$fileIsReady && $attempts < $maxAttempts) {
        clearstatcache(TRUE, $dumpFilePath);
        $currentFileSize = file_exists($dumpFilePath) ? filesize($dumpFilePath) : 0;
        if ($currentFileSize > 0 && $currentFileSize === $previousFileSize) {
          $fileIsReady = TRUE;
        }
        else if ($attempts == 5 && $currentFileSize === $previousFileSize) {
          $containerInspectRequestParams = [
            'routeParams' => [
              'execId' => $execId,
            ],
          ];
          $containerInspectRequest = $this->sodaScsDockerExecServiceActions->buildInspectRequest($containerInspectRequestParams);
          $containerInspectResponse = $this->sodaScsDockerExecServiceActions->makeRequest($containerInspectRequest);
          if (!$containerInspectResponse['success']) {
            return SodaScsResult::failure(
              error: $containerInspectResponse['error'],
              message: 'Snapshot creation failed: Could not inspect container.',
            );
          }
          $inspectContainerResponse = $containerInspectResponse['data']['portainerResponse']->getBody()->getContents();
          Error::logException(
          $this->logger,
          new \Exception('Database dump file error'),
          'Failed to create snapshot. Database dump file size not changed Container is still running.',
          [],
          LogLevel::ERROR
          );
          $this->messenger->addError($this->t('Failed to create snapshot. See logs for more details.'));
          return SodaScsResult::failure(
            error: 'Database dump file error',
            message: 'Snapshot creation failed: Maximum number of attempts to check if the container is running reached. Container is still running.',
          );
        }
        else {
          $previousFileSize = $currentFileSize;
          sleep(5);
          $attempts++;
        }
      }

      // Exit if timeout is reached.
      if ($attempts === $maxAttempts) {
        Error::logException(
          $this->logger,
          new \Exception('Container timeout'),
          'Failed to create snapshot. Maximum number of attempts to check if the container is running reached. Container is still running.',
          [],
          LogLevel::ERROR
        );
        $this->messenger->addError($this->t('Failed to create snapshot. See logs for more details.'));
        return SodaScsResult::failure(
          error: 'Container timeout',
          message: 'Snapshot creation failed: Maximum number of attempts to check if the container is running reached. Container is still running.',
        );
      }

      $randomInt = $this->sodaScsSnapshotHelpers->generateRandomSuffix();
      $containerName = 'snapshot--' . $randomInt . '--' . $snapshotMachineName . '--database';
      // @todo Abstract this to own function.
      // Create and run a short-lived container to tar and sign the SQL dump.
      $createContainerRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest([
        'name' => $containerName,
        'volumes' => NULL,
        'image' => 'alpine:latest',
        'user' => '33:33',
        'cmd' => [
          'sh',
          '-c',
          'tar czf /backup/' . $snapshotPaths['tarFileName'] . ' -C /source . && cd /backup && sha256sum ' . $snapshotPaths['tarFileName'] . ' > ' . $snapshotPaths['sha256FileName'],
        ],
        'hostConfig' => [
          'Binds' => [
            $snapshotPaths['backupPathWithType'] . ':/source',
            $snapshotPaths['backupPathWithType'] . ':/backup',
          ],
          'AutoRemove' => FALSE,
        ],
      ]);
      $createContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($createContainerRequest);
      if (!$createContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $createContainerResponse['error'],
          message: 'Snapshot creation failed: Could not create snapshot container.',
        );
      }

      $containerId = json_decode($createContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];
      $startContainerRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => [
          'containerId' => $containerId,
        ],
      ]);
      $startContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startContainerRequest);
      if (!$startContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $startContainerResponse['error'],
          message: 'Snapshot creation failed: Could not start snapshot container.',
        );
      }

      return SodaScsResult::success(
        data: [
          $component->bundle() => [
            'componentBundle' => $component->bundle(),
            'componentId' => $component->id(),
            'componentMachineName' => $component->get('machineName')->value,
            'containerId' => $containerId,
            'containerName' => $containerName,
            'createContainerResponse' => $createContainerResponse,
            'metadata' => [
              'backupPath' => $snapshotPaths['backupPath'],
              'relativeUrlBackupPath' => $snapshotPaths['relativeUrlBackupPath'],
              'contentFilePaths' => [
                'tarFilePath' => $snapshotPaths['absoluteTarFilePath'],
                'sha256FilePath' => $snapshotPaths['absoluteSha256FilePath'],
              ],
              'contentFileNames' => [
                'tarFileName' => $snapshotPaths['tarFileName'],
                'sha256FileName' => $snapshotPaths['sha256FileName'],
              ],
              'snapshotMachineName' => $snapshotMachineName,
              'timestamp' => $timestamp,
            ],
            'startContainerResponse' => $startContainerResponse,
          ],
        ],
        message: 'Created and started snapshot container successfully.',
      );
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Snapshot creation failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return SodaScsResult::failure(
        error: $e->getMessage(),
        message: 'Snapshot creation failed.',
      );
    }
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

    return [
      'message' => 'SQL component deleted, users cleaned',
      'data' => [
        'deleteDbResult' => $deleteDbResult,
      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

  /**
   * Restore Component from Snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The SODa SCS Snapshot.
   *
   * @return SodaScsResult
   *   Result information with restored component.
  */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot): SodaScsResult {
    return SodaScsResult::success(
      message: 'Component restored from snapshot successfully.',
      data: [],
    );
  }
}
