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
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles the communication with the SCS user manager daemon.
 */
#[Autowire(service: 'soda_scs_manager.docker_volumes_service.actions')]
class SodaScsDockerVolumesServiceActions implements SodaScsServiceRequestInterface {

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
   * Build token request.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildTokenRequest($requestParams = []): array {
    return [];
  }

  /**
   * Portainer instance health check.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildHealthCheckRequest(array $requestParams): array {
    $healthRequest = $this->buildGetRequest($requestParams);
    return $healthRequest;
  }

  /**
   * Builds the create volume request for the Portainer service API.
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
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerVolumeServiceSettings = $this->sodaScsServiceHelpers->initDockerVolumesServiceSettings();

    $route = $portainerServiceSettings['host'] . $portainerServiceSettings['baseUrl'] . str_replace('{endpointId}', $portainerServiceSettings['portainerEndpointId'], $dockerApiSettings['baseUrl']) . $dockerVolumeServiceSettings['baseUrl'] . $dockerVolumeServiceSettings['createUrl'];
    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['portainerAuthenticationToken'],
      ],
      'body' => json_encode(
        [
          'Name' => $requestParams['machineName'],
          'Driver' => 'local',
          'labels' => [
            'com.docker.compose.project' => $requestParams['project'],
            'com.docker.compose.volume' => $requestParams['machineName'],
          ],
        ]
      ),
    ];
  }

  /**
   * Builds the delete volume request for the Portainer service API.
   *
   * @param array $requestParams
   *   The request params.
   *
   * @return array
   *   The request array.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildDeleteRequest(array $requestParams): array {
    // Init settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerVolumeServiceSettings = $this->sodaScsServiceHelpers->initDockerVolumesServiceSettings();

    // Construct route.
    $route = $portainerServiceSettings['host'] . $portainerServiceSettings['baseUrl'] . str_replace('{endpointId}', $portainerServiceSettings['portainerEndpointId'], $dockerApiSettings['baseUrl']) . $dockerVolumeServiceSettings['baseUrl'] . str_replace('{volumeId}', urlencode($requestParams['machineName']), $dockerVolumeServiceSettings['deleteUrl']);

    return [
      'method' => 'DELETE',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['portainerAuthenticationToken'],
      ],
    ];
  }

  /**
   * Builds the inspect volume request for the Portainer service API.
   *
   * @param array $requestParams
   *   The name of the volume to inspect.
   *
   * @return array
   *   The request array.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildGetRequest(array $requestParams): array {
    // Init settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerVolumeServiceSettings = $this->sodaScsServiceHelpers->initDockerVolumesServiceSettings();

    // Set route params.
    $requestParams['routeParams']['endpointId'] = $portainerServiceSettings['endpointId'];
    // Construct route.
    $route =
    // Host route.
    $portainerServiceSettings['host'] .
    // Base URL.
    $portainerServiceSettings['baseUrl'] . $dockerApiSettings['baseUrl'] . $dockerVolumeServiceSettings['baseUrl'] .
    // Read one URL.
    $dockerVolumeServiceSettings['readOneUrl'];

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
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
      ],
    ];
  }

  /**
   * Builds the list volumes request for the Portainer service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildGetAllRequest(array $requestParams): array {
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerVolumeServiceSettings = $this->sodaScsServiceHelpers->initDockerVolumesServiceSettings();

    $route = $portainerServiceSettings['host'] . $portainerServiceSettings['baseUrl'] . str_replace('{endpointId}', $portainerServiceSettings['portainerEndpointId'], $dockerApiSettings['baseUrl']) . $dockerVolumeServiceSettings['baseUrl'] . $dockerVolumeServiceSettings['readUrl'];
    return [
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $this->settings->get('wisski')['portainerOptions']['authenticationToken'],
      ],
    ];
  }

  /**
   * Build update request.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildUpdateRequest(array $requestParams): array {
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerVolumeServiceSettings = $this->sodaScsServiceHelpers->initDockerVolumesServiceSettings();

    $route = $portainerServiceSettings['host'] . $portainerServiceSettings['baseUrl'] . str_replace('{endpointId}', $portainerServiceSettings['portainerEndpointId'], $dockerApiSettings['baseUrl']) . $dockerVolumeServiceSettings['baseUrl'] . str_replace('{volumeId}', urlencode($requestParams['machineName']), $dockerVolumeServiceSettings['updateUrl']);
    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['portainerAuthenticationToken'],
      ],
      'body' => json_encode(
        [
          'Name' => $requestParams['machineName'],
          'Driver' => 'local',
          'DriverOpts' => $requestParams['driverOpts'],
          'labels' => [
            'com.docker.compose.volume' => $requestParams['machineName'],
          ],
        ]
      ),
    ];
  }

}
