<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps the instance-bound configuration of a WissKI across a data import.
 *
 * A demo WissKI receives the database of the template source. That database
 * carries configuration that points at the source instance (triplestore
 * adapter, SSO client, IIIF server, Nextcloud mount, site name). The
 * container entrypoint writes these values only on first install, so they are
 * captured from the target before the import and written back afterwards.
 */
class SodaScsWisskiInstanceConfigRemapper {

  /**
   * Config name prefixes that belong to the instance, not to the content.
   */
  public const INSTANCE_CONFIG_PREFIXES = [
    'dlf_aim_3d_viewer.',
    'nextcloud_webdav_mount.settings',
    'openid_connect.',
    'sso_bouncer.',
    'wisski_iip_image.wisski_iiif_settings',
    'wisski_salz.wisski_salz_adapter.',
  ];

  /**
   * Marks the payload in drush output, which may contain PHP notices.
   */
  protected const OUTPUT_MARKER = 'SCS_INSTANCE_CONFIG:';

  /**
   * Drush may need a while on large sites after a database import.
   */
  protected const DRUSH_TIMEOUT_SECONDS = 600;

  /**
   * Constructs the remapper.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
  ) {}

  /**
   * Writes captured configuration and the site name back into the instance.
   *
   * @param array<string, array> $configs
   *   Config data keyed by config name, as returned by captureInstanceConfig.
   * @param string $siteName
   *   New value for system.site:name.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent
   *   The running WissKI component that received the import.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result.
   */
  public function applyInstanceConfig(array $configs, string $siteName, SodaScsComponentInterface $wisskiComponent): SodaScsResult {
    $payload = base64_encode(json_encode(
      ['configs' => $configs, 'siteName' => $siteName],
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));
    $code = '$payload = json_decode(base64_decode(\'' . $payload . '\'), TRUE);'
      . '$factory = \Drupal::configFactory();'
      . 'foreach ($payload["configs"] as $name => $data) { $factory->getEditable($name)->setData($data)->save(TRUE); }'
      . '$factory->getEditable("system.site")->set("name", $payload["siteName"])->save();'
      . 'echo "' . self::OUTPUT_MARKER . 'ok";';

    $result = $this->runDrush($wisskiComponent, ['php:eval', $code]);
    if (!$result->success) {
      return $result;
    }
    // The template may stem from an older image than the target.
    $result = $this->runDrush($wisskiComponent, ['updatedb', '--yes']);
    if (!$result->success) {
      return $result;
    }

    return $this->runDrush($wisskiComponent, ['cache:rebuild']);
  }

  /**
   * Reads the instance-bound configuration from a running WissKI.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent
   *   The WissKI component whose container is running.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   On success data['configs'] holds config data keyed by config name.
   */
  public function captureInstanceConfig(SodaScsComponentInterface $wisskiComponent): SodaScsResult {
    $prefixes = base64_encode(json_encode(self::INSTANCE_CONFIG_PREFIXES, JSON_THROW_ON_ERROR));
    $code = '$storage = \Drupal::service("config.storage"); $configs = [];'
      . 'foreach (json_decode(base64_decode(\'' . $prefixes . '\'), TRUE) as $prefix) {'
      . ' foreach ($storage->listAll($prefix) as $name) { $configs[$name] = $storage->read($name); } }'
      . 'echo "' . self::OUTPUT_MARKER . '" . base64_encode(json_encode($configs));';

    $result = $this->runDrush($wisskiComponent, ['php:eval', $code]);
    if (!$result->success) {
      return $result;
    }

    $configs = $this->parsePayload((string) ($result->data['output'] ?? ''));
    if ($configs === NULL) {
      return SodaScsResult::failure(
        message: 'Could not read the instance configuration of the WissKI.',
        error: 'Missing or malformed drush output.',
      );
    }
    if (!array_filter(array_keys($configs), static fn (string $name): bool => str_starts_with($name, 'wisski_salz.wisski_salz_adapter.'))) {
      return SodaScsResult::failure(
        message: 'The WissKI has no triplestore adapter yet.',
        error: 'Instance configuration incomplete; the installation has probably not finished.',
      );
    }

    return SodaScsResult::success(
      message: 'Instance configuration captured.',
      data: ['configs' => $configs],
    );
  }

  /**
   * Extracts the base64 JSON payload that follows the output marker.
   *
   * @return array|null
   *   Decoded config map, or NULL when the output holds no valid payload.
   */
  public function parsePayload(string $output): ?array {
    if (!preg_match('/' . preg_quote(self::OUTPUT_MARKER, '/') . '([A-Za-z0-9+\/=]+)/', $output, $matches)) {
      return NULL;
    }
    $decoded = json_decode((string) base64_decode($matches[1], TRUE), TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Runs a drush command as www-data inside the WissKI container.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent
   *   The WissKI component.
   * @param string[] $arguments
   *   Drush arguments.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The exec result.
   */
  protected function runDrush(SodaScsComponentInterface $wisskiComponent, array $arguments): SodaScsResult {
    return $this->containerHelpers->executeDockerExecCommand([
      'cmd' => array_merge(['drush'], $arguments),
      'containerName' => $this->containerHelpers->getComponentContainerName($wisskiComponent),
      'timeout' => self::DRUSH_TIMEOUT_SECONDS,
      'user' => 'www-data',
    ]);
  }

}
