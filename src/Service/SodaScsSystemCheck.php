<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsSystemCheckResult;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks whether the SCS Manager and the services it drives are set up.
 *
 * Covers settings, reachability of the services, the snapshot file system,
 * the host timers, the job queues and the WissKI containers. Shown in the
 * Drupal status report and by "drush scs:check".
 */
class SodaScsSystemCheck {

  use StringTranslationTrait;

  /**
   * Host timers that report a heartbeat after each run, by heartbeat name.
   *
   * Values are the systemd timer units in 01_scripts/global/.
   */
  public const HEARTBEATS = [
    'demo-queue'                => 'scs-demo-queue.timer',
    'nextcloud-mount-reconcile' => 'scs-nextcloud-mount-reconcile.timer',
    'wisski-update-queue'       => 'scs-wisski-update-queue.timer',
  ];

  /**
   * Cache ID of the last full run, used by the status report.
   */
  private const CACHE_ID = 'soda_scs_manager:system_check';

  /**
   * Seconds the status report reuses a run; probes take several seconds.
   */
  private const CACHE_SECONDS = 300;

  /**
   * Free space below this share of the snapshot file system is a warning.
   */
  private const DISK_FREE_WARNING_RATIO = 0.1;

  /**
   * Timers run every minute; an older heartbeat means the timer stopped.
   */
  private const HEARTBEAT_MAX_AGE_SECONDS = 300;

  /**
   * Seconds a probe of a service may take.
   */
  private const PROBE_TIMEOUT_SECONDS = 5;

  /**
   * Seconds a job may wait in a queue before it counts as stuck.
   */
  private const QUEUE_ITEM_MAX_AGE_SECONDS = 1800;

  /**
   * Settings that must not be empty, keyed by config path.
   */
  private const REQUIRED_SETTINGS = [
    'administratorEmail'                                          => 'Administrator email',
    'dbHost'                                                      => 'Database host',
    'dbPort'                                                      => 'Database port',
    'dbRootPassword'                                              => 'Database root password',
    'jupyterhub.generalSettings.baseUrl'                          => 'JupyterHub URL',
    'keycloak.keycloakTabs.generalSettings.fields.adminPassword'  => 'Keycloak admin password',
    'keycloak.keycloakTabs.generalSettings.fields.adminUsername'  => 'Keycloak admin user',
    'keycloak.keycloakTabs.generalSettings.fields.keycloakRealm'  => 'Keycloak realm',
    'keycloak.keycloakTabs.generalSettings.fields.keycloakUrl'    => 'Keycloak URL',
    'nextcloud.generalSettings.adminPassword'                     => 'Nextcloud admin password',
    'nextcloud.generalSettings.adminUsername'                     => 'Nextcloud admin user',
    'nextcloud.generalSettings.baseUrl'                           => 'Nextcloud URL',
    'portainer.portainerOptions.authenticationToken'              => 'Portainer API token',
    'portainer.portainerOptions.endpointId'                       => 'Portainer endpoint ID',
    'portainer.portainerOptions.host'                             => 'Portainer URL',
    'scsHost'                                                     => 'SCS host',
    'triplestore.generalSettings.adminPassword'                   => 'Triplestore admin password',
    'triplestore.generalSettings.adminUsername'                   => 'Triplestore admin user',
    'triplestore.generalSettings.host'                            => 'Triplestore URL',
    'webprotege.generalSettings.apiKey'                           => 'WebProtégé API key',
    'webprotege.generalSettings.host'                             => 'WebProtégé URL',
    'wisski.instances.baseUrl'                                    => 'WissKI instance URL pattern',
    'wisski.instances.versions.defaultVersion'                    => 'WissKI default version',
    'wisski.instances.versions.development.composeStack'          => 'WissKI development compose stack',
    'wisski.instances.versions.development.image'                 => 'WissKI development image',
  ];

