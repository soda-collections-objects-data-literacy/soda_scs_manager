<?php

namespace Drupal\wisski_cloud_account_manager;

use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslationInterface;
use GuzzleHttp\ClientInterface;

/**
 * Handles the communication with the WissKI Cloud account manager daemon.
 */
class WisskiCloudAccountManagerDaemonApiActions {

  use DependencySerializationTrait;

  /**
   * The URL path to all account data GET endpoint.
   *
   * @var string
   */
  private string $ALL_ACCOUNTS = '/account/all';

  /**
   * The URL path to the POST endpoint.
   *
   * @var string
   */
  private string $ACCOUNT_POST_URL_PART = '/account';

  /**
   * The URL path to provision and validation GET endpoint.
   *
   * @var string
   */
  private string $ACCOUNT_PROVISION_AND_VALIDATION_URL_PART;

  /**
   * The URL path to provision and validation GET endpoint.
   *
   * @var string
   */
  private string $ACCOUNT_VALIDATION_URL_PART = '/account/validation';

  /**
   * The base URL of the WissKI Cloud account manager daemon.
   *
   * @var string
   */
  private string $DAEMON_URL;

  /**
   * The URL path to the filter by account data GET endpoint.
   *
   * @var string
   */
  private string $FILTER_BY_DATA_URL_PART = '/account/by_data';

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The string translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected TranslationInterface $stringTranslation;

