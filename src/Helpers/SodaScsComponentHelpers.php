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
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;


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

  /**
   * The Triplestore service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $triplestoreServiceActions;

  /**
   * The Wisski service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $wisskiServiceActions;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    ClientInterface $httpClient,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
    SodaScsServiceRequestInterface $dockerVolumesServiceActions,
    SodaScsServiceRequestInterface $portainerServiceActions,
    SodaScsServiceActionsInterface $sqlServiceActions,
    SodaScsServiceRequestInterface $triplestoreServiceActions,
    SodaScsServiceRequestInterface $wisskiServiceActions,

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
    $this->portainerServiceActions = $portainerServiceActions;
    $this->sqlServiceActions = $sqlServiceActions;
    $this->triplestoreServiceActions = $triplestoreServiceActions;
    $this->wisskiServiceActions = $wisskiServiceActions;
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
    try {
      $requestParams = [
        'machineName' => $machineName,
      ];
      $healthRequest = $this->dockerVolumesServiceActions->buildHealthCheckRequest($requestParams);
      $healthRequestResult = $this->dockerVolumesServiceActions->makeRequest($healthRequest);
      if ($healthRequestResult['code'] != 200) {
        return [
          "message" => 'Filesystem health check is not available: ' . $healthRequestResult['error'],
          'code' => $healthRequestResult['code'],
          'success' => FALSE,
        ];
      } else {
        return [
          "message" => $this->t("Filesystem health check is available.", ),
          'code' => 200,
          'success' => TRUE,
        ];
      }
    }
    catch (\Exception $e) {
      return [
        "message" => 'Filesystem health check is not available: ' . $e->getMessage(),
        'code' => $e->getCode(),
        'success' => FALSE,
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
      $route = 'https://' . $this->settings->get('triplestore')['openGdpSettings']['host'] . $healthCheckRoutePart;
      $requestParams = [
        'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($this->settings->get('triplestore')['openGdpSettings']['adminUsername'] . ':' . $this->settings->get('triplestore')['openGdpSettings']['adminPassword']),
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

}
