<?php

namespace Drupal\soda_scs_manager;

use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\soda_scs_manager\DistilleryDatabaseConnection;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Schema\Undefined;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsApiActions {

  use DependencySerializationTrait;

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
    $this->stringTranslation = $stringTranslation;
    $this->twig = $twig;
  }


   /**
   * Provisions an user in the SCS user manager daemon.
   *
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
  public function crudComponent($action, $options) {
    try {

      // Determine the route part depending on the action.
      switch ($action) {
        case 'create':
          $restMethod = 'post';
          break;
        case 'update':
          $restMethod = 'put';
          break;
        case 'delete':
          $restMethod = 'delete';
          break;
        default:
          $restMethod = 'get';
          break;
      }

      // Set the request options.
      $body = json_encode($options);

      // Headers.

      $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Bearer' => $this->settings->get('apiToken'),
      ];

      // Send the GET request using the `drupal_http_request()` function.
      $response = $this->httpClient->request($restMethod, $this->settings->get('apiUrl') . '/components', [
        'headers' => $headers,
        'body' => $body,
      ]);

      // Check the response and handle the data accordingly.
      if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
        // Request successful, handle the data in $response->data.
        $resultArray = json_decode($response->getBody()->getContents(), TRUE);
        $this->messenger
          ->addMessage($this->stringTranslation->translate('@message', ['@message' => $resultArray['message']]));
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

}
