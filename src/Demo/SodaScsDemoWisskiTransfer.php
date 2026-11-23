<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsDatabaseHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWisskiVolumeHelpers;
use Drupal\soda_scs_manager\Service\SodaScsWisskiTriplestoreTokenSync;
use Drupal\soda_scs_manager\Service\SodaScsWisskiVarnishCache;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Moves the data of a WissKI stack (database, triplestore, volumes).
 *
 * The WissKI container is stopped while its volumes and database are read
 * or replaced, so the copy is consistent and Drupal never sees half a state.
 */
class SodaScsDemoWisskiTransfer {

  public const DATABASE_FILE = 'wisski-database.sql';
  public const TRIPLESTORE_BASENAME = 'wisski-triplestore';
  public const VOLUMES_FILE = 'wisski-volumes.tar.gz';

  /**
   * Written by the WissKI entrypoint once the site is installed.
   */
  protected const INSTALL_COMPLETE_MARKER = '/opt/drupal/web/sites/.wisski-install-complete';

  /**
   * Present while the WissKI entrypoint runs the installation.
   */
  protected const INSTALL_IN_PROGRESS_MARKER = '/opt/drupal/web/sites/.wisski-install-in-progress';

  /**
   * How often and how long to wait for drush after a container start.
   */
  protected const DRUSH_READY_ATTEMPTS = 60;
  protected const DRUSH_READY_INTERVAL_SECONDS = 5;

