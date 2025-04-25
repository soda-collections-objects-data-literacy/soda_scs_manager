<?php

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\File\FileSystem;
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
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpersInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

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
   * The SCS Docker exec service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface
   */
  protected SodaScsExecRequestInterface $sodaScsDockerExecServiceActions;

  /**
   * The SCS Docker run service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface
   */
  protected SodaScsRunRequestInterface $sodaScsDockerRunServiceActions;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The SCS component helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected SodaScsComponentHelpers $sodaScsComponentHelpers;

  /**
   * The SCS stack helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsHelpersInterface
   */
  protected SodaScsHelpersInterface $sodaScsStackHelpers;

  /**
   * The SCS Keycloak actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceActions;

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
    SodaScsExecRequestInterface $sodaScsDockerExecServiceActions,
    SodaScsRunRequestInterface $sodaScsDockerRunServiceActions,
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    SodaScsHelpersInterface $sodaScsStackHelpers,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceActions,
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
    $this->sodaScsDockerExecServiceActions = $sodaScsDockerExecServiceActions;
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
    $this->settings = $settings;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->sodaScsPortainerServiceActions = $sodaScsPortainerServiceActions;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->sodaScsKeycloakServiceActions = $sodaScsKeycloakServiceActions;
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
      $machineName = 'wisski-' . $entity->get('machineName')->value;
      // Get included SQL component if this is a stack (not a component)
      if ($entity instanceof SodaScsStackInterface) {
        // Retrieve the SQL component that this WissKI component will use.
        $sqlComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($entity, 'soda_scs_sql_component');
        if (!$sqlComponent) {
          throw new \Exception('SQL component not found for WissKI component');
        }
        $dbName = $sqlComponent->get('machineName')->value;
      }

      // Create service key if it does not exist.
      $keyProps = [
        'bundle'  => 'soda_scs_wisski_component',
        'bundleLabel' => $wisskiComponentBundleInfo['label'],
        'type'  => 'password',
        'userId'  => $entity->getOwnerId(),
        'username' => $entity->getOwner()->getDisplayName(),
      ];
      $wisskiComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($keyProps) ?? $this->sodaScsServiceKeyActions->createServiceKey($keyProps);
      $wisskiComponentServiceKeyPassword = $wisskiComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('WissKI service key password not found.');

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent */
      $wisskiComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create(
        [
          'bundle' => 'soda_scs_wisski_component',
          'label' => $entity->get('label')->value . ' (WissKI)',
          'machineName' => $machineName,
          'owner'  => $entity->getOwner(),
          'description' => $wisskiComponentBundleInfo['description'],
          'imageUrl' => $wisskiComponentBundleInfo['imageUrl'],
          'flavours' => $entity->get('flavours')->value,
          'health' => 'Unknown',
          'partOfProjects' => $entity->get('partOfProjects'),
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

        $flavoursList = $wisskiComponent->get('flavours')->value;

        $flavours = '';

        // Process flavours array into a space-separated string.
        if (is_array($flavoursList)) {
          // Extract values from each flavour entry.
          foreach ($flavoursList as $flavour) {
            if (isset($flavour['value'])) {
              $flavoursArray[] = $flavour['value'];
            }
          }

          // Join flavours with spaces if any were found.
          if (!empty($flavours)) {
            $flavours = implode(' ', $flavoursArray);
          }
        }

        $wisskiType = 'stack';
      }
      else {
        // If it is not a stack we set the values to empty strings.
        $dbName = '';
        $wisskiComponentServiceKeyPassword = '';
        $sqlComponentServiceKeyPassword = '';
        $triplestoreComponentServiceKeyPassword = '';
        $triplestoreComponentServiceTokenString = '';
        $flavours = '';
        $wisskiType = 'component';
      }

      // Request keycloak client configs.
      $keycloakTokenRequest = $this->sodaScsKeycloakServiceActions->buildTokenRequest([]);
      $keycloakTokenResponse = $this->sodaScsKeycloakServiceActions->makeRequest($keycloakTokenRequest);
      if (!$keycloakTokenResponse['success']) {
        throw new \Exception('Keycloak token request failed.');
      }
      $keycloakTokenResponseContents = json_decode($keycloakTokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
      $keycloakToken = $keycloakTokenResponseContents['access_token'];
      $openidConnectClientSecret = $this->sodaScsComponentHelpers->createSecret();

      $keycloakCreateClientRequest = $this->sodaScsKeycloakServiceActions->buildCreateRequest([
        // @todo Use url of component.
        'clientId' => $machineName,
        'name' => $entity->get('label')->value,
        'description' => 'Change me',
        'token' => $keycloakToken,
        'rootUrl' => 'https://' . $machineName . '.scs.sammlungen.io',
        'adminUrl' => 'https://' . $machineName . '.scs.sammlungen.io',
        'logoutUrl' => 'https://' . $machineName . '.scs.sammlungen.io/logout',
        // @todo: Use secret from service key.
        'secret' => $openidConnectClientSecret,
      ]);

      $keycloakCreateClientResponse = $this->sodaScsKeycloakServiceActions->makeRequest($keycloakCreateClientRequest);

      if (!$keycloakCreateClientResponse['success']) {
        throw new \Exception('Keycloak create client request failed.');
      }
      // @todo Use project from component.
      $requestParams = [
        'dbName' => $dbName,
        'flavours' => $flavours,
        'machineName' => $machineName,
        'openidConnectClientSecret' => $openidConnectClientSecret,
        'project' => 'my_project',
        'sqlServicePassword' => $sqlComponentServiceKeyPassword,
        'triplestoreServicePassword' => $triplestoreComponentServiceKeyPassword,
        'triplestoreServiceToken' => $triplestoreComponentServiceTokenString,
        'tsRepository' => $triplestoreComponent->get('machineName')->value,
        'userId' => $wisskiComponent->getOwnerId(),
        'username' => $wisskiComponent->getOwner()->getDisplayName(),
        'wisskiServicePassword' => $wisskiComponentServiceKeyPassword,
        'wisskiType' => $wisskiType,
      ];
      // Create Drupal instance.
      $portainerCreateRequest = $this->sodaScsPortainerServiceActions->buildCreateRequest($requestParams);
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Request failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Request failed. See logs for more details."));
      return [
        'message' => 'Request failed.',
        'data' => [
          'portainerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    try {
      $portainerCreateRequestResult = $this->sodaScsPortainerServiceActions->makeRequest($portainerCreateRequest);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Portainer request failed: @error", [
        '@error' => $e->getMessage(),
      ]);
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
   * Create SODa SCS Snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   *
   * @return array
   *   Result information with the created snapshot.
   */
  public function createSnapshot(SodaScsComponentInterface $component): array {
    try {
      $timestamp = time();
      $date = date('Y-m-d', $timestamp);
      $machineName = $component->get('machineName')->value;
      $snapshotName = $machineName . '--snapshot--' . $timestamp;
      $backupPath = '/var/scs-manager/snapshots/' . $component->getOwner()->getDisplayName() . '/' . $date . '/' . $machineName;

      // Create the backup directory.
      $dirCreateResult = $this->sodaScsComponentHelpers->createDir($backupPath);
      if (!$dirCreateResult['success']) {
        return $dirCreateResult;
      }

      // Create and run the snapshot container.
      $requestParams = [
        'name' => $snapshotName,
        'volumes' => NULL,
        'image' => 'alpine:latest',
        'user' => '33:33',
        'cmd' => [
          'tar',
          'czf',
          '/backup/' . $snapshotName . '--webroot.tar.gz',
          '-C',
          '/source',
          '.',
        ],
        'hostConfig' => [
          'Binds' => [
            $machineName . '_drupal-root:/source',
            $backupPath . ':/backup'
          ],
          'AutoRemove' => FALSE,
        ],
      ];

      // Make the create container request.
      $createContainerRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest($requestParams);
      $createContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($createContainerRequest);

      if (!$createContainerResponse['success']) {
        return [
          'message' => 'Create container request failed. Snapshot creation aborted..',
          'data' => $createContainerResponse,
          'success' => FALSE,
          'error' => $createContainerResponse['error'],
          'statusCode' => $createContainerResponse['statusCode'],
        ];
      }

      // Get container ID from response.
      $containerId = json_decode($createContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];

      // Make the start container request.
      $startContainerRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest(['containerId' => $containerId]);
      $startContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startContainerRequest);

      if (!$startContainerResponse['success']) {
        return [
          'message' => 'Start container request failed. Snapshot creation aborted..',
          'data' => $startContainerResponse,
          'success' => FALSE,
          'error' => $startContainerResponse['error'],
          'statusCode' => $startContainerResponse['statusCode'],
        ];
      }

      return [
        'message' => 'Snapshot created successfully.',
        'data' => $createContainerResponse,
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => $createContainerResponse['statusCode'],
      ];
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Snapshot creation failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return [
        'message' => 'Snapshot creation failed.',
        'data' => $e,
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => 500,
      ];
    }
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
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot get WissKI component at portainer: @message',
        ['@component' => $component->getLabel(), '@message' => $e->getMessage()],
        LogLevel::ERROR
      );
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
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot assemble WissKI delete request: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
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
        Error::logException(
          $this->loggerFactory->get('soda_scs_manager'),
          new \Exception($requestResult['error']),
          'Could not delete WissKI stack at portainer: @message',
          ['@message' => $requestResult['error']],
          LogLevel::ERROR
        );
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
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot delete WissKI component: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
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
