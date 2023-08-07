<?php

namespace Drupal\wisski_cloud_account_manager;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Language\LanguageManagerInterface;
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
   * The base URL of the WissKI Cloud account manager daemon.
   *
   * @var string
   */
  private string $DAEMON_URL;

  /**
   * The URL path to the POST endpoint.
   *
   * @var string
   */
  private string $USER_POST_URL_PART = '/user';

  /**
   * The URL path to the GET endpoint.
   *
   * @var string
   */
  private string $FILTER_BY_DATA_URL_PART = '/user/by_data';

  /**
   * The string translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected TranslationInterface $stringTranslation;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;



  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * Class constructor.
   */
  public function __construct(
    TranslationInterface $stringTranslation,
    MessengerInterface $messenger,
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    MailManagerInterface $mailManager,
    LanguageManagerInterface $languageManager) {
    // Services from container.
    $this->stringTranslation = $stringTranslation;
    $this->messenger = $messenger;
    $this->httpClient = $httpClient;
    $this->mailManager = $mailManager;
    $this->languageManager = $languageManager;

    // Settings.
    $settings = $configFactory
      ->getEditable('wisski_cloud_account_manager.settings');
    $this->settings = $settings;

    // Set the daemon URL and the URL parts class variables.
    $this->DAEMON_URL = $settings->get('daemonUrl') ?: 'http://wisski_cloud_api_daemon:3000/wisski-cloud-daemon/api/v1';
    $this->USER_POST_URL_PART = $settings->get('userPostUrlPath') ?: '/user';
    $this->FILTER_BY_DATA_URL_PART = $settings->get('userFilterByData') ?: '/user/by_data';

  }

  /**
   * Adds a new account to the WissKI Cloud account manager daemon.
   *
   * @param array $account
   *   The account to add.
   *
   * @return array
   *   The response from the daemon (user id with validation code).
   */
  public function addAccount(array $account): array {
    $request = [
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'body' => json_encode($account),
    ];
    $userPostUrl = $this->DAEMON_URL . $this->USER_POST_URL_PART;
    $response = $this->httpClient->post($userPostUrl, $request);
    return array_merge(json_decode($response->getBody()->getContents(), TRUE), ['statusCode' => $response->getStatusCode()]);
  }

  /**
   * Check if an account with the given data already exists.
   *
   * @param array $dataToCheck
   *   The data to check.
   *
   * @return array
   *   The response from the daemon.
   */
  public function checkAccountData($dataToCheck): array {
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
        "accountData" => json_decode($response->getBody()->getContents(), TRUE),
      ];
    }
    else {
      // Request failed, handle the error.
      return [
        "message" => 'Request failed with code: ' . $response->getStatusCode(),
        "accountData" => [],
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
    $module = 'wisski_cloud_account_manager';
    $key = 'wisski_cloud_account_validation';
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $to = $email;

    $validationLink = \Drupal::request()->getSchemeAndHttpHost() . '/wisski-cloud-account-manager/validate/' . $validationCode;

    $params['message'] = Markup::create($this->stringTranslation->translate('<p>Please validate your account by clicking on this <a href="@validationLink" target="_blank">link</a> or copy this to the address bar of your browser: <p>@validationLink</p>.</p>', ['@validationLink' => $validationLink]));
    $params['subject'] = $this->stringTranslation->translate('WissKI Cloud account validation');

    $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, TRUE);
    dpm($result, 'result');
    if ($result['result'] === TRUE) {
      $this->messenger
        ->addMessage($this->stringTranslation->translate('Email sent successfully.'));
    }
    else {
      $this->messenger
        ->addMessage($this->stringTranslation->translate('There was an error sending the email.'), 'error');
    }
  }

}
