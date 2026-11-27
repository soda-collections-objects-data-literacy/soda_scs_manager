<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Demo;

use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Demo\SodaScsWisskiUriRewriter;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Runs the script that rewrites the URIs inside a copied WissKI.
 *
 * Issue 3640914. The script is a string that "drush php:eval" runs in the
 * WissKI; here it is evaluated against the WissKI tables and a config object
 * of the test database, with the map in SCS_URI_MAP as in the container.
 */
#[CoversClass(SodaScsWisskiUriRewriter::class)]
#[Group('soda_scs_manager')]
final class SodaScsWisskiUriRewriteScriptTest extends SodaScsKernelTestBase {

  private const SOURCE_GRAPH = 'https://source.example.org/contents/';

  private const SOURCE_SITE = 'https://source.example.org/';

  private const TARGET_GRAPH = 'https://demo-copy.example.org/contents/';

  private const TARGET_SITE = 'https://demo-copy.example.org/';

  /**
   * Base of a WissKI the source was copied from; it stays as it is.
   */
  private const LEGACY_GRAPH = 'https://legacy.example.org/contents/';

  /**
   * The map the rewriter builds for the two WissKIs.
   */
  private const MAP = [
    self::SOURCE_SITE => self::TARGET_SITE,
    self::SOURCE_GRAPH => self::TARGET_GRAPH,
  ];

  /**
   * {@inheritdoc}
   */
  protected function installSchemas(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('soda_scs_manager', ['keycloak_user_registration']);
    $this->installEntitySchema('user');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('SCS_URI_MAP');
    parent::tearDown();
  }

  /**
   * A URI column is rewritten; rows with other URIs are not touched.
   */
  public function testRewritesUriColumnAndLeavesOtherRowsAlone(): void {
    $this->createWisskiTables();
    $this->insertId2Uri(1, self::SOURCE_GRAPH . 'abc', 'rdf');
    $this->insertId2Uri(2, self::LEGACY_GRAPH . 'def', 'rdf');
    $this->insertId2Uri(3, 'urn:other:ghi', 'rdf');

    $counts = $this->runScript(self::MAP);

    $this->assertSame(1, $counts['rows']);
    $this->assertSame(
      [1 => self::TARGET_GRAPH . 'abc', 2 => self::LEGACY_GRAPH . 'def', 3 => 'urn:other:ghi'],
      $this->column('wisski_salz_id2uri', 'rid', 'uri'),
    );
  }

  /**
   * Other columns of a rewritten row stay as they are.
   */
  public function testRewriteChangesOnlyTheUriColumn(): void {
    $this->createWisskiTables();
    $this->insertId2Uri(1, self::SOURCE_GRAPH . 'abc', 'rdf');

    $this->runScript(self::MAP);

    $this->assertSame([1 => 'rdf'], $this->column('wisski_salz_id2uri', 'rid', 'adapter_id'));
    $this->assertSame([1 => '7'], $this->column('wisski_salz_id2uri', 'rid', 'eid'));
  }

  /**
   * The datatable is keyed by entity and language: both rows are rewritten.
   */
  public function testRewritesDatatableRowsPerEntityAndLanguage(): void {
    $this->createWisskiTables();
    $database = $this->container->get('database');
    foreach (['de', 'en'] as $langcode) {
      $database->insert('wisski_datatable')->fields([
        'eid' => 4,
        'langcode' => $langcode,
        'wisski_uri' => self::SOURCE_SITE . 'wisski/navigate/4/view?l=' . $langcode,
      ])->execute();
    }
    $database->insert('wisski_datatable')->fields([
      'eid' => 5,
      'langcode' => 'en',
      'wisski_uri' => self::SOURCE_SITE . 'wisski/navigate/5/view',
    ])->execute();

    $counts = $this->runScript(self::MAP);

    $this->assertSame(3, $counts['rows']);
    $uris = $database->select('wisski_datatable', 't')->fields('t', ['wisski_uri'])->orderBy('eid')->orderBy('langcode')->execute()->fetchCol();
    $this->assertSame([
      self::TARGET_SITE . 'wisski/navigate/4/view?l=de',
      self::TARGET_SITE . 'wisski/navigate/4/view?l=en',
      self::TARGET_SITE . 'wisski/navigate/5/view',
    ], $uris);
  }

