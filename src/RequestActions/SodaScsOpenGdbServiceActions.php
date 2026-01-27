<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\RequestActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsOpenGdbServiceActions implements SodaScsOpenGdbRequestInterface {

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

  /**
   * The Soda SCS service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    TranslationInterface $stringTranslation,
  ) {
    $this->settings = $configFactory->get('soda_scs_manager.settings');
    $this->httpClient = $httpClient;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
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
    $triplestoreServiceSettings = $this->sodaScsServiceHelpers->initTriplestoreServiceSettings();
    $triplestoreRepositoriesSettings = $this->sodaScsServiceHelpers->initTriplestoreRepositoriesSettings();
    $triplestoreUserSettings = $this->sodaScsServiceHelpers->initTriplestoreUserSettings();

    switch ($requestParams['type']) {
      case 'repository':
        $url = $triplestoreRepositoriesSettings['baseUrl'] . $triplestoreRepositoriesSettings['createUrl'];
        $body = json_encode([
          'id' => $requestParams['body']['machineName'],
          'title' => $requestParams['body']['title'],
          "publicRead" => $requestParams['body']['publicRead'],
          "publicWrite" => $requestParams['body']['publicWrite'],
        ]);
        break;

      case 'user':
        $url = $triplestoreUserSettings['baseUrl'] . str_replace('{userId}', $requestParams['routeParams']['username'], $triplestoreUserSettings['createUrl']);
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
        throw new MissingDataException('Create URL setting is not set.');

    }

    $route = $triplestoreServiceSettings['host'] . $url;

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
        'Authorization' => 'Basic ' . base64_encode($triplestoreServiceSettings['adminUsername'] . ':' . $triplestoreServiceSettings['adminPassword']),
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
    // Initialize settings.
    $triplestoreServiceSettings = $this->sodaScsServiceHelpers->initTriplestoreServiceSettings();
    $triplestoreRepositoriesSettings = $this->sodaScsServiceHelpers->initTriplestoreRepositoriesSettings();
    $triplestoreUserSettings = $this->sodaScsServiceHelpers->initTriplestoreUserSettings();

    switch ($requestParams['type']) {
      case 'repository':
        $baseUrl = $triplestoreRepositoriesSettings['baseUrl'];
        $readUrl = str_replace('{repositoryId}', $requestParams['routeParams']['machineName'], $triplestoreRepositoriesSettings['readOneUrl']);
        $url = $baseUrl . $readUrl;
        break;

      case 'user':
        $baseUrl = $triplestoreUserSettings['baseUrl'];
        $readUrl = str_replace('{userId}', $requestParams['routeParams']['username'], $triplestoreUserSettings['readOneUrl']);
        $url = $baseUrl . $readUrl;
        break;

      default:
        throw new MissingDataException('Get URL setting is not set.');

    }

    $route = $triplestoreServiceSettings['host'] . $url;

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
        'Authorization' => 'Basic ' . base64_encode($triplestoreServiceSettings['adminUsername'] . ':' . $triplestoreServiceSettings['adminPassword']),
      ],
      'body' => $requestParams['body'] ?? '',
    ];
  }

  /**
   * Builds the health check request.
   *
   * @return array
   *   The health check request.
   */
  public function buildHealthCheckRequest(array $requestParams): array {
    // Initialize settings.
    $triplestoreServiceSettings = $this->sodaScsServiceHelpers->initTriplestoreServiceSettings();
    $triplestoreRepositoriesSettings = $this->sodaScsServiceHelpers->initTriplestoreRepositoriesSettings();
    switch ($requestParams['type']) {
      case 'repository':
        $dynamicUrlPart = $triplestoreRepositoriesSettings['baseUrl'] . str_replace('{repositoryId}', $requestParams['routeParams']['machineName'], $triplestoreRepositoriesSettings['healthCheckUrl']);
        break;

      case 'service':
        $dynamicUrlPart = $triplestoreServiceSettings['healthCheckUrl'];
        break;

      default:
        throw new MissingDataException('Health check URL setting is not set.');
    }

    $route = $triplestoreServiceSettings['host'] . $dynamicUrlPart;

    return [
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($triplestoreServiceSettings['adminUsername'] . ':' . $triplestoreServiceSettings['adminPassword']),
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
    $triplestoreServiceSettings = $this->sodaScsServiceHelpers->initTriplestoreServiceSettings();
    $triplestoreUserSettings = $this->sodaScsServiceHelpers->initTriplestoreUserSettings();
    $triplestoreRepositoriesSettings = $this->sodaScsServiceHelpers->initTriplestoreRepositoriesSettings();
    switch ($requestParams['type']) {
      case 'repository':
        $url = $triplestoreRepositoriesSettings['baseUrl'] . str_replace('{repositoryId}', $requestParams['routeParams']['machineName'], $triplestoreRepositoriesSettings['updateUrl']);
        $body = json_encode($requestParams['body']);
        break;

      case 'user':
        $url = $triplestoreUserSettings['baseUrl'] . str_replace('{userId}', $requestParams['routeParams']['username'], $triplestoreUserSettings['updateUrl']);
        $body = json_encode($requestParams['body']);
        break;

      default:
        throw new MissingDataException('Update URL setting is not set.');

    }

    $route = $triplestoreServiceSettings['host'] . $url;

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
        'Authorization' => 'Basic ' . base64_encode($triplestoreServiceSettings['adminUsername'] . ':' . $triplestoreServiceSettings['adminPassword']),
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
    // Initialize settings.
    $triplestoreServiceSettings = $this->sodaScsServiceHelpers->initTriplestoreServiceSettings();
    $triplestoreRepositoriesSettings = $this->sodaScsServiceHelpers->initTriplestoreRepositoriesSettings();
    $triplestoreUserSettings = $this->sodaScsServiceHelpers->initTriplestoreUserSettings();

    // @todo May use $requestParams['type'] if there is no other logic.
    switch ($requestParams['type']) {
      case 'repository':
        $repositoriesBaseUrl = $triplestoreRepositoriesSettings['baseUrl'];
        $deleteUrlRoute = str_replace('{repositoryId}', $requestParams['routeParams']['machineName'], $triplestoreRepositoriesSettings['deleteUrl']);
        $url = $repositoriesBaseUrl . $deleteUrlRoute;
        break;

      case 'user':
        $usersBaseUrl = $triplestoreUserSettings['baseUrl'];
        $deleteUrlRoute = str_replace('{userId}', $requestParams['routeParams']['username'], $triplestoreUserSettings['deleteUrl']);
        $url = $usersBaseUrl . $deleteUrlRoute;
        break;

      default:
        throw new MissingDataException('Delete URL setting is not set.');

    }

    $route = $triplestoreServiceSettings['host'] . $url;

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
        'Authorization' => 'Basic ' . base64_encode($triplestoreServiceSettings['adminUsername'] . ':' . $triplestoreServiceSettings['adminPassword']),
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

      return [
        'data' => [
          'openGdbResponse' => $response,
        ],
        'error' => '',
        'message' => 'Request succeeded.',
        'statusCode' => $response->getStatusCode(),
        'success' => TRUE,
      ];
    }
    catch (ClientException $e) {
      if ($request['type'] === 'repository' && $e->getCode() === 404) {
        $this->messenger->addError($this->t("OpenGDB request for @type failed. See logs for more details.", ['@type' => $request['type']]));
        Error::logException($this->loggerFactory->get('soda_scs_manager'), $e, 'OpenGDB request failed: @message', ['@message' => $e->getMessage()], LogLevel::ERROR);
        return [
          'message' => 'Request failed, is the triplestore running?',
          'data' => [
            'openGdbResponse' => $e,
          ],
          'success' => FALSE,
        ];
      }
      // @todo User not exist is ok, try to make this no error
      elseif ($request['type'] === 'user' && $e->getCode() === 404) {
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
    }
    catch (ServerException $e) {
      $this->messenger->addError($this->t("OpenGDB request for @type failed. See logs for more details.", ['@type' => $request['type']]));
      Error::logException($this->loggerFactory->get('soda_scs_manager'), $e, 'OpenGDB request failed: @message', ['@message' => $e->getMessage()], LogLevel::ERROR);
      return [
        'data' => [
          'openGdbResponse' => $e,
        ],
        'error' => $e->getMessage(),
        'message' => 'Request failed with code @code' . $e->getCode(),
        'statusCode' => $e->getCode(),
        'success' => FALSE,
      ];
    }
    $this->messenger->addError($this->t("OpenGDB request for @type failed. See logs for more details.", ['@type' => $request['type']]));
    Error::logException($this->loggerFactory->get('soda_scs_manager'), $e, 'OpenGDB request failed: @message', ['@message' => $e->getMessage()], LogLevel::ERROR);
    return [
      'data' => [
        'openGdbResponse' => $e,
      ],
      'error' => $e->getMessage(),
      'message' => 'Request failed with code @code' . $e->getCode(),
      'statusCode' => $e->getCode(),
      'success' => FALSE,
    ];
  }

  /**
   * Build token request.
   *
   * @param array{body: array{username: string, password: string}} $requestParams
   *
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildTokenRequest(array $requestParams = []): array {
    // Initialize settings.
    $triplestoreServiceSettings = $this->sodaScsServiceHelpers->initTriplestoreServiceSettings();
    $triplestoreMiscSettings = $this->sodaScsServiceHelpers->initTriplestoreMiscSettings();

    $route = $triplestoreServiceSettings['host'] . $triplestoreMiscSettings['tokenUrl'];

    // Open GDB /api-token-auth/ expects form-urlencoded (Django token auth).
    $bodyParams = $requestParams['body'] ?? [];
    $body = is_array($bodyParams) ? http_build_query($bodyParams) : (string) $bodyParams;

    return [
      'type' => 'token',
      'success' => TRUE,
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json',
      ],
      'body' => $body,
    ];
  }

  /**
   * Builds the dump request for the OpenGDB service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The dump request.
   *
   * @todo Abstract this in settings. Why only repositories and not /rest/repositories as baseUrl?
   */
  public function buildDumpRequest(array $requestParams): array {
    $triplestoreServiceSettings = $this->sodaScsServiceHelpers->initTriplestoreServiceSettings();

    // @todo Make this more flexible with settings.
    $route =
    // https://ts.scs.sammlungen.io
    $triplestoreServiceSettings['host'] .
    // /repositories/
    '/repositories' .
    // {repositoryId}
    '/{repositoryId}';

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'type' => 'select',
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($triplestoreServiceSettings['adminUsername'] . ':' . $triplestoreServiceSettings['adminPassword']),
      ],
      'body' => !empty($requestParams['body']) ? json_encode($requestParams['body']) : '',
    ];
  }

  /**
   * Builds the replace repository request.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The replace repository request.
   */
  public function buildReplaceRepositoryRequest(array $requestParams): array {
    $triplestoreServiceSettings = $this->sodaScsServiceHelpers->initTriplestoreServiceSettings();

    $route =
    // https://ts.scs.sammlungen.io
    $triplestoreServiceSettings['host'] .
    // /repositories
    '/repositories' .
    // {repositoryId}/statements
    '/{repositoryId}/statements';

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    switch ($requestParams['format']) {
      case 'nq':
        $contentType = 'text/x-nquads';
        break;

      default:
        throw new MissingDataException('Format is required for replace repository request.');
    }

    return [
      'type' => 'replace',
      'success' => TRUE,
      'method' => 'PUT',
      'route' => $route,
      'headers' => [
        'Content-Type' => $contentType,
        'Accept' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($triplestoreServiceSettings['adminUsername'] . ':' . $triplestoreServiceSettings['adminPassword']),
      ],
      'body' => $requestParams['body'] ?? throw new MissingDataException('Body is required for replace repository request.'),
    ];
  }

}
