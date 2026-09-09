<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

/**
 * Resolves shared Docker DNS names from environment with stable defaults.
 *
 * During the naming migration, set SCS_CONTAINER_* (and related URL vars) in
 * the Manager container environment / .env. Prefer soda_scs_manager.settings
 * when an operator has configured an explicit value.
 *
 * @see docs/service-infrastructure/naming-vocabulary.md
 */
final class SodaScsDockerDns {

  /**
   * Shared MariaDB compose service / container DNS.
   */
  public static function database(): string {
    return self::env('SCS_CONTAINER_DATABASE', 'scs--database');
  }

  /**
   * Nextcloud FUSE mounter compose service / container DNS.
   */
  public static function nextcloudMounter(): string {
    return self::env('SCS_CONTAINER_NEXTCLOUD_MOUNTER', 'nextcloud-mounter');
  }

  /**
   * rclone rc-API base URL for the mounter sidecar.
   */
  public static function nextcloudMounterRcUrl(): string {
    $url = getenv('NEXTCLOUD_MOUNTER_RC_URL');
    if (is_string($url) && $url !== '') {
      return $url;
    }
    return 'http://' . self::nextcloudMounter() . ':5572';
  }

  /**
   * OpenGDB AuthProxy container DNS.
   */
  public static function authproxy(): string {
    return self::env('SCS_CONTAINER_AUTHPROXY', 'scs--authproxy');
  }

  /**
   * Internal triplestore base URL (no trailing slash).
   */
  public static function authproxyInternalBase(): string {
    $url = getenv('INTERNAL_TS_BASE');
    if (is_string($url) && $url !== '') {
      return rtrim($url, '/');
    }
    return 'http://' . self::authproxy() . ':8000';
  }

  /**
   * Access-proxy helper container name (if used).
   */
  public static function accessProxy(): string {
    return self::env('SCS_CONTAINER_ACCESS_PROXY', 'scs--access-proxy');
  }

  /**
   * Traefik / reverse-proxy container DNS.
   */
  public static function reverseProxy(): string {
    return self::env('SCS_CONTAINER_REVERSE_PROXY', 'scs--reverse-proxy');
  }

  /**
   * Non-empty getenv value or default.
   */
  public static function env(string $name, string $default): string {
    $value = getenv($name);
    if (is_string($value) && $value !== '') {
      return $value;
    }
    return $default;
  }

  /**
   * Prefer config value, then env default (treat empty / {empty} as unset).
   */
  public static function configOrEnv(?string $configValue, string $envName, string $default): string {
    $trimmed = trim((string) $configValue);
    if ($trimmed !== '' && $trimmed !== '{empty}') {
      return $trimmed;
    }
    return self::env($envName, $default);
  }

}
