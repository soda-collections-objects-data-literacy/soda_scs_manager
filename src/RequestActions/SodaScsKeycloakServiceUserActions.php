<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\RequestActions;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

/**
 * Class SodaScsKeycloakServiceUserActions.
 *
 * Implements actions for Keycloak service requests.
 *
 * @todo Kill redundant param replacement etc.
 */
#[Autowire(service: 'soda_scs_manager.keycloak_service.user.actions')]
class SodaScsKeycloakServiceUserActions implements SodaScsServiceRequestInterface {

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
   * Constructs a new SodaScsKeycloakServiceUserActions object.
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
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
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
    $keycloakGeneralSettings = $this->sodaScsServiceHelpers->initKeycloakGeneralSettings();
    $keycloakUsersSettings = $this->sodaScsServiceHelpers->initKeycloakUsersSettings();

    // Build the route.
    $route =
      // Host route.
      $keycloakGeneralSettings['host'] .
      // Base URL.
      $keycloakUsersSettings['baseUrl'] .
      // Create URL.
      $keycloakUsersSettings['createUrl'];

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    // Add query parameters if they exist.
    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'success' => TRUE,
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $requestParams['token'],
      ],
      'body' => json_encode($requestParams['body']),
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
    $keycloakGeneralSettings = $this->sodaScsServiceHelpers->initKeycloakGeneralSettings();
    $keycloakUsersSettings = $this->sodaScsServiceHelpers->initKeycloakUsersSettings();

    $type = $requestParams['type'] ?? 'user';

    // Build the route.
    $route =
      // Host route.
      $keycloakGeneralSettings['host'] .
      // Base URL.
      str_replace('{realm}', $keycloakGeneralSettings['realm'], $keycloakUsersSettings['baseUrl']);

    // ...of all groups of a user.
    if ($type === 'group') {
      $route .=
        // Get all URL.
        $keycloakUsersSettings['readAllGroupsUrl'];
    }

    // ...of all users.
    else {
      $route .=
        // Get all URL.
        $keycloakUsersSettings['readAllUrl'];
    }

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    // Add query parameters if they exist.
    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'type' => $type,
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
    $keycloakGeneralSettings = $this->sodaScsServiceHelpers->initKeycloakGeneralSettings();
    $keycloakUsersSettings = $this->sodaScsServiceHelpers->initKeycloakUsersSettings();

    // Build the route.
    $route =
      // Host route.
      $keycloakGeneralSettings['host'] .
      // Base URL.
      str_replace('{realm}', $keycloakGeneralSettings['realm'], $keycloakUsersSettings['baseUrl']) .
      // Read one URL.
      $keycloakUsersSettings['readOneUrl'];

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    // Add query parameters if they exist.
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
    $keycloakGeneralSettings = $this->sodaScsServiceHelpers->initKeycloakGeneralSettings();
    $keycloakUsersSettings = $this->sodaScsServiceHelpers->initKeycloakUsersSettings();

    // Build the route.
    $route =
      // Host route.
      $keycloakGeneralSettings['host'] .
      // Base URL.
      $keycloakUsersSettings['baseUrl'] .
      // Health check URL.
      $keycloakUsersSettings['healthCheckUrl'];

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

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
   *     routeParams:
   *       - realm (required): The realm name.
   *       - userId (required): The user ID.
   *     body:
   *       - UserRepresentation (optional): The user representation.
   *          - id (optional):
   *            The user ID.
   *          - username (optional):
   *            The username.
   *          - firstName (optional):
   *            The user's first name.
   *          - lastName (optional):
   *            The user's last name.
   *          - email (optional):
   *            The user's email address.
   *          - emailVerified (optional):
   *            Whether the email is verified.
   *          - attributes (optional):
   *            Map of user attributes.
   *          - userProfileMetadata (optional):
   *            User profile metadata.
   *          - self (optional):
   *            Self reference.
   *          - origin (optional):
   *            Origin information.
   *          - createdTimestamp (optional):
   *            Creation timestamp.
   *          - enabled (optional):
   *            Whether the user is enabled.
   *          - totp (optional):
   *            Time-based one-time password status.
   *          - federationLink (optional):
   *            Federation link.
   *          - serviceAccountClientId (optional):
   *            Service account client ID.
   *          - credentials (optional):
   *            List of credentials.
   *          - disableableCredentialTypes (optional):
   *            Set of disableable credential types.
   *          - requiredActions (optional):
   *            List of required actions.
   *          - federatedIdentities (optional):
   *            List of federated identities.
   *          - realmRoles (optional):
   *            List of realm roles.
   *          - clientRoles (optional):
   *            Map of client roles.
   *          - clientConsents (optional):
   *            List of client consents.
   *          - notBefore (optional):
   *            Not before timestamp.
   *          - applicationRoles (optional):
   *            Map of application roles.
   *          - socialLinks (optional):
   *            List of social links.
   *          - groups (optional):
   *            List of groups.
   *          - access (optional):
   *            Map of access permissions.
   *
   * @return array
   *   The update request.
   *
   * @todo Make it clearer how to update user infos and user group infos.
   */
  public function buildUpdateRequest(array $requestParams): array {
    $keycloakGeneralSettings = $this->sodaScsServiceHelpers->initKeycloakGeneralSettings();
    $keycloakUsersSettings = $this->sodaScsServiceHelpers->initKeycloakUsersSettings();

    $success = FALSE;
    // Build the route.
    $route =
      // Host route.
      $keycloakGeneralSettings['host'] .
      // Base URL.
        str_replace('{realm}', $keycloakGeneralSettings['realm'], $keycloakUsersSettings['baseUrl']);

    switch ($requestParams['type']) {
      case 'addUserToGroup':
        $route .= $keycloakUsersSettings['addUserToGroupUrl'];
        $success = TRUE;
        break;

      case 'updateUser':
        $route .= $keycloakUsersSettings['updateUrl'];
        $success = TRUE;
        break;

      default:
        $success = FALSE;
        break;
    }

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    // Prepare the request body.
    $body = json_encode($requestParams['body'] ?? []);

    // Add query parameters if they exist.
    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'success' => $success,
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
    $keycloakGeneralSettings = $this->sodaScsServiceHelpers->initKeycloakGeneralSettings();
    $keycloakUsersSettings = $this->sodaScsServiceHelpers->initKeycloakUsersSettings();

    $requestParams['routeParams']['realm'] = $keycloakGeneralSettings['realm'];

    $success = FALSE;

    $type = $requestParams['type'] ?? NULL;
    // Build the route.
    $route =
      // Host route.
      $keycloakGeneralSettings['host'] .
      // Base URL.
      $keycloakUsersSettings['baseUrl'];

    $append = match ($type) {
      'user' => $keycloakUsersSettings['deleteUrl'],
      'removeUserFromGroup' => $keycloakUsersSettings['removeUserFromGroupUrl'],
      default => NULL,
    };

    if ($append !== NULL) {
      $route .= $append;
      $success = TRUE;
    }

    // Replace any route parameters.
    if (!empty($requestParams['routeParams'])) {
      foreach ($requestParams['routeParams'] as $key => $value) {
        $route = str_replace('{' . $key . '}', $value, $route);
      }
    }

    // Add query parameters if they exist.
    if (!empty($requestParams['queryParams'])) {
      $route .= '?' . http_build_query($requestParams['queryParams']);
    }

    return [
      'type' => $type,
      'success' => $success,
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
  public function buildTokenRequest($requestParams = []): array {
    $keycloakGeneralSettings = $this->sodaScsServiceHelpers->initKeycloakGeneralSettings();

    $route = $keycloakGeneralSettings['host'] .
      // Token URL.
      $keycloakGeneralSettings['tokenUrl'];

    // Set up the form parameters matching the curl command format.
    $body = [
      'grant_type' => 'password',
      'client_id' => 'admin-cli',
      'username' => $keycloakGeneralSettings['adminUsername'] ?? '',
      'password' => $keycloakGeneralSettings['adminPassword'] ?? '',
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
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Keycloak request failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
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
