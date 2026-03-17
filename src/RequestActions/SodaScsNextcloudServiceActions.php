<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\RequestActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Message;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Implements Nextcloud OCS API request actions.
 *
 * Covers app-password lifecycle:
 *  - create: GET /ocs/v2.php/core/getapppassword.
 *  - delete: DELETE /ocs/v2.php/core/apppassword.
 *  - get: GET /ocs/v1.php/cloud/user (current-user info).
 *  - health: GET /status.php.
 *
 * Authentication is either Basic Auth (username + password) or OIDC Bearer
 * token.  Pass 'token' in $requestParams for Bearer, or 'username'/'password'
 * for Basic Auth.
 *
 * The 'appName' key in $requestParams is sent as the User-Agent header and
 * becomes the visible label of the generated app password in Nextcloud's
 * security settings.
 */
class SodaScsNextcloudServiceActions implements SodaScsServiceRequestInterface {

  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The SCS service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  /**
   * Constructs a new SodaScsNextcloudServiceActions object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers $sodaScsServiceHelpers
   *   The SCS service helpers.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    TranslationInterface $stringTranslation,
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->settings = $config_factory->get('soda_scs_manager.settings');
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Sends an HTTP request and returns a normalised response array.
   *
   * @param array $request
   *   Keys: 'method', 'route', 'headers', optionally 'body'/'form_params'.
   *
   * @return array
   *   Keys: 'message', 'data' (with 'nextcloudResponse'), 'statusCode',
   *   'success', 'error'.
   */
  public function makeRequest($request): array {
    $requestParams['headers'] = $request['headers'];
    if (isset($request['body'])) {
      $requestParams['body'] = $request['body'];
    }
    if (isset($request['form_params'])) {
      $requestParams['form_params'] = $request['form_params'];
    }
    $requestParams['timeout'] = $request['timeout'] ?? 30;

    try {
      $response = $this->httpClient->request($request['method'], $request['route'], $requestParams);

      return [
        'message' => 'Request succeeded',
        'data' => ['nextcloudResponse' => $response],
        'statusCode' => $response->getStatusCode(),
        'success' => TRUE,
        'error' => '',
      ];
    }
    catch (RequestException $e) {
      $logger = $this->loggerFactory->get('soda_scs_manager');
      $requestDump = Message::toString($e->getRequest());
      $responseDump = $e->hasResponse() ? Message::toString($e->getResponse()) : '';
      $logger->error('Nextcloud request failed: @message', ['@message' => $e->getMessage()]);
      $logger->debug('Failed Nextcloud request: %request', ['%request' => $requestDump]);
      if ($responseDump !== '') {
        $logger->debug('Failed Nextcloud response: %response', ['%response' => $responseDump]);
      }

      $detailedError = $e->getMessage();
      if ($e->hasResponse()) {
        $responseBody = (string) $e->getResponse()->getBody();
        if ($responseBody !== '') {
          $detailedError .= ' | body: ' . $responseBody;
        }
      }

      return [
        'message' => $this->t('Request failed with code @code', ['@code' => $e->getCode()]),
        'data' => ['nextcloudResponse' => $e],
        'statusCode' => $e->getCode(),
        'success' => FALSE,
        'error' => $detailedError,
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error('Unexpected Nextcloud request failure: @message', ['@message' => $e->getMessage()]);

      return [
        'message' => $this->t('Request failed with code @code', ['@code' => $e->getCode()]),
        'data' => ['nextcloudResponse' => $e],
        'statusCode' => $e->getCode(),
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Builds a request to create (generate) a Nextcloud app password.
   *
   * Nextcloud endpoint: GET /ocs/v2.php/core/getapppassword.
   *
   * The endpoint converts the caller's current session credentials into a new
   * named app password.  The 'appName' value is sent as User-Agent and appears
   * as the token label in Nextcloud → Settings → Security.
   *
   * Authentication options (pass exactly one):
   *   - Basic Auth: supply 'username' and 'password' in $requestParams.
   *   - OIDC Bearer: supply 'token' (ID/access token) in $requestParams.
   *
   * Note: the endpoint returns HTTP 403 if you are already authenticated with
   * an app password.  Only real passwords or OIDC tokens work here.
   *
   * @param array $requestParams
   *   Required keys:
   *     - 'appName' (string): label for the new app password.
   *   Authentication (one set required):
   *     - 'username' + 'password' for Basic Auth, OR
   *     - 'token' for OIDC Bearer token.
   *
   * @return array
   *   Request array ready to pass to makeRequest().
   */
  public function buildCreateRequest(array $requestParams): array {
    $nextcloudSettings = $this->sodaScsServiceHelpers->initNextcloudSettings();
    $route = rtrim($nextcloudSettings['baseUrl'], '/') . '/ocs/v2.php/core/getapppassword?format=json';

    $headers = [
      'OCS-APIRequest' => 'true',
      'Accept' => 'application/json',
      'User-Agent' => $requestParams['appName'] ?? 'SCS Manager',
    ];

    $headers['Authorization'] = $this->buildAuthHeader($requestParams);

    return [
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => $headers,
    ];
  }

  /**
   * Builds a request to fetch the current authenticated user's info.
   *
   * Nextcloud endpoint: GET /ocs/v1.php/cloud/user.
   *
   * Useful to resolve a login name to the canonical Nextcloud username after
   * authentication via OIDC (where the login name may differ from the UID).
   *
   * @param array $requestParams
   *   Authentication keys as described in buildCreateRequest().
   *
   * @return array
   *   Request array ready to pass to makeRequest().
   */
  public function buildGetRequest(array $requestParams): array {
    $nextcloudSettings = $this->sodaScsServiceHelpers->initNextcloudSettings();
    $route = rtrim($nextcloudSettings['baseUrl'], '/') . '/ocs/v1.php/cloud/user?format=json';

    return [
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'OCS-APIRequest' => 'true',
        'Accept' => 'application/json',
        'Authorization' => $this->buildAuthHeader($requestParams),
      ],
    ];
  }

  /**
   * Builds a request to list all Nextcloud users (admin only).
   *
   * Nextcloud endpoint: GET /ocs/v1.php/cloud/users.
   *
   * @param array $requestParams
   *   Authentication keys (admin credentials recommended).
   *
   * @return array
   *   Request array ready to pass to makeRequest().
   */
  public function buildGetAllRequest(array $requestParams): array {
    $nextcloudSettings = $this->sodaScsServiceHelpers->initNextcloudSettings();
    $route = rtrim($nextcloudSettings['baseUrl'], '/') . '/ocs/v1.php/cloud/users?format=json';

    return [
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'OCS-APIRequest' => 'true',
        'Accept' => 'application/json',
        'Authorization' => $this->buildAuthHeader($requestParams),
      ],
    ];
  }

  /**
   * Builds a health-check request against /status.php.
   *
   * @param array $requestParams
   *   No authentication required for this public endpoint.
   *
   * @return array
   *   Request array ready to pass to makeRequest().
   */
  public function buildHealthCheckRequest(array $requestParams): array {
    $nextcloudSettings = $this->sodaScsServiceHelpers->initNextcloudSettings();
    $route = rtrim($nextcloudSettings['baseUrl'], '/') . '/status.php';

    return [
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Accept' => 'application/json',
      ],
    ];
  }

  /**
   * Builds a request to delete the app password currently in use.
   *
   * Nextcloud endpoint: DELETE /ocs/v2.php/core/apppassword.
   *
   * Must authenticate with the app password you want to revoke (not the real
   * password).  Useful for clean-up when a component is deleted.
   *
   * @param array $requestParams
   *   Required:
   *     - 'username' (string): Nextcloud username.
   *     - 'password' (string): The app password to revoke.
   *
   * @return array
   *   Request array ready to pass to makeRequest().
   */
  public function buildDeleteRequest(array $requestParams): array {
    $nextcloudSettings = $this->sodaScsServiceHelpers->initNextcloudSettings();
    $route = rtrim($nextcloudSettings['baseUrl'], '/') . '/ocs/v2.php/core/apppassword';

    return [
      'success' => TRUE,
      'method' => 'DELETE',
      'route' => $route,
      'headers' => [
        'OCS-APIRequest' => 'true',
        'Accept' => 'application/json',
        'Authorization' => $this->buildAuthHeader($requestParams),
      ],
    ];
  }

  /**
   * Not applicable for Nextcloud; returns an empty stub.
   *
   * Nextcloud uses Basic Auth or OIDC Bearer tokens directly — there is no
   * separate token-fetch step needed at the application level.
   *
   * {@inheritdoc}
   */
  public function buildTokenRequest(array $requestParams = []): array {
    return [
      'success' => TRUE,
      'method' => '',
      'route' => '',
      'headers' => [],
    ];
  }

  /**
   * Not applicable for Nextcloud app-password management; returns a stub.
   *
   * {@inheritdoc}
   */
  public function buildUpdateRequest(array $requestParams): array {
    return [
      'success' => FALSE,
      'method' => '',
      'route' => '',
      'headers' => [],
      'error' => 'Update is not supported for Nextcloud app passwords.',
    ];
  }

  /**
   * Builds a request to initiate Nextcloud Login Flow v2.
   *
   * POST to /index.php/login/v2 (anonymous). Returns poll token and login URL.
   * See: https://docs.nextcloud.com/server/stable/developer_manual/client_apis/LoginFlow/
   *
   * @return array
   *   Request array ready to pass to makeRequest().
   */
  public function buildLoginFlowV2InitRequest(): array {
    $nextcloudSettings = $this->sodaScsServiceHelpers->initNextcloudSettings();
    $route = rtrim($nextcloudSettings['baseUrl'], '/') . '/index.php/login/v2';

    return [
      'success' => TRUE,
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ];
  }

  /**
   * Builds a request to poll Nextcloud Login Flow v2 for completion.
   *
   * POST to the poll endpoint with token. Returns 404 until user completes
   * login, then 200 with server, loginName, appPassword.
   *
   * @param string $pollEndpoint
   *   The poll endpoint URL from init response.
   * @param string $token
   *   The poll token from init response.
   *
   * @return array
   *   Request array ready to pass to makeRequest().
   */
  public function buildLoginFlowV2PollRequest(string $pollEndpoint, string $token): array {
    return [
      'success' => TRUE,
      'method' => 'POST',
      'route' => $pollEndpoint,
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json',
      ],
      'body' => 'token=' . rawurlencode($token),
    ];
  }

  /**
   * Builds the Authorization header value for a given set of params.
   *
   * @param array $requestParams
   *   Either 'token' (Bearer) or 'username'+'password' (Basic).
   *
   * @return string
   *   The fully-formed Authorization header value.
   */
  protected function buildAuthHeader(array $requestParams): string {
    if (!empty($requestParams['token'])) {
      return 'Bearer ' . $requestParams['token'];
    }
    $user = $requestParams['username'] ?? '';
    $pass = $requestParams['password'] ?? '';
    return 'Basic ' . base64_encode($user . ':' . $pass);
  }

}
