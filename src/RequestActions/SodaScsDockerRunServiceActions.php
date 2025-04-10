<?php

namespace Drupal\soda_scs_manager\RequestActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles Docker container run operations via Portainer API.
 */
class SodaScsDockerRunServiceActions implements SodaScsRunRequestInterface {

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
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The SCS component helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  /**
   * The SCS portainer actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsPortainerServiceActions;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface
   */
  protected SodaScsServiceActionsInterface $sodaScsSqlServiceActions;

  /**
   * The Twig renderer.
   *
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected TwigEnvironment $twig;

  /**
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    ClientInterface $httpClient,
    LanguageManagerInterface $languageManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    RequestStack $requestStack,
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    SodaScsServiceRequestInterface $sodaScsPortainerServiceActions,
    SodaScsServiceActionsInterface $sodaScsSqlServiceActions,
    TranslationInterface $stringTranslation,
    TwigEnvironment $twig,
  ) {
    // Services from container.
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->languageManager = $languageManager;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->requestStack = $requestStack;
    $this->settings = $settings;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
    $this->sodaScsPortainerServiceActions = $sodaScsPortainerServiceActions;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
    $this->stringTranslation = $stringTranslation;
    $this->twig = $twig;
  }

  /**
   * Make request.
   *
   * @param array $request
   *   The request array.
   *
   * @return array
   *   The response array.
   */
  public function makeRequest($request): array {
    // Assemble requestParams.
    $requestParams['headers'] = $request['headers'];
    if (isset($request['body'])) {
      $requestParams['body'] = $request['body'];
    }
    if (isset($request['query'])) {
      $requestParams['query'] = $request['query'];
    }

    // Send the request.
    try {
      $response = $this->httpClient->request($request['method'], $request['route'], $requestParams);
      return [
        'message' => 'Request succeeded',
        'data' => [
          'portainerResponse' => $response,
        ],
        'success' => TRUE,
        'error' => '',
        'statusCode' => $response->getStatusCode(),
      ];
    }
    catch (\Exception $e) {
      return [
        'message' => 'Request failed',
        'data' => [
          'portainerResponse' => $e,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => $e->getCode(),
      ];
    }
  }

  /**
   * Builds the create container request for the Docker run API.
   *
   * @param array $requestParams
   *   The request params.
   *
   * @return array
   *   The request array.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildCreateRequest(array $requestParams): array {
    // Initialize settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerRunServiceSettings = $this->sodaScsServiceHelpers->initDockerRunServiceSettings();

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['portainerHostRoute'] .
      // /api/endpoints
      $portainerServiceSettings['portainerEndpointsBaseUrlRoute'] .
      // /{endpointId}/docker
      str_replace('{endpointId}', $portainerServiceSettings['portainerEndpointId'], $dockerApiSettings['baseUrl']) .
      // /containers/create
      $dockerRunServiceSettings['createUrl'];

    // Add name query parameter if specified.
    $query = [];
    if (!empty($requestParams['name'])) {
      $query['name'] = $requestParams['name'];
    }

    // Build container configuration.
    $containerConfig = [
      'Image' => $requestParams['image'],
      'Cmd' => $requestParams['cmd'] ?? NULL,
      'Entrypoint' => $requestParams['entrypoint'] ?? NULL,
      'Env' => $requestParams['env'] ?? [],
      'ExposedPorts' => $requestParams['exposedPorts'] ?? NULL,
      'Tty' => $requestParams['tty'] ?? FALSE,
      'OpenStdin' => $requestParams['openStdin'] ?? FALSE,
      'StdinOnce' => $requestParams['stdinOnce'] ?? FALSE,
      'WorkingDir' => $requestParams['workingDir'] ?? '',
      'Volumes' => $requestParams['volumes'] ?? NULL,
    ];

    // Host configuration if provided.
    if (!empty($requestParams['hostConfig'])) {
      $containerConfig['HostConfig'] = $requestParams['hostConfig'];
    }

    // Clean up null values.
    $containerConfig = array_filter($containerConfig, function ($value) {
      return $value !== NULL;
    });

    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['portainerAuthenticationToken'],
      ],
      'query' => !empty($query) ? $query : NULL,
      'body' => json_encode($containerConfig),
    ];
  }

  /**
   * Builds the start container request for the Docker container API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The start request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildStartRequest(array $requestParams): array {
    // Initialize settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerRunServiceSettings = $this->sodaScsServiceHelpers->initDockerRunServiceSettings();

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['portainerHostRoute'] .
      // /api/endpoints
      $portainerServiceSettings['portainerEndpointsBaseUrlRoute'] .
      // /{endpointId}/docker
      str_replace('{endpointId}', $portainerServiceSettings['portainerEndpointId'], $dockerApiSettings['baseUrl']) .
      // /containers/{containerId}/start
      str_replace('{containerId}', $requestParams['containerId'], $dockerRunServiceSettings['startUrl']);

    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['portainerAuthenticationToken'],
      ],
    ];
  }

  /**
   * Builds the stop container request for the Docker container API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The stop request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildStopRequest(array $requestParams): array {
    // Initialize settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerRunServiceSettings = $this->sodaScsServiceHelpers->initDockerRunServiceSettings();

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['portainerHostRoute'] .
      // /api/endpoints
      $portainerServiceSettings['portainerEndpointsBaseUrlRoute'] .
      // /{endpointId}/docker
      str_replace('{endpointId}', $portainerServiceSettings['portainerEndpointId'], $dockerApiSettings['baseUrl']) .
      // /containers/{containerId}/stop
      str_replace('{containerId}', $requestParams['containerId'], $dockerRunServiceSettings['stopUrl']);

    $query = [];
    if (isset($requestParams['timeout'])) {
      $query['t'] = $requestParams['timeout'];
    }

    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['portainerAuthenticationToken'],
      ],
      'query' => !empty($query) ? $query : NULL,
    ];
  }

  /**
   * Builds the inspect container request for the Docker container API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The inspect request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildInspectRequest(array $requestParams): array {
    // Initialize settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerRunServiceSettings = $this->sodaScsServiceHelpers->initDockerRunServiceSettings();

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['portainerHostRoute'] .
      // /api/endpoints
      $portainerServiceSettings['portainerEndpointsBaseUrlRoute'] .
      // /{endpointId}/docker
      str_replace('{endpointId}', $portainerServiceSettings['portainerEndpointId'], $dockerApiSettings['baseUrl']) .
      // /containers/{containerId}/json
      str_replace('{containerId}', $requestParams['containerId'], $dockerRunServiceSettings['inspectUrl']);

    return [
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['portainerAuthenticationToken'],
      ],
    ];
  }

  /**
   * Builds the remove container request for the Docker container API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The remove request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildRemoveRequest(array $requestParams): array {
    // Initialize settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerRunServiceSettings = $this->sodaScsServiceHelpers->initDockerRunServiceSettings();

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['portainerHostRoute'] .
      // /api/endpoints
      $portainerServiceSettings['portainerEndpointsBaseUrlRoute'] .
      // /{endpointId}/docker
      str_replace('{endpointId}', $portainerServiceSettings['portainerEndpointId'], $dockerApiSettings['baseUrl']) .
      // /containers/{containerId}
      str_replace('{containerId}', $requestParams['containerId'], $dockerRunServiceSettings['removeUrl']);

    $query = [
      'force' => $requestParams['force'] ?? FALSE,
      'v' => $requestParams['removeVolumes'] ?? FALSE,
    ];

    return [
      'method' => 'DELETE',
      'route' => $route,
      'headers' => [
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['portainerAuthenticationToken'],
      ],
      'query' => $query,
    ];
  }

}
