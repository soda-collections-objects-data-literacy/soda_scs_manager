<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Site\Settings;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * JupyterHub REST API client for the SCS Manager service account.
 *
 * Manages the hub users that own the project servers (project-<gid>). The
 * hub defines the service "scs-manager" with the same token.
 */
class SodaScsJupyterHubApiClient {

  /**
   * Server state: running and reachable.
   */
  public const STATE_RUNNING = 'running';

  /**
   * Server state: spawn in progress.
   */
  public const STATE_STARTING = 'starting';

  /**
   * Server state: stop in progress.
   */
  public const STATE_STOPPING = 'stopping';

  /**
   * Server state: no server.
   */
  public const STATE_STOPPED = 'stopped';

  /**
   * Server state: the hub user does not exist.
   */
  public const STATE_MISSING = 'missing';

  /**
   * Constructs the client.
   */
  public function __construct(
    #[Autowire(service: 'http_client')]
    protected ClientInterface $httpClient,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    protected SodaScsServiceHelpers $serviceHelpers,
  ) {}

  /**
   * Deletes a hub user; a missing user is fine.
   *
   * @throws \RuntimeException
   *   When the hub rejects the request.
   */
  public function deleteUser(string $name): void {
    $this->request('DELETE', '/users/' . rawurlencode($name), [204, 404]);
  }

  /**
   * Creates a hub user unless it exists.
   *
   * @throws \RuntimeException
   *   When the hub rejects the request.
   */
  public function ensureUser(string $name): void {
    $response = $this->request('GET', '/users/' . rawurlencode($name), [200, 404]);
    if ($response->getStatusCode() === 404) {
      $this->request('POST', '/users/' . rawurlencode($name), [201, 409]);
    }
  }

  /**
   * Returns the state of a user's default server (see STATE_* constants).
   *
   * @throws \RuntimeException
   *   When the hub rejects the request.
   */
  public function getServerState(string $name): string {
    $response = $this->request('GET', '/users/' . rawurlencode($name), [200, 404]);
    if ($response->getStatusCode() === 404) {
      return self::STATE_MISSING;
    }
    $user = json_decode((string) $response->getBody(), TRUE);
    $server = is_array($user) ? ($user['servers'][''] ?? NULL) : NULL;
    if (!is_array($server)) {
      return self::STATE_STOPPED;
    }
    return match ($server['pending'] ?? NULL) {
      'spawn' => self::STATE_STARTING,
      'stop' => self::STATE_STOPPING,
      default => empty($server['ready']) ? self::STATE_STARTING : self::STATE_RUNNING,
    };
  }

  /**
   * Whether the API token is configured.
   */
  public function isConfigured(): bool {
    return $this->apiToken() !== '';
  }

  /**
   * Starts a user's default server; an already running one is fine.
   *
   * @throws \RuntimeException
   *   When the hub rejects the request.
   */
  public function startServer(string $name): void {
    // 201 started, 202 pending, 400 already running or pending.
    $this->request('POST', '/users/' . rawurlencode($name) . '/server', [201, 202, 400]);
  }

  /**
   * Stops a user's default server; a stopped one is fine.
   *
   * @throws \RuntimeException
   *   When the hub rejects the request.
   */
  public function stopServer(string $name): void {
    // 204 stopped, 202 pending, 400 not running, 404 no such user.
    $this->request('DELETE', '/users/' . rawurlencode($name) . '/server', [202, 204, 400, 404]);
  }

  /**
   * Stops a server and waits until it is gone.
   *
   * @return bool
   *   TRUE when the server is stopped (or the user does not exist).
   *
   * @throws \RuntimeException
   *   When the hub rejects the request.
   */
  public function stopServerAndWait(string $name, int $timeoutSeconds = 60): bool {
    $this->stopServer($name);
    $deadline = time() + max(1, $timeoutSeconds);
    do {
      $state = $this->getServerState($name);
      if ($state === self::STATE_STOPPED || $state === self::STATE_MISSING) {
        return TRUE;
      }
      usleep(500000);
    } while (time() <= $deadline);
    return FALSE;
  }

  /**
   * Base URL of the hub API, e.g. https://code.example.org/hub/api.
   */
  protected function apiUrl(): string {
    $url = getenv('JUPYTERHUB_API_URL') ?: Settings::get('jupyterhub_api_url', '');
    if (is_string($url) && $url !== '') {
      return rtrim($url, '/');
    }
    $base = rtrim((string) ($this->serviceHelpers->initJupyterHubSettings()['baseUrl'] ?? ''), '/');
    if ($base === '') {
      throw new \RuntimeException('JupyterHub base URL is not configured.');
    }
    return $base . '/hub/api';
  }

  /**
   * API token of the hub service "scs-manager".
   */
  protected function apiToken(): string {
    $token = getenv('JUPYTERHUB_SCS_MANAGER_API_TOKEN') ?: Settings::get('jupyterhub_scs_manager_api_token', '');
    return is_string($token) ? $token : '';
  }

  /**
   * Sends an API request and checks the status code.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path below the API URL, starting with a slash.
   * @param list<int> $acceptedStatusCodes
   *   Status codes that count as success.
   *
   * @throws \RuntimeException
   *   When the token is missing, the hub is unreachable or answers with an
   *   unexpected status.
   */
  protected function request(string $method, string $path, array $acceptedStatusCodes): ResponseInterface {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('JUPYTERHUB_SCS_MANAGER_API_TOKEN is not set.');
    }
    try {
      $response = $this->httpClient->request($method, $this->apiUrl() . $path, [
        'headers' => [
          'Authorization' => 'token ' . $this->apiToken(),
          'Accept' => 'application/json',
        ],
        'http_errors' => FALSE,
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('JupyterHub API @method @path failed: @msg', [
        '@method' => $method,
        '@path' => $path,
        '@msg' => $e->getMessage(),
      ]);
      throw new \RuntimeException('JupyterHub API is unreachable.', 0, $e);
    }

    $status = $response->getStatusCode();
    if (!in_array($status, $acceptedStatusCodes, TRUE)) {
      $this->logger->error('JupyterHub API @method @path returned HTTP @status: @body', [
        '@method' => $method,
        '@path' => $path,
        '@status' => $status,
        '@body' => mb_substr((string) $response->getBody(), 0, 300),
      ]);
      throw new \RuntimeException(sprintf('JupyterHub API returned HTTP %d.', $status));
    }
    return $response;
  }

}