  /**
   * A serialized value is unserialized, rewritten and serialized again.
   */
  public function testRewritesSerializedValueWithCorrectStringLengths(): void {
    $this->createWisskiTables();
    $properties = [
      'path' => ['x', self::SOURCE_GRAPH . 'ontology#has_part', 'y'],
      'label' => 'See ' . self::SOURCE_SITE . 'wisski/navigate/2/view for details',
      'count' => 3,
      'legacy' => self::LEGACY_GRAPH . 'keep',
    ];
    $this->insertProperties('f1', self::SOURCE_GRAPH . 'field', serialize($properties));

    $counts = $this->runScript(self::MAP);

    $this->assertSame(1, $counts['rows']);
    $stored = $this->column('wisski_entity_field_properties', 'id', 'properties')['f1'];
    $value = @unserialize($stored, ['allowed_classes' => FALSE]);
    $this->assertSame([
      'path' => ['x', self::TARGET_GRAPH . 'ontology#has_part', 'y'],
      'label' => 'See ' . self::TARGET_SITE . 'wisski/navigate/2/view for details',
      'count' => 3,
      'legacy' => self::LEGACY_GRAPH . 'keep',
    ], $value, 'The stored value unserializes, so every s:N: length is right.');
    $this->assertStringContainsString('s:' . strlen(self::TARGET_GRAPH . 'ontology#has_part') . ':"' . self::TARGET_GRAPH . 'ontology#has_part"', $stored);
    $this->assertSame(self::TARGET_GRAPH . 'field', $this->column('wisski_entity_field_properties', 'id', 'ident')['f1']);
  }

  /**
   * A plain text replacement would have broken the lengths.
   */
  public function testTextReplacementOfTheSerializedValueWouldBreakIt(): void {
    $serialized = serialize(['p' => self::SOURCE_GRAPH . 'a']);

    $this->assertFalse(@unserialize(strtr($serialized, self::MAP), ['allowed_classes' => FALSE]), 'Precondition: the bases differ in length.');
  }

  /**
   * A value that is not serialized is replaced as text.
   */
  public function testRewritesPlainTextInColumnThatMayBeSerialized(): void {
    $this->createWisskiTables();
    $this->insertProperties('f2', 'plain', 'text ' . self::SOURCE_GRAPH . 'a');

    $this->runScript(self::MAP);

    $this->assertSame('text ' . self::TARGET_GRAPH . 'a', $this->column('wisski_entity_field_properties', 'id', 'properties')['f2']);
  }

  /**
   * A serialized value that holds no base stays byte for byte.
   */
  public function testSerializedValueWithoutBaseIsNotWritten(): void {
    $this->createWisskiTables();
    $serialized = serialize(['p' => self::LEGACY_GRAPH . 'a', 'n' => 1]);
    $this->insertProperties('f3', self::SOURCE_GRAPH . 'ident-only', $serialized);

    $this->runScript(self::MAP);

    $this->assertSame($serialized, $this->column('wisski_entity_field_properties', 'id', 'properties')['f3']);
    $this->assertSame(self::TARGET_GRAPH . 'ident-only', $this->column('wisski_entity_field_properties', 'id', 'ident')['f3']);
  }

  /**
   * The longer graph base wins over the site base it starts with.
   */
  public function testGraphBaseWinsOverTheSiteBaseItStartsWith(): void {
    $this->createWisskiTables();
    $this->insertId2Uri(1, self::SOURCE_GRAPH . 'abc', 'rdf');

    $this->runScript([
      self::SOURCE_SITE => 'https://site.example.net/',
      self::SOURCE_GRAPH => 'https://graph.example.net/ns/',
    ]);

    $this->assertSame([1 => 'https://graph.example.net/ns/abc'], $this->column('wisski_salz_id2uri', 'rid', 'uri'));
  }

  /**
   * A second run finds nothing to change.
   */
  public function testSecondRunChangesNothing(): void {
    $this->createWisskiTables();
    $this->insertId2Uri(1, self::SOURCE_GRAPH . 'abc', 'rdf');
    $this->runScript(self::MAP);

    $counts = $this->runScript(self::MAP);

    $this->assertSame(['rows' => 0, 'config' => 0], $counts);
  }

  /**
   * Tables a WissKI does not have are skipped.
   */
  public function testMissingTablesAreSkipped(): void {
    $counts = $this->runScript(self::MAP);

    $this->assertSame(['rows' => 0, 'config' => 0], $counts);
  }

  /**
   * Config objects are rewritten in nested values; others are not saved.
   */
  public function testRewritesConfigAndCountsOnlyChangedObjects(): void {
    $this->config('soda_scs_manager_test.uris')
      ->set('front', self::SOURCE_SITE . 'node/1')
      ->set('nested', ['list' => [self::SOURCE_GRAPH . 'g', self::LEGACY_GRAPH . 'k', 7, TRUE]])
      ->save(TRUE);
    $this->config('soda_scs_manager_test.plain')->set('name', 'Demo')->save(TRUE);

    $counts = $this->runScript(self::MAP);

    $this->assertSame(1, $counts['config']);
    $config = $this->config('soda_scs_manager_test.uris')->getRawData();
    $this->assertSame(self::TARGET_SITE . 'node/1', $config['front']);
    $this->assertSame([self::TARGET_GRAPH . 'g', self::LEGACY_GRAPH . 'k', 7, TRUE], $config['nested']['list']);
    $this->assertSame('Demo', $this->config('soda_scs_manager_test.plain')->get('name'));
  }

