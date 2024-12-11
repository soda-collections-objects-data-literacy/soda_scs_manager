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
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsPortainerServiceActions implements SodaScsServiceRequestInterface {

  use DependencySerializationTrait;

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
   * The string translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected TranslationInterface $stringTranslation;

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
    $this->messenger->addError($this->stringTranslation->translate("Portainer request failed. See logs for more details."));
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
    $url = $this->settings->get('wisski')['routes']['createUrl'];
    if (empty($url)) {
      throw new MissingDataException('Create URL setting is not set.');
    }

    $queryParams = [
      'endpointId' => $this->settings->get('wisski')['portainerOptions']['endpoint'],
    ];
    if (empty($queryParams['endpointId'])) {
      throw new MissingDataException('Endpoint ID setting is not set.');
    }

    $route = $url . '?' . http_build_query($queryParams);

    // URLs with underscores are not supported.
    $defaultGraphSubdomain = str_replace('_', '-', $requestParams['subdomain']);

    $trustedHost = str_replace('.', '\.', $requestParams['subdomain'] . '.' . $this->settings->get('scsHost'));

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
        "value" => $requestParams['subdomain'],
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
        'http://%s.%s/contents/',
        $defaultGraphSubdomain,
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
        "value" => $requestParams['subdomain'],
      ],
      [
        "name" => "SITE_NAME",
        "value" => $requestParams['subdomain'],
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
        "value" => 'https://' . $this->settings->get('triplestore')['openGdpSettings']['host'] . '/repositories/' . $requestParams['subdomain'],
      ],
      [
        "name" => "TS_REPOSITORY",
        "value" => $requestParams['subdomain'],
      ],
      [
        "name" => "TS_USERNAME",
        "value" => $requestParams['username'],
      ],
      [
        "name" => "TS_WRITE_URL",
        "value" => 'https://' . $this->settings->get('triplestore')['openGdpSettings']['host'] . '/repositories/' . $requestParams['subdomain'] . '/statements',
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
        'name' => $requestParams['subdomain'],
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
    $route = $this->settings->get('wisski')['routes']['readAllUrl'];
    if (empty($route)) {
      throw new MissingDataException('Read all URL setting is not set.');
    }

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
    $url = $this->settings->get('wisski')['routes']['deleteUrl'];
    if (empty($url)) {
      throw new MissingDataException('Delete URL setting is not set.');
    }

    $queryParams['endpointId'] = $this->settings->get('wisski')['portainerOptions']['endpoint'];

    if (empty($queryParams['endpointId'])) {
      throw new MissingDataException('Endpoint ID setting is not set.');
    }

    if (empty($queryParams['externalId'])) {
      throw new MissingDataException('Stack ID setting is not set.');
    }

    $route = $url . $queryParams['externalId'] . '?' . http_build_query($queryParams);

    return [
      'success' => TRUE,
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

}
