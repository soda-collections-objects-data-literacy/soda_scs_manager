<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Site\Settings;
use Drupal\soda_scs_manager\Traits\SecureLoggingTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * HTTP client for the nextcloud-mounter rclone rc-API.
 */
class NextcloudMounterClient {

  use SecureLoggingTrait;

  /**
   * Constructs a NextcloudMounterClient.
   */
  public function __construct(
    #[Autowire(service: 'http_client')]
    protected ClientInterface $httpClient,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns whether the sidecar rc-API answers core/pid.
   */
  public function ping(): bool {
    try {
      $this->request('core/pid');
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Obscures a password via rclone core/obscure.
   *
   * @throws \RuntimeException
   *   When the rc call fails.
   */
  public function obscure(string $password): string {
    $result = $this->request('core/obscure', ['clear' => $password]);
    $obscured = $result['obscured'] ?? NULL;
    if (!is_string($obscured) || $obscured === '') {
      throw new \RuntimeException('rclone core/obscure returned no obscured password.');
    }
    return $obscured;
  }

  /**
   * Lists active mounts from the sidecar.
   *
   * @return list<array<string, mixed>>
   *   Mount descriptors from mount/listmounts.
   */
  public function listMounts(): array {
    $result = $this->request('mount/listmounts');
    $mounts = $result['mountPoints'] ?? $result['mounts'] ?? [];
    return is_array($mounts) ? array_values($mounts) : [];
  }

  /**
   * Mounts a user's Nextcloud WebDAV at /mnt/nextcloud/<machineName>.
   *
   * @throws \RuntimeException
   *   When the rc call fails.
   */
  public function mount(string $machineName, string $webdavUrl, string $ncUser, string $appPassword): void {
    $this->ensureMountDirectory($machineName);
    $obscured = $this->obscure($appPassword);
    $fs = sprintf(
      ':webdav,url="%s",vendor=nextcloud,user=%s,pass=%s:',
      $webdavUrl,
      $ncUser,
      $obscured,
    );
    $this->request('mount/mount', [
      'fs' => $fs,
      'mountPoint' => $this->sidecarMountPoint($machineName),
      'mountOpt' => ['AllowOther' => TRUE],
      'vfsOpt' => [
        'CacheMode' => 2,
        'UID' => 33,
        'GID' => 33,
        'Umask' => 18,
      ],
    ]);
  }

  /**
   * Unmounts a user's mount. Missing mounts are ignored.
   */
  public function unmount(string $machineName): void {
    try {
      $this->request('mount/unmount', [
        'mountPoint' => $this->sidecarMountPoint($machineName),
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->notice('Nextcloud unmount for @user ignored: @msg', [
        '@user' => $machineName,
        '@msg' => $this->sanitizeForLogging($e->getMessage()),
      ]);
    }
  }

  /**
   * Host path for probes and mkdir (Manager container bind).
   */
  public function hostMountPath(string $machineName): string {
    return rtrim($this->mountsRootInContainer(), '/') . '/' . $machineName;
  }

  /**
   * Sidecar mount point path.
   */
  public function sidecarMountPoint(string $machineName): string {
    return '/mnt/nextcloud/' . $machineName;
  }

  /**
   * Ensures the per-user directory exists on the shared host bind.
   */
  public function ensureMountDirectory(string $machineName): void {
    $path = $this->hostMountPath($machineName);
    if (!is_dir($path) && !@mkdir($path, 0755, TRUE) && !is_dir($path)) {
      throw new \RuntimeException(sprintf('Could not create mount directory %s.', $path));
    }
  }

  /**
   * Probes whether the host mount path looks healthy.
   *
   * @return string
   *   One of: mounted, missing, broken.
   */
  public function probeMount(string $machineName): string {
    $path = $this->hostMountPath($machineName);
    if (!file_exists($path) && !is_dir($path)) {
      return 'missing';
    }
    $stat = @stat($path);
    if ($stat === FALSE) {
      $error = error_get_last()['message'] ?? '';
      if (str_contains($error, 'Transport endpoint is not connected') || str_contains($error, 'Input/output error')) {
        return 'broken';
      }
      return 'broken';
    }
    return 'mounted';
  }

  /**
   * Whether listmounts already contains this sidecar mount point.
   */
  public function isListed(string $machineName, ?array $mounts = NULL): bool {
    $mounts ??= $this->listMounts();
    $wanted = $this->sidecarMountPoint($machineName);
    foreach ($mounts as $mount) {
      $point = is_array($mount)
        ? ($mount['MountPoint'] ?? $mount['mountPoint'] ?? $mount['Fs'] ?? NULL)
        : NULL;
      if (is_string($point) && ($point === $wanted || str_ends_with($point, '/' . $machineName))) {
        return TRUE;
      }
      if (is_string($mount) && ($mount === $wanted || str_ends_with($mount, '/' . $machineName))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * POSTs JSON to an rc endpoint with Basic Auth.
   *
   * @param array<string, mixed> $payload
   *   Request body.
   *
   * @return array<string, mixed>
   *   Decoded JSON body.
   *
   * @throws \RuntimeException
   */
  protected function request(string $endpoint, array $payload = []): array {
    $url = rtrim($this->rcUrl(), '/') . '/' . ltrim($endpoint, '/');
    try {
      // rclone rc expects a JSON object; Guzzle encodes [] for empty arrays.
      $body = $payload === [] ? new \stdClass() : $payload;
      $response = $this->httpClient->request('POST', $url, [
        'auth' => [$this->rcUser(), $this->rcPass()],
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        'json' => $body,
        'http_errors' => TRUE,
        'timeout' => 60,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Nextcloud mounter rc @endpoint failed: @msg', [
        '@endpoint' => $endpoint,
        '@msg' => $this->sanitizeForLogging($e->getMessage(), [
          '/("pass"\s*:\s*")([^"]+)(")/' => '$1[REDACTED]$3',
          '/(:webdav,[^:]*,pass=)([^:]+)(:)/' => '$1[REDACTED]$3',
        ]),
      ]);
      throw new \RuntimeException('Nextcloud mounter rc call failed: ' . $endpoint, 0, $e);
    }

    $body = (string) $response->getBody();
    if ($body === '') {
      return [];
    }
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException('Nextcloud mounter returned non-JSON for ' . $endpoint);
    }
    return $decoded;
  }

  protected function rcUrl(): string {
    $url = getenv('NEXTCLOUD_MOUNTER_RC_URL') ?: Settings::get('nextcloud_mounter_rc_url', 'http://nextcloud-mounter:5572');
    return is_string($url) && $url !== '' ? $url : 'http://nextcloud-mounter:5572';
  }

  protected function rcUser(): string {
    $user = getenv('NEXTCLOUD_MOUNTER_RC_USER') ?: Settings::get('nextcloud_mounter_rc_user', 'scs-manager');
    return is_string($user) && $user !== '' ? $user : 'scs-manager';
  }

  protected function rcPass(): string {
    $pass = getenv('NEXTCLOUD_MOUNTER_RC_PASS') ?: Settings::get('nextcloud_mounter_rc_pass', '');
    return is_string($pass) ? $pass : '';
  }

  protected function mountsRootInContainer(): string {
    // Compose binds host NEXTCLOUD_MOUNTS_ROOT to /mnt/nextcloud-mounts.
    return '/mnt/nextcloud-mounts';
  }

  /**
   * Host-side mounts root (for stack env rendering).
   */
  public function mountsRootOnHost(): string {
    $root = getenv('NEXTCLOUD_MOUNTS_ROOT') ?: Settings::get('nextcloud_mounts_root', '/var/lib/scs/nextcloud-mounts');
    return is_string($root) && $root !== '' ? $root : '/var/lib/scs/nextcloud-mounts';
  }

}
