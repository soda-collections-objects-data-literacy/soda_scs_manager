<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\RequestActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * HTTP client for the WebProtégé REST API (/data/*).
 */
class SodaScsWebprotegeServiceActions implements SodaScsServiceRequestInterface {

  use StringTranslationTrait;

  /**
   * Download format parameter for OWL functional syntax.
   */
  public const FUNCTIONAL_SYNTAX_FORMAT = 'ofn';

  /**
   * Content type WebProtégé expects for posted functional syntax.
   */
  public const FUNCTIONAL_SYNTAX_MIME_TYPE = 'text/owl-functional';

  /**
   * Seconds for ontology downloads and uploads; large ontologies take a while.
   */
  protected const ONTOLOGY_TRANSFER_TIMEOUT = 300;

  /**
   * Seconds for all other API calls.
   */
  protected const REQUEST_TIMEOUT = 30;

  /**
   * Constructs the WebProtégé request actions.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'http_client')]
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected MessengerInterface $messenger,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    protected SodaScsServiceHelpers $sodaScsServiceHelpers,
    TranslationInterface $stringTranslation,
  ) {
    $this->settings = $configFactory->get('soda_scs_manager.settings');
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * Posts axioms in OWL functional syntax into a project's root ontology.
   *
   * @param string $commitMessage
   *   Revision message shown in the WebProtégé history.
   * @param string $functionalSyntax
   *   The ontology document; its Import() declarations must be removed,
   *   because WebProtégé would try to fetch them.
   * @param string $projectId
   *   WebProtégé project UUID.
   */
  public function buildAddAxiomsRequest(string $commitMessage, string $functionalSyntax, string $projectId): array {
    $settings = $this->requireApiSettings();
    if ($settings === NULL) {
      return $this->missingApiKeyRequest('axioms');
    }

    $headers = $this->apiHeaders($settings['apiKey']);
    $headers['Content-Type'] = self::FUNCTIONAL_SYNTAX_MIME_TYPE;
    return [
      'type' => 'axioms',
      'success' => TRUE,
      'method' => 'POST',
      'route' => $settings['apiBaseUrl'] . '/data/projects/' . rawurlencode($projectId) . '/axioms?' . http_build_query(['msg' => $commitMessage]),
      'headers' => $headers,
      'body' => $functionalSyntax,
      'timeout' => self::ONTOLOGY_TRANSFER_TIMEOUT,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildCreateRequest(array $requestParams): array {
    $settings = $this->requireApiSettings();
    if ($settings === NULL) {
      return $this->missingApiKeyRequest('create');
    }

    return [
      'type' => 'project',
      'success' => TRUE,
      'method' => 'POST',
      'route' => $settings['apiBaseUrl'] . '/data/projects',
      'headers' => $this->apiHeaders($settings['apiKey']),
      'body' => json_encode($requestParams['body'] ?? []),
    ];
  }

  /**
   * Downloads the head revision of a project as a zip of ontology documents.
   *
   * Needs the SCS endpoint GET /data/projects/{id}/ontology of the
   * WebProtégé fork. The zip holds the root ontology and its imports.
   *
   * @param string $projectId
   *   WebProtégé project UUID.
   */
  public function buildDownloadOntologyRequest(string $projectId): array {
    $settings = $this->requireApiSettings();
    if ($settings === NULL) {
      return $this->missingApiKeyRequest('ontology');
    }

    $headers = $this->apiHeaders($settings['apiKey']);
    $headers['Accept'] = 'application/zip';
    return [
      'type' => 'ontology',
      'success' => TRUE,
      'method' => 'GET',
      'route' => $settings['apiBaseUrl'] . '/data/projects/' . rawurlencode($projectId) . '/ontology?format=' . self::FUNCTIONAL_SYNTAX_FORMAT,
      'headers' => $headers,
      'timeout' => self::ONTOLOGY_TRANSFER_TIMEOUT,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildGetAllRequest(array $requestParams): array {
    $settings = $this->requireApiSettings();
    if ($settings === NULL) {
      return $this->missingApiKeyRequest('list');
    }

    return [
      'type' => 'project',
      'success' => TRUE,
      'method' => 'GET',
      'route' => $settings['apiBaseUrl'] . '/data/projects',
      'headers' => $this->apiHeaders($settings['apiKey']),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Set $requestParams['type'] to 'revisions' to list the project's revisions
   * instead of its details.
   */
  public function buildGetRequest(array $requestParams): array {
    $settings = $this->requireApiSettings();
    if ($settings === NULL) {
      return $this->missingApiKeyRequest('get');
    }

    $type = (string) ($requestParams['type'] ?? 'project');
    $projectId = (string) ($requestParams['routeParams']['projectId'] ?? '');
    $route = $settings['apiBaseUrl'] . '/data/projects/' . rawurlencode($projectId);
    if ($type === 'revisions') {
      $route .= '/revisions';
    }
    return [
      'type' => $type,
      'success' => TRUE,
      'method' => 'GET',
      'route' => $route,
      'headers' => $this->apiHeaders($settings['apiKey']),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildHealthCheckRequest(array $requestParams): array {
    $settings = $this->sodaScsServiceHelpers->initWebprotegeInstanceSettings();
    $host = $this->normalizeBaseUrl((string) ($settings['host'] ?? ''));
    return [
      'type' => 'health',
      'success' => TRUE,
      'method' => 'GET',
      'route' => $host,
      'headers' => [
        'Accept' => 'text/html',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildUpdateRequest(array $requestParams): array {
    $settings = $this->requireApiSettings();
    if ($settings === NULL) {
      return $this->missingApiKeyRequest('collaborator');
    }

    $projectId = (string) ($requestParams['routeParams']['projectId'] ?? '');
    $userName = (string) ($requestParams['routeParams']['userName'] ?? '');
    return [
      'type' => 'collaborator',
      'success' => TRUE,
      'method' => 'PUT',
      'route' => $settings['apiBaseUrl'] . '/data/projects/' . rawurlencode($projectId) . '/collaborators/' . rawurlencode($userName),
      'headers' => $this->apiHeaders($settings['apiKey']),
      'body' => json_encode($requestParams['body'] ?? ['permission' => 'EDIT']),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildDeleteRequest(array $requestParams): array {
    $settings = $this->requireApiSettings();
    if ($settings === NULL) {
      return $this->missingApiKeyRequest('delete');
    }

    $type = (string) ($requestParams['type'] ?? 'project');
    if ($type === 'user') {
      $userName = (string) ($requestParams['routeParams']['userName'] ?? '');
      return [
        'type' => 'user',
        'success' => TRUE,
        'method' => 'DELETE',
        'route' => $settings['apiBaseUrl'] . '/data/users/' . rawurlencode($userName),
        'headers' => $this->apiHeaders($settings['apiKey']),
      ];
    }

    $projectId = (string) ($requestParams['routeParams']['projectId'] ?? '');
    $route = $settings['apiBaseUrl'] . '/data/projects/' . rawurlencode($projectId);
    if ($type === 'collaborator') {
      $userName = (string) ($requestParams['routeParams']['userName'] ?? '');
      $route .= '/collaborators/' . rawurlencode($userName);
    }

    return [
      'type' => $type,
      'success' => TRUE,
      'method' => 'DELETE',
      'route' => $route,
      'headers' => $this->apiHeaders($settings['apiKey']),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildTokenRequest(array $requestParams = []): array {
    return [
      'type' => 'token',
      'success' => FALSE,
      'error' => 'WebProtégé uses API keys, not tokens.',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function makeRequest($request): array {
    if (empty($request['success'])) {
      return [
        'data' => [],
        'error' => (string) ($request['error'] ?? 'Request could not be built.'),
        'message' => (string) ($request['error'] ?? 'Request could not be built.'),
        'statusCode' => 0,
        'success' => FALSE,
      ];
    }

    $options = [
      'headers' => $request['headers'] ?? [],
      'http_errors' => TRUE,
      'timeout' => $request['timeout'] ?? self::REQUEST_TIMEOUT,
    ];
    if (isset($request['body'])) {
      $options['body'] = $request['body'];
    }

    try {
      $response = $this->httpClient->request($request['method'], $request['route'], $options);
      return [
        'data' => [
          'webprotegeResponse' => $response,
        ],
        'error' => '',
        'message' => 'Request succeeded.',
        'statusCode' => $response->getStatusCode(),
        'success' => TRUE,
      ];
    }
    catch (RequestException $e) {
      $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'WebProtégé request failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR,
      );
      return [
        'data' => [
          'webprotegeResponse' => $e->getResponse(),
        ],
        'error' => $e->getMessage(),
        'message' => 'WebProtégé request failed.',
        'statusCode' => $statusCode,
        'success' => FALSE,
      ];
    }
    catch (\Throwable $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'WebProtégé request failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR,
      );
      return [
        'data' => [],
        'error' => $e->getMessage(),
        'message' => 'WebProtégé request failed.',
        'statusCode' => 0,
        'success' => FALSE,
      ];
    }
  }

  /**
   * @return array{apiBaseUrl: string, apiKey: string}|null
   */
  private function requireApiSettings(): ?array {
    try {
      $settings = $this->sodaScsServiceHelpers->initWebprotegeInstanceSettings();
    }
    catch (MissingDataException $e) {
      return NULL;
    }

    $apiKey = trim((string) ($settings['apiKey'] ?? ''));
    if ($apiKey === '') {
      return NULL;
    }

    return [
      'apiBaseUrl' => $this->normalizeBaseUrl((string) ($settings['apiBaseUrl'] ?? $settings['host'] ?? '')),
      'apiKey' => $apiKey,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function missingApiKeyRequest(string $type): array {
    return [
      'type' => $type,
      'success' => FALSE,
      'error' => 'WebProtégé API key is not configured.',
    ];
  }

  /**
   * @return array<string, string>
   */
  private function apiHeaders(string $apiKey): array {
    return [
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
      'Authorization' => 'apikey ' . $apiKey,
    ];
  }

  private function normalizeBaseUrl(string $url): string {
    $trimmed = rtrim($url, '/');
    if ($trimmed === '') {
      return '';
    }
    if (!preg_match('#^https?://#i', $trimmed)) {
      return 'https://' . $trimmed;
    }
    return $trimmed;
  }

}
