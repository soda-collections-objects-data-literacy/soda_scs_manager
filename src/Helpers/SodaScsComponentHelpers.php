<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use GuzzleHttp\ClientInterface;

/**
 * Helper functions for SCS components.
 *
 * @todo Use health check functions from the service actions.
 */
class SodaScsComponentHelpers {
  use StringTranslationTrait;

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
  protected SodaScsServiceRequestInterface $sodaScsOpenGdbServiceActions;

  /**
   * The Portainer service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsPortainerServiceActions;

  /**
   * The settings.
   *
   * @var \Drupal\Core\Config
   */
  protected Config $settings;

  /**
   * The container helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers
   */
  protected SodaScsContainerHelpers $sodaScsContainerHelpers;

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
  protected SodaScsServiceActionsInterface $sodaScsSqlServiceActions;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    SodaScsContainerHelpers $sodaScsContainerHelpers,
    #[Autowire(service: 'soda_scs_manager.docker_exec_service.actions')]
    SodaScsExecRequestInterface $sodaScsDockerExecServiceActions,
    #[Autowire(service: 'soda_scs_manager.opengdb_service.actions')]
    SodaScsServiceRequestInterface $sodaScsOpenGdbServiceActions,
    #[Autowire(service: 'soda_scs_manager.portainer_service.actions')]
    SodaScsServiceRequestInterface $sodaScsPortainerServiceActions,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    #[Autowire(service: 'soda_scs_manager.sql_service.actions')]
    SodaScsServiceActionsInterface $sodaScsSqlServiceActions,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->settings = $configFactory->getEditable('soda_scs_manager.settings');
    $this->sodaScsContainerHelpers = $sodaScsContainerHelpers;
    $this->sodaScsDockerExecServiceActions = $sodaScsDockerExecServiceActions;
    $this->sodaScsOpenGdbServiceActions = $sodaScsOpenGdbServiceActions;
    $this->sodaScsPortainerServiceActions = $sodaScsPortainerServiceActions;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Drupal instance health check.
   */
  public function drupalHealthCheck(SodaScsComponentInterface $component) {
    try {
      $inspectContainerResponse = $this->sodaScsContainerHelpers->inspectContainer($component->get('containerId')->value);
    }
    catch (\Exception $e) {
      return [
        "message" => 'Component is not available.',
        'code' => $e->getCode(),
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    if (!$inspectContainerResponse->success) {
      return [
        "message" => 'Component is not available.',
        'code' => 404,
        'success' => FALSE,
        'error' => $inspectContainerResponse->error,
      ];
    }

    $containerStatus = $inspectContainerResponse->data[$component->get('containerId')->value]['State']['Status'];

    if ($containerStatus != 'running') {
      return [
        "message" => (string) $this->t('Application status: @status', ['@status' => $containerStatus]),
        "status" => $containerStatus,
        'code' => 200,
        'success' => TRUE,
        'error' => '',
      ];
    }

    try {
      $requestParams = [
        'type' => 'instance',
        'machineName' => $component->get('machineName')->value,
      ];
      $healthRequest = $this->sodaScsPortainerServiceActions->buildHealthCheckRequest($requestParams);
      $healthRequestResult = $this->sodaScsPortainerServiceActions->makeRequest($healthRequest);

      switch ($healthRequestResult['statusCode']) {
        case 200:
          return [
            "message" => 'Available.',
            "status" => 'running',
            'code' => $healthRequestResult['statusCode'],
            'success' => TRUE,
            'error' => '',
          ];

        case 502:
          return [
            "message" => 'Starting',
            "status" => 'starting',
            'code' => $healthRequestResult['statusCode'],
            'success' => FALSE,
            'error' => $healthRequestResult['error'],
          ];

        case 0:
          if ($containerStatus == 'running') {
            return [
              "message" => 'Starting',
              "status" => 'starting',
              'code' => $healthRequestResult['statusCode'],
              'success' => FALSE,
              'error' => $healthRequestResult['error'],
            ];
          }
          else {
            return [
              "message" => 'Stopped',
              "status" => 'stopped',
              'code' => $healthRequestResult['statusCode'],
              'success' => FALSE,
              'error' => $healthRequestResult['error'],
            ];
          }

        default:
          return [
            "message" => 'Not available',
            "status" => 'unknown',
            'code' => $healthRequestResult['statusCode'],
            'success' => FALSE,
            'error' => $healthRequestResult['error'],
          ];
      }
    }
    catch (\Exception $e) {
      return [
        "message" => 'Not available',
        "status" => 'unknown',
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

    $accessProxycmd = [
      'ls',
      '-l',
      '/shared/' . $machineName,
    ];

    $requestParams = [
      'containerName' => 'access-proxy',
      'cmd' => $accessProxycmd,
      'user' => 'root',
    ];

    // Check if Portainer service is available.
    try {

      $execCreateRequest = $this->sodaScsDockerExecServiceActions->buildCreateRequest($requestParams);
      $execCreateResult = $this->sodaScsDockerExecServiceActions->makeRequest($execCreateRequest);

      if ($execCreateResult['statusCode'] != 201) {
        return [
          "message" => 'Access proxy is not available',
          'code' => $execCreateResult['statusCode'],
          'data' => $execCreateResult['data'],
          'success' => FALSE,
          'error' => $execCreateResult['error'],
        ];
      }
      $execCreateResultData = json_decode($execCreateResult['data']['portainerResponse']->getBody()->getContents(), TRUE);
      $execStartRequest = $this->sodaScsDockerExecServiceActions->buildStartRequest(['execId' => $execCreateResultData['Id']]);
      $execStartResult = $this->sodaScsDockerExecServiceActions->makeRequest($execStartRequest);      if ($execStartResult['statusCode'] != 200) {
        return [
          "message" => 'Access proxy is not available',
          'code' => $execStartResult['statusCode'],
          'data' => $execStartResult['data'],
          'success' => FALSE,
          'error' => $execStartResult['error'],
        ];
      }
      // Inspect the exec to retrieve the command exit code.
      $execInspectRequest = $this->sodaScsDockerExecServiceActions->buildInspectRequest([
        'routeParams' => [
          'execId' => $execCreateResultData['Id'],
        ],
      ]);
      $execInspectResult = $this->sodaScsDockerExecServiceActions->makeRequest($execInspectRequest);
      if ($execInspectResult['statusCode'] != 200) {
        return [
          "message" => 'Access proxy exec inspect failed',
          'code' => $execInspectResult['statusCode'],
          'data' => $execInspectResult['data'],
          'success' => FALSE,
          'error' => $execInspectResult['error'],
        ];
      }
      $execInspectData = json_decode($execInspectResult['data']['portainerResponse']->getBody()->getContents(), TRUE);
      $exitCode = $execInspectData['ExitCode'] ?? NULL;
      return [
        'message' => $exitCode === 0 ? 'Filesystem available.' : 'Filesystem not available.',
        'code' => $execInspectResult['statusCode'],
        'success' => $exitCode === 0,
        'error' => $exitCode === 0 ? '' : 'Command exit code: ' . (string) $exitCode,
        'exitCode' => $exitCode,
      ];
    }
    catch (\Exception $e) {
      return [
        "message" => 'Access proxy is not available',
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
    $fullAccess = $this->sodaScsSqlServiceActions->userHasReadWriteAccessToDatabase($dbUser, $dbName, $dbUserPassword);
    if (!$fullAccess) {
      return [
        'message' => $this->t("MariaDB health check failed for component @component.", ['@component' => $component->id()]),
        'status' => 'unhealthy',
        'success' => FALSE,
      ];
    }
    return [
      'message' => $this->t("MariaDB health check passed for component @component.", ['@component' => $component->id()]),
      'status' => 'healthy',
      'success' => TRUE,
    ];
  }

  /**
   * Get the checksum of a file.
   */
  public function getChecksum(string $path) {
    return hash_file('sha256', $path);
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
      $healthCheckRequest = $this->sodaScsOpenGdbServiceActions->buildHealthCheckRequest($requestParams);
      $healthCheckResult = $this->sodaScsOpenGdbServiceActions->makeRequest($healthCheckRequest);
      if ($healthCheckResult['statusCode'] != 200) {
        return [
          'message' => $this->t("Triplestore health check failed for component @component: @error", [
            '@component' => $component,
            '@error' => $healthCheckResult['error'],
          ]),
          'status' => 'unknown',
          'success' => FALSE,
          'error' => $healthCheckResult['error'],
          'data' => $healthCheckResult['data'],
        ];
      }
      return [
        'message' => $this->t("Triplestore is healthy for component @component.", [
          '@component' => $component,
        ]),
        'status' => 'healthy',
        'success' => TRUE,
      ];
    }
    catch (\Exception $e) {
      return [
        'message' => $this->t("Triplestore health check failed for component @component: @error", [
          '@component' => $component,
          '@error' => $e->getMessage(),
        ]),
        'status' => 'unknown',
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
   * Resolves linked SQL and triplestore components from an entity.
   *
   * Returns an array with keys: 'sql', 'triplestore', and 'dbName'.
   * Values are NULL or strings if not present.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS entity to inspect.
   *
   * @return array
   *   Resolved components and database name with keys:
   *   - sql:
   *     \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null.
   *   - triplestore:
   *     \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null.
   *   - dbName: string.
   */
  public function resolveConnectedComponents(SodaScsComponentInterface $entity): array {
    $sqlComponent = NULL;
    $triplestoreComponent = NULL;
    try {
      /** @var \Drupal\Core\Field\EntityReferenceFieldItemList|null $connectedComponents */
      $connectedComponents = $entity->get('connectedComponents');
      if ($connectedComponents) {
        $connectedComponentEntities = $connectedComponents->referencedEntities();
        foreach ($connectedComponentEntities as $connectedComponent) {
          /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $connectedComponent */
          $bundle = $connectedComponent->bundle();
          if ($bundle === 'soda_scs_sql_component') {
            $sqlComponent = $connectedComponent;
          }
          elseif ($bundle === 'soda_scs_triplestore_component') {
            $triplestoreComponent = $connectedComponent;
          }
        }
      }
    }
    catch (MissingDataException $e) {
      // Ignore missing data; return nulls for unresolved components.
    }

    return [
      'sql' => $sqlComponent,
      'triplestore' => $triplestoreComponent,
    ];
  }

}
