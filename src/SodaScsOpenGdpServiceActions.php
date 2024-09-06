<?php

namespace Drupal\soda_scs_manager;

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
    $requestParams = [
      'queryParams' => [],
      'routeParams' => [],
      'body' => [
        'subdomain' => 'mutant',
        'title' => 'my mutant',
        'publicRead' => FALSE,
        'publicWrite' => FALSE,
      ],
    ];
    $url = $this->settings->get('triplestore')['routes']['repository']['createUrl'];
    if (empty($url)) {
      throw new MissingDataException('Create URL setting is not set.');
    }

    $queryParams = $requestParams['queryParams'];

    $route = $url . implode('/', $requestParams['routeParams']) . '?' . http_build_query($queryParams);

    return [
      'success' => TRUE,
      'method' => 'POST',
      'route' => $route,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($this->settings->get('triplestore')['openGdpSettings']['adminUsername'] . ':' . $this->settings->get('triplestore')['openGdpSettings']['adminPassword']),
      ],
      'body' => json_encode([
        'id' => $requestParams['body']['subdomain'],
        'title' => $requestParams['body']['title'],
        "publicRead" => $requestParams['body']['publicRead'],
        "publicWrite" => $requestParams['body']['publicWrite'],
      ]),
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
  public function buildReadRequest(array $requestParams): array {
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
    return [];
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
    return [];
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
    return [];
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
        'message' => 'Request succeeded',
        'data' => [
          'openGdbResponse' => $response,
        ],
        'success' => TRUE,
        'error' => '',
      ];
    }
    catch (ClientException $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("OpenGDB request failed with code @code error: @error trace @trace", [
        '@code' => $e->getCode(),
        '@error' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
    }
    $this->messenger->addError($this->stringTranslation->translate("OpenGDB request failed. See logs for more details."));
    return [
      'message' => 'Request failed with code @code' . $e->getCode(),
      'data' => [
        'openGdbResponse' => $e,
      ],
      'success' => FALSE,
      'error' => $e->getMessage(),
    ];
  }

}
