<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\soda_scs_manager\Demo\SodaScsDemoVersion;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsDemoVersion
 * @group soda_scs_manager
 */
class SodaScsDemoVersionTest extends UnitTestCase {

  /**
   * @covers ::bump
   * @dataProvider bumpProvider
   */
  public function testBump(string $version, string $part, string $expected): void {
    $this->assertSame($expected, SodaScsDemoVersion::bump($version, $part));
  }

  /**
   * Versions, bumped parts and results.
   */
  public static function bumpProvider(): array {
    return [
      'patch' => ['1.2.3', SodaScsDemoVersion::BUMP_PATCH, '1.2.4'],
      'minor resets patch' => ['1.2.3', SodaScsDemoVersion::BUMP_MINOR, '1.3.0'],
      'major resets minor and patch' => ['1.2.3', SodaScsDemoVersion::BUMP_MAJOR, '2.0.0'],
      'two digits' => ['1.9.0', SodaScsDemoVersion::BUMP_MINOR, '1.10.0'],
    ];
  }

  /**
   * @covers ::bump
   */
  public function testBumpRejectsInvalidVersion(): void {
    $this->expectException(\InvalidArgumentException::class);
    SodaScsDemoVersion::bump('1.2', SodaScsDemoVersion::BUMP_PATCH);
  }

  /**
   * @covers ::compare
   */
  public function testCompareIsNumeric(): void {
    $this->assertGreaterThan(0, SodaScsDemoVersion::compare('1.10.0', '1.9.0'));
    $this->assertLessThan(0, SodaScsDemoVersion::compare('1.9.9', '2.0.0'));
    $this->assertSame(0, SodaScsDemoVersion::compare('1.2.3', '1.2.3'));
  }

  /**
   * @covers ::isValid
   * @dataProvider validityProvider
   */
  public function testIsValid(string $version, bool $expected): void {
    $this->assertSame($expected, SodaScsDemoVersion::isValid($version));
  }

  /**
   * Version strings and whether they are accepted.
   */
  public static function validityProvider(): array {
    return [
      'initial' => ['1.0.0', TRUE],
      'zero major' => ['0.1.0', TRUE],
      'two digits' => ['1.10.12', TRUE],
      'two parts' => ['1.0', FALSE],
      'four parts' => ['1.0.0.0', FALSE],
      'leading zero' => ['1.01.0', FALSE],
      'v prefix' => ['v1.0.0', FALSE],
      'pre-release' => ['1.0.0-beta', FALSE],
      'trailing newline' => ["1.0.0\n", FALSE],
      'empty' => ['', FALSE],
    ];
  }

  /**
   * @covers ::suggestions
   */
  public function testSuggestions(): void {
    $this->assertSame([SodaScsDemoVersion::BUMP_MAJOR => '1.0.0'], SodaScsDemoVersion::suggestions(NULL));
    $this->assertSame([
      SodaScsDemoVersion::BUMP_PATCH => '1.2.1',
      SodaScsDemoVersion::BUMP_MINOR => '1.3.0',
      SodaScsDemoVersion::BUMP_MAJOR => '2.0.0',
    ], SodaScsDemoVersion::suggestions('1.2.0'));
  }

}
