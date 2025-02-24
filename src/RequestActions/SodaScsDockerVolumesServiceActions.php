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
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles the communication with the SCS user manager daemon.
 */
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
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface
   */
  protected SodaScsServiceActionsInterface $sodaScsMysqlServiceActions;


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
    SodaScsServiceActionsInterface $sodaScsMysqlServiceActions,
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
    $this->sodaScsMysqlServiceActions = $sodaScsMysqlServiceActions;
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
      $response = json_decode($response->getBody()->getContents(), TRUE);

      return [
        'message' => 'Request succeeded',
        'data' => [
          'portainerResponse' => $response,
        ],
        'success' => TRUE,
        'error' => '',
      ];
    }
    catch (ClientException $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Portainer request failed with code @code error: @error trace @trace", [
        '@code' => $e->getCode(),
        '@error' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
    }
    $this->messenger->addError($this->t("Portainer request failed. See logs for more details."));
    return [
      'message' => 'Request failed with code @code' . $e->getCode(),
      'data' => [
        'portainerResponse' => $e,
      ],
      'success' => FALSE,
      'error' => $e->getMessage(),
    ];
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
  public function buildTokenRequest(array $requestParams): array {
    return [];
  }

  /**
   * Portainer instance health check.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildHealthCheckRequest(array $requestParams): array {
    $health = $this->buildGetRequest($requestParams);

    return $health;
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
    if (empty($this->settings->get('wisski')['routes']['dockerApi']['url'])) {
      throw new MissingDataException('Docker API URL setting is not set.');
    }

    $route = 'https://' . $this->settings->get('wisski')['portainerOptions']['host'] . $this->settings->get('wisski')['routes']['dockerApi']['url'] . '/volumes/create';
    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $this->settings->get('wisski')['portainerOptions']['authenticationToken'],
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
   * @param string $volumeName
   *   The name of the volume to delete.
   *
   * @return array
   *   The request array.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildDeleteRequest(array $requestParams): array {
    if (empty($this->settings->get('wisski')['routes']['dockerApi']['url'])) {
      throw new MissingDataException('Docker API URL setting is not set.');
    }
    $route = 'https://' . $this->settings->get('wisski')['portainerOptions']['host'] . $this->settings->get('wisski')['routes']['dockerApi']['url'] . '/volumes/' . urlencode($requestParams['machineName']);
    return [
      'method' => 'DELETE',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $this->settings->get('wisski')['portainerOptions']['authenticationToken'],
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
    if (empty($this->settings->get('wisski')['routes']['dockerApi']['url'])) {
      throw new MissingDataException('Docker API URL setting is not set.');
    }
    $route = 'https://' . $this->settings->get('wisski')['portainerOptions']['host'] . $this->settings->get('wisski')['routes']['dockerApi']['url'] . '/volumes/' . urlencode($requestParams['machineName']);
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
    if (empty($this->settings->get('wisski')['routes']['dockerApi']['url'])) {
      throw new MissingDataException('Docker API URL setting is not set.');
    }
    $route = $this->settings->get('wisski')['routes']['dockerApi']['url'] . '/volumes';
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

  /** Build update request.
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
    if (empty($this->settings->get('wisski')['routes']['dockerApi']['url'])) {
      throw new MissingDataException('Docker API URL setting is not set.');
    }

    $route = $this->settings->get('wisski')['routes']['dockerApi']['url'] . '/volumes/' . urlencode($requestParams['machineName']);
    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $this->settings->get('wisski')['portainerOptions']['authenticationToken'],
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
