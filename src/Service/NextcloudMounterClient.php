<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Site\Settings;
use Drupal\soda_scs_manager\Helpers\SodaScsDockerDns;
use Drupal\soda_scs_manager\Traits\SecureLoggingTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * HTTP client for the nextcloud-mounter rclone rc-API.
 *
 * Mount paths are relative to the shared mounts root: a user machine name for
 * personal Drives, or _projects/<gid>/<label> for project Team Folders.
 */
class NextcloudMounterClient {

  use SecureLoggingTrait;

  /**
   * Directory below the mounts root that holds project Team Folder mounts.
   */
  public const PROJECTS_DIR = '_projects';

  /**
   * Owner (uid and gid) of mount directories: www-data.
   */
  protected const MOUNT_DIRECTORY_OWNER = 33;

  /**
   * Mounts root inside the sidecar container.
   */
  public const SIDECAR_ROOT = '/mnt/nextcloud';

  /**
   * Default rclone VFS options; callers may override single keys.
   */
  protected const DEFAULT_VFS_OPTIONS = [
    'CacheMode' => 2,
    'UID'       => 33,
    'GID'       => 33,
    'Umask'     => 18,
  ];

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
   * Lists the sidecar mount points of the active mounts.
   *
   * @param list<array<string, mixed>|string>|null $mounts
   *   Result of listMounts(), fetched when NULL.
   *
   * @return list<string>
   *   Absolute sidecar paths, e.g. /mnt/nextcloud/rnsrk.
   */
  public function listMountPoints(?array $mounts = NULL): array {
    $mounts ??= $this->listMounts();
    $points = [];
    foreach ($mounts as $mount) {
      $point = is_array($mount)
        ? ($mount['MountPoint'] ?? $mount['mountPoint'] ?? NULL)
        : $mount;
      if (is_string($point) && $point !== '') {
        $points[] = $point;
      }
    }
    return $points;
  }

  /**
   * Mounts a Nextcloud WebDAV URL at /mnt/nextcloud/<relativePath>.
   *
   * @param string $relativePath
   *   Mount path below the mounts root.
   * @param string $webdavUrl
   *   WebDAV URL of the remote root.
   * @param string $ncUser
   *   Nextcloud login name.
   * @param string $appPassword
   *   Nextcloud app password.
   * @param array<string, mixed> $vfsOptions
   *   rclone VFS options that replace the defaults.
   *
   * @throws \RuntimeException
   *   When the rc call fails.
   */
  public function mount(string $relativePath, string $webdavUrl, string $ncUser, string $appPassword, array $vfsOptions = []): void {
    $this->ensureMountDirectory($relativePath);
    $obscured = $this->obscure($appPassword);
    $fs = sprintf(
      ':webdav,url="%s",vendor=nextcloud,user=%s,pass=%s:',
      $webdavUrl,
      $ncUser,
      $obscured,
    );
    $this->request('mount/mount', [
      'fs' => $fs,
      'mountPoint' => $this->sidecarMountPoint($relativePath),
      'mountOpt' => ['AllowOther' => TRUE],
      'vfsOpt' => array_replace(self::DEFAULT_VFS_OPTIONS, $vfsOptions),
    ]);
  }

  /**
   * Unmounts a mount. Missing mounts are ignored.
   */
  public function unmount(string $relativePath): void {
    try {
      $this->request('mount/unmount', [
        'mountPoint' => $this->sidecarMountPoint($relativePath),
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->notice('Nextcloud unmount of @path ignored: @msg', [
        '@path' => $relativePath,
        '@msg' => $this->sanitizeForLogging($e->getMessage()),
      ]);
    }
  }

  /**
   * Host path for probes and mkdir (Manager container bind).
   */
  public function hostMountPath(string $relativePath): string {
    return rtrim($this->mountsRootInContainer(), '/') . '/' . $relativePath;
  }

  /**
   * Sidecar mount point path.
   */
  public function sidecarMountPoint(string $relativePath): string {
    return self::SIDECAR_ROOT . '/' . $relativePath;
  }

  /**
   * Ensures the mount directory and its parents exist on the shared host bind.
   */
  public function ensureMountDirectory(string $relativePath): void {
    $path = rtrim($this->mountsRootInContainer(), '/');
    foreach (explode('/', $relativePath) as $segment) {
      $path .= '/' . $segment;
      if (is_dir($path)) {
        continue;
      }
      if (!@mkdir($path, 0755) && !is_dir($path)) {
        throw new \RuntimeException(sprintf('Could not create mount directory %s.', $path));
      }
      // Drush runs as root, PHP-FPM as www-data; both must manage the tree.
      @chown($path, self::MOUNT_DIRECTORY_OWNER);
      @chgrp($path, self::MOUNT_DIRECTORY_OWNER);
    }
  }

  /**
   * Removes an empty, unmounted mount directory (best effort).
   *
   * Never touches a live mount: rmdir fails on mount points and non-empty
   * directories.
   */
  public function removeMountDirectory(string $relativePath): void {
    $path = $this->hostMountPath($relativePath);
    clearstatcache(TRUE, $path);
    if (is_dir($path)) {
      @rmdir($path);
    }
  }

  /**
   * Probes whether the host mount path looks healthy.
   *
   * @return string
   *   One of: mounted, missing, broken.
   */
  public function probeMount(string $relativePath): string {
    $path = $this->hostMountPath($relativePath);
    if (!file_exists($path) && !is_dir($path)) {
      return 'missing';
    }
    // A dead FUSE endpoint fails stat ("Transport endpoint is not connected").
    if (@stat($path) === FALSE) {
      return 'broken';
    }
    return 'mounted';
  }

  /**
   * Whether listmounts already contains exactly this sidecar mount point.
   */
  public function isListed(string $relativePath, ?array $mounts = NULL): bool {
    return in_array($this->sidecarMountPoint($relativePath), $this->listMountPoints($mounts), TRUE);
  }

  /**
   * POSTs JSON to an rc endpoint with Basic Auth.
   *
   * @param string $endpoint
   *   The rc endpoint, e.g. mount/mount.
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
    $url = getenv('NEXTCLOUD_MOUNTER_RC_URL') ?: Settings::get('nextcloud_mounter_rc_url', '');
    if (is_string($url) && $url !== '') {
      return $url;
    }
    return SodaScsDockerDns::nextcloudMounterRcUrl();
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