  /**
   * An empty map is refused and nothing changes.
   */
  public function testEmptyMapIsRefusedAndChangesNothing(): void {
    $this->createWisskiTables();
    $this->insertId2Uri(1, self::SOURCE_GRAPH . 'abc', 'rdf');

    $output = $this->evaluate('{}');

    $this->assertSame('SCS_URI_REWRITE:{"error":"empty map"}', $output);
    $this->assertSame([1 => self::SOURCE_GRAPH . 'abc'], $this->column('wisski_salz_id2uri', 'rid', 'uri'));
  }

  /**
   * A map that is no JSON is refused as well.
   */
  public function testMalformedMapIsRefused(): void {
    $this->assertSame('SCS_URI_REWRITE:{"error":"empty map"}', $this->evaluate('not json'));
  }

  /**
   * The rewriter reads the counts the script prints.
   */
  public function testRewriterParsesTheOutputOfTheScript(): void {
    $this->createWisskiTables();
    $this->insertId2Uri(1, self::SOURCE_GRAPH . 'abc', 'rdf');
    $output = $this->evaluate((string) json_encode(self::MAP, JSON_UNESCAPED_SLASHES));
    $containerHelpers = $this->createMock(SodaScsContainerHelpers::class);
    $containerHelpers->method('executeDockerExecCommand')
      ->willReturn(SodaScsResult::success(['output' => $output], 'ok'));

    $result = (new SodaScsWisskiUriRewriter($containerHelpers, $this->entityTypeManager, $this->createMock(SodaScsDemoProjectApps::class)))
      ->rewriteDatabase($this->createMock(SodaScsComponentInterface::class), self::MAP);

    $this->assertTrue($result->success);
    $this->assertSame(['config' => 0, 'rows' => 1], $result->data);
  }

  /**
   * Creates the WissKI tables the script rewrites.
   */
  private function createWisskiTables(): void {
    $schema = $this->container->get('database')->schema();
    $schema->createTable('wisski_salz_id2uri', [
      'fields' => [
        'rid' => ['type' => 'serial', 'not null' => TRUE],
        'eid' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'uri' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
        'adapter_id' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
      ],
      'primary key' => ['rid'],
    ]);
    $schema->createTable('wisski_datatable', [
      'fields' => [
        'eid' => ['type' => 'int', 'not null' => TRUE],
        'langcode' => ['type' => 'varchar', 'length' => 12, 'not null' => TRUE],
        'wisski_uri' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
      ],
      'primary key' => ['eid', 'langcode'],
    ]);
    $schema->createTable('wisski_entity_field_properties', [
      'fields' => [
        'id' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        'ident' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
        'properties' => ['type' => 'blob', 'size' => 'big'],
      ],
      'primary key' => ['id'],
    ]);
  }

  /**
   * Inserts a row into wisski_salz_id2uri.
   */
  private function insertId2Uri(int $rid, string $uri, string $adapterId): void {
    $this->container->get('database')->insert('wisski_salz_id2uri')
      ->fields(['rid' => $rid, 'eid' => 7, 'uri' => $uri, 'adapter_id' => $adapterId])
      ->execute();
  }

  /**
   * Inserts a row into wisski_entity_field_properties.
   */
  private function insertProperties(string $id, string $ident, string $properties): void {
    $this->container->get('database')->insert('wisski_entity_field_properties')
      ->fields(['id' => $id, 'ident' => $ident, 'properties' => $properties])
      ->execute();
  }

  /**
   * Reads one column of a table, keyed by another.
   *
   * @return array<string|int, string>
   *   Values by key.
   */
  private function column(string $table, string $key, string $column): array {
    return $this->container->get('database')->select($table, 't')->fields('t', [$key, $column])->orderBy($key)
      ->execute()->fetchAllKeyed();
  }

  /**
   * Runs the script and returns its counts.
   *
   * @param array<string, string> $map
   *   The URI map.
   *
   * @return array{rows: int, config: int}
   *   The counts.
   */
  private function runScript(array $map): array {
    $output = $this->evaluate((string) json_encode($map, JSON_UNESCAPED_SLASHES));
    $this->assertStringStartsWith('SCS_URI_REWRITE:', $output);
    $counts = json_decode(substr($output, strlen('SCS_URI_REWRITE:')), TRUE);
    $this->assertIsArray($counts, $output);
    return $counts;
  }

  /**
   * Evaluates the script of the rewriter with the given map text.
   *
   * @return string
   *   What the script printed.
   */
  private function evaluate(string $mapJson): string {
    $script = (new \ReflectionClassConstant(SodaScsWisskiUriRewriter::class, 'DATABASE_SCRIPT'))->getValue();
    putenv('SCS_URI_MAP=' . $mapJson);
    ob_start();
    try {
      // The code drush php:eval gets as its argument; it is the module's own.
      // phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged
      (static fn () => eval($script))();
    }
    finally {
      $output = (string) ob_get_clean();
    }
    return $output;
  }

}
