<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Clears the Varnish cache of one WissKI instance.
 *
 * Each instance has its own `{machineName}--varnish` container. A ban marks
 * every cached object obsolete; the next request is fetched from WissKI.
 */
class SodaScsWisskiVarnishCache {

  use StringTranslationTrait;

  /**
   * varnishadm expression that matches every cached URL.
   *
   * @var list<string>
   */
  public const BAN_COMMAND = ['varnishadm', 'ban', 'req.url', '~', '.'];

  /**
   * Seconds to wait for varnishadm.
   */
  private const BAN_TIMEOUT_SECONDS = 30;

  /**
   * Suffix of the per-instance Varnish container name.
   */
  private const CONTAINER_SUFFIX = '--varnish';

  /**
   * Machine names accepted for WissKI instances.
   */
  private const MACHINE_NAME_PATTERN = '/^[a-z0-9-]+$/';

  /**
   * Constructs a SodaScsWisskiVarnishCache.
   */
  public function __construct(
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerChannelInterface $logger,
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
  ) {}

  /**
   * Bans every cached object in the instance Varnish container.
   *
   * @param string $machineName
   *   WissKI component machine name (Portainer SERVICE_NAME).
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success when varnishadm accepted the ban.
   */
  public function clear(string $machineName): SodaScsResult {
    if (!$this->isValidMachineName($machineName)) {
      $this->logger->warning('Refused to clear Varnish cache: invalid WissKI machine name.');
      return SodaScsResult::failure(
        error: 'Invalid WissKI machine name.',
        message: (string) $this->t('This WissKI instance has no valid machine name.'),
        log: FALSE,
      );
    }

    $containerName = $this->varnishContainerName($machineName);
    $lookup = $this->containerHelpers->findLiveContainerByName($containerName);
    if (!$lookup->success) {
      $this->logger->warning('Varnish container @container was not found for WissKI @name.', [
        '@container' => $containerName,
        '@name' => $machineName,
      ]);
      return SodaScsResult::failure(
        error: (string) ($lookup->error ?? 'Varnish container not found.'),
        message: (string) $this->t('Varnish container not found.'),
        log: FALSE,
      );
    }

    $state = (string) ($lookup->data['state'] ?? '');
    if ($state !== 'running') {
      $this->logger->warning('Varnish container @container of WissKI @name is @state.', [
        '@container' => $containerName,
        '@name' => $machineName,
        '@state' => $state !== '' ? $state : 'not running',
      ]);
      return SodaScsResult::failure(
        error: 'Varnish container is not running.',
        message: (string) $this->t('The Varnish container of this WissKI instance is not running.'),
        log: FALSE,
      );
    }

    $containerId = (string) ($lookup->data['containerId'] ?? '');
    $execResult = $this->containerHelpers->executeDockerExecCommand([
      'cmd' => self::BAN_COMMAND,
      'containerName' => $containerId !== '' ? $containerId : $containerName,
      'timeout' => self::BAN_TIMEOUT_SECONDS,
      'user' => 'root',
    ]);
    if (!$execResult->success) {
      $this->logger->error('Varnish cache clear failed for WissKI @name (@container): @error', [
        '@container' => $containerName,
        '@error' => (string) ($execResult->error ?? $execResult->message),
        '@name' => $machineName,
      ]);
      return SodaScsResult::failure(
        error: (string) ($execResult->error ?? 'varnishadm ban failed.'),
        message: (string) $this->t('Could not clear the Varnish cache.'),
        log: FALSE,
      );
    }

    $this->logger->info('Cleared Varnish cache of WissKI @name in container @container.', [
      '@container' => $containerName,
      '@name' => $machineName,
    ]);
    return SodaScsResult::success(
      data: [
        'containerName' => $containerName,
      ],
      message: (string) $this->t('Varnish cache cleared.'),
    );
  }

  /**
   * Docker name of the Varnish container for a WissKI machine name.
   *
   * @param string $machineName
   *   WissKI component machine name.
   *
   * @return string
   *   Container name, for example wisski-demo--varnish.
   */
  public function varnishContainerName(string $machineName): string {
    return $machineName . self::CONTAINER_SUFFIX;
  }

  /**
   * Whether a machine name can be used as a container name fragment.
   */
  private function isValidMachineName(string $machineName): bool {
    return $machineName !== '' && preg_match(self::MACHINE_NAME_PATTERN, $machineName) === 1;
  }

}