  /**
   * Constructs the system check.
   */
  public function __construct(
    #[Autowire(service: 'cache.default')]
    protected CacheBackendInterface $cache,
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'http_client')]
    protected ClientInterface $httpClient,
    #[Autowire(service: 'soda_scs_manager.nextcloud_mounter.client')]
    protected NextcloudMounterClient $mounterClient,
    #[Autowire(service: 'soda_scs_manager.snapshot.helpers')]
    protected SodaScsSnapshotHelpers $snapshotHelpers,
    protected StateInterface $state,
    #[Autowire(service: 'datetime.time')]
    protected TimeInterface $time,
    #[Autowire(service: 'soda_scs_manager.wisski_update_queue')]
    protected SodaScsWisskiUpdateQueue $wisskiUpdateQueue,
  ) {}

  /**
   * Returns the state key of a heartbeat.
   *
   * @param string $name
   *   A key of HEARTBEATS.
   */
  public static function heartbeatStateKey(string $name): string {
    return 'soda_scs_manager.heartbeat.' . $name;
  }

  /**
   * Records that a host timer has just run.
   *
   * @param string $name
   *   A key of HEARTBEATS.
   *
   * @throws \InvalidArgumentException
   *   For an unknown heartbeat name.
   */
  public function recordHeartbeat(string $name): void {
    if (!isset(self::HEARTBEATS[$name])) {
      throw new \InvalidArgumentException('Unknown heartbeat ' . $name . '. Known: ' . implode(', ', array_keys(self::HEARTBEATS)) . '.');
    }
    $this->state->set(self::heartbeatStateKey($name), $this->time->getCurrentTime());
  }

  /**
   * Runs all checks.
   *
   * @param bool $useCache
   *   Reuse a run from the last CACHE_SECONDS; the status report does, the
   *   drush command does not.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsSystemCheckResult[]
   *   The results in display order.
   */
  public function run(bool $useCache = FALSE): array {
    if ($useCache && ($cached = $this->cache->get(self::CACHE_ID)) && is_array($cached->data)) {
      return $cached->data;
    }

    $results = array_merge(
      $this->checkSettings(),
      $this->checkServices(),
      $this->checkFileSystem(),
      $this->checkHeartbeats(),
      $this->checkQueues(),
      $this->checkWisskiContainers(),
    );
    $this->cache->set(self::CACHE_ID, $results, $this->time->getCurrentTime() + self::CACHE_SECONDS);
    return $results;
  }

  /**
   * Checks that the snapshot root exists, is writable and has free space.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsSystemCheckResult[]
   *   The results.
   */
  protected function checkFileSystem(): array {
    $group = (string) $this->t('File system');
    try {
      $root = $this->snapshotHelpers->getSnapshotFilesystemRoot();
    }
    catch (\Exception $e) {
      return [$this->error('snapshot_root', $group, (string) $this->t('Snapshot directory'), $e->getMessage())];
    }

    if (!is_dir($root) || !is_writable($root)) {
      return [$this->error(
        'snapshot_root',
        $group,
        (string) $this->t('Snapshot directory'),
        (string) $this->t('@path is missing or not writable for the web server. Snapshots and the database dumps of WissKI updates are written there.', ['@path' => $root]),
      )];
    }

    $results = [$this->ok('snapshot_root', $group, (string) $this->t('Snapshot directory'), $root)];
    $free = @disk_free_space($root);
    $total = @disk_total_space($root);
    if ($free === FALSE || $total === FALSE || $total <= 0) {
      return $results;
    }
    $freeLabel = (string) $this->t('@free GB free of @total GB', [
      '@free'  => round($free / 1e9, 1),
      '@total' => round($total / 1e9, 1),
    ]);
    $results[] = $free / $total < self::DISK_FREE_WARNING_RATIO
      ? $this->warning('snapshot_disk', $group, (string) $this->t('Free space for snapshots'), $freeLabel, (string) $this->t('Less than 10% free. Delete old snapshots or grow the volume.'))
      : $this->ok('snapshot_disk', $group, (string) $this->t('Free space for snapshots'), $freeLabel);
    return $results;
  }

  /**
   * Checks that every host timer has reported a recent run.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsSystemCheckResult[]
   *   The results.
   */
  protected function checkHeartbeats(): array {
    $group = (string) $this->t('Host timers');
    $results = [];
    foreach (self::HEARTBEATS as $name => $unit) {
      $lastRun = (int) $this->state->get(self::heartbeatStateKey($name), 0);
      $title = $unit;
      if ($lastRun === 0) {
        $results[] = $this->error('heartbeat_' . $name, $group, $title, (string) $this->t('never ran'), (string) $this->t('The timer is not installed or has never run. Install it on the host as described in the pre-start steps (docs/en/technical/initial-setup/pre-start-steps.md).'));
        continue;
      }
      $age = $this->time->getCurrentTime() - $lastRun;
      $value = (string) $this->t('last run @minutes min ago', ['@minutes' => intdiv($age, 60)]);
      $results[] = $age > self::HEARTBEAT_MAX_AGE_SECONDS
        ? $this->error('heartbeat_' . $name, $group, $title, $value, (string) $this->t('The timer stopped or its runs fail. Check "systemctl status @unit" and the service unit on the host.', ['@unit' => $unit]))
        : $this->ok('heartbeat_' . $name, $group, $title, $value);
    }
    return $results;
  }

  /**
   * Checks the job queues for items that wait too long.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsSystemCheckResult[]
   *   The results.
   */
  protected function checkQueues(): array {
    $group = (string) $this->t('Job queues');
    $queues = [
      SodaScsDemoManager::QUEUE_NAME              => (string) $this->t('Demo jobs'),
      SodaScsWisskiUpdateQueue::QUEUE_NAME        => (string) $this->t('WissKI updates'),
    ];
    $results = [];
    foreach ($queues as $queueName => $title) {
      $oldest = $this->database->select('queue', 'q')
        ->condition('name', $queueName)
        ->fields('q', ['created'])
        ->orderBy('created')
        ->range(0, 1)
        ->execute()
        ->fetchField();
      $count = (int) $this->database->select('queue', 'q')->condition('name', $queueName)->countQuery()->execute()->fetchField();
      $value = (string) $this->t('@count waiting', ['@count' => $count]);
      if ($oldest !== FALSE && $this->time->getCurrentTime() - (int) $oldest > self::QUEUE_ITEM_MAX_AGE_SECONDS) {
        $results[] = $this->warning('queue_' . $queueName, $group, $title, $value, (string) $this->t('A job has been waiting for more than 30 minutes. No background run picks it up; check the host timer and @log in the manager container.', [
          '@log' => $queueName === SodaScsWisskiUpdateQueue::QUEUE_NAME ? SodaScsWisskiUpdateQueue::LOG_FILE : '/tmp/scs-demo-queue.log',
        ]));
        continue;
      }
      $results[] = $this->ok('queue_' . $queueName, $group, $title, $value);
    }
    return $results;
  }

  /**
   * Probes the services the manager drives.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsSystemCheckResult[]
   *   The results.
   */
  protected function checkServices(): array {
    $group = (string) $this->t('Services');
    $settings = $this->configFactory->get('soda_scs_manager.settings');
    $results = [];

    $results[] = $this->checkDatabase($group);

    $portainerHost = rtrim((string) $settings->get('portainer.portainerOptions.host'), '/');
    $results[] = $this->probe('service_portainer', $group, 'Portainer', $portainerHost . '/api/endpoints/' . rawurlencode((string) $settings->get('portainer.portainerOptions.endpointId')), [
      'X-API-Key' => (string) $settings->get('portainer.portainerOptions.authenticationToken'),
    ], TRUE);

    $keycloakUrl = rtrim((string) $settings->get('keycloak.keycloakTabs.generalSettings.fields.keycloakUrl'), '/');
    $realm = rawurlencode((string) $settings->get('keycloak.keycloakTabs.generalSettings.fields.keycloakRealm'));
    $results[] = $this->probe('service_keycloak', $group, 'Keycloak', $keycloakUrl . '/realms/' . $realm . '/.well-known/openid-configuration', [], TRUE);

    $results[] = $this->probe('service_nextcloud', $group, 'Nextcloud', rtrim((string) $settings->get('nextcloud.generalSettings.baseUrl'), '/') . '/status.php', [], TRUE);
    $results[] = $this->checkNextcloudMounter($group);

    $triplestoreHost = (string) ($settings->get('triplestore.generalSettings.internalHost') ?: $settings->get('triplestore.generalSettings.host'));
    $results[] = $this->probe('service_triplestore', $group, (string) $this->t('Triplestore'), rtrim($triplestoreHost, '/') . (string) $settings->get('triplestore.routes.repositories.baseUrl'), [], TRUE);

    $results[] = $this->probe('service_jupyterhub', $group, 'JupyterHub', rtrim((string) $settings->get('jupyterhub.generalSettings.baseUrl'), '/') . '/hub/api');

    $webprotegeHost = (string) ($settings->get('webprotege.generalSettings.apiBaseUrl') ?: $settings->get('webprotege.generalSettings.host'));
    $results[] = $this->probe('service_webprotege', $group, 'WebProtégé', $webprotegeHost);

    return $results;
  }

  /**
   * Checks that all required settings have a value.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsSystemCheckResult[]
   *   One result for all settings.
   */
  protected function checkSettings(): array {
    $group = (string) $this->t('Settings');
    $settings = $this->configFactory->get('soda_scs_manager.settings');
    $missing = [];
    foreach (self::REQUIRED_SETTINGS as $path => $label) {
      $value = $settings->get($path);
      if ($value === NULL || $value === '' || $value === []) {
        $missing[] = $label;
      }
    }
    if ($missing !== []) {
      return [$this->error('settings', $group, (string) $this->t('SCS Manager settings'), (string) $this->t('@count missing', ['@count' => count($missing)]), (string) $this->t('Not set: @settings. Fill them in on the SCS Manager settings pages.', [
        '@settings' => implode(', ', $missing),
      ]))];
    }
    return [$this->ok('settings', $group, (string) $this->t('SCS Manager settings'), (string) $this->t('@count required settings set', ['@count' => count(self::REQUIRED_SETTINGS)]))];
  }

  /**
   * Checks that every WissKI container is running.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsSystemCheckResult[]
   *   One result for all instances.
   */
  protected function checkWisskiContainers(): array {
    $group = (string) $this->t('WissKI instances');
    $title = (string) $this->t('Drupal containers');
    $storage = $this->entityTypeManager->getStorage('soda_scs_component');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('bundle', 'soda_scs_wisski_component')->execute();
    $notRunning = [];
    $staleUpdates = [];
    foreach ($storage->loadMultiple($ids) as $component) {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
      $containerName = $this->containerHelpers->getComponentContainerName($component);
      $container = $this->containerHelpers->findLiveContainerByName($containerName);
      if (!$container->success || ($container->data['state'] ?? '') !== 'running') {
        $notRunning[] = $component->label() . ' (' . ($container->success ? $container->data['state'] : $this->t('not found')) . ')';
      }
      $activeUpdate = $this->wisskiUpdateQueue->activeUpdate($component);
      if ($activeUpdate !== NULL && $this->time->getCurrentTime() - (int) $activeUpdate['queuedAt'] > self::QUEUE_ITEM_MAX_AGE_SECONDS) {
        $staleUpdates[] = (string) $component->label();
      }
    }

    $results = [];
    $value = (string) $this->t('@running of @total running', [
      '@running' => count($ids) - count($notRunning),
      '@total'   => count($ids),
    ]);
    $results[] = $notRunning === []
      ? $this->ok('wisski_containers', $group, $title, $value)
      : $this->warning('wisski_containers', $group, $title, $value, (string) $this->t('Not running: @instances.', ['@instances' => implode(', ', $notRunning)]));
    if ($staleUpdates !== []) {
      $results[] = $this->warning('wisski_updates', $group, (string) $this->t('Updates'), (string) $this->t('@count running for more than 30 minutes', ['@count' => count($staleUpdates)]), (string) $this->t('Updates of @instances have been queued or running for more than 30 minutes. Check @log in the manager container.', [
        '@instances' => implode(', ', $staleUpdates),
        '@log'       => SodaScsWisskiUpdateQueue::LOG_FILE,
      ]));
    }
    return $results;
  }

  /**
   * Connects to the shared MariaDB as root.
   */
  private function checkDatabase(string $group): SodaScsSystemCheckResult {
    $settings = $this->configFactory->get('soda_scs_manager.settings');
    $title = (string) $this->t('Database (MariaDB root)');
    try {
      $pdo = new \PDO(
        sprintf('mysql:host=%s;port=%d', (string) $settings->get('dbHost'), (int) $settings->get('dbPort')),
        'root',
        (string) $settings->get('dbRootPassword'),
        [\PDO::ATTR_TIMEOUT => self::PROBE_TIMEOUT_SECONDS, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
      );
      $pdo->query('SELECT 1');
      return $this->ok('service_database', $group, $title, (string) $this->t('reachable'));
    }
    catch (\PDOException $e) {
      return $this->error('service_database', $group, $title, (string) $this->t('not reachable'), (string) $this->t('Root login failed: @error. SQL components, snapshots and WissKI update backups need it.', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Pings the rclone sidecar that mounts Nextcloud folders.
   */
  private function checkNextcloudMounter(string $group): SodaScsSystemCheckResult {
    $title = (string) $this->t('Nextcloud mount sidecar');
    return $this->mounterClient->ping()
      ? $this->ok('service_nextcloud_mounter', $group, $title, (string) $this->t('reachable'))
      : $this->error('service_nextcloud_mounter', $group, $title, (string) $this->t('not reachable'), (string) $this->t('User Drives and project folders cannot be mounted. Check the nextcloud-mounter container and the NEXTCLOUD_MOUNTER_RC_* variables of the manager.'));
  }

  /**
   * Builds an error result.
   */
  private function error(string $id, string $group, string $title, string $value, string $description = ''): SodaScsSystemCheckResult {
    return new SodaScsSystemCheckResult($id, $group, $title, SodaScsSystemCheckResult::STATUS_ERROR, $value, $description);
  }

  /**
   * Builds a passed result.
   */
  private function ok(string $id, string $group, string $title, string $value): SodaScsSystemCheckResult {
    return new SodaScsSystemCheckResult($id, $group, $title, SodaScsSystemCheckResult::STATUS_OK, $value);
  }

  /**
   * Requests a URL; any answer below 500 counts as reachable.
   *
   * @param string $id
   *   Check ID.
   * @param string $group
   *   Group label.
   * @param string $title
   *   Service name.
   * @param string $url
   *   URL to request.
   * @param array<string, string> $headers
   *   Request headers.
   * @param bool $requireSuccess
   *   Only a 2xx answer passes, for endpoints that must work without or with
   *   the configured credentials.
   */
  private function probe(string $id, string $group, string $title, string $url, array $headers = [], bool $requireSuccess = FALSE): SodaScsSystemCheckResult {
    if (!preg_match('#^https?://#', $url)) {
      return $this->error($id, $group, $title, (string) $this->t('no URL'), (string) $this->t('The URL of this service is not set.'));
    }
    try {
      $response = $this->httpClient->request('GET', $url, [
        'connect_timeout' => self::PROBE_TIMEOUT_SECONDS,
        'headers'         => $headers,
        'http_errors'     => FALSE,
        'timeout'         => self::PROBE_TIMEOUT_SECONDS,
      ]);
    }
    catch (\Throwable $e) {
      return $this->error($id, $group, $title, (string) $this->t('not reachable'), (string) $this->t('@url: @error', ['@url' => $url, '@error' => $e->getMessage()]));
    }

    $statusCode = $response->getStatusCode();
    $value = (string) $this->t('HTTP @code', ['@code' => $statusCode]);
    if ($statusCode >= 500 || ($requireSuccess && ($statusCode < 200 || $statusCode >= 300))) {
      return $this->error($id, $group, $title, $value, (string) $this->t('@url answered with HTTP @code. Check the service and its credentials in the SCS Manager settings.', ['@url' => $url, '@code' => $statusCode]));
    }
    return $this->ok($id, $group, $title, $value);
  }

  /**
   * Builds a warning result.
   */
  private function warning(string $id, string $group, string $title, string $value, string $description): SodaScsSystemCheckResult {
    return new SodaScsSystemCheckResult($id, $group, $title, SodaScsSystemCheckResult::STATUS_WARNING, $value, $description);
  }

}
