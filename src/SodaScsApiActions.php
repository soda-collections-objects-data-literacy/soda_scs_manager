<?php

namespace Drupal\soda_scs_manager;

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
use Drupal\soda_scs_manager\SodaScsDbActions;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Template\TwigEnvironment;
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

  public function createComponent($bundle, $options): array {
    switch ($bundle) {
      case 'wisski':
        $options['dbPassword'] = $this->generateRandomPassword();
        $result = $this->sodaScsDbActions->createDb($options['subdomain'], $options['userName'], $options['dbPassword']);
        if (!$result['success']) {
          break;
        }
        $result = $this->buildPortainerCreateRequest($options);
        if (!$result['success']) {
          break;
        }
        $result = $this->makeRequest($result);
        if (!$result['success']) {
          break;
        }
        break;
      default:
        $restMethod = 'GET';
        return [
          "message" => 'dummy',
          "data" => [],
          'success' => FALSE,
          'error' => 'dummy'
        ];
        break;
    }
    return $result;
  }

  public function readComponent() {
    return  [
      'message' => 'Component read',
      'data' => [],
      'error' => NULL,
      'success' => TRUE,
    ];

  }

  public function updateComponent() {

  }

  public function deleteComponent($bundle, $options): array {
    switch ($bundle) {
      case 'wisski':
        $result = $this->sodaScsDbActions->deleteDb($options['subdomain'], $options['userName']);
        if (!$result['success']) {
          return $result;
        }
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

  public function makeRequest($request): array {
    try {
      // Send the GET request using the `drupal_http_request()` function.
      $response = $this->httpClient->request($request['method'], $request['route'], [
        'headers' => $request['headers'],
        'body' => $request['body'],
      ]);

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
      if ($response->getStatusCode() == 404) {
        // Request successful, handle the data in $response->data.
        $resultArray = json_decode($response->getBody()->getContents(), TRUE);
        $this->messenger
          ->addError($this->stringTranslation->translate('@message', ['@message' => $resultArray['message']]));
        return [
          'message' => 'Request failed with code: ' . $response->getStatusCode(),
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
      $this->loggerFactory
        ->get('soda_scs_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Can not communicate with the SCS user manager daemon. Try again later or contact @email.',
          [
            '@email'
            => $this->ADMIN_EMAIL,
          ]));
      return [
        "message" => 'Request failed with exception.',
        "data" => [],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Gets the users from the Drupal database and Distillery.
   *
   * @param int $uid
   * The user ID to get.
   *
   * @return array
   * The users.
   *
   * @throws \Exception
   * If the request fails.
   */
  public function getUsersFromDb($uid = NULL): array {
    try {
      $driver = $this->database->driver();
      $query = $this->database->select('users_field_data', 'ufd');
      $query->fields('ufd', ['uid', 'name', 'mail']);
      $query->join('user__roles', 'ur', 'ufd.uid = ur.entity_id');
      $query->addField('ufd', 'status', 'enabled');
      if ($driver == 'mysql') {
        $query->addExpression('GROUP_CONCAT(ur.roles_target_id)', 'role');
      }
      elseif ($driver == 'pgsql') {
        $query->addExpression('STRING_AGG(ur.roles_target_id, \',\')', 'role');
      }
      $query->groupBy('ufd.uid');
      $query->groupBy('ufd.name');
      $query->groupBy('ufd.mail');
      $query->groupBy('ufd.status');
      $query->orderBy('ufd.name', 'ASC');

      if ($uid) {
        $query->condition('ufd.uid', $uid, '=');
      }

      $users = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      foreach ($users as $index => $user) {
        $users[$index]['role'] = explode(',', $user['role']);
      }

      return $users;
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('soda_scs_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Can not communicate with the SCS user manager daemon. Try again later or contact cloud@wiss-ki.eu.'));
      return [];
    }
  }

  /**
   * Builds the request for the Portainer service API.
   *
   * @param array $options
   *
   * @return array
   */
  public function buildPortainerCreateRequest(array $options): array {
    try {
      $url = $this->settings->get('wisski')['routes']['createUrl'];
      if (empty($url)) {
        throw new \Exception('Create URL setting is not set.');
      }

      $queryParams = [
        'endpointId' => $this->settings->get('wisski')['portainerOptions']['endpoint'],
      ];
      if (empty($queryParams['endpointId'])) {
        throw new \Exception('Endpoint ID setting is not set.');
      }

      $route = $url . '?' . http_build_query($queryParams);

      $env = [
        ["name" => "DB_DRIVER", "value" => "mysql"],
        ["name" => "DB_HOST", "value" => $this->settings->get('dbHost')],
        ["name" => "DB_NAME", "value" => $options['subdomain']],
        ["name" => "DB_PASSWORD", "value" => $options['dbPassword']],
        ["name" => "DB_USER", "value" => $options['userName']],
        ["name" => "DOMAIN", "value" => $this->settings->get('scsHost')],
        ["name" => "DRUPAL_USER", "value" => "admin"],
        ["name" => "DRUPAL_PASSWORD", "value" => "admin"],
        ["name" => "SERVICE_NAME", "value" => $options['subdomain']],
        ["name" => "SITE_NAME", "value" => "Drupal"],
      ];

      foreach ($env as $variable) {
        if (empty($variable['value'])) {
          throw new \Exception($variable['name'] . ' setting is not set.');
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
    } catch (\Exception $exception) {
      $this->loggerFactory
        ->get('soda_scs_manager')
        ->error('Could not construct portainer request: ' . $exception->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Could not construct portainer request. See logs for more.'));
    }
    return ['success'  => FALSE];
  }

  function generateRandomPassword(): string {
    $password = '';
    while (strlen($password) < 32) {
      $password .= base_convert(random_int(0, 35), 10, 36);
    }
    return substr($password, 0, 32);
  }

}
