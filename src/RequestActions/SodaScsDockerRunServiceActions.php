<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\RequestActions;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 *
 * @todo Seperate container related requests from run
 * related requests maybe?
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
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    #[Autowire(service: 'soda_scs_manager.portainer_service.actions')]
    SodaScsServiceRequestInterface $sodaScsPortainerServiceActions,
    #[Autowire(service: 'soda_scs_manager.sql_service.actions')]
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
   * Builds the get all containers request for the Docker run API.
   *
   * @param array $requestParams
   *   The request params.
   *
   * @return array
   *   The request array.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildGetAllRequest(array $requestParams): array {
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerRunServiceSettings = $this->sodaScsServiceHelpers->initDockerRunServiceSettings();

    $requestParams['routeParams']['endpointId'] = $portainerServiceSettings['endpointId'];

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['host'] .
      // /api/endpoints
      $portainerServiceSettings['baseUrl'] .
      // /{endpointId}/docker
      $dockerApiSettings['baseUrl'] .
      // /containers
      $dockerRunServiceSettings['baseUrl'] .
      // /json
      $dockerRunServiceSettings['readAllUrl'];

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    // Add query parameters if they exist.
    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
      ],
    ];
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
      $portainerServiceSettings['host'] .
      // /api/endpoints
      $portainerServiceSettings['baseUrl'] .
      // /{endpointId}/docker
      str_replace('{endpointId}', $portainerServiceSettings['endpointId'], $dockerApiSettings['baseUrl']) .
      // /containers
      $dockerRunServiceSettings['baseUrl'] .
      // /create
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
      'User' => $requestParams['user'] ?? NULL,
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
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
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

    $requestParams['routeParams']['endpointId'] = $portainerServiceSettings['endpointId'];

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['host'] .
      // /api/endpoints
      $portainerServiceSettings['baseUrl'] .
      // /{endpointId}/docker
      $dockerApiSettings['baseUrl'] .
      // /containers
      $dockerRunServiceSettings['baseUrl'] .
      // /{containerId}/start
      $dockerRunServiceSettings['startUrl'];

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    // Add query parameters if they exist.
    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
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

    $requestParams['routeParams']['endpointId'] = $portainerServiceSettings['endpointId'];

    // Set timeout for request and container stop before force stop.
    $requestParams['timeout'] ??= 30;
    $requestParams['queryParams']['t'] = $requestParams['timeout'];

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['host'] .
      // /api/endpoints
      $portainerServiceSettings['baseUrl'] .
      // /{endpointId}/docker
      $dockerApiSettings['baseUrl'] .
      // /containers/
      $dockerRunServiceSettings['baseUrl'] .
      // /{containerId}/stop
      $dockerRunServiceSettings['stopUrl'];

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
      ],
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

    $requestParams['routeParams']['endpointId'] = $portainerServiceSettings['endpointId'];

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['host'] .
      // /api/endpoints
      $portainerServiceSettings['baseUrl'] .
      // /{endpointId}/docker
      $dockerApiSettings['baseUrl'] .
      // /containers
      $dockerRunServiceSettings['baseUrl'] .
      // /{containerId}/json
      $dockerRunServiceSettings['readOneUrl'];

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    // Add query parameters if they exist.
    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
      ],
    ];
  }

  /**
   * Builds the remove container request for the Docker container API.
   *
   * @param array $requestParams
   *   The request parameters.
   *   - array queryParams: The query parameters.
   *     - bool force: Force the removal of the container.
   *     - bool v: Remove volumes associated with the container.
   *     - bool link: Remove the specified link associated with the container.
   *   - array routeParams: The route parameters.
   *     - string containerId: The container ID.
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

    $requestParams['routeParams']['endpointId'] = $portainerServiceSettings['endpointId'];

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['host'] .
      // /api/endpoints
      $portainerServiceSettings['baseUrl'] .
      // /{endpointId}/docker
      $dockerApiSettings['baseUrl'] .
      // /containers/
      $dockerRunServiceSettings['baseUrl'] .
      // /{containerId}
      $dockerRunServiceSettings['removeUrl'];

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    // Add query parameters if they exist.
    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'method' => 'DELETE',
      'route' => $route,
      'headers' => [
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
      ],
    ];
  }

}
