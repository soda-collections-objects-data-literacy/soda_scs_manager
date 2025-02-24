<?php

namespace Drupal\soda_scs_manager\RequestActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsOpenGdpServiceActions implements SodaScsServiceRequestInterface {

  use StringTranslationTrait;

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
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
  ) {
    $this->settings = $configFactory->get('soda_scs_manager.settings');
    $this->httpClient = $httpClient;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the create request for the OpenGDB service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildCreateRequest(array $requestParams): array {
    switch ($requestParams['type']) {
      case 'repository':
        $url = $this->settings->get('triplestore')['routes']['repository']['createUrl'];
        $body = json_encode([
          'id' => $requestParams['body']['machineName'],
          'title' => $requestParams['body']['title'],
          "publicRead" => $requestParams['body']['publicRead'],
          "publicWrite" => $requestParams['body']['publicWrite'],
        ]);
        break;

      case 'user':
        $url = $this->settings->get('triplestore')['routes']['user']['createUrl'];
        $body = json_encode([
          'password' => $requestParams['body']['password'],
          "grantedAuthorities" => [
            "ROLE_USER",
            "READ_REPO_" . $requestParams['body']['machineName'],
            "WRITE_REPO_" . $requestParams['body']['machineName'],
          ],
        ]);
        break;

      default:
        $url = '';
        $body = json_encode([]);

    }

    if (empty($url)) {
      throw new MissingDataException('Create URL setting is not set.');
    }

    $route = $url . implode('/', $requestParams['routeParams']);

    if ($requestParams['queryParams']) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'type' => $requestParams['type'],
      'success' => TRUE,
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($this->settings->get('triplestore')['openGdpSettings']['adminUsername'] . ':' . $this->settings->get('triplestore')['openGdpSettings']['adminPassword']),
      ],
      'body' => $body,
    ];
  }

  /**
   * Builds the create request for the OpenGDB service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildGetAllRequest(array $requestParams): array {
    return [];
  }

  /**
   * Build request to get all stacks.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildGetRequest($requestParams): array {
    switch ($requestParams['type']) {
      case 'repository':
        $url = $this->settings->get('triplestore')['routes']['repository']['readOneUrl'];
        break;

      case 'user':
        $url = $this->settings->get('triplestore')['routes']['user']['readOneUrl'];
        break;

      default:
        $url = '';

    }

    if (empty($url)) {
      throw new MissingDataException('Get URL setting is not set.');
    }

    $route = $url . implode('/', $requestParams['routeParams']);

    if ($requestParams['queryParams']) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'type' => $requestParams['type'],
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($this->settings->get('triplestore')['openGdpSettings']['adminUsername'] . ':' . $this->settings->get('triplestore')['openGdpSettings']['adminPassword']),
      ],
    ];
  }

  /**
   * Builds the health check request.
   *
   * @return array
   *   The health check request.
   */
  public function buildHealthCheckRequest(): array {
    $route = $this->settings->get('triplestore')['routes']['healthCheck']['url'];
    if (empty($route)) {
      throw new MissingDataException('Health check URL setting is not set.');
    }

    return [
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($this->settings->get('triplestore')['openGdpSettings']['adminUsername'] . ':' . $this->settings->get('triplestore')['openGdpSettings']['adminPassword']),
      ],
    ];
  }

  /**
   * Builds the update request for the OpenGDB service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildUpdateRequest(array $requestParams): array {
    switch ($requestParams['type']) {
      case 'repository':
        $url = $this->settings->get('triplestore')['routes']['repository']['updateUrl'];
        $body = json_encode($requestParams['body']);
        break;

      case 'user':
        $url = $this->settings->get('triplestore')['routes']['user']['updateUrl'];
        $body = json_encode($requestParams['body']);
        break;

      default:
        $url = '';
        $body = json_encode([]);

    }

    if (empty($url)) {
      throw new MissingDataException('Get URL setting is not set.');
    }

    $route = $url . implode('/', $requestParams['routeParams']);

    if ($requestParams['queryParams']) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'type' => $requestParams['type'],
      'success' => TRUE,
      'method' => 'PUT',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($this->settings->get('triplestore')['openGdpSettings']['adminUsername'] . ':' . $this->settings->get('triplestore')['openGdpSettings']['adminPassword']),
      ],
      'body' => $body,
    ];
  }

  /**
   * Builds the delete request for the OpenGDB service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildDeleteRequest(array $requestParams): array {
    // @todo May use $requestParams['type'] if there is no other logic.
    switch ($requestParams['type']) {
      case 'repository':
        $url = $this->settings->get('triplestore')['routes']['repository']['deleteUrl'];
        break;

      case 'user':
        $url = $this->settings->get('triplestore')['routes']['user']['deleteUrl'];
        break;

      default:
        $url = '';

    }

    if (empty($url)) {
      throw new MissingDataException('Delete URL setting is not set.');
    }

    $route = $url . implode('/', $requestParams['routeParams']);

    if ($requestParams['queryParams']) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'type' => $requestParams['type'],
      'success' => TRUE,
      'method' => 'DELETE',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($this->settings->get('triplestore')['openGdpSettings']['adminUsername'] . ':' . $this->settings->get('triplestore')['openGdpSettings']['adminPassword']),
      ],
    ];
  }

  /**
   * Make the API request.
   *
   * @param array $request
   *   The request array for the makeRequest function.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsRequestException
   */
  public function makeRequest($request): array {
    // Assemble requestParams.
    $requestParams['headers'] = $request['headers'];
    if (isset($request['body'])) {
      $requestParams['body'] = $request['body'];
    }
    // Send the request.
    try {
      $response = $this->httpClient->request($request['method'], $request['route'], $requestParams);
      $response = json_decode($response->getBody()->getContents(), TRUE);

      return [
        'message' => 'Request succeeded.',
        'data' => [
          'openGdbResponse' => $response,
        ],
        'success' => TRUE,
        'error' => '',
      ];
    }
    catch (ClientException $e) {
      // @todo User not exist is ok, try to make this no error
      if ($request['type'] === 'user' && $e->getCode() === 404) {
        $username = array_slice(explode('/', $request['route']), -1)[0];
        $this->messenger
          ->addWarning(
            $this->t(
              "OpenGDB user: @username does not exist. Try to create it for you...",
              [
                '@username' => $username,
              ]
            )
          );
        return [
          'message' => 'Request succeeded, but user does not exist.',
          'data' => [
            'openGdbResponse' => $e,
          ],
          'success' => FALSE,
          'error' => $e->getMessage(),
        ];
      }
      // @todo Implement tracing in every logger.
      $this->loggerFactory->get('soda_scs_manager')->error("OpenGDB request failed with code @code error: @error trace @trace", [
        '@code' => $e->getCode(),
        '@error' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
    }
    $this->messenger->addError($this->t("OpenGDB request failed. See logs for more details."));
    return [
      'message' => 'Request failed with code @code' . $e->getCode(),
      'data' => [
        'openGdbResponse' => $e,
      ],
      'success' => FALSE,
      'error' => $e->getMessage(),
    ];
  }

  /**
   * Build token request.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildTokenRequest(array $requestParams): array {

    $route = $this->settings->get('triplestore')['routes']['token']['tokenUrl'] ?? throw new MissingDataException('Token URL setting is not set.');

    $body = json_encode($requestParams['body']);

    return [
      'success' => TRUE,
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      'body' => $body,
    ];
  }

}
