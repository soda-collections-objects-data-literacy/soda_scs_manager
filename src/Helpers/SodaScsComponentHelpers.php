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
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectStatus;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
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
   * Looks up the project an application belongs to.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers
   */
  protected SodaScsProjectApplicationHelpers $projectApplicationHelpers;

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
    #[Autowire(service: 'soda_scs_manager.project_application.helpers')]
    SodaScsProjectApplicationHelpers $projectApplicationHelpers,
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
    $this->projectApplicationHelpers = $projectApplicationHelpers;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Drupal instance health check.
   *
   * 1. Inspect Docker container state (stopped, problem, unknown, …).
   * 2. Only when the container is running, probe Drupal HTTP reachability.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The health check result.
   *
   * @todo Return a SodaScsResult object.
   */
  public function drupalHealthCheck(SodaScsComponentInterface $component) {
    try {
      $inspectContainerResponse = $this->sodaScsContainerHelpers->inspectComponentContainer($component);
    }
    catch (\Exception $e) {
      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::UNKNOWN,
        (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::UNKNOWN),
        $e->getMessage(),
        (int) $e->getCode(),
      );
    }

    if (!$inspectContainerResponse->success) {
      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::UNKNOWN,
        (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::UNKNOWN),
        (string) $inspectContainerResponse->error,
        404,
      );
    }

    $containerId = (string) ($component->get('containerId')->value ?? '');

    $inspectPayload = $inspectContainerResponse->data ?? [];
    $resolvedContainerId = is_array($inspectPayload) && $inspectPayload !== []
      ? (string) array_key_first($inspectPayload)
      : $containerId;
    $containerState = is_array($inspectPayload[$resolvedContainerId]['State'] ?? NULL)
      ? $inspectPayload[$resolvedContainerId]['State']
      : [];
    $containerHealth = SodaScsHealthStatus::fromDockerInspect($containerState);
    if ($containerHealth !== SodaScsHealthStatus::RUNNING) {
      return $this->presentContainerHealth($component, $containerHealth);
    }

    try {
      $requestParams = [
        'machineName' => $component->get('machineName')->value,
        'type' => 'instance',
      ];
      $healthRequest = $this->sodaScsPortainerServiceActions->buildHealthCheckRequest($requestParams);
      $healthRequestResult = $this->sodaScsPortainerServiceActions->makeRequest($healthRequest);

      $statusCode = (int) ($healthRequestResult['statusCode'] ?? 0);
      $err = (string) ($healthRequestResult['error'] ?? '');
      if ($statusCode === 0 && $this->httpFailureLooksLikeTlsPeerIssue($err)) {
        return SodaScsHealthStatus::payload(
          SodaScsHealthStatus::PROBLEM,
          (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::PROBLEM),
          (string) $this->t('TLS certificate error'),
          $statusCode,
        );
      }

      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::fromHttpCode($statusCode, TRUE),
        NULL,
        $err,
        $statusCode,
      );
    }
    catch (\Exception $e) {
      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::PROBLEM,
        (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::PROBLEM),
        $e->getMessage(),
        (int) $e->getCode(),
      );
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
    if ($component->hasField('containerId')) {
      $inspectContainerResponse = $this->sodaScsContainerHelpers->inspectComponentContainer($component);
      if ($inspectContainerResponse->success) {
        $inspectPayload = $inspectContainerResponse->data ?? [];
        $resolvedContainerId = is_array($inspectPayload) && $inspectPayload !== []
          ? (string) array_key_first($inspectPayload)
          : (string) ($component->get('containerId')->value ?? '');
        $containerState = is_array($inspectPayload[$resolvedContainerId]['State'] ?? NULL)
          ? $inspectPayload[$resolvedContainerId]['State']
          : [];
        $containerHealth = SodaScsHealthStatus::fromDockerInspect($containerState);
        if ($containerHealth !== SodaScsHealthStatus::RUNNING) {
          return SodaScsHealthStatus::payload($containerHealth);
        }
      }
    }

    $fullAccess = $this->sodaScsSqlServiceActions->userHasReadWriteAccessToDatabase($dbUser, $dbName, $dbUserPassword);
    if (!$fullAccess) {
      return SodaScsHealthStatus::payload(SodaScsHealthStatus::PROBLEM);
    }
    return SodaScsHealthStatus::payload(SodaScsHealthStatus::RUNNING);
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
      $statusCode = (int) ($healthCheckResult['statusCode'] ?? 0);
      if ($statusCode !== 200) {
        return SodaScsHealthStatus::payload(
          SodaScsHealthStatus::fromHttpCode($statusCode),
          NULL,
          (string) ($healthCheckResult['error'] ?? ''),
          $statusCode,
        );
      }
      return SodaScsHealthStatus::payload(SodaScsHealthStatus::RUNNING, NULL, '', $statusCode);
    }
    catch (\Exception $e) {
      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::UNKNOWN,
        (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::UNKNOWN),
        $e->getMessage(),
        (int) $e->getCode(),
      );
    }
  }

  /**
   * Check WebProtege health.
   *
   * Performs an HTTP GET request to the WebProtege host URL to verify
   * the service is reachable and responding.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The health check result.
   */
  public function checkWebprotegeHealth(SodaScsComponentInterface $component) {
    try {
      $webprotegeSettings = $this->sodaScsServiceHelpers->initWebprotegeInstanceSettings();
      $url = $webprotegeSettings['host'];

      if (empty($url)) {
        return SodaScsHealthStatus::payload(
          SodaScsHealthStatus::UNKNOWN,
          (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::UNKNOWN),
          (string) $this->t('WebProtege host is not configured in settings.'),
          500,
        );
      }

      // Ensure the URL has a scheme.
      if (!preg_match('#^https?://#', $url)) {
        $url = 'https://' . $url;
      }

      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 5,
        'connect_timeout' => 3,
        'http_errors' => FALSE,
      ]);

      $statusCode = $response->getStatusCode();
      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::fromHttpCode($statusCode),
        NULL,
        $statusCode >= 200 && $statusCode < 400
          ? ''
          : (string) $this->t('Service returned HTTP @code.', ['@code' => (string) $statusCode]),
        $statusCode,
      );
    }
    catch (\Exception $e) {
      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::UNKNOWN,
        (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::UNKNOWN),
        $e->getMessage(),
        (int) $e->getCode(),
      );
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

  /**
   * Whether the component's project is still being created or reset.
   *
   * Demo restore stops WissKI on purpose. The container is exited for that
   * stretch, but the application is still being set up.
   */
  private function demoSetupInProgress(SodaScsComponentInterface $component): bool {
    $project = $this->projectApplicationHelpers->loadPartOfProject($component);
    if (
      !$project instanceof SodaScsProjectInterface
      || !$project->hasField('demoStatus')
      || $project->get('demoStatus')->isEmpty()
    ) {
      return FALSE;
    }
    return SodaScsDemoProjectStatus::tryFrom((string) $project->get('demoStatus')->value)?->isBusy() ?? FALSE;
  }

  /**
   * Detects TLS/certificate verification failures.
   *
   * Mirrors criteria used for Portainer health-check TLS retry.
   */
  private function httpFailureLooksLikeTlsPeerIssue(string $error): bool {
    if ($error === '') {
      return FALSE;
    }
    $lower = strtolower($error);
    $markers = [
      'ssl certificate problem',
      'ssl: ',
      'ssl operation failed',
      'certificate verify failed',
      'unable to verify the first certificate',
      'self signed certificate',
      'self-signed certificate',
      'hostname does not match',
      'does not match certificate',
      'certificate has expired',
      'unable to get local issuer certificate',
      "peer's certificate",
      'peers certificate',
      'curl error 60',
      'curl error 51',
      'curl error 58',
      'local issuer certificate',
      'handshake failure',
      'wrong version number',
    ];
    foreach ($markers as $marker) {
      if (str_contains($lower, $marker)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reports container health, keeping demo setup on starting.
   *
   * @return array
   *   The health check result.
   */
  private function presentContainerHealth(SodaScsComponentInterface $component, string $containerHealth): array {
    $setupInProgress = $containerHealth === SodaScsHealthStatus::STOPPED
      && $this->demoSetupInProgress($component);
    return SodaScsHealthStatus::payload(
      SodaScsHealthStatus::duringSetup($containerHealth, $setupInProgress),
      NULL,
      '',
      $setupInProgress ? 202 : 200,
    );
  }

}
