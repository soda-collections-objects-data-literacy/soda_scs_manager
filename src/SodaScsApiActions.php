<?php

namespace Drupal\soda_scs_manager;

use Drupal\soda_scs_manager\Exception\SodaScsRequestException;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\TypedData\Exception\MissingDataException;
use GuzzleHttp\ClientInterface;


/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsApiActions {

  use DependencySerializationTrait;

  protected $ADMIN_EMAIL = 'not set';

  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

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
   * @var \Drupal\soda_scs_manager\SodaScsDbActions
   */
  protected SodaScsDbActions $sodaScsDbActions;

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
    ClientInterface $httpClient,
    LanguageManagerInterface $languageManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MailManagerInterface $mailManager,
    MessengerInterface $messenger,
    RequestStack $requestStack,
    SodaScsDbActions $sodaScsDbActions,
    TranslationInterface $stringTranslation,
    TwigEnvironment $twig
  ) {
    // Services from container.
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->httpClient = $httpClient;
    $this->database = $database;
    $this->languageManager = $languageManager;
    $this->loggerFactory = $loggerFactory;
    $this->mailManager = $mailManager;
    $this->messenger = $messenger;
    $this->requestStack = $requestStack;
    $this->settings = $settings;
    $this->sodaScsDbActions = $sodaScsDbActions;
    $this->stringTranslation = $stringTranslation;
    $this->twig = $twig;
  }

  /**
   * Creates a component.
   *
   * @param string $bundle
   *   The bundle of the component.
   * @param array $options
   *   The options for the component.
   *
   * @return array
   *   The result of the request.
   *
   */
  public function createStack(string $bundle, array $options): array {
    switch ($bundle) {
      case 'wisski':
          try {
            // Create Drupal database.
            $createDbResult = $this->sodaScsDbActions->createDb($options['subdomain'], $options['userId'], $options['servicePassword']);
          } catch (MissingDataException $e) {
            $this->loggerFactory->get('soda_scs_manager')
              ->error("Cannot create database. @error", [
                '@error' => $e->getMessage(),
              ]);
            $this->messenger->addError($this->stringTranslation->translate("Cannot create database. See logs for more details."));
            return [
              'message' => 'Cannot create database.',
              'data' => [
                'createDbResult' => NULL,
                'portainerResponse' => NULL,
              ],
              'success' => FALSE,
              'error' => $e->getMessage(),
            ];
          }
          try {
            // Create Drupal instance.
            $portainerCreateRequest = $this->buildPortainerCreateRequest($options);
            $portainerResponse = $this->makeRequest($portainerCreateRequest);
          } catch (MissingDataException $e) {
            $this->loggerFactory->get('soda_scs_manager')
              ->error("Cannot assemble Request: @error", [
                '@error' => $e->getMessage(),
              ]);
            $this->messenger->addError($this->stringTranslation->translate("Cannot assemble request. See logs for more details."));
            return [
              'message' => 'Cannot assemble Request.',
              'data' => [
                'createDbResult' => $createDbResult,
                'portainerResponse' => NULL,
              ],
              'success' => FALSE,
              'error' => $e->getMessage(),
            ];
          } catch (SodaScsRequestException $e) {
            $this->loggerFactory->get('soda_scs_manager')
              ->error("Request response with error: @error", [
                '@error' => $e->getMessage(),
              ]);
            $this->messenger->addError($this->stringTranslation->translate("Request error. See logs for more details."));
            return [
              'message' => 'Request failed with exception: ' . $e->getMessage(),
              'data' => [
                'createDbResult' => $createDbResult,
                'portainerResponse' => NULL,
              ],
              'success' => FALSE,
              'error' => $e->getMessage(),
            ];
          }
          return [
            'message' => 'Component created',
            'data' => [
              'createDbResult' => $createDbResult,
              'portainerResponse' => $portainerResponse,
            ],
            'success' => TRUE,
            'error' => NULL,
          ];

        default:
        $restMethod = 'GET';
        $result = [
          "message" => 'dummy',
          "data" => [],
          'success' => FALSE,
          'error' => 'dummy'
        ];
        break;
    }
    return $result;
  }

  /**
   * Get all stacks of a bundle.
   *
   * @param $bundle
   * @param $options
   *
   * @return array
   */
  public function getStacks($bundle, $options): array {
    switch ($bundle) {
      case 'wisski':
        try {
          // Build request.
          $portainerGetStacksRequest = $this->buildPortainerGetStacksRequest($options);
          $portainerResponse = $this->makeRequest($portainerGetStacksRequest);
        }
        catch (SodaScsRequestException $e) {
          $this->loggerFactory->get('soda_scs_manager')
            ->error("Request response with error: @error", [
              '@error' => $e->getMessage(),
            ]);
          $this->messenger->addError($this->stringTranslation->translate("Request error. See logs for more details."));
          return [
            'message' => 'Request failed with exception: ' . $e->getMessage(),
            'data' => [
              'portainerResponse' => NULL,
            ],
            'success' => FALSE,
            'error' => $e->getMessage(),
          ];
        }
        return [
          'message' => 'Got all stacks',
          'data' => [
            'portainerResponse' => $portainerResponse,
          ],
          'success' => TRUE,
          'error' => NULL,
        ];
        break;
      default:
        return [];
    }
  }
  public function readStack($bundle, array $options): array {

    return  [
      'message' => 'Component read',
      'data' => [],
      'error' => NULL,
      'success' => TRUE,
    ];

  }

  public function updateStack() {

  }

  public function deleteStack($bundle, $options): array {
    switch ($bundle) {
      case 'wisski':
        try {
          // Delete Drupal database.
          $deleteDbResult = $this->sodaScsDbActions->deleteDb($options['subdomain'], $options['userName'], $options['servicePassword']);
        } catch (MissingDataException $e) {
          $this->loggerFactory->get('soda_scs_manager')
            ->error("Cannot delete database. @error", [
              '@error' => $e->getMessage(),
            ]);
          $this->messenger->addError($this->stringTranslation->translate("Cannot delete database. See logs for more details."));
          return [
            'message' => 'Cannot create database.',
            'data' => [
              'deleteDbResult' => NULL,
              'portainerResponse' => NULL,
            ],
            'success' => FALSE,
            'error' => $e->getMessage(),
          ];
        }
        try {
          // Delete Drupal instance.
          $portainerCreateRequest = $this->buildPortainerDeleteRequest($options);
          $portainerResponse = $this->makeRequest($portainerCreateRequest);


        } catch (MissingDataException $e) {
          $this->loggerFactory->get('soda_scs_manager')
            ->error("Cannot assemble Request: @error", [
              '@error' => $e->getMessage(),
            ]);
          $this->messenger->addError($this->stringTranslation->translate("Cannot assemble request. See logs for more details."));
          return [
            'message' => 'Cannot assemble Request.',
            'data' => [
              'DeleteDbResult' => $deleteDbResult,
              'portainerResponse' => NULL,
            ],
            'success' => FALSE,
            'error' => $e->getMessage(),
          ];
        } catch (SodaScsRequestException $e) {
          $this->loggerFactory->get('soda_scs_manager')
            ->error("Request response with error: @error", [
              '@error' => $e->getMessage(),
            ]);
          $this->messenger->addError($this->stringTranslation->translate("Request error. See logs for more details."));
          return [
            'message' => 'Request failed with exception: ' . $e->getMessage(),
            'data' => [
              'DeleteDbResult' => $deleteDbResult,
              'portainerResponse' => NULL,
            ],
            'success' => FALSE,
            'error' => $e->getMessage(),
          ];
        }
        return [
          'message' => 'Component deleted',
          'data' => [
            'deleteDbResult' => $deleteDbResult,
            'portainerResponse' => $portainerResponse,
          ],
          'success' => TRUE,
          'error' => NULL,
        ];
        break;
      default:
        $restMethod = 'GET';
        $result = [
          "message" => 'dummy',
          "data" => [],
          'success' => FALSE,
          'error' => 'dummy'
        ];
        break;
    }
    return $result;
  }

  /**
   * @param $request
   *
   * @return array
   * @throws SodaScsRequestException
   */
  public function makeRequest($request): array {
    try {
      // Assemble options.
      $options['headers'] = $request['headers'];
      if (isset($request['body'])) {
        $options['body'] = $request['body'];
      }

      // Send the GET request.
      $response = $this->httpClient->request($request['method'], $request['route'], $options);

      // Check the response and handle the data accordingly.
      if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
        // Request successful, handle the data in $response->data.
        $resultArray = json_decode($response->getBody()->getContents(), TRUE);
        return [
          'message' => 'Request successful with code: ' . $response->getStatusCode(),
          'data' => $resultArray,
          'success' => TRUE,
          'error' => NULL,
          ];
      }
      else {
        // Request failed, handle the error.
        return [
          'message' => 'Request failed with code: ' . $response->getStatusCode(),
          "data" => [],
          'success' => FALSE,
          'error' => $response->getBody()->getContents(),
        ];
      }
    }
    catch (GuzzleException $e) {
      // Request failed, handle the error.
      throw new SodaScsRequestException($this->stringTranslation->translate('Request to container manager failed with code @code: @error', [
        '@code' => $e->getCode(),
        '@error' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
      ]), 0, $e);
    }
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
  public function buildPortainerCreateRequest(array $options): array {
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
        ["name" => "DB_NAME", "value" => $options['subdomain']],
        ["name" => "DB_PASSWORD", "value" => $options['servicePassword']],
        ["name" => "DB_USER", "value" => $options['userName']],
        ["name" => "DOMAIN", "value" => $this->settings->get('scsHost')],
        ["name" => "DRUPAL_USER", "value" => $options['userName']],
        ["name" => "DRUPAL_PASSWORD", "value" => $options['servicePassword']],
        ["name" => "SERVICE_NAME", "value" => $options['subdomain']],
        ["name" => "SITE_NAME", "value" => $options['subdomain']],
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
          'composeFile' => 'drupal10.3-php8.2-apache-bookworm-vanilla/traefik/external_db/docker-compose.yml',
          'env' => $env,
          'name' => $options['subdomain'],
          'repositoryAuthentication' => FALSE,
          'repositoryURL' => 'https://github.com/rnsrk/soda_scs_manager_stacks.git',
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
  public function buildPortainerReadRequest(array $options): array {
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
  public function buildPortainerGetStacksRequest($options): array {
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
  public function buildPortainerUpdateRequest(array $options): array {
    return [];
  }

  /**
   * Builds the delete request for the Portainer service API.
   *
   * @param array $options
   *
   * @return array
   *
   * @throws MissingDataException
   */
  public function buildPortainerDeleteRequest(array $options): array {
    $url = $this->settings->get('wisski')['routes']['deleteUrl'];
    if (empty($url)) {
      throw new MissingDataException('Delete URL setting is not set.');
    }

    $queryParams = [
      'endpointId' => $this->settings->get('wisski')['portainerOptions']['endpoint'],
    ];
    if (empty($queryParams['endpointId'])) {
      throw new MissingDataException('Endpoint ID setting is not set.');
    }

    if (empty($options['externalId'])) {
      throw new MissingDataException('Stack ID setting is not set.');
    }

    $route = $url . $options['externalId'] . '?' . http_build_query($queryParams);

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
   * Generates a random password.
   *
   * @return string
   */
  function generateRandomPassword(): string {
    $password = '';
    while (strlen($password) < 32) {
      $password .= base_convert(random_int(0, 35), 10, 36);
    }
    return substr($password, 0, 32);
  }
}
