<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LogLevel;
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
   * SodaScsDrupalHelpers constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   The cache backend.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsDatabaseHelpers $sodaScsDatabaseHelpers
   *   The database helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers $sodaScsComponentHelpers
   *   The component helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers $sodaScsContainerHelpers
   *   The container helpers.
   * @param \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions
   *   The service key actions.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    #[Autowire(service: 'cache.default')]
    CacheBackendInterface $cacheBackend,
    #[Autowire(service: 'logger.factory')]
    LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'soda_scs_manager.database.helpers')]
    SodaScsDatabaseHelpers $sodaScsDatabaseHelpers,
    #[Autowire(service: 'soda_scs_manager.component.helpers')]
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    SodaScsContainerHelpers $sodaScsContainerHelpers,
    #[Autowire(service: 'soda_scs_manager.service_key.actions')]
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    #[Autowire(service: 'datetime.time')]
    TimeInterface $time,
  ) {
    $this->cacheBackend = $cacheBackend;
    $this->loggerFactory = $loggerFactory;
    $this->sodaScsDatabaseHelpers = $sodaScsDatabaseHelpers;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsContainerHelpers = $sodaScsContainerHelpers;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->time = $time;
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
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult|null
   *   A failure result when the Drupal instance is not healthy, or NULL when
   *   it is healthy.
   */
  private function ensureDrupalHealthy(SodaScsComponentInterface $component): ?SodaScsResult {
    $health = $this->sodaScsComponentHelpers->drupalHealthCheck($component);

    $status = is_array($health) ? (string) ($health['status'] ?? '') : '';
    $success = is_array($health) ? (bool) ($health['success'] ?? FALSE) : FALSE;

    // We consider the instance healthy only when it is running and available.
    if (!$success || $status !== 'running') {
      $message = $status !== ''
        ? (string) $this->t('Drupal is not healthy (status: @status). Not retrieving packages.', ['@status' => $status])
        : (string) $this->t('Drupal is not healthy. Not retrieving packages.');

      $error = is_array($health) ? json_encode($health, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'Health check failed.';
      $error = $error !== '' ? 'Drupal health check failed: ' . $error : 'Drupal health check failed.';

      return SodaScsResult::failure(
        error: $error,
        message: $message,
      );
    }

    return NULL;
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
      if ($healthFailure = $this->ensureDrupalHealthy($component)) {
        return $healthFailure;
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
      if ($healthFailure = $this->ensureDrupalHealthy($component)) {
        return $healthFailure;
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
      if ($healthFailure = $this->ensureDrupalHealthy($component)) {
        return $healthFailure;
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
   * Update the Drupal packages.
   *
   * Use docker exec command to:
   * - Remove the composer.lock file,
   * - update the composer.json file from git repository,
   * - perform composer install.
   *
   * @todo make backup of the composer.json/ composer.lock files.
   * @todo We may use a script on the server to update the Drupal packages
   * to be independent of network connection inbetween the process.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function updateDrupalPackages(SodaScsComponentInterface $component): SodaScsResult {
    try {
      if ($healthFailure = $this->ensureDrupalHealthy($component)) {
        return $healthFailure;
      }

      // Secure the Drupal packages and database before updating.
      $secureDrupalPackagesAndDatabaseResult = $this->secureDrupalPackagesAndDatabase($component);
      if (!$secureDrupalPackagesAndDatabaseResult->success) {
        return $secureDrupalPackagesAndDatabaseResult;
      }

      // @todo We may parse the boolean to string 'development' or 'production' value.
      $mode = $component->get('developmentInstance')->value ? 'development' : 'production';

      // @todo Secure the data before updating the Drupal packages.
      // Secure the database before updating the Drupal packages.
      // Secure composer.json and .lock (or tar the runtime dirs)
      // Remove the composer.lock file.
      $deleteComposerLockResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd'           => [
          'rm',
          '/opt/drupal/composer.lock',
        ],
        'containerName' => (string) $component->get('containerId')->value,
        'user'          => 'www-data',
      ]);
      if (!$deleteComposerLockResponse->success) {
        // Ignore missing composer.lock file (it is safe to continue).
        $deleteComposerLockError = (string) ($deleteComposerLockResponse->error ?? '');
        $isMissingComposerLockOk = str_contains($deleteComposerLockError, "rm: cannot remove '/opt/drupal/composer.lock'")
          && str_contains($deleteComposerLockError, 'No such file or directory');

        if (!$isMissingComposerLockOk) {
          $errorDetail = 'Failed to remove composer.lock file: ' . $deleteComposerLockResponse->error;
          return SodaScsResult::failure(
            error: $errorDetail,
            message: (string) $this->t('Failed to update Drupal packages.'),
          );
        }
      }
      // Download the composer.json file from the git repository.
      $drupalPackageUrl = strtr('https://raw.githubusercontent.com/soda-collections-objects-data-literacy/drupal_packages/refs/heads/main/wisski_base/{mode}/{version}/composer.json', [
        '{mode}' => $mode,
        '{version}' => $component->get('version')->value,
      ]);
      $downloadComposerJsonResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd'           => [
          'wget',
          $drupalPackageUrl,
          '-O',
          '/opt/drupal/composer.json',
        ],
        'containerName' => (string) $component->get('containerId')->value,
        'user'          => 'www-data',
      ]);
      if (!$downloadComposerJsonResponse->success) {
        $errorDetail = 'Failed to download composer.json file: ' . ($downloadComposerJsonResponse->error ?? '');
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
          'deleteComposerLock' => $deleteComposerLockResponse->data,
          'downloadComposerJson' => $downloadComposerJsonResponse->data,
          'composerInstall' => $composerInstallResponse->data,
        ],
      );
    }
    // @todo Perform database update with drush updatedb (secure database before).
    catch (\Exception $e) {
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
   * Secure Drupal packages and database before updating.
   *
   * Packs composer.json and composer.lock files to a tar file.
   * Dump and tar the database.
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

      // Backup composer package informations.
      $backupComposerPackageInformationsResult = $this->backupComposerPackageInformations($component, $backupPath);
      if (!$backupComposerPackageInformationsResult->success) {
        $errorDetail = 'Failed to backup composer package informations: ' . ($backupComposerPackageInformationsResult->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to secure Drupal packages and database.'),
        );
      }

      // Prepare the backup directory, set Drupal to maintenance mode and
      // clear the Drupal cache.
      $preDumpStepsResult = $this->preDumpSteps($component, $backupPath);
      if (!$preDumpStepsResult->success) {
        $errorDetail = 'Failed to prepare pre-dump steps: ' . ($preDumpStepsResult->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to secure Drupal packages and database.'),
        );
      }

      // Dump the database to a tar file.
      $dumpDatabaseResult = $this->sodaScsDatabaseHelpers->dumpDatabase($backupPath, $component);
      if (!$dumpDatabaseResult->success) {
        $errorDetail = 'Failed to dump database: ' . ($dumpDatabaseResult->error ?? '');
        return SodaScsResult::failure(
          error: $errorDetail,
          message: (string) $this->t('Failed to secure Drupal packages and database.'),
        );
      }

      return SodaScsResult::success(
        message: (string) $this->t('Drupal packages and database secured successfully.'),
        data: [
          'backupComposerPackageInformations' => $backupComposerPackageInformationsResult->data,
          'dumpDatabase' => $dumpDatabaseResult->data,
        ],
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: 'Failed to secure Drupal packages and database: ' . $e->getMessage(),
        message: (string) $this->t('Failed to secure Drupal packages and database.'),
      );
    }
  }

  /**
   * Backup composer package informations.
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
      $composerJsonFilePath = '/opt/drupal/composer.json';
      $composerLockFilePath = '/opt/drupal/composer.lock';
      $composerTarFilePath = $backupPath . '/composer.tar.gz';

      $composerTarResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd'           => [
          'tar',
          '-czf',
          $composerTarFilePath,
          $composerJsonFilePath,
          $composerLockFilePath,
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
   * - Ensures the backup directory exists.
   * - Sets Drupal to maintainment mode.
   * - Clears the Drupal cache.
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

}
