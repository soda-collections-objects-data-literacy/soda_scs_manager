<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;


/**
 * Helper functions for SCS components.
 * 
 * @todo Use health check functions from the service actions.
 */
class SodaScsComponentHelpers {
  use StringTranslationTrait;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

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
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The settings.
   *
   * @var \Drupal\Core\Config
   */
  protected Config $settings;

  /**
   * The Docker Volumes service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $dockerVolumesServiceActions;

  /**
   * The openGDB service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $openGdbServiceActions;

  /**
   * The Portainer service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $portainerServiceActions;

  /**
   * The SQL service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface
   */
  protected SodaScsServiceActionsInterface $sqlServiceActions;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    ClientInterface $httpClient,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
    SodaScsServiceRequestInterface $dockerVolumesServiceActions,
    SodaScsServiceRequestInterface $openGdbServiceActions,
    SodaScsServiceRequestInterface $portainerServiceActions,
    SodaScsServiceActionsInterface $sqlServiceActions,

  ) {
    // Services from container.
    $this->settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
    $this->dockerVolumesServiceActions = $dockerVolumesServiceActions;
    $this->openGdbServiceActions = $openGdbServiceActions;
    $this->portainerServiceActions = $portainerServiceActions;
    $this->sqlServiceActions = $sqlServiceActions;
  }

  /**
   * Drupal instance health check.
   */
  public function drupalHealthCheck(string $machineName) {
    try {
      $route = 'https://' . $machineName . '.' . $this->settings->get('scsHost') . $this->settings->get('wisski')['instances']['healthCheck']['url'];
      $response = $this->httpClient->request('get', $route);
      if ($response->getStatusCode() == 200) {
        // Request successful, handle the data in $response->data.
        return [
          "message" => "Component health check is available.",
          'code' => $response->getStatusCode(),
          'success' => TRUE,
        ];
      }
      else {
        // Request failed, handle the error.
        return [
          "message" => 'Component health check is not available: ' . $response->getStatusCode(),
          'code' => $response->getStatusCode(),
          'success' => FALSE,
        ];
      }
    }
    catch (\Exception $e) {
      return [
        "message" => 'Component health check is not available: ' . $e->getMessage(),
        'code' => $e->getCode(),
        'success' => FALSE,
      ];
    }
  }

  
  /**
   * Check filesystem health.
   */
  public function checkFilesystemHealth(string $machineName) {

    # Check if Portainer service is available.
    try {
      $requestParams = [
        'machineName' => $machineName,
      ];
      $healthRequest = $this->portainerServiceActions->buildHealthCheckRequest($requestParams);
      $healthRequestResult = $this->portainerServiceActions->makeRequest($healthRequest);
      if ($healthRequestResult['statusCode'] != 200) {
        return [
          "message" => 'Portainer is not available.',
          'code' => $healthRequestResult['statusCode'],
          'data' => $healthRequestResult['data'],
          'success' => FALSE,
          'error' => $healthRequestResult['error'],
        ];
      } 
    }
    catch (\Exception $e) {
      return [
        "message" => 'Portainer is not available.',
        'code' => $e->getCode(),
        'success' => FALSE,
        'data' => $e,
        'error' => $e->getMessage(),
      ];
    }

    try {
      $requestParams = [
        'machineName' => $machineName,
      ];
      $healthRequest = $this->dockerVolumesServiceActions->buildHealthCheckRequest($requestParams);
      $healthRequestResult = $this->dockerVolumesServiceActions->makeRequest($healthRequest);
      if ($healthRequestResult['statusCode'] != 200) {
        return [
          "message" => 'Filesystem is not available.',
          'code' => $healthRequestResult['statusCode'],
          'data' => $healthRequestResult['data'],
          'success' => FALSE,
          'error' => $healthRequestResult['error'],
        ];
      } else {
        return [
          "message" => $this->t("Filesystem is available.", ),
          'code' => $healthRequestResult['statusCode'],
          'data' => $healthRequestResult['data'],
          'success' => TRUE,
          'error' => '',
        ];
      }
    }
    catch (\Exception $e) {
      return [
        "message" => 'Filesystem is not available.',
        'code' => $e->getCode(),
        'success' => FALSE,
        'data' => $e,
        'error' => $e->getMessage(),
      ];
    }
  }


  /**
   * Check SQL database health.
   */
  public function checkSqlHealth(int $componentId) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
    $component = $this->entityTypeManager->getStorage('soda_scs_component')->load($componentId);
    $dbName = $component->get('machineName')->value;
    $dbUser = $component->getOwner()->getDisplayName();
    $sqlServiceKeyId = $component->get('serviceKey')->target_id;
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $sqlServiceKey */
    $sqlServiceKey = $this->entityTypeManager->getStorage('soda_scs_service_key')->load($sqlServiceKeyId);
    $dbUserPassword = $sqlServiceKey->get('servicePassword')->value;
    $fullAccess = $this->sqlServiceActions->userHasReadWriteAccessToDatabase($dbUser, $dbName, $dbUserPassword);
    $iddd = $component->id();
    if (!$fullAccess) {
      return [
        'message' => $this->t("MariaDB health check failed for component @component.", ['@component' => $component->id()]),
        'success' => FALSE,
      ];
    }
    return [
      'message' => $this->t("MariaDB health check passed for component @component.", ['@component' => $component->id()]),
      'success' => TRUE,
    ];
    }

  public function checkTriplestoreHealth(string $component, string $machineName) {
    try {
      $healthCheckRoutePart = str_replace('{repositoryID}', $machineName, $this->settings->get('triplestore')['repositories']['healthCheck']['url']);
      $route = 'https://' . $this->settings->get('triplestore')['openGdbSettings']['host'] . $healthCheckRoutePart;
      $requestParams = [
        'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($this->settings->get('triplestore')['openGdbSettings']['adminUsername'] . ':' . $this->settings->get('triplestore')['openGdbSettings']['adminPassword']),
      ],
      ];
      $response = $this->httpClient->request('GET', $route, $requestParams);
      if ($response->getStatusCode() == 200) {
        return [
          'message' => "Triplestore is healthy for component $component.",
          'success' => TRUE,
        ];
      }
    }
    catch (\Exception $e) {
      return [
        'message' => $this->t("Triplestore health check failed for component @component: @error", ['@component' => $component, '@error' => $e->getMessage()]),
        'success' => FALSE,
      ];
    }
  }

  /**
   * Initialize docker volumes service settings.
   *
   * @return array
   *   The docker volumes service settings.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initDockerVolumesServiceSettings() {
    $dockerVolumeSettings = [];
    $dockerVolumeSettings['portainerHostRoute'] = $this->settings->get('portainer.portainerOptions.host');
    $dockerVolumeSettings['portainerAuthenticationTokenRoute'] = $this->settings->get('portainer.portainerOptions.authenticationToken');
    $dockerVolumeSettings['portainerEndpointId'] = $this->settings->get('portainer.portainerOptions.endpointId');
    $dockerVolumeSettings['portainerEndpointsBaseUrlRoute'] = $this->settings->get('portainer.routes.endpoints.baseUrl');
    $dockerVolumeSettings['portainerEndpointsBaseUrlRoute'] = $this->settings->get('portainer.routes.endpoints.baseUrl');
    $dockerVolumeSettings['dockerApiBaseUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.baseUrl');
    $dockerVolumeSettings['dockerApiVolumesBaseUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.baseUrl');
    $dockerVolumeSettings['dockerApiVolumesCreateUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.crud.createUrl');

    if (empty($portainerHostRoute)) {
      throw new MissingDataException('Portainer host setting is not set.');
    }
    if (empty($portainerAuthenticationTokenRoute)) {
      throw new MissingDataException('Portainer authentication token setting is not set.');
    }
    if (empty($portainerEndpointId)) {
      throw new MissingDataException('Portainer endpoint setting is not set.');
    }
    if (empty($portainerEndpointsBaseUrlRoute)) {
      throw new MissingDataException('Portainer endpoints base URL setting is not set.');
    }
    if (empty($dockerApiBaseUrlRoute)) {
      throw new MissingDataException('Docker API URL setting is not set.');
    }
    if (empty($dockerApiVolumesBaseUrlRoute)) {
      throw new MissingDataException('Docker API volumes URL setting is not set.');
    }
    if (empty($dockerApiVolumesCreateUrlRoute)) {
      throw new MissingDataException('Docker API volumes create URL setting is not set.');
    }

    return $dockerVolumeSettings;
  }
}
