<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Validation;

use Drupal\soda_scs_manager\Validation\InputDisallowedTerms;
use Drupal\soda_scs_manager\Validation\UsernameValidator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests username validation rules.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Validation\UsernameValidator
 * @group soda_scs_manager
 */
class UsernameValidatorTest extends UnitTestCase {

  /**
   * @covers ::validate
   * @dataProvider validUsernamesProvider
   */
  public function testValidUsernames(string $value): void {
    $this->assertNull(UsernameValidator::validate($value));
  }

  /**
   * @return iterable<string, array{string}>
   */
  public static function validUsernamesProvider(): iterable {
    yield 'letters' => ['testuser'];
    yield 'digits' => ['123'];
    yield 'mixed' => ['user_42'];
    yield 'minimum length' => [str_repeat('a', InputDisallowedTerms::USERNAME_MIN_LENGTH)];
  }

  /**
   * @covers ::validate
   * @dataProvider invalidUsernamesProvider
   */
  public function testInvalidUsernames(string $value, string $expectedCode): void {
    $violation = UsernameValidator::validate($value);
    $this->assertIsArray($violation);
    $this->assertSame($expectedCode, $violation['code']);
  }

  /**
   * @return iterable<string, array{string, string}>
   */
  public static function invalidUsernamesProvider(): iterable {
    yield 'one letter' => ['a', 'too_short'];
    yield 'two digits' => ['12', 'too_short'];
    yield 'special chars' => ['test-user', 'invalid_characters'];
    yield 'reserved' => ['admin', 'reserved'];
    yield 'sql keyword' => ['insert', 'disallowed_term'];
  }

}
