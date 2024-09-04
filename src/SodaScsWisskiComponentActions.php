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
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\SodaScsServiceKeyActions;
use Drupal\soda_scs_manager\SodaScsServiceRequestInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\SodaScsComponentHelpers;
use Exception;
use GuzzleHttp\ClientInterface;

/**
* Handles the communication with the SCS user manager daemon.
*/
class SodaScsWisskiComponentActions implements SodaScsComponentActionsInterface {
  
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
   * The SCS component helpers service.
   * 
   * @var \Drupal\soda_scs_manager\SodaScsComponentHelpers
   */
  protected SodaScsComponentHelpers $sodaScsComponentHelpers;
  
  /**
  * The SCS Portainer actions service.
  * 
  * @var \Drupal\soda_scs_manager\SodaScsServiceRequestInterface
  */
  protected SodaScsServiceRequestInterface $sodaScsPortainerServiceActions;
  
  /**
  * The SCS database actions service.
  *
  * @var \Drupal\soda_scs_manager\SodaScsServiceActionsInterface
  */
  protected SodaScsServiceActionsInterface $sodaScsSqlServiceActions;
  
  /**
  * The SCS Service Key actions service.
  *
  * @var \Drupal\soda_scs_manager\SodaScsServiceKeyActionsInterface
  */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;
  
  /**
  * The string translation service.
  *
  * @var \Drupal\Core\StringTranslation\TranslationInterface
  */
  protected TranslationInterface $stringTranslation;
  
  /**
  * Class constructor.
  */
  public function __construct(ConfigFactoryInterface $configFactory, Connection $database, EntityTypeManagerInterface $entityTypeManager, ClientInterface $httpClient, LoggerChannelFactoryInterface $loggerFactory, MessengerInterface $messenger, SodaScsComponentHelpers $sodaScsComponentHelpers, SodaScsServiceRequestInterface $sodaScsPortainerServiceActions, SodaScsServiceActionsInterface $sodaScsSqlServiceActions, SodaScsServiceKeyActions $sodaScsServiceKeyActions, TranslationInterface $stringTranslation, ){
    // Services from container.
    $settings = $configFactory
    ->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->settings = $settings;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsPortainerServiceActions = $sodaScsPortainerServiceActions;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->stringTranslation = $stringTranslation;
  }
  
  /**
  * Create SODa SCS Component.
  * 
  * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
  * 
  * @return array
  */
  public function createComponent(SodaScsComponentInterface $component): array {
    try {
      // Create service key if it does not exist.
      $wisskiComponentServiceKey = $this->sodaScsServiceKeyActions->getServiceKey($component) ?? $this->sodaScsServiceKeyActions->createServiceKey($component);
      
      $sqlComponent = $this->sodaScsComponentHelpers->retrieveReferencedComponent($component, 'sql');
      $sqlComponentServiceKey = $this->sodaScsServiceKeyActions->getServiceKey($sqlComponent) ?? $this->sodaScsServiceKeyActions->createServiceKey($sqlComponent);
    
      $component->set('serviceKey', $wisskiComponentServiceKey);
      $requestParams = [
        'subdomain' => $component->get('subdomain')->value,
        'project' => 'my_project',
        'userId' => $component->getOwnerId(),
        'userName' => $component->getOwner()->getDisplayName(),
        'wisskiServicePassword' => $wisskiComponentServiceKey->get('servicePassword')->value,
        'sqlServicePassword' => $sqlComponentServiceKey->get('servicePassword')->value,
        'triplestoreServicePassword' => 'supersecurepassword'
      ];
      // Create Drupal instance.
      $portainerCreateRequest = $this->sodaScsPortainerServiceActions->buildCreateRequest($requestParams);
    } catch (MissingDataException $e) {
      $this->loggerFactory->get('soda_scs_manager')
      ->error("Cannot assemble Request: @error", [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Cannot assemble request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'portainerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    
      $requestResult = $this->sodaScsPortainerServiceActions->makeRequest($portainerCreateRequest);
      
      if ($requestResult['success'] === FALSE) {
        return $requestResult;
      }
      // Set the external ID.
      $component->set('externalId', $requestResult['data']['portainerResponse']['Id']);
      
      // Save the component.
      $component->save();
      
      $wisskiComponentServiceKey->set('scsComponent', [$component->id()]);
      $wisskiComponentServiceKey->save();
      
      return [
        'message' => 'Created WissKI component.',
        'data' => [
          'portainerResponse' => $requestResult,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];
  }
  
  
  
  /**
  * Retrieves a SODa SCS Component component.
  *
  * @param SodaScsComponentInterface $component The SODa SCS Component component to retrieve.
  *
  * @return array
  */
  public function getComponent(SodaScsComponentInterface $component): array {
    return [];
  }
  
  /**
  * Updates a SODa SCS Component component.
  *
  * @param SodaScsComponentInterface $component The SODa SCS Component component to update.
  * 
  * @return array
  */
  public function updateComponent(SodaScsComponentInterface $component): array {
    return [];
  }
  
  /**
  * Deletes a SODa SCS Component component.
  *
  * @param SodaScsComponentInterface $component The SODa SCS Component component to delete.
  * 
  * @return array
  */
  public function deleteComponent(SodaScsComponentInterface $component): array {
    try {
      $queryParams['externalId'] = $component->get('externalId')->value;
      $portainerDeleteRequest = $this->sodaScsPortainerServiceActions->buildDeleteRequest($queryParams);
    } 
    catch (MissingDataException $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Cannot assemble WissKI delete request: @error", [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Cannot WissKI assemble delete request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'portainerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    } try {
      /** @var array $portainerResponse */
      $requestResult = $this->sodaScsPortainerServiceActions->makeRequest($portainerDeleteRequest);
      if (!$requestResult['success']) {
        $this->loggerFactory->get('soda_scs_manager')->error("Could not delete WissKI stack at portainer. error: @error", [
          '@error' => $requestResult['error'],
        ]);
        $this->messenger->addError($this->stringTranslation->translate("Could not delete WissKI stack at portainer, but will delete the component anyway. See logs for more details."));
      }
      $component->delete();
      return [
        'message' => 'Deleted WissKI component.',
        'data' => [
          'portainerResponse' => $portainerResponse,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];
    } catch (Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Cannot delete WissKI component: @error", [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Cannot delete WissKI component. See logs for more details."));
      return [
        'message' => 'Cannot delete WissKI component.',
        'data' => [
          'portainerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }
}