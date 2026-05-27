<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Validation;

use Drupal\soda_scs_manager\Validation\InputDisallowedTerms;
use Drupal\soda_scs_manager\Validation\PersonalNameValidator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests personal name validation rules.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Validation\PersonalNameValidator
 * @group soda_scs_manager
 */
class PersonalNameValidatorTest extends UnitTestCase {

  /**
   * @covers ::validate
   * @dataProvider validNamesProvider
   */
  public function testValidNames(string $value): void {
    $this->assertNull(PersonalNameValidator::validate($value));
  }

  /**
   * @return iterable<string, array{string}>
   */
  public static function validNamesProvider(): iterable {
    yield 'simple name' => ['Anna'];
    yield 'hyphenated' => ['García-López'];
    yield 'apostrophe' => ["O'Brien"];
    yield 'accented' => ['José'];
    yield 'with spaces' => ['Mary Jane'];
  }

  /**
   * @covers ::validate
   * @dataProvider invalidNamesProvider
   */
  public function testInvalidNames(string $value, string $expectedCode): void {
    $violation = PersonalNameValidator::validate($value);
    $this->assertIsArray($violation);
    $this->assertSame($expectedCode, $violation['code']);
  }

  /**
   * @return iterable<string, array{string, string}>
   */
  public static function invalidNamesProvider(): iterable {
    yield 'digits' => ['Test123', 'contains_digits'];
    yield 'only digits' => ['123', 'contains_digits'];
    yield 'special chars' => ['User@123', 'invalid_characters'];
    yield 'too short' => ['Al', 'too_few_letters'];
    yield 'sql keyword' => ['Insert', 'disallowed_term'];
    yield 'coding term token' => ['Jane Empty', 'disallowed_term'];
    yield 'null keyword' => ['null', 'disallowed_term'];
  }

  /**
   * @covers ::countLetters
   */
  public function testCountLettersIgnoresSeparators(): void {
    $this->assertSame(5, PersonalNameValidator::countLetters("O'Brien"));
    $this->assertSame(3, PersonalNameValidator::countLetters('A-B'));
  }

  /**
   * @covers ::validate
   */
  public function testEmptyValueIsNotValidated(): void {
    $this->assertNull(PersonalNameValidator::validate(''));
    $this->assertNull(PersonalNameValidator::validate('   '));
  }

  /**
   * @covers ::validate
   */
  public function testMinimumLetterCountConstant(): void {
    $letters = str_repeat('a', InputDisallowedTerms::PERSONAL_NAME_MIN_LETTERS);
    $this->assertNull(PersonalNameValidator::validate($letters));
    $this->assertSame(
      'too_few_letters',
      PersonalNameValidator::validate(str_repeat('a', InputDisallowedTerms::PERSONAL_NAME_MIN_LETTERS - 1))['code'] ?? NULL,
    );
  }

}
