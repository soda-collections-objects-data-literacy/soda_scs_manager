<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes selected orphan resources after re-checking they are still orphans.
 */
class SodaScsOrphanCleanup {

  use StringTranslationTrait;

  /**
   * Selection key prefixes for the orphan cleanup form.
   */
  public const KIND_DANGLING = 'dangling';
  public const KIND_PORTAINER = 'portainer';
  public const KIND_DATABASE = 'database';
  public const KIND_REPOSITORY = 'repository';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'http_client')]
    protected ClientInterface $httpClient,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    protected SodaScsServiceHelpers $serviceHelpers,
    #[Autowire(service: 'soda_scs_manager.orphan_detector')]
    protected SodaScsOrphanDetector $orphanDetector,
    #[Autowire(service: 'soda_scs_manager.stack.actions')]
    protected SodaScsStackActionsInterface $stackActions,
    #[Autowire(service: 'soda_scs_manager.component.actions')]
    protected SodaScsComponentActionsInterface $componentActions,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Builds a stable selection key.
   *
   * @param string $kind
   *   One of the KIND_* constants.
   * @param string ...$parts
   *   Key parts after the kind.
   *
   * @return string
   *   Encoded selection key.
   */
  public static function selectionKey(string $kind, string ...$parts): string {
    return implode('|', array_merge([$kind], $parts));
  }

  /**
   * Parses a selection key.
   *
   * @param string $key
   *   Encoded selection key.
   *
   * @return array{0: string, 1: list<string>}|null
   *   Kind and remaining parts, or NULL when invalid.
   */
  public static function parseSelectionKey(string $key): ?array {
    $parts = explode('|', $key);
    if ($parts === [] || $parts[0] === '') {
      return NULL;
    }
    $kind = array_shift($parts);
    return [$kind, $parts];
  }

  /**
   * Deletes the selected orphan keys.
   *
   * Re-runs detection first and skips anything that is no longer an orphan.
   *
   * @param list<string> $keys
   *   Selection keys from the form.
   *
   * @return array{deleted: int, skipped: int, errors: list<string>}
   *   Counts and soft error messages.
   */
  public function deleteSelected(array $keys): array {
    $report = $this->orphanDetector->detect();
    $allowed = $this->allowedKeysFromReport($report);

    $deleted = 0;
    $skipped = 0;
    $errors = [];

    // Stacks first so child components selected with them are already gone.
    $ordered = $this->orderKeys($keys);

    foreach ($ordered as $key) {
      if (!isset($allowed[$key])) {
        $skipped++;
        continue;
      }
      $parsed = self::parseSelectionKey($key);
      if ($parsed === NULL) {
        $skipped++;
        continue;
      }
      [$kind, $parts] = $parsed;
      try {
        $ok = match ($kind) {
          self::KIND_DANGLING => $this->deleteDanglingEntity($parts),
          self::KIND_PORTAINER => $this->deletePortainerOrphan($parts),
          self::KIND_DATABASE => $this->deleteDatabaseOrphan($parts),
          self::KIND_REPOSITORY => $this->deleteRepositoryOrphan($parts),
          default => FALSE,
        };
      }
      catch (\Throwable $e) {
        $ok = FALSE;
        $errors[] = (string) $this->t('Could not delete @key: @message', [
          '@key' => $key,
          '@message' => $e->getMessage(),
        ]);
        $this->logger->error('Orphan cleanup failed for @key: @message', [
          '@key' => $key,
          '@message' => $e->getMessage(),
        ]);
      }
      if ($ok) {
        $deleted++;
      }
      else {
        $skipped++;
      }
    }

    return [
      'deleted' => $deleted,
      'skipped' => $skipped,
      'errors' => $errors,
    ];
  }

  /**
   * Maps the current report to allowed selection keys.
   *
   * @param array $report
   *   Result of detect().
   *
   * @return array<string, true>
   *   Allowed keys.
   */
  protected function allowedKeysFromReport(array $report): array {
    $allowed = [];
    foreach ($report['danglingProjectEntities'] ?? [] as $entity) {
      $allowed[self::selectionKey(
        self::KIND_DANGLING,
        $entity['entityType'],
        $entity['id'],
      )] = TRUE;
    }
    foreach ($report['portainerStacks'] ?? [] as $stack) {
      $allowed[self::selectionKey(
        self::KIND_PORTAINER,
        $stack['id'],
        $stack['name'],
      )] = TRUE;
    }
    foreach ($report['databases'] ?? [] as $name) {
      $allowed[self::selectionKey(self::KIND_DATABASE, $name)] = TRUE;
    }
    foreach ($report['repositories'] ?? [] as $name) {
      $allowed[self::selectionKey(self::KIND_REPOSITORY, $name)] = TRUE;
    }
    return $allowed;
  }

  /**
   * Orders keys so stacks are deleted before components.
   *
   * @param list<string> $keys
   *   Selection keys.
   *
   * @return list<string>
   *   Ordered keys.
   */
  protected function orderKeys(array $keys): array {
    $stacks = [];
    $rest = [];
    foreach (array_unique($keys) as $key) {
      $parsed = self::parseSelectionKey($key);
      if ($parsed !== NULL && $parsed[0] === self::KIND_DANGLING && ($parsed[1][0] ?? '') === 'soda_scs_stack') {
        $stacks[] = $key;
      }
      else {
        $rest[] = $key;
      }
    }
    return array_merge($stacks, $rest);
  }

  /**
   * Deletes a dangling stack or component through the normal actions.
   *
   * @param list<string> $parts
   *   entityType and id.
   *
   * @return bool
   *   TRUE when deleted.
   */
  protected function deleteDanglingEntity(array $parts): bool {
    $entityTypeId = $parts[0] ?? '';
    $id = $parts[1] ?? '';
    if ($entityTypeId === '' || $id === '') {
      return FALSE;
    }
    $entity = $this->entityTypeManager->getStorage($entityTypeId)->load($id);
    if ($entity === NULL) {
      return TRUE;
    }

    if ($entityTypeId === 'soda_scs_stack') {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $entity */
      $result = $this->stackActions->deleteStack($entity);
      if (empty($result['success'])) {
        $this->logger->error('Orphan stack delete failed for @id: @error', [
          '@id' => $id,
          '@error' => $result['error'] ?? $result['message'] ?? 'unknown',
        ]);
        return FALSE;
      }
      return TRUE;
    }

    if ($entityTypeId === 'soda_scs_component') {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity */
      $result = $this->componentActions->deleteComponent($entity);
      if (empty($result['success'])) {
        $this->logger->error('Orphan component delete failed for @id: @error', [
          '@id' => $id,
          '@error' => $result['error'] ?? $result['message'] ?? 'unknown',
        ]);
        return FALSE;
      }
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Deletes an orphan Portainer stack and leftover volumes.
   *
   * @param list<string> $parts
   *   Portainer id and name.
   *
   * @return bool
   *   TRUE when deleted.
   */
  protected function deletePortainerOrphan(array $parts): bool {
    $id = $parts[0] ?? '';
    $name = $parts[1] ?? '';
    if ($id === '' || $name === '') {
      return FALSE;
    }

    $portainer = $this->serviceHelpers->initPortainerServiceSettings();
    $stacksSettings = $this->serviceHelpers->initPortainerStacksSettings();
    $host = rtrim((string) $portainer['host'], '/');
    $endpoint = (string) $portainer['endpointId'];
    $headers = [
      'Accept' => 'application/json',
      'X-API-Key' => $portainer['authenticationToken'],
    ];

    try {
      $response = $this->httpClient->request(
        'DELETE',
        $host . $stacksSettings['baseUrl'] . '/' . rawurlencode($id),
        [
          'headers' => $headers,
          'query' => ['endpointId' => $endpoint],
          'http_errors' => FALSE,
          'timeout' => 120,
        ],
      );
    }
    catch (GuzzleException $e) {
      $this->logger->error('Orphan Portainer delete failed for @id: @message', [
        '@id' => $id,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    $code = $response->getStatusCode();
    if (!in_array($code, [200, 204, 404], TRUE)) {
      $this->logger->error('Orphan Portainer delete for @id returned HTTP @code', [
        '@id' => $id,
        '@code' => (string) $code,
      ]);
      return FALSE;
    }

    $this->deletePortainerVolumesForStack($host, $endpoint, $headers, $name);
    return TRUE;
  }

  /**
   * Deletes Docker volumes whose name starts with the stack name prefix.
   *
   * @param string $host
   *   Portainer host.
   * @param string $endpoint
   *   Endpoint ID.
   * @param array $headers
   *   Request headers.
   * @param string $stackName
   *   Portainer stack name.
   */
  protected function deletePortainerVolumesForStack(string $host, string $endpoint, array $headers, string $stackName): void {
    $prefix = $stackName . '--';
    try {
      $response = $this->httpClient->request(
        'GET',
        $host . '/api/endpoints/' . rawurlencode($endpoint) . '/docker/volumes',
        [
          'headers' => $headers,
          'http_errors' => FALSE,
          'timeout' => 30,
        ],
      );
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Orphan Portainer volume list failed for @name: @message', [
        '@name' => $stackName,
        '@message' => $e->getMessage(),
      ]);
      return;
    }
    if ($response->getStatusCode() !== 200) {
      return;
    }
    $payload = json_decode((string) $response->getBody(), TRUE);
    foreach (($payload['Volumes'] ?? []) as $volume) {
      $volumeName = (string) ($volume['Name'] ?? '');
      if ($volumeName !== $stackName && !str_starts_with($volumeName, $prefix)) {
        continue;
      }
      try {
        $this->httpClient->request(
          'DELETE',
          $host . '/api/endpoints/' . rawurlencode($endpoint) . '/docker/volumes/' . rawurlencode($volumeName),
          [
            'headers' => $headers,
            'query' => ['force' => 'true'],
            'http_errors' => FALSE,
            'timeout' => 60,
          ],
        );
      }
      catch (GuzzleException $e) {
        $this->logger->warning('Orphan volume delete failed for @volume: @message', [
          '@volume' => $volumeName,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Drops an orphan MariaDB database.
   *
   * @param list<string> $parts
   *   Database name.
   *
   * @return bool
   *   TRUE when dropped or already gone.
   */
  protected function deleteDatabaseOrphan(array $parts): bool {
    $name = $parts[0] ?? '';
    if ($name === '' || str_contains($name, '`') || str_starts_with($name, '#mysql')) {
      return FALSE;
    }
    // Never drop shared infrastructure databases, even if selected somehow.
    $infrastructure = [
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
    if (in_array($name, $infrastructure, TRUE)) {
      $this->logger->error('Refused to drop infrastructure database @name during orphan cleanup.', [
        '@name' => $name,
      ]);
      return FALSE;
    }
    $database = $this->serviceHelpers->initDatabaseServiceSettings();
    $pdo = new \PDO(
      sprintf('mysql:host=%s;port=%d', $database['host'], (int) ($database['port'] ?? 3306)),
      'root',
      (string) $database['rootPassword'],
      [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
    );
    $existing = $pdo->query('SHOW DATABASES')->fetchAll(\PDO::FETCH_COLUMN);
    if (!in_array($name, $existing, TRUE)) {
      return TRUE;
    }
    $pdo->exec('DROP DATABASE `' . str_replace('`', '``', $name) . '`');
    return TRUE;
  }

  /**
   * Deletes an orphan triplestore repository.
   *
   * @param list<string> $parts
   *   Repository id.
   *
   * @return bool
   *   TRUE when deleted or already gone.
   */
  protected function deleteRepositoryOrphan(array $parts): bool {
    $name = $parts[0] ?? '';
    if ($name === '') {
      return FALSE;
    }
    $triplestore = $this->serviceHelpers->initTriplestoreServiceSettings();
    $repositoriesSettings = $this->serviceHelpers->initTriplestoreRepositoriesSettings();
    $base = rtrim((string) ($triplestore['internalHost'] ?: $triplestore['host']), '/');
    $url = $base . $repositoriesSettings['baseUrl'] . '/' . rawurlencode($name);
    try {
      $response = $this->httpClient->request('DELETE', $url, [
        'auth' => [
          $triplestore['adminUsername'],
          $triplestore['adminPassword'],
        ],
        'headers' => ['Accept' => 'application/json'],
        'http_errors' => FALSE,
        'timeout' => 60,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Orphan repository delete failed for @name: @message', [
        '@name' => $name,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
    return in_array($response->getStatusCode(), [200, 204, 404], TRUE);
  }

}
