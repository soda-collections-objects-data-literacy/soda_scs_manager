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
use GuzzleHttp\Exception\{ClientException, RequestException};



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
    if (empty($this->settings->get('portainer')['portainerOptions']['host'])) {
      throw new MissingDataException('Portainer host setting is not set.');
    }

    if (empty($this->settings->get('portainer')['routes']['stack']['baseUrl'])) {
      throw new MissingDataException('Stack create URL setting is not set.');
    }

    if (empty($this->settings->get('portainer')['routes']['stack']['crud']['createUrl'])) {
      throw new MissingDataException('Stack create URL setting is not set.');
    }

    if (empty($this->settings->get('portainer')['portainerOptions']['endpoint'])) {
      throw new MissingDataException('Endpoint ID setting is not set.');
    } else {
      $queryParams = [
        'endpointId' => $this->settings->get('portainer')['portainerOptions']['endpoint'],
      ];
    }

    $route = $this->settings->get('portainer')['portainerOptions']['host'] . $this->settings->get('portainer')['routes']['stack']['baseUrl'] . $this->settings->get('portainer')['routes']['stack']['crud']['createUrl'] . '?' . http_build_query($queryParams);

    // URLs with underscores are not supported.
    $defaultGraphmachineName = str_replace('_', '-', $requestParams['machineName']);

    $trustedHost = str_replace('.', '\.', $requestParams['machineName'] . '.' . $this->settings->get('scsHost'));

    $env = [
      [
        "name" => "DB_DRIVER",
        "value" => "mysql",
      ],
      [
        "name" => "DB_HOST",
        "value" => $this->settings->get('dbHost'),
      ],
      [
        "name" => "DB_NAME",
        "value" => $requestParams['machineName'],
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
        "value" => sprintf(
        '%s.%s/contents/',
        $defaultGraphmachineName,
        $this->settings->get('scsHost')),
      ],
      [
        "name" => "DOMAIN",
        "value" => $this->settings->get('scsHost'),
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
        "value" => $this->settings->get('triplestore')['generalSettings']['host'] . '/repositories/' . $requestParams['machineName'],
      ],
      [
        "name" => "TS_REPOSITORY",
        "value" => $requestParams['machineName'],
      ],
      [
        "name" => "TS_USERNAME",
        "value" => $requestParams['username'],
      ],
      [
        "name" => "TS_WRITE_URL",
        "value" => $this->settings->get('triplestore')['generalSettings']['host'] . '/repositories/' . $requestParams['machineName'] . '/statements',
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

    foreach ($env as $variable) {
      if ($variable['name'] == "WISSKI_FLAVOURS") {
        continue;
      }
      if (empty($variable['value'])) {
        throw new MissingDataException($variable['name'] . ' setting is not set.');
      }
    }
    return [
      'success' => TRUE,
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $this->settings->get('wisski')['portainerOptions']['authenticationToken'],
      ],
      'body' => json_encode([
        'composeFile' => 'docker-compose.yml',
        'env' => $env,
        'name' => $requestParams['machineName'],
        'repositoryAuthentication' => FALSE,
        'repositoryURL' => 'https://github.com/soda-collections-objects-data-literacy/wisski-base-stack.git',
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

    if (empty($this->settings->get('wisski')['portainerOptions']['host'])) {
      throw new MissingDataException('Portainer host setting is not set.');
    }

    if (empty($this->settings->get('portainer')['routes']['stacks']['baseUrl'])) {
      throw new MissingDataException('Portainer base URL setting is not set.');
    }

    if (empty($this->settings->get('portainer')['routes']['stacks']['getOneUrl'])) {
      throw new MissingDataException('Portainer get one stack URL setting is not set.');
    }

    if (empty($this->settings->get('wisski')['portainerOptions']['authenticationToken'])) {
      throw new MissingDataException('Portainer authentication token setting is not set.');
    }

    $route = $this->settings->get('wisski')['portainerOptions']['host'] . $this->settings->get('portainer')['routes']['stacks']['baseUrl'] . str_replace('{stackId}', $requestParams['stackId'], $this->settings->get('portainer')['routes']['stacks']['getOneUrl']);

    return [
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $this->settings->get('portainer')['portainerOptions']['authenticationToken'],
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

    if (empty($this->settings->get('portainer')['portainerOptions']['host'])) {
      throw new MissingDataException('Portainer host setting is not set.');
    }

    if (empty($this->settings->get('portainer')['portainerOptions']['endpoint'])) {
      throw new MissingDataException('Portainer endpoint setting is not set.');
    } else {
      $queryParams['endpointId'] = $this->settings->get('portainer')['portainerOptions']['endpoint'];
    }

    if (empty($this->settings->get('portainer')['routes']['stacks']['baseUrl'])) {
      throw new MissingDataException('Stack delete URL setting is not set.');
    }

    if (empty($this->settings->get('portainer')['routes']['stacks']['crud']['deleteUrl'])) {
      throw new MissingDataException('Stack delete URL setting is not set.');
    }

    if (empty($queryParams['externalId'])) {
      throw new MissingDataException('Stack ID setting is not set.');
    }

    $route = $this->settings->get('portainer')['portainerOptions']['host'] . str_replace('{stackId}', $queryParams['externalId'], $this->settings->get('portainer')['routes']['stacks']['crud']['deleteUrl']) . '?' . http_build_query($queryParams);

    return [
      'success' => TRUE,
      'method' => 'DELETE',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $this->settings->get('portainer')['portainerOptions']['authenticationToken'],
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
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildHealthCheckRequest(array $requestParams): array {
    if (empty($this->settings->get('portainer')['portainerOptions']['host'])) {
      throw new MissingDataException('Portainer host setting is not set.');
    }
    if (empty($this->settings->get('portainer')['routes']['healthCheck']['url'])) {
      throw new MissingDataException('Portainer health check endpoint setting is not set.');
    }

    $route = 'https://' .$this->settings->get('portainer')['portainerOptions']['host'] . $this->settings->get('portainer')['routes']['healthCheck']['url'];
    return [
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $this->settings->get('wisski')['portainerOptions']['authenticationToken'],
      ],
    ];
  } 

}
