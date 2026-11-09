<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the source commit of an image tag from an OCI registry.
 *
 * Talks the Docker Registry HTTP API v2 with an anonymous bearer token, so it
 * works for public images on ghcr.io and Docker Hub. Results are cached,
 * because the lookup runs while an application page is rendered.
 */
class SodaScsImageRegistryClient {

  /**
   * Seconds a found build stays cached.
   */
  private const CACHE_TTL_SECONDS = 900;

  /**
   * Seconds a failed lookup stays cached, so a slow registry is not retried
   * on every page view.
   */
  private const FAILURE_CACHE_TTL_SECONDS = 300;

  /**
   * Accept header for manifest requests (indexes and single manifests).
   */
  private const MANIFEST_ACCEPT = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json';

  /**
   * OCI image label with the build time of the image.
   */
  public const CREATED_LABEL = 'org.opencontainers.image.created';

  /**
   * OCI image label that carries the source commit of the image build.
   */
  public const REVISION_LABEL = 'org.opencontainers.image.revision';

  /**
   * Seconds to wait for each registry request.
   */
  private const TIMEOUT_SECONDS = 5;

  /**
   * Constructs a SodaScsImageRegistryClient.
   */
  public function __construct(
    #[Autowire(service: 'cache.default')]
    protected CacheBackendInterface $cache,
    #[Autowire(service: 'http_client')]
    protected ClientInterface $httpClient,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerChannelInterface $logger,
    #[Autowire(service: 'datetime.time')]
    protected TimeInterface $time,
  ) {}

  /**
   * Source commit and build time of an image tag in its registry.
   *
   * @param string $repository
   *   Image repository with registry host, for example
   *   ghcr.io/org/wisski-base-image-development.
   * @param string $tag
   *   Image tag, for example main.
   *
   * @return array{created: int, revision: string}
   *   See buildFromLabels(); empty when the lookup fails.
   */
  public function build(string $repository, string $tag): array {
    $parsed = $this->parseRepository($repository);
    if ($parsed === NULL || !preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/', $tag)) {
      return self::buildFromLabels([]);
    }

    $cacheId = 'soda_scs_manager:image_build:' . $repository . ':' . $tag;
    $cached = $this->cache->get($cacheId);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $build = $this->fetchBuild($parsed['host'], $parsed['path'], $tag);
    $ttl = $build['revision'] !== '' ? self::CACHE_TTL_SECONDS : self::FAILURE_CACHE_TTL_SECONDS;
    $this->cache->set($cacheId, $build, $this->time->getRequestTime() + $ttl);
    return $build;
  }

  /**
   * Reads commit and build time from image labels.
   *
   * @param array<mixed> $labels
   *   Image or container labels.
   *
   * @return array{created: int, revision: string}
   *   Unix build time (0 when unknown) and commit hash ('' when unknown).
   */
  public static function buildFromLabels(array $labels): array {
    $revision = (string) ($labels[self::REVISION_LABEL] ?? '');
    $created = strtotime((string) ($labels[self::CREATED_LABEL] ?? ''));
    return [
      'created'  => $created !== FALSE ? $created : 0,
      'revision' => preg_match('/^[0-9a-f]{7,64}$/', $revision) ? $revision : '',
    ];
  }

  /**
   * Requests an anonymous pull token from the realm a 401 response names.
   */
  protected function anonymousToken(string $challenge): string {
    if (!preg_match_all('/(\w+)="([^"]*)"/', $challenge, $matches, PREG_SET_ORDER)) {
      return '';
    }
    $params = [];
    foreach ($matches as $match) {
      $params[strtolower($match[1])] = $match[2];
    }
    $realm = $params['realm'] ?? '';
    if (!str_starts_with($realm, 'https://')) {
      return '';
    }

    $query = array_filter([
      'scope'   => $params['scope'] ?? '',
      'service' => $params['service'] ?? '',
    ]);
    $response = $this->httpClient->request('GET', $realm, [
      'query'   => $query,
      'timeout' => self::TIMEOUT_SECONDS,
    ]);
    $data = json_decode((string) $response->getBody(), TRUE, 16, JSON_THROW_ON_ERROR);
    return (string) ($data['token'] ?? $data['access_token'] ?? '');
  }

