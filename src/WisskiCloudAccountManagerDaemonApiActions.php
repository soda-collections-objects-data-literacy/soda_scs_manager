<?php

namespace Drupal\wisski_cloud_account_manager;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use GuzzleHttp\ClientInterface;

/**
 * Handles the communication with the WissKI Cloud account manager daemon.
 */
class WisskiCloudAccountManagerDaemonApiActions {

  const DAEMON_URL = 'http://wisski_cloud_api_daemon:3000/wisski-cloud-daemon/api/v1/user/';

  const FILTER_BY_DATA_URL_PART = 'by_data';

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
   * Class constructor.
   */
  public function __construct(TranslationInterface $stringTranslation, MessengerInterface $messenger, ClientInterface $httpClient, ConfigFactoryInterface $configFactory) {
    $this->stringTranslation = $stringTranslation;
    $this->messenger = $messenger;
    $this->httpClient = $httpClient;
    $this->settings = $configFactory
      ->getEditable('wisski_cloud_account_manager.settings');
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
    dpm($account, 'account');
    $request = [
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'body' => json_encode($account),
    ];
    dpm($request, 'request');
    $response = $this->httpClient->post(self::DAEMON_URL, $request);
    return json_decode($response->getBody()->getContents(), TRUE);
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
    $request_url = self::DAEMON_URL . self::FILTER_BY_DATA_URL_PART . '?' . $query_string;

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

}
