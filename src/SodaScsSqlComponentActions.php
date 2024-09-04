<?php

namespace Drupal\soda_scs_manager;


use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\SodaScsMysqlServiceActions;
use Drupal\soda_scs_manager\SodaScsServiceKeyActions;
use GuzzleHttp\ClientInterface;

/**
* Handles the communication with the SCS user manager daemon.
*/
class SodaScsSqlComponentActions implements SodaScsComponentActionsInterface {
  
  use DependencySerializationTrait;
  
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
  * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
  */
  protected LoggerChannelFactoryInterface $loggerFactory;
  
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
  * @var \Drupal\soda_scs_manager\SodaScsMysqlServiceActions
  */
  protected SodaScsMysqlServiceActions $sodaScsMysqlServiceActions;
  
  /**
  * The SCS Service Key actions service.
  *
  * @var \Drupal\soda_scs_manager\SodaScsServiceKeyActions
  */
  protected SodaScsServiceKeyActions $sodaScsServiceKeyActions;
  
  /**
  * The string translation service.
  *
  * @var \Drupal\Core\StringTranslation\TranslationInterface
  */
  protected TranslationInterface $stringTranslation;
  
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
    SodaScsMysqlServiceActions $sodaScsMysqlServiceActions,
    SodaScsServiceKeyActions $sodaScsServiceKeyActions,
    TranslationInterface $stringTranslation,
    ) {
      // Services from container.
      $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
      $this->database = $database;
      $this->entityTypeManager = $entityTypeManager;
      $this->httpClient = $httpClient;
      $this->loggerFactory = $loggerFactory;
      $this->messenger = $messenger;
      $this->settings = $settings;
      $this->sodaScsMysqlServiceActions = $sodaScsMysqlServiceActions;
      $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
      $this->stringTranslation = $stringTranslation;
    }
    
    /**
    * Create SQL.
    * 
    * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
    * 
    * @return array
    */
    public function createComponent(SodaScsComponentInterface $component): array {
      try {    
        //Create a new SODa SCS database component.  
        /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentBundleInterface $bundle */
        $bundle = $this->entityTypeManager->getStorage('soda_scs_component_bundle')->load('sql');
        $subdomain = $component->get('subdomain')->value . '-db';
        $sqlComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create(
          [
            'bundle' => 'sql',
            'label' => $subdomain . '.' . $this->settings->get('scsHost') . ' (SQL Database)',
            'subdomain' => $subdomain,
            'user'  => $component->get('user')->target_id,
            'description' =>  $bundle->getDescription(),
            'imageUrl' => $bundle->getImageUrl(),
            ]
          );
          
          // Create service key if it does not exist.
          $sqlServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($sqlComponent) ?? $this->sodaScsServiceKeyActions->createServiceKey($sqlComponent);
          
          /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $sqlComponent */
          $sqlComponent->set('serviceKey', $sqlServiceKeyEntity);
          
          
          // All settings available?
          
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
          
          $dbName = $component->get('subdomain')->value . '-db';
          // Check if the database exists.
          $checkDbExistsResult = $this->sodaScsMysqlServiceActions->existService($dbName);
          
          // Command failed.
          if ($checkDbExistsResult['execStatus'] != 0) {
            return $this->sodaScsMysqlServiceActions->handleCommandFailure($checkDbExistsResult, 'check if database', $dbName);
          }
          
          if ($checkDbExistsResult['result']) {
            // Database already exists
            $this->messenger->addError($this->stringTranslation->translate('Database already exists. See logs for more details.'));
            return [];
          }
          
          // Check if the user exists
          $checkDbUserExistsResult = $this->sodaScsMysqlServiceActions->existServiceUser($dbUserName);
          
          // Command failed
          if ($checkDbUserExistsResult['execStatus'] != 0) {
            return $this->sodaScsMysqlServiceActions->handleCommandFailure($checkDbUserExistsResult, 'check if user', $dbUserName);
          }
          
          if ($checkDbUserExistsResult['result'] == 0) {
            // Database user does not exist
            // Create the database user
            /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $sqlServiceKeyEntity */
            $userPassword = $sqlServiceKeyEntity->get('servicePassword')->value;
            $createDbUserResult = $this->sodaScsMysqlServiceActions->createServiceUser($dbUserName, $userPassword);
            
            // Command failed
            if ($createDbUserResult['execStatus'] != 0) {
              return $this->sodaScsMysqlServiceActions->handleCommandFailure($createDbUserResult, 'create user', $dbUserName);
            }
          }
          
          // Grant rights to the database user
          $grantRights2DbResult = $this->sodaScsMysqlServiceActions->grantServiceRights($dbUserName, $dbName, ['ALL PRIVILEGES']);
          
          // Command failed
          if ($grantRights2DbResult['execStatus'] != 0) {
            return $this->sodaScsMysqlServiceActions->handleCommandFailure($grantRights2DbResult, 'grant rights to user', 'user', 'dbUser');
          }
          
          // Create Drupal database.
          $createDatabaseServiceResult = $this->sodaScsMysqlServiceActions->createService($sqlComponent);
          
          if ($createDatabaseServiceResult['execStatus'] != 0) {
            return $this->sodaScsMysqlServiceActions->handleCommandFailure($createDatabaseServiceResult, 'create database', $dbName);
          }
          $sqlComponent->save();
          return [
            'message' => 'Create database component.',
            'data' => [
              'sqlComponent' => $sqlComponent,
            ],
            'success' => TRUE,
            'error' => FALSE,
          ];
        } catch (MissingDataException $e) {
          $this->loggerFactory->get('soda_scs_manager')
          ->error("Cannot create database. @error", [
            '@error' => $e->getMessage(),
          ]);
          $this->messenger->addError($this->stringTranslation->translate("Cannot create database. See logs for more details."));
          return [
            'message' => 'Cannot create database.',
            'data' => NULL,
            'success' => FALSE,
            'error' => $e->getMessage(),
          ];
        }
      }
      
      
      /**
      * Retrieves a SQL component.
      *
      * @param SodaScsComponentInterface $component The SQL component to retrieve.
      *
      * @return array
      */
      public function getComponent(SodaScsComponentInterface $component): array {
        return [];
      }
      
      /**
      * Updates a SQL component.
      *
      * @param SodaScsComponentInterface $component The SQL component to update.
      * 
      * @return array
      */
      public function updateComponent(SodaScsComponentInterface $component): array {
        return [];
      }
      
      /**
      * Deletes a SQL component.
      *
      * @param SodaScsComponentInterface $component The SQL component to delete.
      * 
      * @return array
      */
      public function deleteComponent(SodaScsComponentInterface $component): array {
        try {
          $deleteDbResult = $this->sodaScsMysqlServiceActions->deleteService($component);
          $component->delete();
        } catch (MissingDataException $e) {
          $this->loggerFactory->get('soda_scs_manager')
          ->error("Cannot delete database: @error trace: @trace", [
            '@error' => $e->getMessage(),
            '@trace' => $e->getTraceAsString(),
          ]);
          $this->messenger->addError($this->stringTranslation->translate("Cannot delete database. See logs for more details."));
        }
        try {
          // GetServiceKey
          /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $sqlServiceKey */
          $sqlServiceKey = $this->entityTypeManager->getStorage('soda_scs_service_key')->load($component->get('serviceKey')->target_id);
          $dbUserPassword = $sqlServiceKey->get('servicePassword')->value;
          $cleanDatabaseUsers = $this->sodaScsMysqlServiceActions->cleanServiceUsers($component->getOwner()->getDisplayName(), $dbUserPassword);
        } catch (MissingDataException $e) {
          $this->loggerFactory->get('soda_scs_manager')
          ->error("Cannot clean database users. @error", [
            '@error' => $e->getMessage(),
          ]);
          $this->messenger->addError($this->stringTranslation->translate("Cannot clean database users. See logs for more details."));
          return [
            'message' => 'Cannot clean database. users',
            'data' => [
              'deleteDbResult' => $deleteDbResult,
              'cleanDatabaseUsers' => $cleanDatabaseUsers
            ],
            'success' => FALSE,
            'error' => $e->getMessage(),
          ];
        }
        return [
          'message' => 'SQL component deleted, users cleaned',
          'data' => [
            'deleteDbResult' => $deleteDbResult,
            'cleanDatabaseUsers' => $cleanDatabaseUsers
          ],
          'success' => TRUE,
          'error' => NULL,
        ];
      }
      
    }