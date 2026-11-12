<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers;
use Drupal\Tests\UnitTestCase;

/**
 * Signature of SodaScsOpenGdbHelpers::createUser().
 *
 * Issue 3637657: an implicitly nullable parameter (`string $x = NULL`) raises
 * a deprecation at compile time on PHP 8.4 and later.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers
 * @group soda_scs_manager
 */
class SodaScsOpenGdbHelpersSignatureTest extends UnitTestCase {

  /**
   * Compiling the helper file must not emit any deprecation.
   *
   * Reflection reports an implicit and an explicit nullable type alike, so the
   * file is linted in a separate PHP process with all errors reported. The
   * deprecation is only raised on PHP 8.4 and later; on older versions this
   * test passes by itself.
   *
   * @covers ::createUser
   */
  public function testHelperFileCompilesWithoutDeprecation(): void {
    $file = (new \ReflectionClass(SodaScsOpenGdbHelpers::class))->getFileName();
    $this->assertIsString($file);

    $output = [];
    $exitCode = 0;
    exec(
      escapeshellarg(PHP_BINARY) . ' -d error_reporting=-1 -d display_errors=1 -l ' . escapeshellarg($file) . ' 2>&1',
      $output,
      $exitCode,
    );

    $this->assertSame(0, $exitCode, implode("\n", $output));
    $this->assertStringNotContainsString('Deprecated', implode("\n", $output));
  }

  /**
   * The machine name parameter is a nullable string that defaults to NULL.
   *
   * Contract test: SodaScsTriplestoreHelpers calls createUser() without a
   * machine name, so NULL must stay a valid value. It does not catch the
   * implicit-nullable deprecation (Reflection cannot tell the two forms
   * apart); testHelperFileCompilesWithoutDeprecation() does.
   *
   * @covers ::createUser
   */
  public function testCreateUserMachineNameIsNullableStringDefaultingToNull(): void {
    $parameters = (new \ReflectionMethod(SodaScsOpenGdbHelpers::class, 'createUser'))->getParameters();

    $this->assertCount(3, $parameters);
    $machineName = $parameters[2];
    $this->assertSame('machineName', $machineName->getName());

    $type = $machineName->getType();
    $this->assertInstanceOf(\ReflectionNamedType::class, $type);
    $this->assertSame('string', $type->getName());
    $this->assertTrue($type->allowsNull());
    $this->assertTrue($machineName->isDefaultValueAvailable());
    $this->assertNull($machineName->getDefaultValue());
  }

}
