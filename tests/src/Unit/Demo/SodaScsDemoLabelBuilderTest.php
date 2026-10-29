<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Component\Transliteration\PhpTransliteration;
use Drupal\soda_scs_manager\Demo\SodaScsDemoLabelBuilder;
use Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsDemoLabelBuilder
 * @group soda_scs_manager
 */
class SodaScsDemoLabelBuilderTest extends UnitTestCase {

  /**
   * The builder under test.
   */
  protected SodaScsDemoLabelBuilder $labelBuilder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->labelBuilder = new SodaScsDemoLabelBuilder(new SodaScsMachineNameHelper(new PhpTransliteration()));
  }

  /**
   * @covers ::appLabel
   * @dataProvider appLabelProvider
   */
  public function testAppLabel(string $appKey, int $index, string $expected): void {
    $this->assertSame($expected, $this->labelBuilder->appLabel($appKey, 'anna', $index));
  }

  /**
   * Application keys and positions with their expected labels.
   */
  public static function appLabelProvider(): array {
    return [
      'wisski' => [SodaScsDemoLabelBuilder::APP_WISSKI, 1, 'anna Demo-WissKI'],
      'jupyter' => [SodaScsDemoLabelBuilder::APP_JUPYTER, 1, 'anna Demo-Jupyter'],
      'first database' => [SodaScsDemoLabelBuilder::APP_DATABASE, 1, 'anna Demo-Datenbank'],
      'second database' => [SodaScsDemoLabelBuilder::APP_DATABASE, 2, 'anna Demo-Datenbank 2'],
      'second triplestore' => [SodaScsDemoLabelBuilder::APP_TRIPLESTORE, 2, 'anna Demo-Triplestore 2'],
    ];
  }

  /**
   * @covers ::appMachineName
   * @dataProvider appMachineNameProvider
   */
  public function testAppMachineName(string $appKey, string $userName, int $index, int $attempt, string $expected): void {
    $this->assertSame($expected, $this->labelBuilder->appMachineName($appKey, $userName, $index, $attempt));
  }

  /**
   * Inputs and expected machine name bases.
   */
  public static function appMachineNameProvider(): array {
    return [
      'wisski' => [SodaScsDemoLabelBuilder::APP_WISSKI, 'anna', 1, 1, 'anna-demo'],
      'database' => [SodaScsDemoLabelBuilder::APP_DATABASE, 'anna', 1, 1, 'anna-demo-db'],
      'triplestore index' => [SodaScsDemoLabelBuilder::APP_TRIPLESTORE, 'anna', 2, 1, 'anna-demo-ts-2'],
      'jupyter retry' => [SodaScsDemoLabelBuilder::APP_JUPYTER, 'anna', 1, 3, 'anna-demo-jupyter-3'],
      'umlauts and dots' => [SodaScsDemoLabelBuilder::APP_WISSKI, 'Jörg.Müller', 1, 1, 'joerg-mueller-demo'],
      'no letters' => [SodaScsDemoLabelBuilder::APP_DATABASE, '123', 1, 1, 'user-demo-db'],
    ];
  }

  /**
   * @covers ::appMachineName
   */
  public function testAppMachineNameRespectsMaximumLength(): void {
    $machineName = $this->labelBuilder->appMachineName(SodaScsDemoLabelBuilder::APP_JUPYTER, str_repeat('verylongusername', 4), 2, 2);
    $this->assertLessThanOrEqual(SodaScsDemoLabelBuilder::MACHINE_NAME_MAX_LENGTH, strlen($machineName));
    $this->assertStringEndsWith('-demo-jupyter-2-2', $machineName);
  }

  /**
   * @covers ::projectLabel
   */
  public function testProjectLabel(): void {
    $this->assertSame('anna Demo', $this->labelBuilder->projectLabel('anna'));
  }

}
