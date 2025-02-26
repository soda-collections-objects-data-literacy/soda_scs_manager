<?php

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpersInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use GuzzleHttp\ClientInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsWisskiComponentActions implements SodaScsComponentActionsInterface {

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
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsHelpersInterface
   */
  protected SodaScsHelpersInterface $sodaScsStackHelpers;

  /**
   * The SCS Portainer actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
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
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsHelpersInterface $sodaScsStackHelpers,
    SodaScsServiceRequestInterface $sodaScsPortainerServiceActions,
    SodaScsServiceActionsInterface $sodaScsSqlServiceActions,
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
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
      $wisskiComponentBundleInfo = \Drupal::service('entity_type.bundle.info')->getBundleInfo('soda_scs_component')['soda_scs_wisski_component'];

      if (!$wisskiComponentBundleInfo) {
        throw new \Exception('WissKI component bundle info not found');
      }
      $machineName = $entity->get('machineName')->value;

      // Create service key if it does not exist.
      $keyProps = [
        'bundle'  => 'soda_scs_wisski_component',
        'type'  => 'password',
        'userId'  => $entity->getOwnerId(),
        'username' => $entity->getOwner()->getDisplayName(),
      ];
      $wisskiComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($keyProps) ?? throw new \Exception('WissKI service key not found.');
      $wisskiComponentServiceKeyPassword = $wisskiComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('WissKI service key password not found.');

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent */
      $wisskiComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create(
        [
          'bundle' => 'soda_scs_wisski_component',
          'label' => $entity->get('label')->value . ' (WissKI Environment)',
          'machineName' => $machineName,
          'owner'  => $entity->getOwner(),
          'description' => $wisskiComponentBundleInfo['description'],
          'imageUrl' => $wisskiComponentBundleInfo['imageUrl'],
          'flavours' => array_values($entity->get('flavours')->getValue()),
          'health' => 'Unknown',
        ]
      );

      $wisskiComponent->serviceKey[] = $wisskiComponentServiceKeyEntity;

      if ($entity instanceof SodaScsStackInterface) {
        // If it is a stack, we need to retrieve the included components.
        $sqlComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($entity, 'soda_scs_sql_component');

        $sqlKeyProps = [
          'bundle'  => 'soda_scs_sql_component',
          'type'  => 'password',
          'userId'  => $sqlComponent->getOwnerId(),
        ];

        $sqlComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($sqlKeyProps) ?? throw new \Exception('SQL service key not found.');
        $sqlComponentServiceKeyPassword = $sqlComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('SQL service key password not found.');
        $triplestoreComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($entity, 'soda_scs_triplestore_component');

        $triplestoreKeyProps = [
          'bundle'  => 'soda_scs_triplestore_component',
          'type'  => 'password',
          'userId'  => $triplestoreComponent->getOwnerId(),
        ];

        $triplestoreComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($triplestoreKeyProps) ?? throw new \Exception('Triplestore service key not found.');
        $triplestoreComponentServiceKeyPassword = $triplestoreComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('Triplestore service key password not found.');

        $triplestoreTokenProps = [
          'bundle'  => 'soda_scs_triplestore_component',
          'type'  => 'token',
          'userId'  => $triplestoreComponent->getOwnerId(),
        ];

        $triplestoreComponentServiceTokenEntity = $this->sodaScsServiceKeyActions->getServiceKey($triplestoreTokenProps) ?? throw new \Exception('Triplestore service token not found.');
        $triplestoreComponentServiceTokenString = $triplestoreComponentServiceTokenEntity->get('servicePassword')->value ?? throw new \Exception('Triplestore service token not found.');

        $flavours_array = $wisskiComponent->get('flavours')->getValue();

        $flavours = [];
        foreach ($flavours_array as $flavour) {
          $flavours[] = $flavour['value'];
        }

        $flavours = implode(' ', $flavours);

        $wisskiType = 'stack';
      }
      else {
        // If it is not a stack we set the values to empty strings.
        $wisskiComponentServiceKeyPassword = '';
        $sqlComponentServiceKeyPassword = '';
        $triplestoreComponentServiceKeyPassword = '';
        $triplestoreComponentServiceTokenString = '';
        $flavours = '';
        $wisskiType = 'component';
      }

      $requestParams = [
        'flavours' => $flavours,
        'machineName' => $wisskiComponent->get('machineName')->value,
        'project' => 'my_project',
        'sqlServicePassword' => $sqlComponentServiceKeyPassword,
        'triplestoreServicePassword' => $triplestoreComponentServiceKeyPassword,
        'triplestoreServiceToken' => $triplestoreComponentServiceTokenString,
        'userId' => $wisskiComponent->getOwnerId(),
        'username' => $wisskiComponent->getOwner()->getDisplayName(),
        'wisskiServicePassword' => $wisskiComponentServiceKeyPassword,
        'wisskiType' => $wisskiType,
      ];
      // Create Drupal instance.
      $portainerCreateRequest = $this->sodaScsPortainerServiceActions->buildCreateRequest($requestParams);
    }
    catch (MissingDataException $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error($this->t("Cannot assemble Request: @error @trace", [
          '@error' => $e->getMessage(),
          '@trace' => $e->getTraceAsString(),
        ]));
      $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
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
        'statusCode' => $portainerCreateRequestResult['statusCode'],
        'success' => FALSE,
        'error' => $portainerCreateRequestResult['error'],
      ];
    }
    $portainerResponsePayload = json_decode($portainerCreateRequestResult['data']['portainerResponse']->getBody()->getContents(), TRUE);
    // Set the external ID.
    $wisskiComponent->set('externalId', $portainerResponsePayload['Id']);

    // Save the component.
    $wisskiComponent->save();

    $wisskiComponentServiceKeyEntity->scsComponent[] = $wisskiComponent->id();
    $wisskiComponentServiceKeyEntity->save();

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
    $queryParams['externalId'] = $component->get('externalId')->value;
    try {
      $portainerGetRequest = $this->sodaScsPortainerServiceActions->buildGetRequest($queryParams);
      $portainerGetResponse = $this->sodaScsPortainerServiceActions->makeRequest($portainerGetRequest);
      if (!$portainerGetResponse['success']) {
        return [
          'message' => $this->t('Cannot get WissKI component @component at portainer.', ['@component' => $component->getLabel()]),
          'data' => $portainerGetResponse,
          'success' => FALSE,
          'error' => $portainerGetResponse['error'],
          'statusCode' => $portainerGetResponse['statusCode'],
        ];
      }

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error($this->t("Cannot get WissKI component @component at portainer: @error @trace", [
        '@component' => $component->getLabel(),
        '@error' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]));
      $this->messenger->addError($this->t("Cannot get WissKI component @component at portainer. See logs for more details.", ['@component' => $component->getLabel()]));
      return [
        'message' => $this->t('Cannot get WissKI component @component at portainer.', ['@component' => $component->getLabel()]),
        'data' => $e,
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => $e->getCode(),
      ];
    }
    try {
      $portainerDeleteRequest = $this->sodaScsPortainerServiceActions->buildDeleteRequest($queryParams);
    }
    catch (MissingDataException $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Cannot assemble WissKI delete request: @error", [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t("Cannot assemble WissKI component delete request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'portainerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => $e->getCode(),
      ];
    }
    try {
      /** @var array $portainerResponse */
      $requestResult = $this->sodaScsPortainerServiceActions->makeRequest($portainerDeleteRequest);
      if (!$requestResult['success']) {
        $this->loggerFactory->get('soda_scs_manager')->error("Could not delete WissKI stack at portainer. error: @error", [
          '@error' => $requestResult['error'],
        ]);
        $this->messenger->addError($this->t("Could not delete WissKI stack at portainer, but will delete the component anyway. See logs for more details."));
      }
      $component->delete();

      return [
        'message' => 'Deleted WissKI component.',
        'data' => [
          'portainerResponse' => $requestResult,
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => $requestResult['statusCode'],
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Cannot delete WissKI component: @error", [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t("Cannot delete WissKI component. See logs for more details."));
      return [
        'message' => 'Cannot delete WissKI component.',
        'data' => [
          'portainerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => $e->getCode(),
      ];
    }
  }

}
