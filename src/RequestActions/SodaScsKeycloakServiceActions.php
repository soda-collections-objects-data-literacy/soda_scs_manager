<?php

namespace Drupal\soda_scs_manager\RequestActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Drupal\soda_scs_manager\Exception\SodaScsRequestException;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;

/**
 * Class SodaScsKeycloakServiceActions.
 *
 * Implements actions for Keycloak service requests.
 */
class SodaScsKeycloakServiceActions implements SodaScsServiceRequestInterface {

  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

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

  /**
   * Constructs a new SodaScsKeycloakServiceActions object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers $sodaScsServiceHelpers
   *   The Soda SCS service helpers.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    RequestStack $request_stack,
    LoggerChannelFactoryInterface $logger_factory,
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    TranslationInterface $stringTranslation,
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->requestStack = $request_stack;
    $this->loggerFactory = $logger_factory;
    $this->settings = $config_factory->get('soda_scs_manager.settings');
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the create request for the Keycloak service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The create request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildCreateRequest(array $requestParams): array {
    $keycloakSettings = $this->sodaScsServiceHelpers->initKeycloakSettings();

    $keycloakSettings['createUrl'] = '/admin/realms/' . $keycloakSettings['realm'] . '/clients';
    $route = $keycloakSettings['host'] . $keycloakSettings['createUrl'];

    $body = [
      "clientId" => $requestParams['clientId'],
      "name" => $requestParams['name'],
      "description" => $requestParams['description'],
      "rootUrl" => $requestParams['rootUrl'],
      "adminUrl" => $requestParams['adminUrl'],
      "baseUrl" => "/",
      "surrogateAuthRequired" => FALSE,
      "enabled" => TRUE,
      "alwaysDisplayInConsole" => FALSE,
      "clientAuthenticatorType" => "client-secret",
      "secret" => $requestParams['secret'],
      "redirectUris" => [
        "*",
      ],
      "webOrigins" => [
        "*",
      ],
      "notBefore" => 0,
      "bearerOnly" => FALSE,
      "consentRequired" => FALSE,
      "standardFlowEnabled" => TRUE,
      "implicitFlowEnabled" => FALSE,
      "directAccessGrantsEnabled" => FALSE,
      "serviceAccountsEnabled" => FALSE,
      "publicClient" => FALSE,
      "frontchannelLogout" => FALSE,
      "protocol" => "openid-connect",
      "attributes" => [
        "realm_client" => FALSE,
      ],
      "authenticationFlowBindingOverrides" => (object) [],
      "fullScopeAllowed" => TRUE,
      "nodeReRegistrationTimeout" => -1,
      "defaultClientScopes" => [
        "web-origins",
        "acr",
        "profile",
        "roles",
        "groups",
        "basic",
        "email",
      ],
      "optionalClientScopes" => [
        "address",
        "phone",
        "offline_access",
        "microprofile-jwt",
      ],
      "access" => [
        "view" => TRUE,
        "configure" => TRUE,
        "manage" => TRUE,
      ],
    ];

    return [
      'success' => TRUE,
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $requestParams['token'],
      ],
      'body' => json_encode($body),
    ];
  }

  /**
   * Builds the get all request for the Keycloak service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The read request.
   */
  public function buildGetAllRequest(array $requestParams): array {
    $keycloakSettings = $this->sodaScsServiceHelpers->initKeycloakSettings();

    if (empty($keycloakSettings['getAllUrl'])) {
      return [
        'success' => FALSE,
        'error' => 'Get All URL setting is not set.',
      ];
    }

    $url = $keycloakSettings['baseUrl'] . $keycloakSettings['getAllUrl'];

    $route = $keycloakSettings['hostRoute'] . $url;

    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'type' => $requestParams['type'] ?? 'users',
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $requestParams['token'],
      ],
    ];
  }

  /**
   * Builds the get request for the Keycloak service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The read request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildGetRequest(array $requestParams): array {
    $keycloakSettings = $this->sodaScsServiceHelpers->initKeycloakSettings();

    if (empty($keycloakSettings['getUrl'])) {
      throw new MissingDataException('Get URL setting is not set.');
    }

    $url = $keycloakSettings['baseUrl'] . $keycloakSettings['getUrl'];

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $url = str_replace('{' . $key . '}', $value, $url);
      }
    }

    $route = $keycloakSettings['hostRoute'] . $url;

    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'type' => $requestParams['type'] ?? 'user',
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $requestParams['token'],
      ],
    ];
  }

  /**
   * Builds health check request for the Keycloak service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The health check request.
   */
  public function buildHealthCheckRequest(array $requestParams): array {
    $keycloakSettings = $this->sodaScsServiceHelpers->initKeycloakSettings();

    if (empty($keycloakSettings['healthCheckUrl'])) {
      return [
        'success' => FALSE,
        'error' => 'Health check URL setting is not set.',
      ];
    }

    $url = $keycloakSettings['baseUrl'] . $keycloakSettings['healthCheckUrl'];

    $route = $keycloakSettings['hostRoute'] . $url;

    return [
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ];
  }

  /**
   * Builds the update request for the Keycloak service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The update request.
   */
  public function buildUpdateRequest(array $requestParams): array {
    $keycloakSettings = $this->sodaScsServiceHelpers->initKeycloakSettings();

    if (empty($keycloakSettings['updateUrl'])) {
      return [
        'success' => FALSE,
        'error' => 'Update URL setting is not set.',
      ];
    }

    $url = $keycloakSettings['baseUrl'] . $keycloakSettings['updateUrl'];

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $url = str_replace('{' . $key . '}', $value, $url);
      }
    }

    // Prepare the request body.
    $body = json_encode($requestParams['body'] ?? []);

    $route = $keycloakSettings['hostRoute'] . $url;

    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'type' => $requestParams['type'] ?? 'user',
      'success' => TRUE,
      'method' => 'PUT',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $requestParams['token'],
      ],
      'body' => $body,
    ];
  }

  /**
   * Builds the delete request for the Keycloak service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The delete request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildDeleteRequest(array $requestParams): array {
    $keycloakSettings = $this->sodaScsServiceHelpers->initKeycloakSettings();

    if (empty($keycloakSettings['deleteUrl'])) {
      throw new MissingDataException('Delete URL setting is not set.');
    }

    $url = $keycloakSettings['baseUrl'] . $keycloakSettings['deleteUrl'];

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $url = str_replace('{' . $key . '}', $value, $url);
      }
    }

    $route = $keycloakSettings['hostRoute'] . $url;

    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'type' => $requestParams['type'] ?? 'user',
      'success' => TRUE,
      'method' => 'DELETE',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $requestParams['token'],
      ],
    ];
  }

  /**
   * Builds the token request for the Keycloak service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildTokenRequest(array $requestParams): array {
    $keycloakSettings = $this->sodaScsServiceHelpers->initKeycloakSettings();

    // Define the token endpoint for the master realm.
    $keycloakSettings['tokenUrl'] = '/realms/master/protocol/openid-connect/token';

    $route = $keycloakSettings['host'] . $keycloakSettings['tokenUrl'];

    // Set up the form parameters matching the curl command format.
    $body = [
      'grant_type' => 'password',
      'client_id' => 'admin-cli',
      'username' => $keycloakSettings['adminUsername'] ?? '',
      'password' => $keycloakSettings['adminPassword'] ?? '',
    ];

    return [
      'success' => TRUE,
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json',
      ],
      'form_params' => $body,
    ];
  }

  /**
   * Make the API request to the Keycloak service.
   *
   * @param array $request
   *   The request.
   *
   * @return array
   *   The response.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsRequestException
   */
  public function makeRequest($request): array {
    // Assemble requestParams.
    $requestParams['headers'] = $request['headers'];
    if (isset($request['body'])) {
      $requestParams['body'] = $request['body'];
    }
    if (isset($request['form_params'])) {
      $requestParams['form_params'] = $request['form_params'];
    }
    // Send the request.
    try {
      $response = $this->httpClient->request($request['method'], $request['route'], $requestParams);

      return [
        'message' => 'Request succeeded',
        'data' => [
          'keycloakResponse' => $response,
        ],
        'statusCode' => $response->getStatusCode(),
        'success' => TRUE,
        'error' => '',
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error('Keycloak request failed: @error', ['@error' => $e->getMessage()]);
      $this->loggerFactory->get('soda_scs_manager')->debug('Request details: @request', ['@request' => print_r($request, TRUE)]);

      return [
        'message' => $this->t('Request failed with code @code', ['@code' => $e->getCode()]),
        'data' => [
          'keycloakResponse' => $e,
        ],
        'statusCode' => $e->getCode(),
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

}
