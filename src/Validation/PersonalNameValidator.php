<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Validation;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Validates personal name fields (first name, last name, etc.).
 */
final class PersonalNameValidator {

  private const ALLOWED_CHARACTERS_PATTERN = '/^[\p{L}\s\-\'\.]+$/u';

  /**
   * Validates a personal name value.
   *
   * @return array{code: string, term?: string}|null
   *   Violation details, or NULL when valid.
   */
  public static function validate(string $value): ?array {
    $trimmed = trim($value);
    if ($trimmed === '') {
      return NULL;
    }

    if (!preg_match(self::ALLOWED_CHARACTERS_PATTERN, $trimmed)) {
      return ['code' => 'invalid_characters'];
    }

    if (preg_match('/\d/u', $trimmed)) {
      return ['code' => 'contains_digits'];
    }

    if (self::countLetters($trimmed) < InputDisallowedTerms::PERSONAL_NAME_MIN_LETTERS) {
      return ['code' => 'too_few_letters'];
    }

    $matchedTerm = InputDisallowedTerms::matchedPersonalNameTerm($trimmed);
    if ($matchedTerm !== NULL) {
      return [
        'code' => 'disallowed_term',
        'term' => $matchedTerm,
      ];
    }

    return NULL;
  }

  /**
   * Counts Unicode letters in a personal name.
   */
  public static function countLetters(string $value): int {
    if (!preg_match_all('/\p{L}/u', $value, $matches)) {
      return 0;
    }

    return count($matches[0]);
  }

  /**
   * Builds a translatable validation error for a field label.
   *
   * @param array{code: string, term?: string} $violation
   */
  public static function violationMessage(array $violation, TranslatableMarkup|string $fieldLabel): TranslatableMarkup {
    return match ($violation['code']) {
      'invalid_characters' => new TranslatableMarkup('@field may only contain letters, spaces, hyphens, apostrophes, and periods.', [
        '@field' => $fieldLabel,
      ]),
      'contains_digits' => new TranslatableMarkup('@field may not contain digits.', [
        '@field' => $fieldLabel,
      ]),
      'too_few_letters' => new TranslatableMarkup('@field must contain at least @count letters.', [
        '@field' => $fieldLabel,
        '@count' => InputDisallowedTerms::PERSONAL_NAME_MIN_LETTERS,
      ]),
      'disallowed_term' => new TranslatableMarkup('@field may not contain the word "@word".', [
        '@field' => $fieldLabel,
        '@word' => $violation['term'] ?? '',
      ]),
      default => new TranslatableMarkup('@field is not valid.', [
        '@field' => $fieldLabel,
      ]),
    };
  }

}