  /**
   * Constructs the transfer.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
    #[Autowire(service: 'soda_scs_manager.database.helpers')]
    protected SodaScsDatabaseHelpers $databaseHelpers,
    #[Autowire(service: 'soda_scs_manager.demo.project_apps')]
    protected SodaScsDemoProjectApps $projectApps,
    #[Autowire(service: 'soda_scs_manager.demo.wisski_config_remapper')]
    protected SodaScsWisskiInstanceConfigRemapper $configRemapper,
    #[Autowire(service: 'soda_scs_manager.triplestore_helpers')]
    protected SodaScsTriplestoreHelpers $triplestoreHelpers,
    #[Autowire(service: 'soda_scs_manager.wisski_triplestore_token_sync')]
    protected SodaScsWisskiTriplestoreTokenSync $tokenSync,
    #[Autowire(service: 'soda_scs_manager.demo.wisski_uri_rewriter')]
    protected SodaScsWisskiUriRewriter $uriRewriter,
    #[Autowire(service: 'soda_scs_manager.wisski_varnish_cache')]
    protected SodaScsWisskiVarnishCache $varnishCache,
    #[Autowire(service: 'soda_scs_manager.wisski_volume.helpers')]
    protected SodaScsWisskiVolumeHelpers $volumeHelpers,
  ) {}

  /**
   * Writes database, triplestore and volumes of a WissKI stack to a directory.
   *
   * @return array{graph: string, site: string}
   *   The base URIs of the exported WissKI, for the manifest.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  public function export(SodaScsStackInterface $stack, string $directory): array {
    [$sqlComponent, $triplestoreComponent, $wisskiComponent] = $this->stackComponents($stack);
    $containerName = $this->containerHelpers->getComponentContainerName($wisskiComponent);
    $ownBases = SodaScsDemoException::assertSuccess($this->uriRewriter->ownBases($wisskiComponent), 'Read WissKI base URIs')->data['bases'];

    SodaScsDemoException::assertSuccess($this->containerHelpers->stopContainer($containerName), 'Stop WissKI');
    try {
      SodaScsDemoException::assertSuccess(
        $this->volumeHelpers->exportVolumes($this->machineName($wisskiComponent), $directory, self::VOLUMES_FILE),
        'Export WissKI volumes',
      );
      SodaScsDemoException::assertSuccess(
        $this->databaseHelpers->exportDatabaseToFile($this->machineName($sqlComponent), $directory . '/' . self::DATABASE_FILE),
        'Export WissKI database',
      );
      $this->exportTriplestore($this->machineName($triplestoreComponent), $directory);
    }
    finally {
      $startResult = $this->containerHelpers->startContainer($containerName);
    }
    // A failed start must not replace the export exception from the try block.
    SodaScsDemoException::assertSuccess($startResult, 'Start WissKI');
    SodaScsDemoException::assertSuccess($this->varnishCache->restart($this->machineName($wisskiComponent)), 'Restart Varnish');
    return $ownBases;
  }

  /**
   * Replaces the data of a WissKI stack and points it back at itself.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The target stack; its WissKI must be installed and running.
   * @param string $directory
   *   Directory written by export().
   * @param string $siteName
   *   Site name of the target.
   * @param array|null $sourceBases
   *   array{graph: string, site: string}, the base URIs of the source; NULL
   *   keeps the URIs of the source.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  public function import(SodaScsStackInterface $stack, string $directory, string $siteName, ?array $sourceBases = NULL): void {
    [$sqlComponent, $triplestoreComponent, $wisskiComponent] = $this->stackComponents($stack);
    $containerName = $this->containerHelpers->getComponentContainerName($wisskiComponent);
    $triplestoreFile = $directory . '/' . self::TRIPLESTORE_BASENAME . '.nq';

    $instanceConfig = SodaScsDemoException::assertSuccess(
      $this->configRemapper->captureInstanceConfig($wisskiComponent),
      'Read WissKI instance configuration',
    )->data['configs'];
    $uriMap = $sourceBases === NULL ? [] : SodaScsWisskiUriRewriter::buildMap(
      $sourceBases,
      SodaScsDemoException::assertSuccess($this->uriRewriter->ownBases($wisskiComponent), 'Read WissKI base URIs')->data['bases'],
    );

    SodaScsDemoException::assertSuccess($this->containerHelpers->stopContainer($containerName), 'Stop WissKI');
    try {
      SodaScsDemoException::assertSuccess(
        $this->volumeHelpers->importVolumes($this->machineName($wisskiComponent), $directory, self::VOLUMES_FILE, TRUE),
        'Import WissKI volumes',
      );
      SodaScsDemoException::assertSuccess(
        $this->databaseHelpers->importDatabaseFromFile($this->machineName($sqlComponent), $directory . '/' . self::DATABASE_FILE, TRUE),
        'Import WissKI database',
      );
      $this->importTriplestore($this->machineName($triplestoreComponent), $triplestoreFile, $uriMap);
    }
    finally {
      $startResult = $this->containerHelpers->startContainer($containerName);
    }
    SodaScsDemoException::assertSuccess($startResult, 'Start WissKI');
    SodaScsDemoException::assertSuccess($this->varnishCache->restart($this->machineName($wisskiComponent)), 'Restart Varnish');

    // The imported database holds the Salz adapter (repository, token) and
    // the SSO config of the source: the target's own config goes back even
    // when a step before fails, and the first failure is reported.
    $failure = NULL;
    try {
      $this->waitForDrush($wisskiComponent);
      // Before the instance config, which wins where both touch a key.
      if ($uriMap !== []) {
        SodaScsDemoException::assertSuccess($this->uriRewriter->rewriteDatabase($wisskiComponent, $uriMap), 'Rewrite WissKI URIs');
      }
    }
    catch (\Throwable $e) {
      $failure = $e;
    }
    try {
      $this->restoreInstance($instanceConfig, $siteName, $wisskiComponent, $triplestoreComponent);
    }
    catch (\Throwable $e) {
      throw $failure === NULL ? $e : new SodaScsDemoException($failure->getMessage() . ' Restoring the WissKI instance configuration failed as well: ' . $e->getMessage(), 0, $failure);
    }
    if ($failure !== NULL) {
      throw $failure;
    }
  }

  /**
   * Whether the WissKI of a stack has finished its first installation.
   *
   * The in-progress marker stays when an installation was interrupted; the
   * entrypoint then refuses to start the site, so it counts as not done.
   */
  public function isInstallComplete(SodaScsStackInterface $stack): bool {
    $wisskiComponent = $this->projectApps->stackComponent($stack, SodaScsDemoProjectApps::WISSKI_COMPONENT_BUNDLE);
    if ($wisskiComponent === NULL) {
      return FALSE;
    }
    return $this->containerHelpers->executeDockerExecCommand([
      'cmd' => ['test', '-f', self::INSTALL_COMPLETE_MARKER, '-a', '!', '-e', self::INSTALL_IN_PROGRESS_MARKER],
      'containerName' => $this->containerHelpers->getComponentContainerName($wisskiComponent),
      'user' => 'www-data',
    ])->success;
  }

