<?php

namespace Drupal\soda_scs_manager;

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


   /**
   * Provisions a user in the SCS user manager daemon.
   *
   * @param string $bundle
   * The bundle to perform the action on.
   * @param string $action
   *  The action to perform: create, read, update, delete.
   * @param int $uid
   *   The user ID.
   * @param array $options
   *  The options to pass.
   *
   * @return array
   *   The response from the daemon.
   */
  public function crudComponent($bundle, $action, $options) {
    try {
      // Determine the route part depending on the action.
      switch ($action) {
        case 'create':
          $restMethod = 'POST';
          break;
        case 'update':
          $restMethod = 'PUT';
          break;
        case 'delete':
          $restMethod = 'DELETE';
          break;
        default:
          $restMethod = 'GET';
          break;
      }
      $provider = 'portainer';
      switch ($bundle) {
        case 'wisski':
          if ($provider == 'distillery') {
            if (in_array($action, [
              'delete',
              'start',
              'stop',
              'rebuild',
              'snapshot',
              'update',
              'echo'
            ])) {
              $restMethod = 'POST';
            }
            if ($action == 'status') {
              $options['serviceUuid'] = $this->sodaScsDbActions->getService('component_id', $options['componentId'])[0]['service_uuid'];
            }
            $request = $this->buildWisskiDistilleryRequest($action, $options);
          } elseif ($provider == 'portainer') {
            // Prepare database
            $options['dbPassword'] = $this->generateRandomPassword();
            $database = $this->sodaScsDbActions->createDb($options['subdomain'], $options['user'], $options['dbPassword']);
            $request = $this->buildPortainerRequest($options);
            $this->loggerFactory
              ->get('soda_scs_manager')
              ->info('Portainer request: ' . json_encode($request));
          }
      }

      // Send the GET request using the `drupal_http_request()` function.
      $response = $this->httpClient->request($restMethod, $request['route'], [
        'headers' => $request['headers'],
        'body' => $request['body']]);

      // Check the response and handle the data accordingly.
      if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
        // Request successful, handle the data in $response->data.
        $resultArray = json_decode($response->getBody()->getContents(), TRUE);
        #$this->messenger
        #  ->addMessage($this->stringTranslation->translate('@message', ['@message' => $resultArray['message']]));
        return  $resultArray;

      }
      if ($response->getStatusCode() == 404) {
        // Request successful, handle the data in $response->data.
        $resultArray = json_decode($response->getBody()->getContents(), TRUE);
        $this->messenger
          ->addError($this->stringTranslation->translate('@message', ['@message' => $resultArray['message']]));
        return  $resultArray;
      }
      else {
        // Request failed, handle the error.
        return [
          "message" => 'Request failed with code: ' . $response->getStatusCode(),
          "data" => [],
          'success' => FALSE,
          'error' =>  $response->getBody()->getContents(),
        ];
      }
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('soda_scs_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Can not communicate with the SCS user manager daemon. Try again later or contact @email.',
          ['@email'
          => $this->ADMIN_EMAIL]));
      return [
        "message" => 'Request failed with exception.',
        "data" => [],
        'success' => FALSE,
        'error' =>  $e->getMessage(),
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
  public function getUsersFromDb($uid = null): array {
    try {
      $driver = $this->database->driver();
      $query = $this->database->select('users_field_data', 'ufd');
      $query->fields('ufd', ['uid', 'name', 'mail']);
      $query->join('user__roles', 'ur', 'ufd.uid = ur.entity_id');
      $query->addField('ufd', 'status', 'enabled');
      if ($driver == 'mysql') {
        $query->addExpression('GROUP_CONCAT(ur.roles_target_id)', 'role');
      } elseif ($driver == 'pgsql') {
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
   * Builds the request for the WissKI Distillery service API.
   *
   * @param $action
   * @param $options
   *
   * @return array
   */
  public function buildWisskiDistilleryRequest($action, $options): array {
    $request = [
      'route' => '',
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $this->settings->get('wisski')['token'],
      ],
      'body' => [],
    ];
    switch ($action) {
      case 'create':
        $request['route'] = 'http://panel.scs.local/api/v1/pow/new';
        $params = json_encode([
          "Slug" => $options['subdomain'],
          "Flavor" => "Drupal 10",
          "System" => [
            "PHP" => "8.2",
            "OpCacheDevelopment" => false,
            "ContentSecurityPolicy" => "",
          ],
        ]);
        $request['body'] = json_encode([
          'call' => 'provision',
          'params' => [$params],
        ]);
        break;
      case 'delete':
        $request['route'] = 'http://scs.local:3001/api/v1/pow/new';
        $params = json_encode([
          "Slug" => $options['subdomain'],
        ]);
        $request['body'] = json_encode([
          'call' => 'purge',
          'params' => [$params],
        ]);
        break;
      case 'start':
        $request['route'] = 'http://scs.local:3001/api/v1/pow/new';
        $request['body']['call'] = 'start';
        $request['body']['params'] = [
          $options['subdomain'],
        ];
        break;
      case 'stop':
        $request['route'] = 'http://scs.local:3001/api/v1/pow/new';
        $request['body']['call'] = 'stop';
        $request['body']['params'] = [
          $options['subdomain'],
        ];
        break;
      case 'rebuild':
        $request['route'] = 'http://scs.local:3001/api/v1/pow/new';
        $request['body']['call'] = 'rebuild';
        $request['body']['params'] = [
          $options['subdomain'],
          [
            "PHP"=> "8.2",
            "OpCacheDevelopment"=> false,
            "ContentSecurityPolicy"=> "",
          ],
        ];
        break;
        case 'snapshot':
          $request['route'] = 'http://scs.local:3001/api/v1/pow/new';
          $request['body']['call'] = 'snapshot';
          $request['body']['params'] = [
            $options['subdomain'],
          ];
          break;
      case 'update':
        $request['route'] = 'http://scs.local:3001/api/v1/pow/new';
        $request['body']['call'] = 'update';
        $request['body']['params'] = [
          $options['subdomain'],
        ];
        break;
      case 'echo':
        $request['route'] = 'http://panel.scs.local/api/v1/pow/new';
        $request['body'] = json_encode([
          "call" => "echo",
          "params" => [
            "message" => "Hello from Drupal",
          ],
        ]);
        break;
      case 'status':
        $request['route'] = 'http://scs.local:3001/api/v1/pow/status/' . $options['serviceUuid'];
        break;
    }
    return $request;
  }

  /**
   * Builds the request for the Portainer service API.
   *
   * @return array
   */
  public function buildPortainerRequest(array $options): array {
    $baseRoute = "https://portainer.dena-dev.de/api/stacks/create/swarm/repository";
    $queryParams = [
      'endpointId' => '1',
    ];
    $route = $baseRoute . '?' . http_build_query($queryParams);
    $env = [
      ["name" => "DB_DRIVER", "value" => "mysql"],
      ["name" => "DB_HOST", "value" => "mariadb"],
      ["name" => "DB_NAME", "value" => $options['subdomain']],
      ["name" => "DB_PASSWORD", "value" => $options['dbPassword']],
      ["name" => "DB_USER", "value" => $options['user']],
      ["name" => "DOMAIN", "value" => "dena-dev.de"],
      ["name" => "DRUPAL_USER", "value" => "admin"],
      ["name" => "DRUPAL_PASSWORD", "value" => "admin"],
      ["name" => "SERVICE_NAME", "value" => $options['subdomain']],
      ["name" => "SITE_NAME", "value" => "Drupal"],
    ];
    $route = [
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-API-Key' => $this->settings->get('wisski')['token'],
      ],
      'body' => json_encode([
        'composeFile' => 'drupal10.3-php8.2-apache-bookworm-vanilla/traefik/external_db/docker-compose.yml',
        'env' => $env,
        'name' => $options['subdomain'],
        'repositoryAuthentication' => false,
        'repositoryURL' => 'https://github.com/rnsrk/scs-manager-stacks.git',
        'swarmID' => 'z2r6wmof2qjqsvrudexikxv51'
      ])
    ];
    return $route;
  }

  function generateRandomPassword(): string {
    $password = '';
    while (strlen($password) < 32) {
      $password .= base_convert(random_int(0, 35), 10, 36);
    }
    return substr($password, 0, 32);
  }

}