  /**
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    ClientInterface $httpClient,
    LanguageManagerInterface $languageManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    MailManagerInterface $mailManager,
    RequestStack $requestStack,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $settings = $configFactory
      ->getEditable('wisski_cloud_account_manager.settings');
    $this->settings = $settings;
    $this->stringTranslation = $stringTranslation;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->httpClient = $httpClient;
    $this->mailManager = $mailManager;
    $this->requestStack = $requestStack;
    $this->languageManager = $languageManager;

    // Set the daemon URL and the URL parts class variables.
    $this->DAEMON_URL = $settings->get('daemonUrl') ?: 'http://wisski_cloud_api_daemon:3000/wisski-cloud-daemon/api/v1';
    $this->ALL_ACCOUNTS = $settings->get('allAccounts') ?: '/account/all';
    $this->ACCOUNT_POST_URL_PART = $settings->get('accountPostUrlPath') ?: '/account';
    $this->FILTER_BY_DATA_URL_PART = $settings->get('accountFilterByData') ?: '/account/by_data';
    $this->ACCOUNT_PROVISION_AND_VALIDATION_URL_PART = $settings->get('accountProvisionAndValidationUrlPart') ?: '/account/provision_and_validation';
    $this->ACCOUNT_VALIDATION_URL_PART = $settings->get('accountValidationUrlPart') ?: '/account/validation';
  }

  /**
   * Adds a new account to the WissKI Cloud account manager daemon.
   *
   * @param array $account
   *   The account to add.
   *
   * @return array
   *   The response from the daemon (account id with validation code).
   */
  public function addAccount(array $account): array {
    try {
      $request = [
        'headers' => [
          'Content-Type' => 'application/json',
        ],
        'body' => json_encode($account),
      ];
      $accountPostUrl = $this->DAEMON_URL . $this->ACCOUNT_POST_URL_PART;
      $response = $this->httpClient->post($accountPostUrl, $request);
      return json_decode($response->getBody()
        ->getContents(), TRUE);
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('wisski_cloud_account_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Can not communicate with the WissKI Cloud account manager daemon. Try again later or contact cloud@wiss-ki.eu.'));
      return [
        "message" => 'Request failed with exception: ' . $e->getMessage(),
        "data" => [
          'email' => NULL,
          'validationCode' => NULL,
        ],
        'success' => FALSE,
      ];
    }
  }

  /**
   * Check if an account with the given data already exists.
   *
   * @param array $dataToCheck
   *   The data to check. Possible keys are:
   *    - email
   *    - subdomain
   *    - username.
   *
   * @return array
   *   The response from the daemon.
   */
  public function checkAccountData(array $dataToCheck): array {
    try {
      // Build the query string from the parameters.
      $query_string = http_build_query($dataToCheck);

      // Combine the base URL and the query string.
      $request_url = $this->DAEMON_URL . $this->FILTER_BY_DATA_URL_PART . '?' . $query_string;

      // Send the GET request using the `drupal_http_request()` function.
      $response = $this->httpClient->get($request_url);

      // Check the response and handle the data accordingly.
      if ($response->getStatusCode() == 200) {
        // Request successful, handle the data in $response->data.
        return [
          "message" => "Get account data",
          "accountData" => json_decode($response->getBody()->getContents(),
            TRUE)['data'],
          'success' => TRUE,
        ];
      }
      else {
        // Request failed, handle the error.
        return [
          "message" => 'Request failed with code: ' . $response->getStatusCode(),
          "accountData" => [
            'accountWithUsername' => NULL,
            'accountWithEmail' => NULL,
            'accountWithSubdomain' => NULL,
          ],
          'success' => FALSE,
        ];
      }
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('wisski_cloud_account_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Can not communicate with the WissKI Cloud account manager daemon. Try again later or contact cloud@wiss-ki.eu.'));
      return [
        "message" => 'Request failed with exception: ' . $e->getMessage(),
        "accountData" => [
          'accountWithUsername' => NULL,
          'accountWithEmail' => NULL,
          'accountWithSubdomain' => NULL,
        ],
        'success' => FALSE,
      ];
    }
  }

  /**
   * Gets all accounts from the WissKI Cloud account manager daemon.
   *
   * @return array
   *   The accounts response from the daemon.
   */
  public function getAccounts(): array {
    try {
      // Combine the base URL and the query string.
      $request_url = $this->DAEMON_URL . $this->ALL_ACCOUNTS;
      // Send the GET request using the `drupal_http_request()` function.
      $response = $this->httpClient->get($request_url);
      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('wisski_cloud_account_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Can not communicate with the WissKI Cloud account manager daemon. Try again later or contact cloud@wiss-ki.eu.'));
      return [
        "message" => 'Request failed with exception: ' . $e->getMessage(),
        "accounts" => [],
        'success' => FALSE,
      ];
    }
  }

  /**
   * Checks the validation status of the given validation code.
   *
   * @param string $validationCode
   *   The validation code to check.
   *
   * @return array
   *   The account data from the daemon.
   */
  public function validateAccount(string $validationCode): array {
    try {
      $url = $this->DAEMON_URL . $this->ACCOUNT_VALIDATION_URL_PART . '/' . $validationCode;
      $validationResponse = $this->httpClient->put($url);
      return json_decode($validationResponse->getBody()
        ->getContents(), TRUE);
    }
    catch (\Exception $e) {
      // Request failed, handle the error.
      $this->loggerFactory
        ->get('wisski_cloud_account_manager')
        ->error('Request failed with exception: ' . $e->getMessage());
      $this->messenger
        ->addError($this->stringTranslation->translate('Can not communicate with the WissKI Cloud account manager daemon. Try again later or contact cloud@wiss-ki.eu.'));
      return [
        "message" => 'Request failed with exception: ' . $e->getMessage(),
        "accounts" => [],
        'success' => FALSE,
      ];
    }
  }

  /**
   * Sends a validation email to the given email address.
   *
   * @param string $email
   *   The email address to send the validation email to.
   * @param string $validationCode
   *   The validation code to be used in the validation link.
   */
  public function sendValidationEmail(string $email, string $validationCode): void {
    try {
      $module = 'wisski_cloud_account_manager';
      $key = 'wisski_cloud_account_validation';
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
      $to = $email;

      $validationLink = $this->requestStack->getCurrentRequest()
        ->getSchemeAndHttpHost() . '/wisski-cloud-account-manager/validate/' . $validationCode;

      $params['message'] = Markup::create($this->stringTranslation->translate('<p>Please validate your account by clicking on this <a href="@validationLink" target="_blank">link</a> or copy this to the address bar of your browser: <p>@validationLink</p>.</p>', ['@validationLink' => $validationLink]));
      $params['subject'] = $this->stringTranslation->translate('WissKI Cloud account validation');
      $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, TRUE);
      if ($result['result'] === TRUE) {
        $this->messenger
          ->addMessage($this->stringTranslation->translate('Email send successfully.'));
      }
      else {
        $this->messenger
          ->addMessage($this->stringTranslation->translate('There was an error sending the email.'), 'error');

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

}
