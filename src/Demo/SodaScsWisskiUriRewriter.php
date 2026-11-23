<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Points the URIs of a copied WissKI at the WissKI that received the copy.
 *
 * A WissKI mints entity URIs and its graphs ({base}originatesFrom,
 * {base}baseFields) under its default graph
 * (https://{machine name}.{SCS host}/contents/) and links each entity to its
 * page under the site domain (https://{domain}/wisski/navigate/{id}/view).
 * The rewriter maps these two bases of the source to those of the target.
 *
 * URIs minted by other WissKIs the source was copied from stay as they are:
 * they are foreign identifiers, and their navigate links carry the entity
 * ids of those WissKIs; rewritten into the target's originatesFrom graph they
 * would resolve to the wrong entities.
 *
 * The triplestore is rewritten in the N-Quads file, line by line, in IRIs
 * and in literals (file URLs). The database is rewritten after the import,
 * in the WissKI tables that hold URIs and in the config, with serialized
 * values unserialized first: a text replacement in the SQL dump would break
 * the string lengths of serialized PHP values.
 */
class SodaScsWisskiUriRewriter {

  /**
   * Kind of base minted entity URIs and graphs live under.
   */
  public const BASE_GRAPH = 'graph';

  /**
   * Kind of base of the site's pages and files.
   */
  public const BASE_SITE = 'site';

  /**
   * Marks the script result in drush output, which may contain notices.
   */
  protected const OUTPUT_MARKER = 'SCS_URI_REWRITE:';

  /**
   * A base the rewriter accepts: scheme, host, path ending with a slash.
   */
  protected const VALID_BASE_PATTERN = '~^https?://[^/<>"\s]+/(?:[^<>"\s]*/)?$~D';

  /**
   * Runs in the target WissKI; reads the map from SCS_URI_MAP.
   *
   * Rows are rewritten one by one with the same strtr() as the N-Quads, so
   * both agree; serialized columns are unserialized (without classes) first.
   */
  protected const DATABASE_SCRIPT = <<<'PHP'
$map = json_decode((string) getenv('SCS_URI_MAP'), TRUE);
if (!is_array($map) || $map === []) {
  echo 'SCS_URI_REWRITE:' . json_encode(['error' => 'empty map']);
  return;
}
$rewrite = function ($value) use (&$rewrite, $map) {
  if (is_string($value)) {
    return strtr($value, $map);
  }
  return is_array($value) ? array_map($rewrite, $value) : $value;
};
$columns = [
  ['wisski_salz_id2uri', ['rid'], ['uri' => FALSE]],
  ['wisski_datatable', ['eid', 'langcode'], ['wisski_uri' => FALSE]],
  ['wisski_entity_field_properties', ['id'], ['ident' => FALSE, 'properties' => TRUE]],
];
$database = \Drupal::database();
$counts = ['rows' => 0, 'config' => 0];
$transaction = $database->startTransaction();
try {
  foreach ($columns as [$table, $keys, $fields]) {
    if (!$database->schema()->tableExists($table)) {
      continue;
    }
    $query = $database->select($table, 't')->fields('t', array_merge($keys, array_keys($fields)));
    $matches = $query->orConditionGroup();
    foreach (array_keys($fields) as $field) {
      foreach (array_keys($map) as $from) {
        $matches->condition($field, '%' . $database->escapeLike($from) . '%', 'LIKE');
      }
    }
    foreach ($query->condition($matches)->execute() as $row) {
      $values = [];
      foreach ($fields as $field => $serialized) {
        $old = $row->{$field};
        if (!is_string($old)) {
          continue;
        }
        $data = $serialized ? @unserialize($old, ['allowed_classes' => FALSE]) : FALSE;
        $new = $data === FALSE && $old !== 'b:0;' ? strtr($old, $map) : serialize($rewrite($data));
        if ($new !== $old) {
          $values[$field] = $new;
        }
      }
      if ($values === []) {
        continue;
      }
      $update = $database->update($table)->fields($values);
      foreach ($keys as $key) {
        $update->condition($key, $row->{$key});
      }
      $update->execute();
      $counts['rows']++;
    }
  }
}
catch (\Throwable $e) {
  $transaction->rollBack();
  throw $e;
}
unset($transaction);
$configFactory = \Drupal::configFactory();
foreach ($configFactory->listAll() as $name) {
  $config = $configFactory->getEditable($name);
  $data = $config->getRawData();
  $new = $rewrite($data);
  if ($new !== $data) {
    $config->setData($new)->save(TRUE);
    $counts['config']++;
  }
}
echo 'SCS_URI_REWRITE:' . json_encode($counts);
PHP;

  /**
   * Constructs the rewriter.
   *
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers $containerHelpers
   *   The container helpers.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps $projectApps
   *   The demo project apps.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.demo.project_apps')]
    protected SodaScsDemoProjectApps $projectApps,
  ) {}

  /**
   * Maps the bases of the source to those of the target.
   *
   * Invalid bases (the manifest is outside input once versions are imported)
   * and bases equal to the target's are left out.
   *
   * @param array $sourceBases
   *   The bases of the copied data, as array{graph?: string, site?: string}.
   * @param array{graph: string, site: string} $targetBases
   *   The bases of the target WissKI.
   *
   * @return array<string, string>
   *   Source base => target base; empty when nothing has to change.
   */
  public static function buildMap(array $sourceBases, array $targetBases): array {
    $map = [];
    foreach ([self::BASE_SITE, self::BASE_GRAPH] as $kind) {
      $base = $sourceBases[$kind] ?? NULL;
      if (is_string($base) && $base !== $targetBases[$kind] && self::isValidBase($base)) {
        $map[$base] = $targetBases[$kind];
      }
    }
    return $map;
  }

  /**
   * Whether a string is a base: scheme, host, path ending with a slash.
   */
  public static function isValidBase(string $base): bool {
    return (bool) preg_match(self::VALID_BASE_PATTERN, $base);
  }

  /**
   * Reads the bases of a running WissKI from its environment.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent
   *   The WissKI component.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success data['bases'] is array{graph: string, site: string}.
   */
  public function ownBases(SodaScsComponentInterface $wisskiComponent): SodaScsResult {
    $result = $this->containerHelpers->executeDockerExecCommand([
      'cmd' => ['printenv', 'WISSKI_DEFAULT_GRAPH', 'DRUPAL_DOMAIN'],
      'containerName' => $this->containerHelpers->getComponentContainerName($wisskiComponent),
      'user' => 'www-data',
    ]);
    $lines = preg_split('/\R/', trim((string) ($result->data['output'] ?? '')));
    $graph = trim((string) ($lines[0] ?? ''));
    $site = 'https://' . trim((string) ($lines[1] ?? '')) . '/';
    if (!$result->success || !self::isValidBase($graph) || !self::isValidBase($site)) {
      return SodaScsResult::failure(
        error: sprintf('WissKI %s has no valid WISSKI_DEFAULT_GRAPH or DRUPAL_DOMAIN.', $wisskiComponent->id()),
        message: 'Could not read the base URIs of the WissKI.',
      );
    }
    return SodaScsResult::success(
      message: 'Read the base URIs of the WissKI.',
      data: ['bases' => [self::BASE_GRAPH => $graph, self::BASE_SITE => $site]],
    );
  }

  /**
   * Rewrites the URIs in the database and config of a running WissKI.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent
   *   The WissKI component.
   * @param array<string, string> $map
   *   Source base => target base, from buildMap().
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success data['rows'] and data['config'] count the changed rows and
   *   config objects.
   */
  public function rewriteDatabase(SodaScsComponentInterface $wisskiComponent, array $map): SodaScsResult {
    $result = $this->containerHelpers->executeDockerExecCommand([
      'cmd' => ['drush', 'php:eval', self::DATABASE_SCRIPT],
      'containerName' => $this->containerHelpers->getComponentContainerName($wisskiComponent),
      'env' => ['SCS_URI_MAP=' . json_encode($map, JSON_UNESCAPED_SLASHES)],
      'timeout' => 900,
      'user' => 'www-data',
    ]);
    $output = (string) ($result->data['output'] ?? '');
    $counts = preg_match('/' . preg_quote(self::OUTPUT_MARKER, '/') . '(\{[^\n]*\})/', $output, $match)
      ? json_decode($match[1], TRUE)
      : NULL;
    if (!$result->success || !is_array($counts) || !isset($counts['rows'], $counts['config'])) {
      return SodaScsResult::failure(
        error: sprintf('URI rewrite in WissKI %s: %s', $wisskiComponent->id(), (string) ($result->error ?? $counts['error'] ?? 'missing result in drush output')),
        message: 'Could not rewrite the URIs of the WissKI.',
      );
    }
    return SodaScsResult::success(
      message: 'Rewrote the URIs of the WissKI.',
      data: ['config' => (int) $counts['config'], 'rows' => (int) $counts['rows']],
    );
  }

  /**
   * The base URIs of the source WissKI of a demo version.
   *
   * Versions built before the manifest listed them have none; for a version
   * built on this site they are read from its source WissKI while that still
   * exists.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface $template
   *   The version.
   *
   * @return array|null
   *   array{graph: string, site: string}, or NULL when they are unknown.
   */
  public function templateSourceBases(SodaScsDemoTemplateInterface $template): ?array {
    $wisskiApp = $template->getManifest()['apps'][SodaScsDemoLabelBuilder::APP_WISSKI] ?? NULL;
    if (!is_array($wisskiApp)) {
      return NULL;
    }
    if (is_array($wisskiApp['uriBases'] ?? NULL)) {
      return $wisskiApp['uriBases'];
    }
    if ($template->isImported()) {
      return NULL;
    }
    $stack = $this->entityTypeManager->getStorage('soda_scs_stack')->load((int) ($wisskiApp['sourceStackId'] ?? 0));
    $component = $stack instanceof SodaScsStackInterface ? $this->projectApps->stackComponent($stack, SodaScsDemoProjectApps::WISSKI_COMPONENT_BUNDLE) : NULL;
    $bases = $component !== NULL ? $this->ownBases($component) : NULL;
    return $bases?->success ? $bases->data['bases'] : NULL;
  }

  /**
   * Writes a copy of an N-Quads file with the bases replaced.
   *
   * @param string $sourceFile
   *   The N-Quads file; it is not changed.
   * @param string $targetFile
   *   The file to write.
   * @param array<string, string> $map
   *   Source base => target base, from buildMap().
   *
   * @throws \RuntimeException
   *   When a file cannot be read or written.
   */
  public static function rewriteNquads(string $sourceFile, string $targetFile, array $map): void {
    $input = @fopen($sourceFile, 'rb');
    if ($input === FALSE) {
      throw new \RuntimeException(sprintf('Cannot read %s.', $sourceFile));
    }
    $output = @fopen($targetFile, 'wb');
    if ($output === FALSE) {
      fclose($input);
      throw new \RuntimeException(sprintf('Cannot write %s.', $targetFile));
    }
    try {
      while (($line = fgets($input)) !== FALSE) {
        if (fwrite($output, strtr($line, $map)) === FALSE) {
          throw new \RuntimeException(sprintf('Cannot write %s.', $targetFile));
        }
      }
    }
    finally {
      fclose($input);
      fclose($output);
    }
  }

}
