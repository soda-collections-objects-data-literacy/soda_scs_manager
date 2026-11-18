<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper;
use Drupal\Tests\UnitTestCase;

/**
 * The machine names an application stores for an entered name (3639084).
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper
 *
 * @group soda_scs_manager
 */
class SodaScsMachineNameHelperStoredNamesTest extends UnitTestCase {

  /**
   * Each bundle maps to the prefixed names its create actions store.
   *
   * @covers ::storedMachineNames
   *
   * @dataProvider storedNamesProvider
   */
  public function testStoredMachineNamesAddThePrefixesOfTheBundle(string $bundle, array $expected): void {
    $this->assertSame($expected, SodaScsMachineNameHelper::storedMachineNames($bundle, 'demo'));
  }

  /**
   * Data provider: bundle and the names stored for "demo", per entity type.
   */
  public static function storedNamesProvider(): array {
    return [
      'sql database' => ['soda_scs_sql_component', ['soda_scs_component' => ['sql-demo']]],
      'triplestore' => ['soda_scs_triplestore_component', ['soda_scs_component' => ['ts-demo']]],
      'wisski component' => ['soda_scs_wisski_component', ['soda_scs_component' => ['wisski-demo']]],
      'wisski stack' => [
        'soda_scs_wisski_stack',
        [
          'soda_scs_component' => ['sql-demo', 'ts-demo', 'wisski-demo'],
          'soda_scs_stack' => ['stack-demo', 'demo'],
        ],
      ],
    ];
  }

  /**
   * A bundle without a listed prefix stores the entered name unchanged.
   *
   * @covers ::storedMachineNames
   */
  public function testStoredMachineNamesKeepNameForUnlistedBundle(): void {
    $unchanged = ['soda_scs_component' => ['demo'], 'soda_scs_stack' => ['demo']];
    $this->assertSame($unchanged, SodaScsMachineNameHelper::storedMachineNames('soda_scs_jupyter_stack', 'demo'));
    $this->assertSame($unchanged, SodaScsMachineNameHelper::storedMachineNames('soda_scs_webprotege_component', 'demo'));
    $this->assertSame($unchanged, SodaScsMachineNameHelper::storedMachineNames('unknown_bundle', 'demo'));
  }

  /**
   * An entered name that already starts with a prefix is prefixed again.
   *
   * The create actions do the same, so the stored name has the prefix twice.
   *
   * @covers ::storedMachineNames
   */
  public function testStoredMachineNamesPrefixNameThatAlreadyHasOne(): void {
    $this->assertSame(['soda_scs_component' => ['sql-sql-demo']], SodaScsMachineNameHelper::storedMachineNames('soda_scs_sql_component', 'sql-demo'));
  }

  /**
   * A WissKI stack under creation still has the bare name, so it counts.
   *
   * @covers ::storedMachineNames
   */
  public function testStoredMachineNamesOfWisskiStackIncludeBareStackName(): void {
    $names = SodaScsMachineNameHelper::storedMachineNames('soda_scs_wisski_stack', 'wisski-demo');
    $this->assertSame(['stack-wisski-demo', 'wisski-demo'], $names['soda_scs_stack']);
    $this->assertNotContains('wisski-demo', $names['soda_scs_component']);
  }

}
