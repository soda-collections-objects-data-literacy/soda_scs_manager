<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finds backend objects that have no SCS Manager entity.
 *
 * Covers Portainer stacks, SQL databases and triplestore repositories that
 * have no matching SCS Manager stack or component entity.
 */
class SodaScsOrphanDetector {

  use StringTranslationTrait;

  /**
   * MariaDB databases that belong to shared infrastructure, not SCS apps.
   */
  private const INFRASTRUCTURE_DATABASES = [
    'information_schema',
    'keycloak',
    'mysql',
    'nextcloud',
    'performance_schema',
    'phpmyadmin',
    'projectpage',
    'scs_manager',
    'sys',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'http_client')]
    protected ClientInterface $httpClient,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    protected SodaScsServiceHelpers $serviceHelpers,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Scans backends and entities for orphans.
   *
   * @return array<string, mixed>
   *   Orphan lists and soft errors, with the keys "portainerStacks" (list of
   *   arrays with id, name and status), "databases" (list of names),
   *   "repositories" (list of names), "danglingProjectEntities" (list of
   *   stacks/components whose partOfProjects points at a missing project),
   *   "errors" (list of messages) and "scannedAt" (Unix timestamp).
   */
  public function detect(): array {
    $index = $this->buildEntityIndex();
    $errors = [];

    $portainer = $this->detectPortainerOrphans($index, $errors);
    $databases = $this->detectDatabaseOrphans($index, $errors);
    $repositories = $this->detectRepositoryOrphans($index, $errors);
    $danglingProjectEntities = $this->detectDanglingProjectEntities();

    return [
      'portainerStacks' => $portainer,
      'databases' => $databases,
      'repositories' => $repositories,
      'danglingProjectEntities' => $danglingProjectEntities,
      'errors' => $errors,
      'scannedAt' => time(),
    ];
  }

  /**
   * Counts all orphan entries from a detect() result.
   *
   * @param array $report
   *   Result of detect().
   *
   * @return int
   *   Total orphan count.
   */
  public function countOrphans(array $report): int {
    return count($report['portainerStacks'] ?? [])
      + count($report['databases'] ?? [])
      + count($report['repositories'] ?? [])
      + count($report['danglingProjectEntities'] ?? []);
  }

  /**
   * Lists stacks and components that still reference a deleted project.
   *
   * @return array
   *   Entities whose partOfProjects target is gone. Each item has entityType,
   *   id, bundle, machineName, label and missingProjectIds.
   */
  protected function detectDanglingProjectEntities(): array {
    $existingProjectIds = [];
    foreach ($this->entityTypeManager->getStorage('soda_scs_project')->loadMultiple() as $project) {
      $existingProjectIds[(string) $project->id()] = TRUE;
    }

    $orphans = [];
    foreach (['soda_scs_stack', 'soda_scs_component'] as $entityTypeId) {
      foreach ($this->entityTypeManager->getStorage($entityTypeId)->loadMultiple() as $entity) {
        if (!$entity->hasField('partOfProjects') || $entity->get('partOfProjects')->isEmpty()) {
          continue;
        }
        $missingProjectIds = [];
        foreach ($entity->get('partOfProjects') as $item) {
          $projectId = (string) ($item->target_id ?? '');
          if ($projectId !== '' && !isset($existingProjectIds[$projectId])) {
            $missingProjectIds[] = $projectId;
          }
        }
        if ($missingProjectIds === []) {
          continue;
        }
        $missingProjectIds = array_values(array_unique($missingProjectIds));
        sort($missingProjectIds);
        $orphans[] = [
          'entityType' => $entityTypeId,
          'id' => (string) $entity->id(),
          'bundle' => $entity->bundle(),
          'machineName' => (string) ($entity->get('machineName')->value ?? ''),
          'label' => (string) $entity->label(),
          'missingProjectIds' => $missingProjectIds,
        ];
      }
    }

    usort($orphans, static function (array $a, array $b): int {
      return [$a['entityType'], $a['machineName'], $a['id']]
        <=> [$b['entityType'], $b['machineName'], $b['id']];
    });

    return $orphans;
  }

  /**
   * Collects machine names and Portainer IDs from stack and component entities.
   *
   * @return array<string, array<string, true>>
   *   Lookup maps keyed by machine name or external ID, under the keys
   *   "stackMachines", "wisskiMachines", "wisskiExternalIds", "sqlMachines"
   *   and "tsMachines".
   */
  protected function buildEntityIndex(): array {
    $index = [
      'stackMachines' => [],
      'wisskiMachines' => [],
      'wisskiExternalIds' => [],
      'sqlMachines' => [],
      'tsMachines' => [],
    ];

    foreach ($this->entityTypeManager->getStorage('soda_scs_stack')->loadMultiple() as $stack) {
      $machineName = (string) ($stack->get('machineName')->value ?? '');
      if ($machineName !== '') {
        $index['stackMachines'][$machineName] = TRUE;
      }
    }

    foreach ($this->entityTypeManager->getStorage('soda_scs_component')->loadMultiple() as $component) {
      $machineName = (string) ($component->get('machineName')->value ?? '');
      $bundle = $component->bundle();
      if ($machineName === '') {
        continue;
      }
      switch ($bundle) {
        case 'soda_scs_sql_component':
          $index['sqlMachines'][$machineName] = TRUE;
          break;

        case 'soda_scs_triplestore_component':
          $index['tsMachines'][$machineName] = TRUE;
          break;

        case 'soda_scs_wisski_component':
          $index['wisskiMachines'][$machineName] = TRUE;
          $externalId = (string) ($component->get('externalId')->value ?? '');
          if ($externalId !== '') {
            $index['wisskiExternalIds'][$externalId] = TRUE;
          }
          break;
      }
    }

    return $index;
  }

  /**
   * Lists Portainer stacks that no WissKI component or stack entity owns.
   *
   * @param array $index
   *   Entity index from buildEntityIndex().
   * @param list<string> $errors
   *   Soft errors, by reference.
   *
   * @return list<array{id: string, name: string, status: string}>
   *   Orphan Portainer stacks.
   */
  protected function detectPortainerOrphans(array $index, array &$errors): array {
    try {
      $portainer = $this->serviceHelpers->initPortainerServiceSettings();
      $stacksSettings = $this->serviceHelpers->initPortainerStacksSettings();
    }
    catch (\Throwable $e) {
      $errors[] = (string) $this->t('Portainer settings are incomplete.');
      $this->logger->error('Orphan scan: Portainer settings incomplete: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    $url = rtrim((string) $portainer['host'], '/') . $stacksSettings['baseUrl'];
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Accept' => 'application/json',
          'X-API-Key' => $portainer['authenticationToken'],
        ],
        'http_errors' => FALSE,
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      $errors[] = (string) $this->t('Could not reach Portainer.');
      $this->logger->error('Orphan scan: Portainer request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    if ($response->getStatusCode() !== 200) {
      $errors[] = (string) $this->t('Portainer returned HTTP @code when listing stacks.', [
        '@code' => (string) $response->getStatusCode(),
      ]);
      return [];
    }

    $stacks = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($stacks)) {
      $errors[] = (string) $this->t('Portainer returned an invalid stack list.');
      return [];
    }

    $orphans = [];
    foreach ($stacks as $stack) {
      if (!is_array($stack)) {
        continue;
      }
      $id = (string) ($stack['Id'] ?? '');
      $name = (string) ($stack['Name'] ?? '');
      if ($id === '' || $name === '') {
        continue;
      }
      if ($this->portainerStackHasEntity($id, $name, $index)) {
        continue;
      }
      $orphans[] = [
        'id' => $id,
        'name' => $name,
        'status' => (string) ($stack['Status'] ?? ''),
      ];
    }

    usort($orphans, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    return $orphans;
  }

  /**
   * Whether a Portainer stack is linked to an SCS Manager entity.
   *
   * @param string $portainerId
   *   Portainer stack ID.
   * @param string $name
   *   Portainer stack name.
   * @param array $index
   *   Entity index.
   *
   * @return bool
   *   TRUE when a matching entity exists.
   */
  protected function portainerStackHasEntity(string $portainerId, string $name, array $index): bool {
    if (isset($index['wisskiExternalIds'][$portainerId])) {
      return TRUE;
    }
    if (isset($index['wisskiMachines'][$name]) || isset($index['stackMachines'][$name])) {
      return TRUE;
    }
    foreach ([$name, 'wisski-' . $name, 'stack-' . $name] as $candidate) {
      if (isset($index['wisskiMachines'][$candidate]) || isset($index['stackMachines'][$candidate])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Lists MariaDB databases that no SQL component owns.
   *
   * @param array $index
   *   Entity index from buildEntityIndex().
   * @param list<string> $errors
   *   Soft errors, by reference.
   *
   * @return list<string>
   *   Orphan database names.
   */
  protected function detectDatabaseOrphans(array $index, array &$errors): array {
    try {
      $database = $this->serviceHelpers->initDatabaseServiceSettings();
    }
    catch (\Throwable $e) {
      $errors[] = (string) $this->t('Database settings are incomplete.');
      $this->logger->error('Orphan scan: database settings incomplete: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    $host = (string) ($database['host'] ?? '');
    $port = (int) ($database['port'] ?? 3306);
    $password = (string) ($database['rootPassword'] ?? '');
    if ($host === '') {
      $errors[] = (string) $this->t('Database settings are incomplete.');
      return [];
    }

    try {
      $pdo = new \PDO(
        sprintf('mysql:host=%s;port=%d', $host, $port),
        'root',
        $password,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
      );
      $databases = $pdo->query('SHOW DATABASES')->fetchAll(\PDO::FETCH_COLUMN);
    }
    catch (\Throwable $e) {
      $errors[] = (string) $this->t('Could not list MariaDB databases.');
      $this->logger->error('Orphan scan: MariaDB list failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    $orphans = [];
    foreach ($databases as $name) {
      $name = (string) $name;
      if ($name === '' || str_starts_with($name, '#mysql')) {
        continue;
      }
      if (in_array($name, self::INFRASTRUCTURE_DATABASES, TRUE)) {
        continue;
      }
      if (isset($index['sqlMachines'][$name])) {
        continue;
      }
      $orphans[] = $name;
    }

    sort($orphans);
    return $orphans;
  }

  /**
   * Lists triplestore repositories that no triplestore component owns.
   *
   * @param array $index
   *   Entity index from buildEntityIndex().
   * @param list<string> $errors
   *   Soft errors, by reference.
   *
   * @return list<string>
   *   Orphan repository IDs.
   */
  protected function detectRepositoryOrphans(array $index, array &$errors): array {
    try {
      $triplestore = $this->serviceHelpers->initTriplestoreServiceSettings();
      $repositoriesSettings = $this->serviceHelpers->initTriplestoreRepositoriesSettings();
    }
    catch (\Throwable $e) {
      $errors[] = (string) $this->t('Triplestore settings are incomplete.');
      $this->logger->error('Orphan scan: triplestore settings incomplete: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    $base = rtrim((string) ($triplestore['internalHost'] ?: $triplestore['host']), '/');
    $url = $base . $repositoriesSettings['baseUrl'];
    try {
      $response = $this->httpClient->request('GET', $url, [
        'auth' => [
          $triplestore['adminUsername'],
          $triplestore['adminPassword'],
        ],
        'headers' => ['Accept' => 'application/json'],
        'http_errors' => FALSE,
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      $errors[] = (string) $this->t('Could not reach the triplestore.');
      $this->logger->error('Orphan scan: triplestore request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    if ($response->getStatusCode() !== 200) {
      $errors[] = (string) $this->t('Triplestore returned HTTP @code when listing repositories.', [
        '@code' => (string) $response->getStatusCode(),
      ]);
      return [];
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    $repoIds = [];
    if (is_array($decoded)) {
      foreach ($decoded as $entry) {
        if (is_string($entry)) {
          $repoIds[] = $entry;
        }
        elseif (is_array($entry) && isset($entry['id'])) {
          $repoIds[] = (string) $entry['id'];
        }
      }
    }
    if ($repoIds === []) {
      preg_match_all('/id="([^"]+)"/', $body, $xmlMatches);
      preg_match_all('/"id"\s*:\s*"([^"]+)"/', $body, $jsonMatches);
      $repoIds = array_values(array_unique(array_merge($xmlMatches[1] ?? [], $jsonMatches[1] ?? [])));
    }

    $orphans = [];
    foreach ($repoIds as $repoId) {
      if ($repoId === '' || str_starts_with($repoId, 'SYSTEM')) {
        continue;
      }
      if (isset($index['tsMachines'][$repoId])) {
        continue;
      }
      $orphans[] = $repoId;
    }

    sort($orphans);
    return $orphans;
  }

}
