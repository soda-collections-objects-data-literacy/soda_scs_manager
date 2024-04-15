<?php

namespace Drupal\wisski_cloud_account_manager;

use Symfony\Component\HttpFoundation\RequestStack;
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
 * Handles the communication with the WissKI Cloud account manager daemon.
 */
class WisskiCloudAccountManagerDaemonApiActions {

  use DependencySerializationTrait;

  /**
   * The admin email address.
   *
   * @var string
   */
  private string $ADMIN_EMAIL;

  /**
   * The URL path to provision PUT endpoint.
   *
   * @var string
   */
  private string $PROVISION_ROUTE;


  /**
   * The base URL of the WissKI Cloud account manager daemon.
   *
   * @var string
   */
  private string $DAEMON_URL;

  /**
   * The URL path to delete DELETE endpoint.
   *
   * @var string
   */
  private string $DELETE_ROUTE;


  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The Route to the health check GET endpoint.
   *
   * @var string
   */
  private string $HEALTH_CHECK_ROUTE;

  /**
   * The Route to the info GET endpoint.
   *
   * @var string
   * @todo not yet implemented
   */
  private string $INFO_ROUTE = '/info';

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
    ->getEditable('wisski_cloud_account_manager.settings');
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

    // Set the daemon URL and the URL parts class variables.
    $this->DAEMON_URL = $settings->get('daemonUrl') ?: 'http://wisski_cloud_api_daemon_app:2912/wisski-cloud-daemon/api/v1';
    $this->DELETE_ROUTE = $settings->get('deleteRoute') ?: '/delete';
    $this->PROVISION_ROUTE = $settings->get('provisionRoute') ?: '/provision';
    $this->ADMIN_EMAIL = \Drupal::config('system.site')->get('mail');
    $this->HEALTH_CHECK_ROUTE= $settings->get('healthCheckRoute') ?: '/health-check';
  }

  /**
   * Adds a new account to the instance.
   *
   * First a new Drupal user is created. Additional data is
   * stored in the wisski_cloud_account_manager_accounts table.
   *
   * @param array $account
   *   The account to add.
   *
   * @return array
   *   The account id with validation code.
   */
  public function addAccount(array $account): array {
    try {

      // Get current language.
      $language = $this->languageManager->getCurrentLanguage()->getId();
      // Create Drupal user object.
      $user = User::create();

      // Mandatory.
      $user->setPassword($account['password']);
      $user->enforceIsNew();
      $user->setEmail($account['email']);
      $user->setUsername($account['username']); // This username must be unique and accepts only string. It must be a minimum of two characters and can contain only lowercase letters, numbers, and underscores.

      // Optional.
      $user->set('init', $account['email']);
      $user->set('langcode', $language);
      $user->set('preferred_langcode', $language);
      $user->set('preferred_admin_langcode', $language);

      // Save user.
      $result = $user->save();
      $validationCode = $this->generateValidationCode();

      $database = $this->database;

      // Check if a record with this user ID already exists.
      $query = $database->select('wisski_cloud_account_manager_accounts', 'w')
        ->fields('w', ['uid'])
        ->condition('w.uid', $user->id(), '=');
      $result = $query->execute()->fetchField();

      if ($result) {
        throw new \Exception('A record with this user ID already exists.');
      }
      $query = $database->insert('wisski_cloud_account_manager_accounts')
        ->fields([
          'uid' => $user->id(),
          'person_name' => $account['personName'],
          'organisation' => $account['organisation'],
          'subdomain' => $account['subdomain'],
          'validation_code' => $validationCode,
        ]);
      $query->execute();
      return [
          'userId' => $user->id(),
          'username' => $account['username'],
          'personName' => $account['personName'],
          'email' => $account['email'],
          'subdomain' => $account['subdomain'],
          'validationCode' => $validationCode ,
      ];
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('wisski_cloud_account_manager')
        ->error('Can not create account: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Error creating account. See logs for details.'));
      return [];
    }
  }

  /**
   * Check for redundant account data.
   *
   * @param string $column
   *   The column to check.
   * @param string $value
   *   The value to check.
   */
  public function checkForRedundantAccountData(string $column, string $value) {
    $database = \Drupal::database();
    $query = $database->select('wisski_cloud_account_manager_accounts', 'w');
    $query->fields('w', [$column]);
    $query->condition('w.' . $column, $value, '=');
    if ($query->execute()->fetchField()) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

   /**
   * Provisions an account in the WissKI Cloud account manager daemon.
   *
   * @param string $action
   *  The action to perform: create, delete, get.
   * @param int $aid
   *   The account ID to provision.
   *
   * @return array
   *   The response from the daemon.
   */
  public function crudInstance($action, $aid) {
    try {

      $aid = trim($aid);
      // Build the query string from the parameters.
      $query_string = http_build_query([
        'aid' => $aid,
      ]);

      // Determine the route part depending on the action.
      switch ($action) {
        case 'create':
          $restMethod = 'put';
          $routePart = $this->PROVISION_ROUTE;
          break;
        case 'delete':
          $restMethod = 'delete';
          $routePart = $this->DELETE_ROUTE;
          break;
        default:
          $restMethod = 'get';
          $routePart = $this->INFO_ROUTE;
          break;
      }

      // Combine the base URL and the query string.
      $request_url = $this->DAEMON_URL . $routePart . '?' . $query_string;
      // Send the GET request using the `drupal_http_request()` function.
      $response = $this->httpClient->request($restMethod, $request_url);
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
        ->get('wisski_cloud_account_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Can not communicate with the WissKI Cloud account manager daemon. Try again later or contact @email.',
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
   * Deletes an account from the WissKI Cloud account manager daemon.
   *
   * @param int $uid
   *   The user ID of the account to delete.
   *
   * @return array
   *   The response from the daemon.
   */
  public function deleteAccount(int $aid): array {
    try {
      $database = $this->database;

      // Select the user ID from the accounts table.
      $selectQuery = $database->select('wisski_cloud_account_manager_accounts', 'w')
      ->fields('w', ['uid'])
      ->condition('w.aid', $aid, '=');
      $uid = $selectQuery->execute()->fetchField();

      // Delete the account from the accounts table.
      $deleteQuery = $database->delete('wisski_cloud_account_manager_accounts')
        ->condition('aid', $aid, '=');
        $deleteQuery->execute();

        // Delete the user if exists.
      $user = User::load($uid);
      $user ? $user->delete() : NULL;
      $this->messenger
      ->addMessage($this->stringTranslation->translate('Account deleted successfully.'));
      return [
        'message' => 'Account deleted successfully.',
        'success' => TRUE,
      ];
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
      ->get('wisski_cloud_account_manager')
      ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Something went wrong!' . $e->getMessage()));
    }
  }

  /**
   * Generates a random validation code with 32 characters.
   *
   * @return string
   *  The generated validation code.
   */
  function generateValidationCode() {
    // Generate 16 random bytes and convert them to a 32 characters hexadecimal string
    $code = bin2hex(random_bytes(16));
    return $code;
  }

  /**
   * Query accounts from the WissKI Cloud account manager daemon.
   *
   * @param int $aid
   *  The account ID to query.
   *
   * @return array[aid, name, mail, organisation, person_name, provisioned, status, subdomain, uid, validation_code]
   *   The accounts response from the daemon.
   */
  public function getAccounts($aid = null): array {
    try {
      $query = $this->database->select('wisski_cloud_account_manager_accounts', 'w');
      $query->fields('w', ['aid', 'organisation', 'person_name', 'provisioned', 'subdomain', 'uid', 'validation_code']);
      $query->leftjoin('users_field_data', 'u', 'w.uid = u.uid');
      $query->fields('u', ['name', 'mail', 'status']);
      if ($aid) {
        $query->condition('w.aid', $aid, '=');
      }
      $accounts = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
      return $accounts;
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
      ->get('wisski_cloud_account_manager')
      ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
      ->addError($this->stringTranslation->translate('Can not communicate with the WissKI Cloud account manager daemon. Try again later or contact cloud@wiss-ki.eu.'));
      return [];
    }
  }

  /**
   * Checks if the WissKI Cloud account manager daemon is available.
   *
   * @return array[message:string, success:boolean]
   *
   */
  public function healthCheck() {
    try {
      // Combine the base URL and the query string.
      $request_url = $this->DAEMON_URL . $this->HEALTH_CHECK_ROUTE;
      // Send the GET request using the `drupal_http_request()` function.
      $response = $this->httpClient->request('get', $request_url);
      // Check the response and handle the data accordingly.
      if ($response->getStatusCode() == 200) {
        // Request successful, handle the data in $response->data.
        return [
          "message" => "WissKI Cloud account manager daemon is available.",
          'success' => TRUE,
        ];
      }
      else {
        // Request failed, handle the error.
        return [
          "message" => 'WissKI Cloud account manager daemon is not available: ' . $response->getStatusCode(),
          'success' => FALSE,
        ];
      }
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('wisski_cloud_account_manager')
        ->error('Something went wrong: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Can not communicate with the WissKI Cloud account manager daemon. Try again later or contact @adminMail.',
          ['@adminMail'
          => $this->ADMIN_EMAIL]));
    }
  }
  /**
   * Purges an account via the WissKI Cloud account manager daemon.
   * Deletes the account from the accounts table and the Drupal user.
   * Deletes the instance via the daemon from the WissKI Cloud.
   * @param int $aid
   *  The account ID to purge.
   * @return array
   *  The response from the daemon.
   */
  public function purgeAccount(int $aid) {
    try {
      // Get the account ID from the route.

      // @todo Why is there a space in the account ID?
      $aid = trim($aid);


      // Delete the instance via the daemon from the WissKI Cloud.
      $response = $this->crudInstance('delete', $aid);

      if ($response['success']) {
        // Delete the account and Drupal user.
        $this->deleteAccount($aid);
        $this->messenger
          ->addMessage($this->stringTranslation->translate('Cloud and Drupal account purged successfully.'));
          return [
            'data' => NULL,
            'error' => NULL,
            'message' => 'Cloud and Drupal account purged successfully.',
            'success' => TRUE,
      ];

      }
      else {
        if (!$response['error']) {
          $this->deleteAccount($aid);
          // No success and no error.
          $this->messenger
          ->addMessage($this->stringTranslation->translate('Cloud Account not found, deleted only Drupal user.'));
          return [
          'data' => NULL,
          'error' => NULL,
          'message' => $response['message'],
          'success' => FALSE,
        ];
        }
        else  {
          // No success and error.
        $this->messenger
          ->addError($this->stringTranslation->translate('Something went wrong: ' . $response['message']));
        $this->loggerFactory->get('wisski_cloud_account_manager')->error($response['error']);
          return [
          'data' => NULL,
          'error' => $response['error'],
          'message' => 'Something went wrong: ' . $response['message'],
          'success' => FALSE,
        ];
        }
      }
    }

    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('wisski_cloud_account_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Something went wrong!' . $e->getMessage()));
    }
  }

   /**
   * Sends a validation email to the given email address.
   *
   * @param string $email
   *   The email address to send the validation email to.
   * @param string $personName
   *   The person name to be used in the validation email.
   * @param string $validationCode
   *   The validation code to be used in the validation link.
   */
  public function sendValidationEmail(string $email, string $personName, string $validationCode): void {
    try {
      $module = 'wisski_cloud_account_manager';
      $key = 'wisski_cloud_account_validation';
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
      $to = $email;

      $validationLink = $this->requestStack->getCurrentRequest()
        ->getSchemeAndHttpHost() . '/wisski-cloud-account-manager/validate/' . $validationCode;

        $message = $this->twig->render('@wisski_cloud_account_manager/wisski-cloud-account-manager-validation-email.html.twig', [
          'personName' => $personName,
          'validationLink' => $validationLink,
        ]);


      $params['subject'] = $this->stringTranslation->translate('WissKI Cloud account validation');
      $params['message'] = Markup::create($message);
      $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, TRUE);
      if ($result['result'] != TRUE) {
        $this->loggerFactory
          ->get('wisski_cloud_account_manager')
          ->error('Email sending operation ended with error: ' . $result['message']);
      }
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('wisski_cloud_account_manager')
        ->error('Email sending operation ended with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Email sending operation ended with error. Try again later or contact cloud@wiss-ki.eu.'));
    }

  }


  /**
   * Validates the account.
   *
   * @param string $validationCode
   *   The validation code to check.
   *
   * @return array [uid, name]
   */
  public function validateAccount(string $validationCode): array {
    try {
      $selectQuery = $this->database->select('wisski_cloud_account_manager_accounts', 'w')
        ->fields('w', ['aid', 'organisation', 'person_name', 'provisioned','subdomain', 'validation_code'])
        ->condition('w.validation_code', $validationCode, '=');
      $selectQuery->join('users_field_data', 'u', 'w.uid = u.uid');
      $selectQuery->fields('u', ['uid', 'name', 'mail', 'status']);
      $account = $selectQuery->execute()->fetchAll(\PDO::FETCH_ASSOC);
      if (isset($account[0]['status'])) {
        if ($account[0]['status'] == 0) {
        $updateQuery = $this->database->update('users_field_data')
          ->fields(['status' => 1])
          ->condition('uid', $account['0']['uid'], '=');
        $updateQuery->execute();
        $account = $selectQuery->execute()->fetchAll(\PDO::FETCH_ASSOC);
        $this->messenger
          ->addMessage($this->stringTranslation->translate('Account validated successfully.'));

        } else {
          $this->messenger
            ->addMessage($this->stringTranslation->translate('Account already validated.'));
        }
        return [
          'uid' => $account[0]['uid'],
          'name' => $account[0]['name']];
      }
      else {
        $this->messenger
          ->addError($this->stringTranslation->translate('Account validation failed. Please contact @adminEmail.',
            ['@adminEmail'
            => $this->ADMIN_EMAIL]));
            return [];
      }
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('wisski_cloud_account_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Can not communicate with the WissKI Cloud account manager daemon. Try again later or contact cloud@wiss-ki.eu.'));
      return [];
    }
  }

  /**
   * Validates the account.
   *
   * @param string $validationCode
   *   The validation code to check.
   *
   * @return array [uid, name]
   */
  public function forceValidateAccount(string $aid): array {
    try {
      $selectQuery = $this->database->select('wisski_cloud_account_manager_accounts', 'w')
        ->fields('w', ['aid', 'organisation', 'person_name', 'provisioned','subdomain', 'validation_code'])
        ->condition('w.aid', $aid, '=');
      $selectQuery->join('users_field_data', 'u', 'w.uid = u.uid');
      $selectQuery->fields('u', ['uid', 'name', 'mail', 'status']);
      $account = $selectQuery->execute()->fetchAll(\PDO::FETCH_ASSOC);
      if (isset($account[0]['status'])) {
        if ($account[0]['status'] == 0) {
        $updateQuery = $this->database->update('users_field_data')
          ->fields(['status' => 1])
          ->condition('uid', $account['0']['uid'], '=');
        $updateQuery->execute();
        $account = $selectQuery->execute()->fetchAll(\PDO::FETCH_ASSOC);
        $this->messenger
          ->addMessage($this->stringTranslation->translate('Account validated successfully.'));

        } else {
          $this->messenger
            ->addMessage($this->stringTranslation->translate('Account already validated.'));
        }
        return [
          'uid' => $account[0]['uid'],
          'name' => $account[0]['name']];
      }
      else {
        $this->messenger
          ->addError($this->stringTranslation->translate('Account validation failed. Please contact @adminEmail.',
            ['@adminEmail'
            => $this->ADMIN_EMAIL]));
            return [];
      }
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('wisski_cloud_account_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Can not communicate with the WissKI Cloud account manager daemon. Try again later or contact cloud@wiss-ki.eu.'));
      return [];
    }
  }

}
