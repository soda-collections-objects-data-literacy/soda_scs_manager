<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Validation;

/**
 * Base slugs forbidden for WissKI quick-create (co-working intro).
 *
 * Covers:
 * - MariaDB/MySQL system schemas.
 * - Names that collide with infrastructure (e.g. keycloak, shared SQL host DB names).
 * - Short names of Docker Compose services across this deployment (root, scs-manager,
 *   scs-nextcloud, jupyterhub, keycloak, webprotege, open_gdb, scs-project-website, etc.).
 *
 * Labels normalize to these slugs via the same rules as stack machine names (lowercase, hyphens).
 * When adding stacks or services to the deployment, extend this list.
 */
final class WisskiIntroReservedBaseSlugs {

  /**
   * Whether the given base slug (pre stack-/sql-/ts-/wisski- prefix) is reserved.
   */
  public static function isReserved(string $baseSlug): bool {
    $key = mb_strtolower($baseSlug);
    return isset(self::lookup()[$key]);
  }

  /**
   * @return array<string, true>
   */
  private static function lookup(): array {
    static $map = NULL;
    if ($map === NULL) {
      $map = array_fill_keys(self::slugs(), TRUE);
    }
    return $map;
  }

  /**
   * @return list<string>
   */
  private static function slugs(): array {
    return array_values(array_unique(array_merge(
      self::mysqlSystemSchemas(),
      self::dockerComposeServiceSlugs(),
      self::genericInfraSlugs(),
    )));
  }

  /**
   * Common image / runtime names that must not be used as instance base slugs.
   *
   * @return list<string>
   */
  private static function genericInfraSlugs(): array {
    return [
      'mariadb',
      'mongo',
      'mongodb',
      'mysql',
      'onlyoffice',
      'postgres',
      'postgresql',
      'traefik',
    ];
  }

  /**
   * @return list<string>
   */
  private static function mysqlSystemSchemas(): array {
    return [
      'information-schema',
      'information_schema',
      'performance-schema',
      'performance_schema',
      'mysql',
      'sys',
    ];
  }

  /**
   * Service keys from docker-compose files, normalized to slug segments (after the last "--"
   * when using a "stack--service" pattern, or the full service name for top-level services).
   *
   * @return list<string>
   */
  private static function dockerComposeServiceSlugs(): array {
    return [
      // keycloak/docker-compose.yml (+ debug db)
      'keycloak',
      'db',
      // webprotege/docker-compose.yml
      'webprotege',
      'wpmongo',
      // docker-compose.yml (root / SCS shared)
      'portainer',
      'database',
      'phpmyadmin',
      'forward-auth',
      'echo',
      'reverse-proxy',
      // scs-manager-stack
      'drupal',
      'redis',
      'varnish',
      // jupyterhub/docker-compose.yml
      'jupyterhub',
      'image-builder',
      'jupyterhub-group-map',
      // scs-nextcloud-stack
      'nextcloud',
      'onlyoffice-document-server',
      'nextcloud-reverse-proxy',
      'onlyoffice-reverse-proxy',
      // open_gdb/docker-compose.yml
      'rdf4j',
      'nginx',
      'outproxy',
      'authproxy',
      'rdf4j-debug-workbench',
      // 00_custom_configs/open_gdb (proxy container)
      'opengdb-proxy',
    ];
  }

}
