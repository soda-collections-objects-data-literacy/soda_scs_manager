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
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
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
   * The SCS stack helpers service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsStackHelpers
   */
  protected SodaScsStackHelpers $sodaScsStackHelpers;

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
  public function __construct(ConfigFactoryInterface $configFactory, Connection $database, EntityTypeManagerInterface $entityTypeManager, ClientInterface $httpClient, LoggerChannelFactoryInterface $loggerFactory, MessengerInterface $messenger, SodaScsStackHelpers $sodaScsStackHelpers, SodaScsServiceRequestInterface $sodaScsPortainerServiceActions, SodaScsServiceActionsInterface $sodaScsSqlServiceActions, SodaScsServiceKeyActions $sodaScsServiceKeyActions, TranslationInterface $stringTranslation,) {
    // Services from container.
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->settings = $settings;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->sodaScsPortainerServiceActions = $sodaScsPortainerServiceActions;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS component.
   *
   * @return array
   *   The SODa SCS component.
   */
  public function createComponent(SodaScsStackInterface|SodaScsComponentInterface $entity): array {
    try {
      // Create WissKI component.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentBundleInterface $bundle */
      $bundle = $this->entityTypeManager->getStorage('soda_scs_component_bundle')->load('wisski');
      $subdomain = $entity->get('subdomain')->value;
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent */
      $wisskiComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create(
        [
          'bundle' => 'wisski',
          'label' => $subdomain . '.' . $this->settings->get('scsHost') . ' (WissKI Environment)',
          'subdomain' => $subdomain,
          'user'  => $entity->getOwner(),
          'description' => $bundle->getDescription(),
          'imageUrl' => $bundle->getImageUrl(),
        ]
      );

      // Create service key if it does not exist.
      $keyProps = [
        'bundle'  => 'wisski',
        'userId'  => $entity->getOwnerId(),
      ];
      $wisskiComponentServiceKey = $this->sodaScsServiceKeyActions->getServiceKey($keyProps) ?? $this->sodaScsServiceKeyActions->createServiceKey($keyProps);
      $wisskiComponent->set('serviceKey', $wisskiComponentServiceKey);

      $sqlComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($entity, 'sql');

      $sqlKeyProps = [
        'bundle'  => 'sql',
        'userId'  => $sqlComponent->getOwnerId(),
      ];

      $sqlComponentServiceKey = $this->sodaScsServiceKeyActions->getServiceKey($sqlKeyProps) ?? $this->sodaScsServiceKeyActions->createServiceKey($sqlKeyProps);

      $triplestoreComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($entity, 'triplestore');

      $triplestoreKeyProps = [
        'bundle'  => 'triplestore',
        'userId'  => $triplestoreComponent->getOwnerId(),
      ];

      $triplestoreComponentServiceKey = $this->sodaScsServiceKeyActions->getServiceKey($triplestoreKeyProps) ?? $this->sodaScsServiceKeyActions->createServiceKey($triplestoreKeyProps);

      $requestParams = [
        'subdomain' => $wisskiComponent->get('subdomain')->value,
        'project' => 'my_project',
        'userId' => $wisskiComponent->getOwnerId(),
        'username' => $wisskiComponent->getOwner()->getDisplayName(),
        'wisskiServicePassword' => $wisskiComponentServiceKey->get('servicePassword')->value,
        'sqlServicePassword' => $sqlComponentServiceKey->get('servicePassword')->value,
        'triplestoreServicePassword' => $triplestoreComponentServiceKey->get('servicePassword')->value,
      ];
      // Create Drupal instance.
      $portainerCreateRequest = $this->sodaScsPortainerServiceActions->buildCreateRequest($requestParams);
    }
    catch (MissingDataException $e) {
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

    $portainerCreateRequestResult = $this->sodaScsPortainerServiceActions->makeRequest($portainerCreateRequest);

    if (!$portainerCreateRequestResult['success']) {
      return [
        'message' => 'Portainer request failed.',
        'data' => [
          'wisskiComponent' => NULL,
          'portainerCreateRequestResult' => $portainerCreateRequestResult,
        ],
        'success' => FALSE,
        'error' => $portainerCreateRequestResult['error'],
      ];
    }
    // Set the external ID.
    $wisskiComponent->set('externalId', $portainerCreateRequestResult['data']['portainerResponse']['Id']);

    // Save the component.
    $wisskiComponent->save();

    $wisskiComponentServiceKey->scsComponent[] = $wisskiComponent->id();
    $wisskiComponentServiceKey->save();

    return [
      'message' => 'Created WissKI component.',
      'data' => [
        'wisskiComponent' => $wisskiComponent,
        'portainerCreateRequestResult' => $portainerCreateRequestResult,

      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

  /**
   * Get all WissKI Components.
   *
   * @return array
   *   The result array with the WissKI components.
   */
  public function getComponents(): array {
    return [];
  }

  /**
   * Retrieves a SODa SCS WissKI component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component component to retrieve.
   *
   * @return array
   *   The result array of the created component.
   */
  public function getComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Updates a SODa SCS Component component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component component to update.
   *
   * @return array
   *   The result array of the created component.
   */
  public function updateComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Deletes a SODa SCS Component component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component component to delete.
   *
   * @return array
   *   The result array of the created component.
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
      $this->messenger->addError($this->stringTranslation->translate("Cannot assemble WissKI component delete request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'portainerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    try {
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
          'portainerResponse' => $requestResult,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];
    }
    catch (\Exception $e) {
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
