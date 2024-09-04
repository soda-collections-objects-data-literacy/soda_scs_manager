<?php

namespace Drupal\soda_scs_manager;

use Symfony\Component\HttpFoundation\RequestStack;
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
use Drupal\soda_scs_manager\SodaScsServiceRequestInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;

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
   * @var \Drupal\soda_scs_manager\SodaScsMysqlServiceActions
   */
  protected SodaScsMysqlServiceActions $sodaScsMysqlServiceActions;

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
    SodaScsMysqlServiceActions $sodaScsMysqlServiceActions,
    TranslationInterface $stringTranslation,
    TwigEnvironment $twig
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
   * @param $request
   *
   * @return array
   * 
   */
  public function makeRequest($request): array {
      // Assemble options.
      $options['headers'] = $request['headers'];
      if (isset($request['body'])) {
        $options['body'] = $request['body'];
      }
      // Send the request.
      try {
        $response = $this->httpClient->request($request['method'], $request['route'], $options);

        return [
          'message' => 'Make request',
          'data' => [
            'portainerResponse' => $response,
          ],
          'success' => TRUE,
          'error' => '',
        ];
      } catch (ClientException $e) {
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
   * @param array $options
   *
   * @return array
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildCreateRequest(array $options): array {
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

      $env = [
        ["name" => "DB_DRIVER", "value" => "mysql"],
        ["name" => "DB_HOST", "value" => $this->settings->get('dbHost')],
        ["name" => "DB_NAME", "value" => $options['subdomain'] . '-db'],
        ["name" => "DB_PASSWORD", "value" => $options['sqlServicePassword']],
        ["name" => "DB_USER", "value" => $options['userName']],
        ["name" => "DEFAULT_GRAPH", "value" => sprintf('http://%s.%s/contents/', $options['subdomain'], $this->settings->get('scsHost'))],
        ["name" => "DOMAIN", "value" => $this->settings->get('scsHost')],
        ["name" => "DRUPAL_USER", "value" => $options['userName']],
        ["name" => "DRUPAL_PASSWORD", "value" => $options['wisskiServicePassword']],
        ["name" => "SERVICE_NAME", "value" => $options['subdomain']],
        ["name" => "SITE_NAME", "value" => $options['subdomain']],
        ["name" => "TS_PASSWORD", "value" => $options['triplestoreServicePassword']],
        ["name" => "TS_READ_URL", "value" => 'https://' . $this->settings->get('tsHost') . '/repository/' . $options['subdomain'] . '-ts'],
        ["name" => "TS_REPOSITORY", "value" => $options['subdomain']],
        ["name" => "TS_USERNAME", "value" => $options['userName']],
        ["name" => "TS_WRITE_URL", "value" => 'https://' . $this->settings->get('tsHost') . '/repository/' . $options['subdomain'] . '-ts' .'/statements'],
        ["name" => "WISSKI_BASE_IMAGE_VERSION", "value" => '1.x'],
        ["name" => "WISSKI_BASE_RECIPE_VERSION", "value" => '1.x-dev'],
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
          'name' => $options['subdomain'],
          'repositoryAuthentication' => FALSE,
          'repositoryURL' => 'https://github.com/soda-collections-objects-data-literacy/wisski-base-stack.git',
          'swarmID' => $this->settings->get('wisski')['portainerOptions']['swarmId'],
        ]),
      ];
  }

  /**
   * Builds the create request for the Portainer service API.
   *
   * @param array $options
   *
   * @return array
   *
   */
  public function buildReadRequest(array $options): array {
    return [];
  }

  /**
   * Build request to get all stacks.
   *
   * @param $options
   *
   * @return array
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildGetRequest($options): array {
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
   * @param array $options
   *
   * @return array
   */
  public function buildUpdateRequest(array $options): array {
    return [];
  }

  /**
   * Builds the delete request for the Portainer service API.
   *
   * @param array $queryParams
   *
   * @return array
   *
   * @throws MissingDataException
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
}