  /**
   * Fetches the build labels of a tag: manifest, then image config.
   *
   * @return array{created: int, revision: string}
   *   See buildFromLabels().
   */
  protected function fetchBuild(string $host, string $path, string $tag): array {
    $baseUrl = 'https://' . $host . '/v2/' . $path;
    $token = '';
    try {
      $manifest = $this->getJson($baseUrl . '/manifests/' . rawurlencode($tag), $token, self::MANIFEST_ACCEPT);
      if (isset($manifest['manifests']) && is_array($manifest['manifests'])) {
        $digest = $this->platformManifestDigest($manifest['manifests']);
        if ($digest === '') {
          return self::buildFromLabels([]);
        }
        $manifest = $this->getJson($baseUrl . '/manifests/' . $digest, $token, self::MANIFEST_ACCEPT);
      }

      $configDigest = (string) ($manifest['config']['digest'] ?? '');
      if (!preg_match('/^sha256:[0-9a-f]{64}$/', $configDigest)) {
        return self::buildFromLabels([]);
      }
      $config = $this->getJson($baseUrl . '/blobs/' . $configDigest, $token);
    }
    catch (GuzzleException | \JsonException $e) {
      $this->logger->warning('Could not read image @image:@tag from registry @host: @message', [
        '@host'    => $host,
        '@image'   => $path,
        '@message' => $e->getMessage(),
        '@tag'     => $tag,
      ]);
      return self::buildFromLabels([]);
    }

    $labels = $config['config']['Labels'] ?? [];
    return self::buildFromLabels(is_array($labels) ? $labels : []);
  }

  /**
   * GETs a registry JSON document, fetching an anonymous token on 401.
   *
   * @param string $url
   *   Registry URL.
   * @param string $token
   *   Bearer token; filled in after the first 401 and reused by the caller.
   * @param string|null $accept
   *   Accept header, if any.
   *
   * @return array<mixed>
   *   Decoded document.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \JsonException
   */
  protected function getJson(string $url, string &$token, ?string $accept = NULL): array {
    try {
      return $this->request($url, $token, $accept);
    }
    catch (ClientException $e) {
      if ($token !== '' || $e->getResponse()->getStatusCode() !== 401) {
        throw $e;
      }
      $token = $this->anonymousToken($e->getResponse()->getHeaderLine('WWW-Authenticate'));
      if ($token === '') {
        throw $e;
      }
      return $this->request($url, $token, $accept);
    }
  }

  /**
   * Splits an image repository into registry host and path.
   *
   * @return array{host: string, path: string}|null
   *   NULL for names this client does not handle.
   */
  protected function parseRepository(string $repository): ?array {
    if (!preg_match('#^([a-z0-9.-]+\.[a-z]{2,}(?::\d+)?)/([a-z0-9._/-]+)$#', $repository, $matches)) {
      return NULL;
    }
    $host = $matches[1] === 'docker.io' ? 'registry-1.docker.io' : $matches[1];
    return ['host' => $host, 'path' => $matches[2]];
  }

  /**
   * Digest of the manifest for this server's platform in an image index.
   *
   * @param array<mixed> $manifests
   *   The index's manifest descriptors.
   */
  protected function platformManifestDigest(array $manifests): string {
    $architecture = match (php_uname('m')) {
      'aarch64', 'arm64' => 'arm64',
      default => 'amd64',
    };
    foreach ($manifests as $descriptor) {
      if (($descriptor['platform']['os'] ?? '') === 'linux'
        && ($descriptor['platform']['architecture'] ?? '') === $architecture) {
        $digest = (string) ($descriptor['digest'] ?? '');
        return preg_match('/^sha256:[0-9a-f]{64}$/', $digest) ? $digest : '';
      }
    }
    return '';
  }

  /**
   * Sends one GET request and decodes the JSON body.
   *
   * @return array<mixed>
   *   Decoded document.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \JsonException
   */
  protected function request(string $url, string $token, ?string $accept): array {
    $headers = [];
    if ($accept !== NULL) {
      $headers['Accept'] = $accept;
    }
    if ($token !== '') {
      $headers['Authorization'] = 'Bearer ' . $token;
    }
    // Blob downloads redirect to a storage host; Guzzle drops the
    // Authorization header on cross-host redirects.
    $response = $this->httpClient->request('GET', $url, [
      'headers' => $headers,
      'timeout' => self::TIMEOUT_SECONDS,
    ]);
    $data = json_decode((string) $response->getBody(), TRUE, 64, JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : [];
  }

}