  /**
   * Exports a triplestore repository to {directory}/wisski-triplestore.nq.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function exportTriplestore(string $repositoryId, string $directory): void {
    try {
      $this->triplestoreHelpers->exportTriplestoreRepository($repositoryId, self::TRIPLESTORE_BASENAME, $directory, 'nq');
    }
    catch (\Throwable $e) {
      throw new SodaScsDemoException('Export WissKI triplestore: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Replaces the content of a triplestore repository.
   *
   * With a URI map, a rewritten copy of the file is imported; the template
   * file stays as it is.
   *
   * @param string $repositoryId
   *   The repository.
   * @param string $file
   *   The N-Quads file.
   * @param array<string, string> $uriMap
   *   Source base => target base.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function importTriplestore(string $repositoryId, string $file, array $uriMap = []): void {
    $rewrittenFile = NULL;
    try {
      if ($uriMap !== []) {
        $rewrittenFile = (string) tempnam(sys_get_temp_dir(), 'scs-demo-nq-');
        SodaScsWisskiUriRewriter::rewriteNquads($file, $rewrittenFile, $uriMap);
      }
      SodaScsDemoException::assertSuccess(
        $this->triplestoreHelpers->importTriplestoreRepository($repositoryId, $rewrittenFile ?? $file, 'nq'),
        'Import WissKI triplestore',
      );
    }
    catch (SodaScsDemoException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      throw new SodaScsDemoException('Import WissKI triplestore: ' . $e->getMessage(), 0, $e);
    }
    finally {
      if ($rewrittenFile !== NULL && is_file($rewrittenFile)) {
        unlink($rewrittenFile);
      }
    }
  }

  /**
   * Writes the target's instance config and triplestore token back.
   *
   * @param array $instanceConfig
   *   Config captured before the import.
   * @param string $siteName
   *   Site name of the target.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent
   *   The target WissKI.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $triplestoreComponent
   *   Its triplestore.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function restoreInstance(array $instanceConfig, string $siteName, SodaScsComponentInterface $wisskiComponent, SodaScsComponentInterface $triplestoreComponent): void {
    SodaScsDemoException::assertSuccess(
      $this->configRemapper->applyInstanceConfig($instanceConfig, $siteName, $wisskiComponent),
      'Restore WissKI instance configuration',
    );
    // The captured adapter keeps the target's token, which is stale when
    // OpenGDB issued a new one for the owner since the WissKI was installed.
    SodaScsDemoException::assertSuccess(
      $this->tokenSync->syncComponents($wisskiComponent, $triplestoreComponent),
      'Update WissKI triplestore token',
    );
  }

  /**
   * Machine name of a component.
   */
  protected function machineName(SodaScsComponentInterface $component): string {
    return (string) $component->get('machineName')->value;
  }

  /**
   * The SQL, triplestore and WissKI components of a WissKI stack.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface[]
   *   SQL, triplestore and WissKI component, in this order.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function stackComponents(SodaScsStackInterface $stack): array {
    $components = [];
    foreach ([
      SodaScsDemoProjectApps::SQL_COMPONENT_BUNDLE,
      SodaScsDemoProjectApps::TRIPLESTORE_COMPONENT_BUNDLE,
      SodaScsDemoProjectApps::WISSKI_COMPONENT_BUNDLE,
    ] as $bundle) {
      $component = $this->projectApps->stackComponent($stack, $bundle);
      if ($component === NULL) {
        throw new SodaScsDemoException(sprintf('WissKI stack %s has no %s.', $stack->id(), $bundle));
      }
      $components[] = $component;
    }
    return $components;
  }

  /**
   * Waits until drush answers in the restarted WissKI container.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function waitForDrush(SodaScsComponentInterface $wisskiComponent): void {
    $containerName = $this->containerHelpers->getComponentContainerName($wisskiComponent);
    for ($attempt = 0; $attempt < self::DRUSH_READY_ATTEMPTS; $attempt++) {
      $result = $this->containerHelpers->executeDockerExecCommand([
        'cmd' => ['drush', 'status', '--field=bootstrap'],
        'containerName' => $containerName,
        'user' => 'www-data',
      ]);
      if ($result->success && str_contains((string) ($result->data['output'] ?? ''), 'Successful')) {
        return;
      }
      sleep(self::DRUSH_READY_INTERVAL_SECONDS);
    }
    throw new SodaScsDemoException(sprintf('WissKI %s did not come back after the import.', $containerName));
  }

}
