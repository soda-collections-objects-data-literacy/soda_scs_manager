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
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Message;
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
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    #[Autowire(service: 'soda_scs_manager.sql_service.actions')]
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
    $requestParams['timeout'] ??= 600;

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
    catch (RequestException $e) {
      $logger = $this->loggerFactory->get('soda_scs_manager');
      $requestDump = Message::toString($e->getRequest());
      $responseDump = $e->hasResponse() ? Message::toString($e->getResponse()) : '';
      $logger->error('Portainer request failed: @message', ['@message' => $e->getMessage()]);
      $logger->debug('Failed Portainer request: %request', ['%request' => $requestDump]);
      if ($responseDump !== '') {
        $logger->debug('Failed Portainer response: %response', ['%response' => $responseDump]);
      }

      $detailedError = $e->getMessage();
      if ($e->hasResponse()) {
        $responseBody = (string) $e->getResponse()->getBody();
        if ($responseBody !== '') {
          $detailedError .= ' | body: ' . $responseBody;
        }
      }

      return [
        'message' => $this->t('Request failed with code @code', ['@code' => $e->getCode()]),
        'data' => [
          'portainerResponse' => $e,
        ],
        'statusCode' => $e->getCode(),
        'success' => FALSE,
        'error' => $detailedError,
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory
        ->get('soda_scs_manager')
        ->error('Unexpected Portainer request failure: @message', ['@message' => $e->getMessage()]);

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

    // @todo: This is a hack to trust the domain varnish name and the raw drupal domain name.
    $trustedHost = '"^' . str_replace('.', '\.', $instanceDomainName) . '\$"' . ',"^raw\.' . str_replace('.', '\.', $instanceDomainName) . '\$"';

    if ($requestParams['wisskiType'] == 'bundled') {
      $repositoryURL = 'https://github.com/soda-collections-objects-data-literacy/wisski-base-stack.git';
    }
    else {
      $repositoryURL = 'https://github.com/soda-collections-objects-data-literacy/wisski-base-component.git';
    }

    $env = [
      [
        "name" => "APACHE_LOGGING",
        "value" => "false",
      ],
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
        "name" => "DB_PORT",
        "value" => $databaseServiceSettings['port'],
      ],
      [
        "name" => "DB_USER",
        "value" => $requestParams['username'],
      ],
      [
        "name" => "DEBUG",
        "value" => "false",
      ],
      [
        "name" => "DEFAULT_GRAPH",
        // @todo whats the best way of concat strings with vars?
        "value" => $defaultGraphIri . '/contents/',
      ],
      [
        "name" => "DRUPAL_LOCALE",
        "value" => $requestParams['defaultLanguage'],
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
        "name" => "KEYCLOAK_ADMIN_GROUP",
        "value" => $requestParams['keycloakAdminGroup'],
      ],
      [
        "name" => "KEYCLOAK_USER_GROUP",
        "value" => $requestParams['keycloakUserGroup'],
      ],
      [
        "name" => "KEYCLOAK_REALM",
        "value" => $keycloakGeneralSettings['realm'],
      ],
      [
        "name" => "MODE",
        "value" => "production",
      ],
      [
        "name" => "OPENID_CONNECT_CLIENT_SECRET",
        "value" => $requestParams['openidConnectClientSecret'],
      ],
      [
        "name" => "USER_GROUPS",
        "value" => $requestParams['userGroups'],
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
        "name" => "WISSKI_DEFAULT_DATA_MODEL_VERSION",
        "value" => $requestParams['wisskiDefaultDataModelVersion'],
      ],
      [
        "name" => "WISSKI_STARTER_VERSION",
        "value" => $requestParams['wisskiStarterVersion'],
      ],
      [
        "name" => "WISSKI_FLAVOURS",
        "value" => $requestParams['flavours'],
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
        'buildArgs' => $requestParams['buildArgs'] ?? [],
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

    $requestParams['routeParams']['endpointId'] = $portainerServiceSettings['endpointId'];

    // Build route.
    // https://portainer.scs.sammlungen.io/
    $route = $portainerServiceSettings['host'] .
    // /stacks
    $portainerStacksSettings['baseUrl'] .
    // /{stackId}
    $portainerStacksSettings['readOneUrl'];

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
   * @param array $requestParams
   *   The query parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildDeleteRequest(array $requestParams): array {
    // Initialize settings.
    $portainerServiceSettings = $this->sodaScsServiceHelpers->initPortainerServiceSettings();
    $portainerStacksSettings = $this->sodaScsServiceHelpers->initPortainerStacksSettings();

    $requestParams['queryParams']['endpointId'] = $portainerServiceSettings['endpointId'];

    // Build route.
    $route =
    // https://portainer.scs.sammlungen.io
    $portainerServiceSettings['host'] .
    // /stacks
    $portainerStacksSettings['baseUrl'] .
    // /{stackId}
    $portainerStacksSettings['deleteUrl'];

    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

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
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildTokenRequest(array $requestParams = []): array {
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
        $route = str_replace('{instanceId}', "raw." . $requestParams['machineName'], $wisskiInstanceSettings['baseUrl']) . $wisskiInstanceSettings['healthCheckUrl'];
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
