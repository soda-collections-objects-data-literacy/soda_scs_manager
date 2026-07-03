<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Helper class for SCS Drupal operations.
 */
class SodaScsDrupalHelpers {

  use StringTranslationTrait;

  /**
   * Cache TTL for Drupal package checks, in seconds.
   *
   * Keep this short enough to avoid stale data, but long enough to prevent
   * repeated container exec calls on frequent refreshes.
   */
  private const DRUPAL_PACKAGES_CACHE_TTL = 600;

  /**
   * Cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cacheBackend;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The container helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers
   */
  protected SodaScsContainerHelpers $sodaScsContainerHelpers;

  /**
   * The component helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected SodaScsComponentHelpers $sodaScsComponentHelpers;

  /**
   * The database helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsDatabaseHelpers
   */
  protected SodaScsDatabaseHelpers $sodaScsDatabaseHelpers;

  /**
   * The service key actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  /**
   * The SCS helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsHelpers
   */
  protected SodaScsHelpers $sodaScsHelpers;

  /**
   * The progress helper.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper
   */
  protected SodaScsProgressHelper $sodaScsProgressHelper;

  /**
   * WissKI component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions
   */
  protected SodaScsWisskiComponentActions $sodaScsWisskiComponentActions;

  /**
   * SodaScsDrupalHelpers constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   The cache backend.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsDatabaseHelpers $sodaScsDatabaseHelpers
   *   The database helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers $sodaScsComponentHelpers
   *   The component helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers $sodaScsContainerHelpers
   *   The container helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers $sodaScsServiceHelpers
   *   The service helpers.
   * @param \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions
   *   The service key actions.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsHelpers $sodaScsHelpers
   *   The SCS helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper $sodaScsProgressHelper
   *   The progress helper.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions $sodaScsWisskiComponentActions
   *   WissKI component actions.
   */
  public function __construct(
    #[Autowire(service: 'cache.default')]
    CacheBackendInterface $cacheBackend,
    #[Autowire(service: 'entity_type.manager')]
    EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'logger.factory')]
    LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'soda_scs_manager.database.helpers')]
    SodaScsDatabaseHelpers $sodaScsDatabaseHelpers,
    #[Autowire(service: 'soda_scs_manager.component.helpers')]
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    SodaScsContainerHelpers $sodaScsContainerHelpers,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    #[Autowire(service: 'soda_scs_manager.service_key.actions')]
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    #[Autowire(service: 'datetime.time')]
    TimeInterface $time,
    #[Autowire(service: 'soda_scs_manager.helpers')]
    SodaScsHelpers $sodaScsHelpers,
    #[Autowire(service: 'soda_scs_manager.progress.helpers')]
    SodaScsProgressHelper $sodaScsProgressHelper,
    #[Autowire(service: 'soda_scs_manager.wisski_component.actions')]
    SodaScsWisskiComponentActions $sodaScsWisskiComponentActions,
  ) {
    $this->cacheBackend = $cacheBackend;
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->sodaScsDatabaseHelpers = $sodaScsDatabaseHelpers;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsContainerHelpers = $sodaScsContainerHelpers;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->time = $time;
    $this->sodaScsHelpers = $sodaScsHelpers;
    $this->sodaScsProgressHelper = $sodaScsProgressHelper;
    $this->sodaScsWisskiComponentActions = $sodaScsWisskiComponentActions;
  }

  /**
   * Clears the Drupal cache for the provided component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The Drupal/WissKI component.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result of the cache clear command.
   */
  public function clearDrupalCache(SodaScsComponentInterface $component): SodaScsResult {
    $containerId = $component->getContainerId();
    if ($containerId === NULL) {
      return SodaScsResult::failure(
        error: 'Component container ID not found.',
        message: (string) $this->t('Component container ID not found.'),
      );
    }

    $cacheClearResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
      'cmd' => [
        'drush',
        'cr',
      ],
      'containerName' => $containerId,
      'user' => 'www-data',
    ]);

    if (!$cacheClearResponse->success) {
      return SodaScsResult::failure(
        error: 'Failed to clear Drupal cache: ' . ($cacheClearResponse->error ?? ''),
        message: (string) $this->t('Failed to clear Drupal cache.'),
      );
    }

    return SodaScsResult::success(
      data: $cacheClearResponse->data,
      message: (string) $this->t('Drupal cache cleared successfully.'),
    );
  }

  /**
   * Ensures the Drupal container is healthy before running package operations.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   * @param bool $wait
   *   When TRUE, poll until Drupal responds on /health or the wait budget expires.
   * @param int $maxWaitSeconds
   *   Maximum wait time when $wait is TRUE (default 10 minutes).
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult|null
   *   A failure result when the Drupal instance is not healthy, or NULL when
   *   it is healthy.
   */
  private function ensureDrupalHealthy(
    SodaScsComponentInterface $component,
    bool $wait = FALSE,
    int $maxWaitSeconds = 600,
  ): ?SodaScsResult {
    $sleepInterval = 5;
    $maxAttempts = $wait
      ? max(1, (int) floor($maxWaitSeconds / $sleepInterval))
      : 1;

    $lastHealth = [];
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
      $health = $this->sodaScsComponentHelpers->drupalHealthCheck($component);
      $lastHealth = is_array($health) ? $health : [];

      $status = (string) ($lastHealth['status'] ?? '');
      $success = (bool) ($lastHealth['success'] ?? FALSE);

      if ($success && $status === 'running') {
        return SodaScsResult::success(
          data: $lastHealth,
          message: (string) $this->t('Drupal is healthy.'),
        );
      }

      if ($wait && $this->isDrupalHealthCheckRetryable($lastHealth) && $attempt < $maxAttempts - 1) {
        sleep($sleepInterval);
        continue;
      }

      break;
    }

    $status = (string) ($lastHealth['status'] ?? '');
    $message = $status !== ''
      ? (string) $this->t('Drupal is not healthy (status: @status). Not retrieving packages.', ['@status' => $status])
      : (string) $this->t('Drupal is not healthy. Not retrieving packages.');

    $error = $lastHealth !== []
      ? json_encode($lastHealth, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
      : 'Health check failed.';
    $error = $error !== '' ? 'Drupal health check failed: ' . $error : 'Drupal health check failed.';

    return SodaScsResult::failure(
      error: $error,
      message: $message,
    );
  }

  /**
   * Whether a Drupal health probe should be retried after stack redeploy.
   *
   * Traefik and Drupal can lag behind Docker "running" (HTTP 404/502/503).
   */
  private function isDrupalHealthCheckRetryable(array $health): bool {
    $status = (string) ($health['status'] ?? '');
    return in_array($status, ['starting', 'unavailable', 'unknown'], TRUE);
  }

  /**
   * Get the installed Drupal packages.
   *
   * Construct run request for composer list command and execute it,
   * get the output and return it as a Soda SCS result. Only required
   * packages are checked for installed versions.
   *
   * Note: This intentionally does not cache installed versions, so the
   * displayed installed package versions always reflect the actual container
   * state. The "latest versions" check is cached separately to avoid
   * over-requesting.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function getInstalledDrupalPackages(SodaScsComponentInterface $component): SodaScsResult {
    try {

      // Ensure the Drupal instance is healthy.
      $healthStatusResult = $this->ensureDrupalHealthy($component);
      if (!$healthStatusResult->success) {
        return $healthStatusResult;
      }

      // Get the packages via Composer.
      //
      // Use JSON output to avoid issues with column formatting and whitespace
      // collapsing when this response is rendered in a browser/JSON viewer.
      $dockerExecCommandResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd'           => [
          'composer',
          'show',
          '--direct',
          '--format=json',
          '--no-ansi',
          '--no-interaction',
        ],
        'containerName' => (string) $component->get('containerId')->value,
        'user'          => 'www-data',
      ]);
      if (!$dockerExecCommandResponse->success) {
        $errorDetail = 'Failed to get installed Drupal packages: ' . ($dockerExecCommandResponse->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to get installed Drupal packages.'),
        );
      }

      // Parse the Composer output.
      $rawOutput = (string) ($dockerExecCommandResponse->data['output'] ?? '');
      $composerData = json_decode($rawOutput, TRUE);
      if (!is_array($composerData) || !isset($composerData['installed']) || !is_array($composerData['installed'])) {
        $errorDetail = $rawOutput ?: 'Invalid Composer output.';
        return SodaScsResult::failure(
          error: 'Failed to parse installed Drupal packages: ' . $errorDetail,
          message: (string) $this->t('Failed to parse installed Drupal packages.'),
        );
      }

      // Create the packages array for the table.
      $packages = [];
      foreach ($composerData['installed'] as $package) {
        if (!is_array($package)) {
          continue;
        }
        $packages[] = [
          'name'        => (string) ($package['name'] ?? ''),
          'version'     => (string) ($package['version'] ?? ''),
          'available'   => (string) ($package['latest'] ?? ''),
          'description' => (string) ($package['description'] ?? ''),
        ];
      }

      // Construct array of the result data.
      $data = [
        'packages' => $packages,
        'exec'     => $dockerExecCommandResponse->data,
        'checkedAt' => $this->time->getCurrentTime(),
      ];

      $data = $this->mergeCachedLatestIntoInstalled($component, $data);

      return SodaScsResult::success(
        message: (string) $this->t('Installed Drupal packages retrieved successfully.'),
        data: $data,
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: 'Failed to get installed Drupal packages: ' . $e->getMessage(),
        message: (string) $this->t('Failed to get installed Drupal packages.'),
      );
    }
  }

  /**
   * Get the latest available Drupal package versions (cached).
   *
   * This runs a composer command inside the component container to get the
   * latest available package versions. Results are
   * cached for a short TTL to avoid over-requesting. The cached results are
   * merged into the installed packages data.
   * Only required packages are checked for latest versions.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   * @param bool $forceRefresh
   *   Whether to bypass cache and force a refresh.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function getLatestDrupalPackages(SodaScsComponentInterface $component, bool $forceRefresh = FALSE): SodaScsResult {
    try {
      // Ensure the Drupal instance is healthy.
      $healthStatusResult = $this->ensureDrupalHealthy($component);
      if (!$healthStatusResult->success) {
        return $healthStatusResult;
      }

      // Get the cached latest packages.
      $cacheId = $this->getDrupalPackagesCacheId('latest', $component);
      if (!$forceRefresh) {
        $cached = $this->cacheBackend->get($cacheId);
        if ($cached && is_array($cached->data)) {
          return SodaScsResult::success(
            message: (string) $this->t('Latest Drupal package versions retrieved successfully (cached).'),
            data: $cached->data,
          );
        }
      }

      // Get the latest packages via Composer.
      // --latest needs a writable COMPOSER_HOME; the image default is root-owned.
      $dockerExecCommandResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd'           => [
          'composer',
          'show',
          '--direct',
          '--latest',
          '--format=json',
          '--no-ansi',
          '--no-interaction',
        ],
        'containerName' => (string) $component->get('containerId')->value,
        'user'          => 'www-data',
        'env'           => [
          'COMPOSER_HOME=/tmp/composer-home-scs-manager',
        ],
      ]);
      if (!$dockerExecCommandResponse->success) {
        $errorDetail = 'Failed to check latest Drupal packages: ' . ($dockerExecCommandResponse->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to check latest Drupal packages.'),
        );
      }

      // Parse the Composer output.
      $rawOutput = (string) ($dockerExecCommandResponse->data['output'] ?? '');
      $composerData = json_decode($rawOutput, TRUE);
      if (!is_array($composerData) || !isset($composerData['installed']) || !is_array($composerData['installed'])) {
        $errorDetail = 'Invalid Composer output: ' . $rawOutput;
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to check latest Drupal packages.'),
        );
      }

      // Create the packages array for the table.
      $packages = [];
      $outdated = [];
      foreach ($composerData['installed'] as $package) {
        if (!is_array($package)) {
          continue;
        }
        $name = (string) ($package['name'] ?? '');
        $version = (string) ($package['version'] ?? '');
        $available = (string) ($package['latest'] ?? '');
        $entry = [
          'name'        => $name,
          'version'     => $version,
          'available'   => $available,
          'description' => (string) ($package['description'] ?? ''),
        ];
        $packages[] = $entry;
        if ($name !== '' && $available !== '' && $available !== $version) {
          $outdated[] = $entry;
        }
      }

      // Construct array of the result data.
      $data = [
        'packages' => $packages,
        'outdated' => $outdated,
        'exec'     => $dockerExecCommandResponse->data,
        'checkedAt' => $this->time->getCurrentTime(),
        'cachedTtl' => self::DRUPAL_PACKAGES_CACHE_TTL,
      ];

      // Cache the latest packages.
      $this->cacheBackend->set(
        $cacheId,
        $data,
        $this->time->getCurrentTime() + self::DRUPAL_PACKAGES_CACHE_TTL,
        $this->getDrupalPackagesCacheTags($component),
      );

      return SodaScsResult::success(
        message: (string) $this->t('Latest Drupal package versions retrieved successfully.'),
        data: $data,
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: 'Failed to check latest Drupal packages: ' . $e->getMessage(),
        message: (string) $this->t('Failed to check latest Drupal packages.'),
      );
    }
  }

  /**
   * Set the Drupal maintainment mode.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   * @param bool $enable
   *   Whether to enable or disable the maintainment mode.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function setDrupalMaintainmentMode(SodaScsComponentInterface $component, bool $enable): SodaScsResult {
    try {
      // Ensure the Drupal instance is healthy.
      $healthStatusResult = $this->ensureDrupalHealthy($component);
      if (!$healthStatusResult->success) {
        return $healthStatusResult;
      }

      // Set the Drupal maintainment mode.
      $dockerExecCommandResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd' => [
          'drush',
          'state:set',
          'system.maintenance_mode',
          $enable ? '1' : '0',
        ],
        'containerName' => (string) $component->get('containerId')->value,
        'user'          => 'www-data',
      ]);
      if (!$dockerExecCommandResponse->success) {
        $errorDetail = 'Failed to set Drupal maintainment mode: ' . ($dockerExecCommandResponse->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to set Drupal maintainment mode.'),
        );
      }

      return SodaScsResult::success(
        message: (string) $this->t('Drupal maintainment mode set successfully.'),
        data: $dockerExecCommandResponse->data,
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: 'Failed to set Drupal maintainment mode: ' . $e->getMessage(),
        message: (string) $this->t('Failed to set Drupal maintainment mode.'),
      );
    }
  }

  /**
   * Performs a simple composer update for development mode.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  protected function simpleComposerUpdate(SodaScsComponentInterface $component): SodaScsResult {
    $composerUpdateResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
      'cmd'           => [
        'composer',
        'update',
        '--no-interaction',
      ],
      'containerName' => (string) $component->get('containerId')->value,
      'user'          => 'www-data',
    ]);

    if (!$composerUpdateResponse->success) {
      $errorDetail = 'Failed to perform composer update: ' . ($composerUpdateResponse->error ?? '');
      return SodaScsResult::failure(
        error: $errorDetail,
        message: (string) $this->t('Failed to update Drupal packages.'),
      );
    }

    return SodaScsResult::success(
      message: (string) $this->t('Drupal packages updated successfully.'),
      data: [
        'composerUpdate' => $composerUpdateResponse->data,
      ],
    );
  }

  /**
   * Performs versioned composer update for production mode.
   *
   * Downloads composer.json and composer.lock from the git repository
   * based on the component's version, then performs composer install.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   * @param string $mode
   *   The mode (production or development).
   * @param string|null $version
   *   The version to use. If NULL, uses production version.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  protected function versionedComposerUpdate(SodaScsComponentInterface $component, string $mode, ?string $version = NULL): SodaScsResult {
    $wisskiInstanceSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();

    // Use provided version or fall back to production version.
    $targetVersion = $version ?? $wisskiInstanceSettings['productionVersion'];

    // Download the composer.json and composer.lock files from the git
    // repository.
    $drupalPackageUrl = strtr('https://raw.githubusercontent.com/soda-collections-objects-data-literacy/drupal_packages/refs/heads/main/wisski_base/{mode}/{version}/', [
      '{mode}'    => $mode,
      '{version}' => $targetVersion,
    ]);

    // Remove existing composer files as root to avoid permission issues.
    $removeFilesResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
      'cmd'           => [
        'sh',
        '-c',
        'rm -rf /opt/drupal/vendor /opt/drupal/composer.json /opt/drupal/composer.lock',
      ],
      'containerName' => (string) $component->get('containerId')->value,
      'user'          => 'root',
    ]);

    if (!$removeFilesResponse->success) {
      $errorDetail = 'Failed to remove existing composer files: ' . ($removeFilesResponse->error ?? '');
      return SodaScsResult::failure(
        error: $errorDetail,
        message: (string) $this->t('Failed to update Drupal packages.'),
      );
    }

    // Download composer.json and composer.lock files as root.
    $downloadComposerJsonResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
      'cmd'           => [
        'wget',
        $drupalPackageUrl . 'composer.json',
        '-O',
        '/opt/drupal/composer.json',
      ],
      'containerName' => (string) $component->get('containerId')->value,
      'user'          => 'root',
    ]);

    if (!$downloadComposerJsonResponse->success) {
      $errorDetail = 'Failed to download composer.json file: ' . ($downloadComposerJsonResponse->error ?? '');
      return SodaScsResult::failure(
        error: $errorDetail,
        message: (string) $this->t('Failed to update Drupal packages.'),
      );
    }

    // Download composer.lock file as root.
    $downloadComposerLockResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
      'cmd'           => [
        'wget',
        $drupalPackageUrl . 'composer.lock',
        '-O',
        '/opt/drupal/composer.lock',
      ],
      'containerName' => (string) $component->get('containerId')->value,
      'user'          => 'root',
    ]);

    if (!$downloadComposerLockResponse->success) {
      $errorDetail = 'Failed to download composer.lock file: ' . ($downloadComposerLockResponse->error ?? '');
      return SodaScsResult::failure(
        error: $errorDetail,
        message: (string) $this->t('Failed to update Drupal packages.'),
      );
    }

    // Set proper ownership and permissions for the entire /opt/drupal
    // directory.
    // @todo Fix this all in set-permissions not here!
    $setPermissionsResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
      'cmd'           => [
        'sh',
        '-c',
        'chown -R www-data:www-data /opt/drupal && chmod -R 775 /opt/drupal',
      ],
      'containerName' => (string) $component->get('containerId')->value,
      'user'          => 'root',
    ]);

    if (!$setPermissionsResponse->success) {
      $errorDetail = 'Failed to set permissions on drupal directory: ' . ($setPermissionsResponse->error ?? '');
      return SodaScsResult::failure(
        error: $errorDetail,
        message: (string) $this->t('Failed to update Drupal packages.'),
      );
    }

    // Perform composer install.
    $composerInstallResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
      'cmd'           => [
        'composer',
        'install',
        '--no-interaction',
      ],
      'containerName' => (string) $component->get('containerId')->value,
      'user'          => 'www-data',
    ]);

    if (!$composerInstallResponse->success) {
      $errorDetail = 'Failed to perform composer install: ' . ($composerInstallResponse->error ?? '');
      return SodaScsResult::failure(
        error: $errorDetail,
        message: (string) $this->t('Failed to update Drupal packages.'),
      );
    }

    return SodaScsResult::success(
      message: (string) $this->t('Drupal packages updated successfully.'),
      data: [
        'downloadComposerJson' => $downloadComposerJsonResponse->data,
        'downloadComposerLock' => $downloadComposerLockResponse->data,
        'composerInstall'      => $composerInstallResponse->data,
      ],
    );
  }

  /**
   * Updates the Drupal database using drush updatedb.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  protected function updateDrupalDatabase(SodaScsComponentInterface $component): SodaScsResult {
    $updateDbResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
      'cmd'           => [
        'drush',
        'updatedb',
        '--yes',
      ],
      'containerName' => (string) $component->get('containerId')->value,
      'user'          => 'www-data',
    ]);

    if (!$updateDbResponse->success) {
      $errorDetail = 'Failed to update database: ' . ($updateDbResponse->error ?? '');
      return SodaScsResult::failure(
        error: $errorDetail,
        message: (string) $this->t('Failed to update Drupal database.'),
      );
    }

    return SodaScsResult::success(
      message: (string) $this->t('Drupal database updated successfully.'),
      data: [
        'updatedb' => $updateDbResponse->data,
      ],
    );
  }

  /**
   * Update the Drupal packages.
   *
   * 1. Check if Drupal is healthy.
   * 2. Secure the database before updating.
   * 3. Redeploy the WissKI stack with the target image version.
   * 4. Perform post update steps.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   * @param string $updateDrupalPackagesOperationUuid
   *   The operation UUID.
   * @param string $targetVersion
   *   The target version to update to. Defaults to 'latest' (production
   *   version).
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function updateDrupalPackages(
    SodaScsComponentInterface $component,
    string $updateDrupalPackagesOperationUuid,
    ?string $targetVersion = 'latest',
  ): SodaScsResult {
    try {
      $wisskiInstanceSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();

      // Determine the actual version label to store on the component.
      $actualVersion = $targetVersion ?? 'latest';
      if ($targetVersion === 'latest') {
        $actualVersion = $wisskiInstanceSettings['defaultVersion'] ?? '';
      }
      elseif ($targetVersion === 'nightly') {
        $actualVersion = 'nightly-' . $this->time->getCurrentTime();
      }

      if (empty($actualVersion)) {
        return SodaScsResult::failure(
          error: 'No version specified or available.',
          message: (string) $this->t('No version specified or available for update.'),
        );
      }

      // If version of the component is the same as the target version,
      // we can skip the update.
      if ($targetVersion !== 'nightly' && $component->get('version')->value === $actualVersion) {
        return SodaScsResult::success(
          data: ['skipped' => TRUE],
          message: (string) $this->t('Drupal packages are already at version @version.', [
            '@version' => $actualVersion,
          ]),
        );
      }

      // Initialize result data array.
      $resultData = [];

      // 1. Ensure the Drupal instance is healthy.
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Ensure Drupal is healthy');
      $ensureDrupalHealthyResult = $this->ensureDrupalHealthy($component);
      if (!$ensureDrupalHealthyResult->success) {
        $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Failed to ensure Drupal is healthy');
        return SodaScsResult::failure(
          error: 'Not updating packages: ' . ($ensureDrupalHealthyResult->error ?? ''),
          message: (string) $this->t('Drupal is not healthy. Not updating packages.'),
        );
      }
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Drupal is healthy');
      $resultData['ensureDrupalHealthy'] = $ensureDrupalHealthyResult->data;

      // 2. Secure the database before updating.
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Secure Drupal database');
      $secureDrupalPackagesAndDatabaseResult = $this->secureDrupalPackagesAndDatabase($component);
      if (!$secureDrupalPackagesAndDatabaseResult->success) {
        $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Failed to secure Drupal database');
        return SodaScsResult::failure(
          error: 'Failed to secure Drupal database: ' . ($secureDrupalPackagesAndDatabaseResult->error ?? ''),
          message: (string) $this->t('Failed to secure Drupal database. Not updating packages.'),
        );
      }
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Drupal database secured');
      $resultData['secureDrupalPackagesAndDatabase'] = $secureDrupalPackagesAndDatabaseResult->data;
      $backupPath = $secureDrupalPackagesAndDatabaseResult->data['backupPath'];

      // 3. Redeploy the WissKI stack with the baked-in package image.
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Redeploy WissKI stack with updated image');
      $stackRedeployResult = $this->sodaScsWisskiComponentActions->redeployStackWithVersion($component, $targetVersion);
      if (!$stackRedeployResult->success) {
        $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Failed to redeploy WissKI stack');
        return SodaScsResult::failure(
          error: 'Failed to redeploy WissKI stack: ' . ($stackRedeployResult->error ?? ''),
          message: (string) $this->t('Failed to redeploy WissKI stack. Not updating packages.'),
        );
      }
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Redeployed WissKI stack');
      $resultData['stackRedeploy'] = $stackRedeployResult->data;

      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Wait for Drupal to become healthy');
      $ensureDrupalHealthyAfterRedeployResult = $this->ensureDrupalHealthy($component, wait: TRUE);
      if (!$ensureDrupalHealthyAfterRedeployResult->success) {
        $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Failed to ensure Drupal is healthy after redeploy');
        return SodaScsResult::failure(
          error: 'Drupal is not healthy after redeploy: ' . ($ensureDrupalHealthyAfterRedeployResult->error ?? ''),
          message: (string) $this->t('Drupal is not healthy after redeploy. Not updating packages.'),
        );
      }
      $resultData['ensureDrupalHealthyAfterRedeploy'] = $ensureDrupalHealthyAfterRedeployResult->data;

      // 4. Update the Drupal database with drush updatedb.
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Perform database update');
      $databaseUpdateResult = $this->updateDrupalDatabase($component);
      if (!$databaseUpdateResult->success) {
        $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Failed to perform database update. Rolling back...');
        $rollbackDatabaseUpdateResult = $this->rollbackDatabaseUpdate($component, $backupPath);
        if (!$rollbackDatabaseUpdateResult->success) {
          $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Failed to roll back database');
          return SodaScsResult::failure(
            error: 'Failed to rollback database update: ' . ($rollbackDatabaseUpdateResult->error ?? ''),
            message: (string) $this->t('Failed to rollback database update. Not updating packages.'),
          );
        }
        $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Rolled back database');
        $resultData['rollbackDatabaseUpdate'] = $rollbackDatabaseUpdateResult->data;
        return SodaScsResult::failure(
          error: 'Failed to update database: ' . ($databaseUpdateResult->error ?? ''),
          message: (string) $this->t('Failed to update database. Not updating packages.'),
        );
      }
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Performed database update');
      $resultData['databaseUpdate'] = $databaseUpdateResult->data;

      // Set the new version of the component.
      if (preg_match('/^nightly(?:-(\d+))?$/', $actualVersion, $matches)) {
        // If $actualVersion is a nightly build, add human-readable timestamp in
        // parenthesis.
        $timestamp = $matches[1] ?? NULL;
        if (is_string($timestamp) && ctype_digit($timestamp)) {
          // Format timestamp to human-readable (e.g., 2024-06-11 15:23:45 UTC).
          $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
          $formatted = $date->format('Y-m-d H:i:s \U\T\C');
          $actualVersion .= ' (' . $formatted . ')';
        }
      }

      // Set the new version of the component and stack.
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Set new Drupal/WissKI environment version');
      // Set the new version of the component.
      $component->set('version', $actualVersion);
      $component->save();

      // Set the new version of the stack.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
      $stack = $component->getPartOfStack();
      $stack->set('version', $actualVersion);
      $stack->save();

      // Post update steps.
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Perform post update steps');
      $postUpdateStepsResult = $this->postUpdateSteps($component, $backupPath);
      if (!$postUpdateStepsResult->success) {
        $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Failed to perform post update steps. Rolling back...');
        return SodaScsResult::failure(
          error: 'Failed to perform post update steps: ' . ($postUpdateStepsResult->error ?? ''),
          message: (string) $this->t('Failed to perform post update steps. Not updating packages.'),
        );
      }
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Performed post update steps');
      $resultData['postUpdateSteps'] = $postUpdateStepsResult->data;

      $this->sodaScsProgressHelper->updateOperation($updateDrupalPackagesOperationUuid, [
        'status' => 'completed',
      ]);

      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Drupal packages updated successfully.');
      $this->sodaScsProgressHelper->updateOperation($updateDrupalPackagesOperationUuid, [
        'status' => 'completed',
      ]);
      return SodaScsResult::success(
        message: (string) $this->t('Drupal packages updated successfully.'),
        data: [
          'resultData' => $resultData,
        ],
      );
    }
    catch (\Exception $e) {
      $this->sodaScsProgressHelper->createStep($updateDrupalPackagesOperationUuid, 'Failed to update Drupal packages');
      $this->sodaScsProgressHelper->updateOperation($updateDrupalPackagesOperationUuid, [
        'status' => 'failed',
      ]);
      return SodaScsResult::failure(
        error: 'Failed to update Drupal packages: ' . $e->getMessage(),
        message: (string) $this->t('Failed to update Drupal packages.'),
      );
    }
  }

  /**
   * Get the cache ID for Drupal package data for a component.
   *
   * @param string $type
   *   The cache type, e.g. 'installed' or 'latest'.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return string
   *   The cache ID.
   */
  private function getDrupalPackagesCacheId(string $type, SodaScsComponentInterface $component): string {
    return 'soda_scs_manager:drupal_packages:' . $type . ':' . $component->id();
  }

  /**
   * Cache tags for Drupal package data for a component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   Cache tags.
   */
  private function getDrupalPackagesCacheTags(SodaScsComponentInterface $component): array {
    return [
      'soda_scs_manager:drupal_packages',
      'soda_scs_manager:drupal_packages:' . $component->id(),
    ];
  }

  /**
   * Merge cached latest results into installed data without re-checking.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   * @param array $installedData
   *   The installed data array (must contain 'packages').
   *
   * @return array
   *   The installed data array merged with latest 'available' values if
   *   present.
   */
  private function mergeCachedLatestIntoInstalled(SodaScsComponentInterface $component, array $installedData): array {
    $installedPackages = $installedData['packages'] ?? [];
    if (!is_array($installedPackages) || $installedPackages === []) {
      return $installedData;
    }

    $latestCacheId = $this->getDrupalPackagesCacheId('latest', $component);
    $latestCached = $this->cacheBackend->get($latestCacheId);
    if (
      !$latestCached
      || !is_array($latestCached->data)
      || empty($latestCached->data['packages'])
      || !is_array($latestCached->data['packages'])
    ) {
      return $installedData;
    }

    $latestByName = [];
    foreach ($latestCached->data['packages'] as $latestPackage) {
      if (!is_array($latestPackage)) {
        continue;
      }
      $name = (string) ($latestPackage['name'] ?? '');
      if ($name === '') {
        continue;
      }
      $latestByName[$name] = (string) ($latestPackage['available'] ?? '');
    }

    if ($latestByName === []) {
      return $installedData;
    }

    foreach ($installedPackages as &$installedPackage) {
      if (!is_array($installedPackage)) {
        continue;
      }
      $name = (string) ($installedPackage['name'] ?? '');
      if ($name !== '' && array_key_exists($name, $latestByName)) {
        $installedPackage['available'] = $latestByName[$name];
      }
    }

    $installedData['packages'] = $installedPackages;
    $installedData['latestCachedAt'] = (int) ($latestCached->data['checkedAt'] ?? 0);
    return $installedData;
  }

  /**
   * Secure the Drupal database before updating.
   *
   * 1. Prepare for dumping.
   *  1.1. Ensure the backup directory exists.
   *  1.2. Set Drupal to maintainment mode.
   *  1.3. Clear the Drupal cache.
   * 2. Dump the database.
   *  2.1. Ensure the backup directory exists.
   *  2.2. Dump the database to a tar file.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function secureDrupalPackagesAndDatabase(SodaScsComponentInterface $component): SodaScsResult {
    try {
      // Environment variables.
      $timestamp = $this->time->getCurrentTime();
      $backupPath = strtr('/opt/drupal/bkp/{timestamp}', [
        '{timestamp}' => $timestamp,
      ]);

      // Prepare the backup directory, set Drupal to maintenance mode and
      // clear the Drupal cache.
      $preDumpStepsResult = $this->preDumpSteps($component, $backupPath);
      if (!$preDumpStepsResult->success) {
        $errorDetail = 'Failed to prepare pre-dump steps: ' . ($preDumpStepsResult->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to secure Drupal database.'),
        );
      }

      // Dump the database to a tar file.
      $dumpDatabaseResult = $this->sodaScsDatabaseHelpers->dumpDatabase($backupPath, $component);
      if (!$dumpDatabaseResult->success) {
        $errorDetail = 'Failed to dump database: ' . ($dumpDatabaseResult->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to secure Drupal database.'),
        );
      }

      return SodaScsResult::success(
        message: (string) $this->t('Drupal database secured successfully.'),
        data: [
          'backupPath' => $backupPath,
          'dumpDatabase' => $dumpDatabaseResult->data,
        ],
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: 'Failed to secure Drupal database: ' . $e->getMessage(),
        message: (string) $this->t('Failed to secure Drupal database.'),
      );
    }
  }

  /**
   * Backup composer package informations.
   *
   * 1. Ensure the backup directory exists.
   * 2. Pack the composer.json and composer.lock files to a tar file.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   * @param string $backupPath
   *   The backup path.
   * @param string $user
   *   User that should execute the command.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function backupComposerPackageInformations(SodaScsComponentInterface $component, string $backupPath, string $user = 'www-data'): SodaScsResult {
    try {
      // Ensure the backup directory exists.
      $createBackupDirResponse = $this->sodaScsContainerHelpers->ensureContainerDirectory($component, $backupPath, $user);
      if (!$createBackupDirResponse->success) {
        $errorDetail = 'Failed to ensure backup directory: ' . ($createBackupDirResponse->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to backup composer package informations.'),
        );
      }

      // Pack the composer.json and composer.lock files to a tar file.
      // Use -C to change to /opt/drupal and use relative paths
      // for cleaner archive.
      $composerTarFilePath = $backupPath . '/composer.tar.gz';

      $composerTarResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd'           => [
          'tar',
          '-czf',
          $composerTarFilePath,
          '-C',
          '/opt/drupal',
          'composer.json',
          'composer.lock',
        ],
        'containerName' => (string) $component->get('containerId')->value,
        'user'          => $user,
      ]);
      if (!$composerTarResponse->success) {
        $errorDetail = 'Failed to pack composer.json and composer.lock files to a tar file: ' . ($composerTarResponse->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to backup composer package informations.'),
        );
      }

      return SodaScsResult::success(
        message: (string) $this->t('Composer package informations backed up successfully.'),
        data: [
          'composerTar' => $composerTarResponse->data,
        ],
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: 'Failed to backup composer package informations: ' . $e->getMessage(),
        message: (string) $this->t('Failed to backup composer package informations.'),
      );
    }
  }

  /**
   * Prepares everything needed before dumping the database.
   *
   * 1. Ensures the backup directory exists.
   * 2. Sets Drupal to maintainment mode.
   * 3. Clears the Drupal cache.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $drupalComponent
   *   The Drupal component.
   * @param string $backupPath
   *   The backup path.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  private function preDumpSteps(SodaScsComponentInterface $drupalComponent, string $backupPath): SodaScsResult {

    try {
      if (!$drupalComponent) {
        return SodaScsResult::success(
          data: ['skipped' => TRUE],
          message: (string) $this->t('Drupal component not provided, skipping cache clear.'),
        );
      }

      $directoryResult = $this->sodaScsContainerHelpers->ensureContainerDirectory($drupalComponent, $backupPath);
      if (!$directoryResult->success) {
        $errorDetail = 'Failed to ensure container directory: ' . ($directoryResult->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to prepare pre-dump steps.'),
        );
      }

      $maintainmentModeResult = $this->setDrupalMaintainmentMode($drupalComponent, TRUE);
      if (!$maintainmentModeResult->success) {
        $errorDetail = 'Failed to set Drupal to maintainment mode: ' . ($maintainmentModeResult->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to prepare pre-dump steps.'),
        );
      }

      $cacheResult = $this->clearDrupalCache($drupalComponent);
      if (!$cacheResult->success) {
        $errorDetail = 'Failed to clear Drupal cache: ' . ($cacheResult->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to prepare pre-dump steps.'),
        );
      }

      return SodaScsResult::success(
        data: ['skipped' => FALSE],
        message: (string) $this->t('Drupal cache cleared and backup directory prepared.'),
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: 'Failed to prepare pre-dump steps: ' . $e->getMessage(),
        message: (string) $this->t('Failed to prepare pre-dump steps.'),
      );
    }
  }

  /**
   * Rolls back the composer update.
   *
   * Unpacks the composer.tar.gz file from the backup directory to restore
   * the composer.json and composer.lock files to /opt/drupal/.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   * @param string $backupPath
   *   The backup path.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  private function rollbackComposerUpdate(SodaScsComponentInterface $component, string $backupPath): SodaScsResult {
    try {
      // Unpack the composer.tar.gz file to /opt/drupal directory.
      // The -C flag changes to /opt/drupal before extraction, ensuring
      // composer.json and composer.lock are restored to the correct location.
      // The --overwrite flag ensures existing files are replaced.
      $composerTarResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd'           => [
          'tar',
          '-xzf',
          $backupPath . '/composer.tar.gz',
          '-C',
          '/opt/drupal',
          '--overwrite',
        ],
        'containerName' => (string) $component->get('containerId')->value,
        'user'          => 'root',
      ]);
      if (!$composerTarResponse->success) {
        $errorDetail = 'Failed to unpack composer.tar.gz file: ' . ($composerTarResponse->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to rollback composer update.'),
        );
      }

      return SodaScsResult::success(
        data: ['skipped' => FALSE],
        message: (string) $this->t('Composer package informations restored successfully.'),
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: 'Failed to rollback composer update: ' . $e->getMessage(),
        message: (string) $this->t('Failed to rollback composer update.'),
      );
    }
  }

  /**
   * Rolls back the database update.
   *
   * Restores the database from the backup located in the backup path.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The Drupal component.
   * @param string $backupPath
   *   The backup path.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  private function rollbackDatabaseUpdate(SodaScsComponentInterface $component, string $backupPath): SodaScsResult {
    try {
      // Get the database dump file path.
      $dumpFilePath = $backupPath . '/database.dump.sql.gz';

      // Get the connected SQL component.
      $resolvedComponents = $this->sodaScsComponentHelpers->resolveConnectedComponents($component);
      $sqlComponent = $resolvedComponents['sql'] ?? NULL;

      if (!$sqlComponent) {
        return SodaScsResult::failure(
          error: 'Connected SQL component not found.',
          message: (string) $this->t('Connected SQL component not found for the Drupal component.'),
        );
      }

      // Get the database name.
      $databaseName = $sqlComponent->get('machineName')->value;

      // Get the client container name.
      $clientContainerName = $component->get('containerName')->value;

      // Get service key for the SQL component.
      $sqlComponentServiceKey = $this->sodaScsServiceKeyActions->getServiceKey([
        'bundle' => 'soda_scs_sql_component',
        'type'   => 'password',
        'userId' => $sqlComponent->getOwnerId(),
      ]);
      if (!$sqlComponentServiceKey) {
        return SodaScsResult::failure(
          error: 'SQL component service key not found for user.',
          message: (string) $this->t('SQL component service key not found for user.'),
        );
      }

      $sqlComponentServiceKeyPassword = $sqlComponentServiceKey->get('servicePassword')->value;
      $databaseHost = $this->sodaScsServiceHelpers->getDatabaseDockerHost();

      // Run the database restore command.
      $restoreExecCommand = [
        'bash',
        '-c',
        'set -o pipefail && gunzip -c ' . $dumpFilePath . ' | mariadb -h' . $databaseHost . ' -u' . $sqlComponent->getOwner()->getDisplayName() . ' -p' . $sqlComponentServiceKeyPassword . ' "' . $databaseName . '"',
      ];

      $restoreExecResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd'           => $restoreExecCommand,
        'containerName' => $clientContainerName,
        'user'          => 'www-data',
      ]);

      if (!$restoreExecResponse->success) {
        return SodaScsResult::failure(
          error: $restoreExecResponse->error ?? 'Failed to restore database from backup.',
          message: (string) $this->t('Failed to restore database from backup.'),
        );
      }

      return SodaScsResult::success(
        data: [
          'skipped' => FALSE,
          'restore' => $restoreExecResponse->data,
        ],
        message: (string) $this->t('Database restored successfully from backup.'),
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: 'Failed to rollback database update: ' . $e->getMessage(),
        message: (string) $this->t('Failed to rollback database update.'),
      );
    }
  }

  /**
   * Deletes the backup directory.
   *
   * @param string $backupPath
   *   The backup path.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   * @param string $user
   *   User that should execute the command.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  private function deleteBackupDirectory(string $backupPath, SodaScsComponentInterface $component, string $user = 'www-data'): SodaScsResult {
    // Validate backup path using abstract validation function.
    $validationResult = $this->sodaScsHelpers->validatePathForSafeDeletion($backupPath);

    if (!$validationResult['isValid']) {
      $errorMessage = match ($validationResult['errorCode']) {
        'empty', 'root', 'forbidden', 'relative', 'traversal' => (string) $this->t('Refused to delete potentially unsafe or invalid backup path.'),
        'hierarchy' => (string) $this->t('Refused to delete path not matching backup or snapshot hierarchy.'),
        default => (string) $this->t('Invalid backup path for deletion.'),
      };

      return SodaScsResult::failure(
        error: 'Invalid or unsafe backup path for deletion: ' . htmlspecialchars($backupPath) . ' (' . $validationResult['errorCode'] . ')',
        message: $errorMessage,
      );
    }
    try {
      // Delete the backup directory using normalized path.
      $normalizedBackupPath = $validationResult['normalizedPath'];
      $deleteBackupDirectoryResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd'           => [
          'rm',
          '-rf',
          $normalizedBackupPath,
        ],
        'containerName' => (string) $component->get('containerId')->value,
        'user'          => $user,
      ]);
      if (!$deleteBackupDirectoryResponse->success) {
        return SodaScsResult::failure(
          error: 'Failed to delete backup directory: ' . ($deleteBackupDirectoryResponse->error ?? ''),
          message: (string) $this->t('Failed to delete backup directory.'),
        );
      }
      return SodaScsResult::success(
        data: ['skipped' => FALSE],
        message: (string) $this->t('Backup directory deleted successfully.'),
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: 'Failed to delete backup directory: ' . $e->getMessage(),
        message: (string) $this->t('Failed to delete backup directory.'),
      );
    }
  }

  /**
   * Post update steps.
   *
   * 1. Delete backup directory.
   * 2. Escape maintenance mode.
   * 3. Clear Drupal cache.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   * @param string $backupPath
   *   The backup path.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  private function postUpdateSteps(SodaScsComponentInterface $component, string $backupPath): SodaScsResult {
    try {
      // Clean backup directory.
      $cleanBackupDirectoryResult = $this->deleteBackupDirectory($backupPath, $component);
      if (!$cleanBackupDirectoryResult->success) {
        return SodaScsResult::failure(
          error: 'Failed to clean backup directory: ' . ($cleanBackupDirectoryResult->error ?? ''),
          message: (string) $this->t('Failed to clean backup directory.'),
        );
      }

      // Escape maintenance mode.
      $escapeMaintenanceModeResult = $this->setDrupalMaintainmentMode($component, FALSE);
      if (!$escapeMaintenanceModeResult->success) {
        return SodaScsResult::failure(
          error: 'Failed to set Drupal to maintenance mode: ' . ($escapeMaintenanceModeResult->error ?? ''),
          message: (string) $this->t('Failed to set Drupal to maintenance mode.'),
        );
      }

      // Clear Drupal cache.
      $clearDrupalCacheResult = $this->clearDrupalCache($component);
      if (!$clearDrupalCacheResult->success) {
        return SodaScsResult::failure(
          error: 'Failed to clear Drupal cache: ' . ($clearDrupalCacheResult->error ?? ''),
          message: (string) $this->t('Failed to clear Drupal cache.'),
        );
      }

      return SodaScsResult::success(
        data: [
          'cleanBackupDirectory' => $cleanBackupDirectoryResult->data,
          'escapeMaintenanceMode' => $escapeMaintenanceModeResult->data,
          'clearDrupalCache' => $clearDrupalCacheResult->data,
        ],
        message: (string) $this->t('Post update steps completed successfully.'),
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: 'Failed to post update steps: ' . $e->getMessage(),
        message: (string) $this->t('Failed to post update steps.'),
      );
    }
  }

}
