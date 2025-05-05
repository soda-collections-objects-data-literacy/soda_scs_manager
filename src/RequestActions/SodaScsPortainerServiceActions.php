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
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use GuzzleHttp\ClientInterface;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsPortainerServiceActions implements SodaScsServiceRequestInterface {

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
   * The SCS service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

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
    MailManagerInterface $mailManager,
    MessengerInterface $messenger,
    RequestStack $requestStack,
    SodaScsServiceHelpers $sodaScsServiceHelpers,
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
    $this->mailManager = $mailManager;
    $this->messenger = $messenger;
    $this->requestStack = $requestStack;
    $this->settings = $settings;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
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

      return [
        'message' => 'Request succeeded',
        'data' => [
          'portainerResponse' => $response,
        ],
        'statusCode' => $response->getStatusCode(),
        'success' => TRUE,
        'error' => '',
      ];
    }
    catch (\Exception $e) {
      return [
        'message' => $this->t('Request failed with code @code', ['@code' => $e->getCode()]),
        'data' => [
          'portainerResponse' => $e,
        ],
        'statusCode' => $e->getCode(),
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Builds the create request for the Portainer service API.
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
    $generalSettings = $this->sodaScsServiceHelpers->initGeneralSettings();
    $keycloakGeneralSettings = $this->sodaScsServiceHelpers->initKeycloakGeneralSettings();
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $portainerStacksSettings = $this->sodaScsServiceHelpers->initPortainerStacksSettings();
    $triplestoreServiceSettings = $this->sodaScsServiceHelpers->initTriplestoreServiceSettings();
    $databaseServiceSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();
    $wisskiInstanceSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();

    // Assemble query params.
    $queryParams = [
      'endpointId' => $portainerServiceSettings['endpointId'],
    ];

    // Build route.
    $route = $portainerServiceSettings['host'] . $portainerStacksSettings['baseUrl'] . $portainerStacksSettings['createUrl'] . '?' . http_build_query($queryParams);

    // URLs with underscores are not supported.
    $defaultGraphMachineName = str_replace('_', '-', $requestParams['machineName']);
    $hostDomainName = str_replace('https://', '', $generalSettings['scsHost']);
    $defaultGraphIri = $this->t('https://@defaultGraphMachineName.@hostDomainName', [
      '@defaultGraphMachineName' => $defaultGraphMachineName,
      '@hostDomainName' => $hostDomainName,
    ]);

    $instanceDomain = str_replace('{instanceId}', $requestParams['machineName'], $wisskiInstanceSettings['baseUrl']);

    $instanceDomainName = str_replace('https://', '', $instanceDomain);

    $trustedHost = str_replace('.', '\.', $instanceDomainName);

    if ($requestParams['wisskiType'] == 'stack') {
      $repositoryURL = 'https://github.com/soda-collections-objects-data-literacy/wisski-base-stack.git';
    }
    else {
      $repositoryURL = 'https://github.com/soda-collections-objects-data-literacy/wisski-base-component.git';
    }

    $env = [
      [
        "name" => "DB_DRIVER",
        "value" => "mysql",
      ],
      [
        "name" => "DB_HOST",
        "value" => str_replace('https://', '', $databaseServiceSettings['host']),
      ],
      [
        "name" => "DB_NAME",
        "value" => $requestParams['dbName'],
      ],
      [
        "name" => "DB_PASSWORD",
        "value" => $requestParams['sqlServicePassword'],
      ],
      [
        "name" => "DB_USER",
        "value" => $requestParams['username'],
      ],
      [
        "name" => "DEFAULT_GRAPH",
        // @todo whats the best way of concat strings with vars?
        "value" => $defaultGraphIri . '/contents/',
      ],
      [
        "name" => "DOMAIN",
        "value" => $instanceDomainName,
      ],
      [
        "name" => "DRUPAL_USER",
        "value" => $requestParams['username'],
      ],
      [
        "name" => "DRUPAL_PASSWORD",
        "value" => $requestParams['wisskiServicePassword'],
      ],
      [
        "name" => "DRUPAL_TRUSTED_HOST",
        "value" => $trustedHost,
      ],
      [
        "name" => "KEYCLOAK_REALM",
        "value" => $keycloakGeneralSettings['realm'],
      ],
      [
        "name" => "OPENID_CONNECT_CLIENT_SECRET",
        "value" => $requestParams['openidConnectClientSecret'],
      ],
      [
        "name" => "SERVICE_NAME",
        "value" => $requestParams['machineName'],
      ],
      [
        "name" => "SITE_NAME",
        "value" => $requestParams['machineName'],
      ],
      [
        "name" => "TS_PASSWORD",
        "value" => $requestParams['triplestoreServicePassword'],
      ],
      [
        "name" => "TS_TOKEN",
        "value" => $requestParams['triplestoreServiceToken'],
      ],
      [
        "name" => "TS_READ_URL",
        "value" => $triplestoreServiceSettings['host'] . '/repositories/' . $requestParams['tsRepository'],
      ],
      [
        "name" => "TS_REPOSITORY",
        "value" => $requestParams['tsRepository'],
      ],
      [
        "name" => "TS_USERNAME",
        "value" => $requestParams['username'],
      ],
      [
        "name" => "TS_WRITE_URL",
        "value" => $triplestoreServiceSettings['host'] . '/repositories/' . $requestParams['tsRepository'] . '/statements',
      ],
      [
        "name" => "WISSKI_BASE_IMAGE_VERSION",
        "value" => 'latest',
      ],
      [
        "name" => "WISSKI_GRAIN_YEAST_WATER_VERSION",
        "value" => 'dev-main',
      ],
      [
        "name" => "WISSKI_FLAVOURS",
        "value" => $requestParams['flavours'],
      ],
      [
        "name" => "WISSKI_SWEET_RECIPE_VERSION",
        "value" => 'dev-main',
      ],
      [
        "name" => "WISSKI_FRUITY_RECIPE_VERSION",
        "value" => 'dev-main',
      ],
      [
        "name" => "WISSKI_MALTY_RECIPE_VERSION",
        "value" => 'dev-main',
      ],
      [
        "name" => "WISSKI_WOODY_RECIPE_VERSION",
        "value" => 'dev-main',
      ],
      [
        "name" => "WISSKI_HERBAL_RECIPE_VERSION",
        "value" => 'dev-main',
      ],

    ];

    return [
      'success' => TRUE,
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
      ],
      'body' => json_encode([
        'composeFile' => 'docker-compose.yml',
        'env' => $env,
        'name' => $requestParams['machineName'],
        'repositoryAuthentication' => FALSE,
        'repositoryURL' => $repositoryURL,
      ]),
    ];
  }

  /**
   * Builds the create request for the Portainer service API.
   *
   * @param array $requestParams
   *   An array of request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildGetAllRequest(array $requestParams): array {
    return [];
  }

  /**
   * Build request to get all stacks.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildGetRequest($requestParams): array {

    // Initialize settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $portainerStacksSettings = $this->sodaScsServiceHelpers->initPortainerStacksSettings();

    // Build route.
    $route = $portainerServiceSettings['host'] . $portainerStacksSettings['baseUrl'] . str_replace('{stackId}', $requestParams['externalId'], $portainerStacksSettings['readOneUrl']);

    return [
      'success' => TRUE,
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
   * Builds the update request for the Portainer service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildUpdateRequest(array $requestParams): array {
    return [];
  }

  /**
   * Builds the delete request for the Portainer service API.
   *
   * @param array $queryParams
   *   The query parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildDeleteRequest(array $queryParams): array {
    // Initialize settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $portainerStacksSettings = $this->sodaScsServiceHelpers->initPortainerStacksSettings();

    // Build route.
    $route = $portainerServiceSettings['host'] . $portainerStacksSettings['baseUrl'] . str_replace('{stackId}', $queryParams['externalId'], $portainerStacksSettings['deleteUrl']) . '?endpointId=' . $portainerServiceSettings['endpointId'];

    return [
      'success' => TRUE,
      'method' => 'DELETE',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
      ],
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
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildHealthCheckRequest(array $requestParams): array {
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $wisskiInstanceSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();
    switch ($requestParams['type']) {
      case 'service':
        $route = $portainerServiceSettings['host'] . $portainerServiceSettings['baseUrl'] . str_replace('{endpointId}', $portainerServiceSettings['endpointId'], $portainerServiceSettings['healthCheckUrl']);
        break;

      case 'instance':
        $route = str_replace('{instanceId}', $requestParams['machineName'], $wisskiInstanceSettings['baseUrl']) . $wisskiInstanceSettings['healthCheckUrl'];
        break;

      default:
        throw new \Exception('Invalid request type');
    }

    return [
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $portainerServiceSettings['authenticationToken'],
      ],
    ];
  }

}
