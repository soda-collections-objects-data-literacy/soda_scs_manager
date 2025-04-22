<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;

/**
 * Helper functions for SCS components.
 *
 * @todo Use health check functions from the service actions.
 */
class SodaScsComponentHelpers {
  use StringTranslationTrait;

  /**
   * The Docker Volumes service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $dockerVolumesServiceActions;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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
   * The settings.
   *
   * @var \Drupal\Core\Config
   */
  protected Config $settings;

  /**
   * The Soda SCS Docker exec service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface
   */
  protected SodaScsExecRequestInterface $sodaScsDockerExecServiceActions;

  /**
   * The Soda SCS service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  /**
   * The SQL service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface
   */
  protected SodaScsServiceActionsInterface $sqlServiceActions;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    SodaScsServiceRequestInterface $dockerVolumesServiceActions,
    EntityTypeManagerInterface $entityTypeManager,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsServiceRequestInterface $openGdbServiceActions,
    SodaScsServiceRequestInterface $portainerServiceActions,
    SodaScsExecRequestInterface $sodaScsDockerExecServiceActions,
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    SodaScsServiceActionsInterface $sqlServiceActions,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->dockerVolumesServiceActions = $dockerVolumesServiceActions;
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->openGdbServiceActions = $openGdbServiceActions;
    $this->portainerServiceActions = $portainerServiceActions;
    $this->settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->sodaScsDockerExecServiceActions = $sodaScsDockerExecServiceActions;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
    $this->sqlServiceActions = $sqlServiceActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Drupal instance health check.
   */
  public function drupalHealthCheck(string $machineName) {
    try {
      $requestParams = [
        'type' => 'instance',
        'machineName' => $machineName,
      ];
      $healthRequest = $this->portainerServiceActions->buildHealthCheckRequest($requestParams);
      $healthRequestResult = $this->portainerServiceActions->makeRequest($healthRequest);

      switch ($healthRequestResult['statusCode']) {
        case 200:
          return [
            "message" => 'Component is available.',
            'code' => $healthRequestResult['statusCode'],
            'success' => TRUE,
            'error' => '',
          ];

        case 502:
          return [
            "message" => 'Component not (yet) available.',
            'code' => $healthRequestResult['statusCode'],
            'success' => FALSE,
            'error' => $healthRequestResult['error'],
          ];

        default:
          return [
            "message" => 'Component is not available: ' . $healthRequestResult['statusCode'],
            'code' => $healthRequestResult['statusCode'],
            'success' => FALSE,
            'error' => $healthRequestResult['error'],
          ];
      }
    }
    catch (\Exception $e) {
      return [
        "message" => 'Component is not available: ' . $e->getMessage(),
        'code' => $e->getCode(),
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Check filesystem health.
   */
  public function checkFilesystemHealth(string $machineName) {

    // Check if Portainer service is available.
    try {
      $requestParams = [
        'machineName' => $machineName,
        'type' => 'service',
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
        'type' => 'instance',
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
      }
      else {
        return [
          "message" => $this->t("Filesystem is available."),
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

  /**
   * Check triplestore health.
   *
   * @param string $component
   *   The component ID.
   * @param string $machineName
   *   The machine name.
   *
   * @return array
   *   The health check result.
   */
  public function checkTriplestoreHealth(string $component, string $machineName) {
    try {
      $requestParams = [
        'type' => 'repository',
        'routeParams' => [
          'machineName' => $machineName,
        ],
      ];
      $healthCheckRequest = $this->openGdbServiceActions->buildHealthCheckRequest($requestParams);
      $healthCheckResult = $this->openGdbServiceActions->makeRequest($healthCheckRequest);
      if ($healthCheckResult['statusCode'] != 200) {
        return [
          'message' => $this->t("Triplestore health check failed for component @component: @error", [
            '@component' => $component,
            '@error' => $healthCheckResult['error'],
          ]),
          'success' => FALSE,
          'error' => $healthCheckResult['error'],
          'data' => $healthCheckResult['data'],
        ];
      }
      return [
        'message' => $this->t("Triplestore is healthy for component @component.", [
          '@component' => $component,
        ]),
        'success' => TRUE,
      ];
    }
    catch (\Exception $e) {
      return [
        'message' => $this->t("Triplestore health check failed for component @component: @error", [
          '@component' => $component,
          '@error' => $e->getMessage(),
        ]),
        'success' => FALSE,
        'error' => $e->getMessage(),
        'data' => $e,
      ];
    }
  }

  /**
   * Create secret key.
   */
  public function createSecret() {
    return bin2hex(random_bytes(16));
  }

  /**
   * Create FS dir via access-proxy container.
   *
   * @param string $path
   *   The path to create.
   *
   * @return array{
   *   message: string,
   *   success: bool,
   *   error: string,
   *   data: array,
   *   statusCode: int,
   *   }
   *   The result of the operation.
   */
  public function createDir(string $path) {
    try {
      $dirCreateExecRequest = $this->sodaScsDockerExecServiceActions->buildCreateRequest([
        'containerName' => 'access-proxy',
        'user' => '33',
        'cmd' => [
          'mkdir',
          '-p',
          $path,
        ],
      ]);

      $dirCreateExecResponse = $this->sodaScsDockerExecServiceActions->makeRequest($dirCreateExecRequest);

      if (!$dirCreateExecResponse['success']) {
        return $dirCreateExecResponse;
      }

      $dirCreateExecId = json_decode($dirCreateExecResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];

      $dirCreateStartExecRequest = $this->sodaScsDockerExecServiceActions->buildStartRequest([
        'execId' => $dirCreateExecId,
      ]);

      $dirCreateStartExecResponse = $this->sodaScsDockerExecServiceActions->makeRequest($dirCreateStartExecRequest);

      if (!$dirCreateStartExecResponse['success']) {
        return $dirCreateStartExecResponse;
      }

      return [
        'message' => $this->t("Directory created successfully."),
        'success' => TRUE,
        'error' => '',
        'data' => [],
        'statusCode' => 200,
      ];
    }
    catch (\Exception $e) {
      return [
        'message' => $this->t("Failed to create directory: @error", ['@error' => $e->getMessage()]),
        'success' => FALSE,
        'error' => $e->getMessage(),
        'data' => $e,
        'statusCode' => $e->getCode(),
      ];
    }
  }

}
