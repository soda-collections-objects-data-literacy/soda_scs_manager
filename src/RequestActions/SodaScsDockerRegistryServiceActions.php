<?php

namespace Drupal\soda_scs_manager\RequestActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\Exception\MissingDataException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsDockerRegistryServiceActions implements SodaScsServiceRequestInterface {

  use DependencySerializationTrait;
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
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->httpClient = $httpClient;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->settings = $settings;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Make request.
   *
   * @param array $request
   *   The request array.
   *
   * @return array
   *   The response array.
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
        'message' => 'Request succeeded',
        'data' => [
          'dockerRegistryResponse' => $response,
        ],
        'success' => TRUE,
        'error' => '',
      ];
    }
    catch (ClientException $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Portainer request failed. error: $e");
    }
    $this->messenger->addError($this->t("Portainer request failed. See logs for more details."));
    return [
      'message' => 'Request failed with code @code' . $e->getCode(),
      'data' => [
        'dockerRegistryResponse' => $e,
      ],
      'success' => FALSE,
      'error' => $e->getMessage(),
    ];
  }

  /**
   * Builds the create request for the Portainer service API.
   *
   * @param array $requestParams
   *   The request params.
   *
   * @return array
   *   The request array.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildCreateRequest(array $requestParams): array {
    return [];
  }

  /**
   * Builds the create request for the Portainer service API.
   *
   * @param array $requestParams
   *   An array of request parameters.
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
    $route = $this->settings->get('dockerRegistry')['routes']['getUrl'];
    $route = str_replace('{package_id}', $requestParams['packageId'], $route);
    if (empty($route)) {
      throw new MissingDataException('Get URL setting is not set.');
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
   * Builds the health check request.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The health check request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildHealthCheckRequest(array $requestParams): array {
    $route = $this->settings->get('dockerRegistry')['routes']['healthCheck']['url'];
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
      ],
    ];
  }

  /**
   * Builds the update request for the Portainer service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildUpdateRequest(array $requestParams): array {
    return [];
  }

  /**
   * Builds the delete request for the Portainer service API.
   *
   * @param array $queryParams
   *   The query parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildDeleteRequest(array $queryParams): array {
    return [];
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
    return [];
  }

}
