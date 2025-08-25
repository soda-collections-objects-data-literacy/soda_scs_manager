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
use Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles the communication with the Docker exec service API.
 *
 * For creating exec commands to perform actions in the containers,
 * like "usermod -a -G www-data www-data" etc.
 *
 * @see https://docs.portainer.io/api/endpoints/docker/exec
 *
 *
 */
class SodaScsDockerExecServiceActions implements SodaScsExecRequestInterface {

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
   * @return array{
   *   message: string,
   *   success: bool,
   *   error: string,
   *   data: array,
   *   statusCode: int,
   *   }
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
   * Builds the create request for the docker exec service API.
   *
   * @param array $requestParams
   *   The request params.
   * - 'cmd[string]': The command to run, i.e. ['mysqldump', '-u', 'root', '-p', 'password', 'database', 'table']
   * - 'containerName': The name of the container to run the command in, i.e. 'database'
   * - 'user': The user to run the command as, i.e. '33'
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
    $dockerExecServiceSettings = $this->sodaScsServiceHelpers->initDockerExecServiceSettings();

    // @todo Container name is not the container id.
    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['host'] .
      // /api/endpoints
      $portainerServiceSettings['baseUrl'] .
      // /{endpointId}/docker
      str_replace('{endpointId}', $portainerServiceSettings['endpointId'], $dockerApiSettings['baseUrl']) .
      // /containers/{containerId}/exec.
      str_replace('{containerId}', $requestParams['containerName'], $dockerExecServiceSettings['createUrl']);

    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
      ],
      'body' => json_encode(
        [
          'Cmd' => $requestParams['cmd'],
          'Detach' => FALSE,
          'Tty' => FALSE,
          'User' => $requestParams['user'],
        ]
      ),
    ];
  }

  /**
   * Builds the start request for the Docker exec API.
   *
   * @param array[] $requestParams
   *   The request parameters:
   *   - 'execId': The exec id.
   *
   * @return array
   *   The start request.
   */
  public function buildStartRequest(array $requestParams): array {
    // Initialize settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerExecServiceSettings = $this->sodaScsServiceHelpers->initDockerExecServiceSettings();

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['host'] .
      // /api/endpoints
      $portainerServiceSettings['baseUrl'] .
      // /{endpointId}/docker
      str_replace('{endpointId}', $portainerServiceSettings['endpointId'], $dockerApiSettings['baseUrl']) .
      // /containers/{containerId}/exec/{execId}/start.
      str_replace('{execId}', $requestParams['execId'], $dockerExecServiceSettings['startUrl']);

    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
      ],
      'body' => json_encode([
        'Detach' => FALSE,
        'Tty' => TRUE,
      ]),
    ];
  }

  /**
   * Builds the resize request for the Docker exec API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The resize request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildResizeRequest(array $requestParams): array {
    // Initialize settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerExecServiceSettings = $this->sodaScsServiceHelpers->initDockerExecServiceSettings();

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['host'] .
      // /api/endpoints
      $portainerServiceSettings['baseUrl'] .
      // /{endpointId}/docker
      str_replace('{endpointId}', $portainerServiceSettings['endpointId'], $dockerApiSettings['baseUrl']) .
      // /containers/{containerId}/exec/{execId}/resize.
      str_replace('{execId}', $requestParams['execId'], $dockerExecServiceSettings['resizeUrl']);

    return [
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
      ],
      'query' => [
        'h' => $requestParams['height'] ?? 24,
        'w' => $requestParams['width'] ?? 80,
      ],
    ];
  }

  /**
   * Builds the inspect request for the Docker exec API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The inspect request.
   */
  public function buildInspectRequest(array $requestParams): array {
    // Initialize settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $dockerApiSettings = $this->sodaScsServiceHelpers->initDockerApiSettings();
    $dockerExecServiceSettings = $this->sodaScsServiceHelpers->initDockerExecServiceSettings();

    $route =
      // https://portainer.scs.sammlungen.io
      $portainerServiceSettings['host'] .
      // /api/endpoints
      $portainerServiceSettings['baseUrl'] .
      // /{endpointId}/docker
      str_replace('{endpointId}', $portainerServiceSettings['endpointId'], $dockerApiSettings['baseUrl']) .
      // /containers/{containerId}/exec/{execId}/inspect.
      str_replace('{execId}', $requestParams['execId'], $dockerExecServiceSettings['inspectUrl']);

    return [
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['portainerAuthenticationToken'],
      ],
    ];
  }

}
