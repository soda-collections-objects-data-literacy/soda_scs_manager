<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Validation;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Validates username fields.
 */
final class UsernameValidator {

  private const ALLOWED_CHARACTERS_PATTERN = '/^[a-zA-Z0-9_]+$/';

  /**
   * Validates a username value.
   *
   * @return array{code: string, term?: string}|null
   *   Violation details, or NULL when valid.
   */
  public static function validate(string $value): ?array {
    $trimmed = trim($value);
    if ($trimmed === '') {
      return NULL;
    }

    if (mb_strlen($trimmed) < InputDisallowedTerms::USERNAME_MIN_LENGTH) {
      return ['code' => 'too_short'];
    }

    if (!preg_match(self::ALLOWED_CHARACTERS_PATTERN, $trimmed)) {
      return ['code' => 'invalid_characters'];
    }

    if (InputDisallowedTerms::isReservedUsername($trimmed)) {
      return ['code' => 'reserved'];
    }

    if (InputDisallowedTerms::isForbiddenUsernameTerm($trimmed)) {
      return [
        'code' => 'disallowed_term',
        'term' => mb_strtolower($trimmed),
      ];
    }

    return NULL;
  }

  /**
   * Builds a translatable validation error for a username field.
   *
   * @param array{code: string, term?: string} $violation
   */
  public static function violationMessage(array $violation): TranslatableMarkup {
    return match ($violation['code']) {
      'too_short' => new TranslatableMarkup('Username must be at least @count characters long.', [
        '@count' => InputDisallowedTerms::USERNAME_MIN_LENGTH,
      ]),
      'invalid_characters' => new TranslatableMarkup('Username may only contain alphanumeric characters and underscores.'),
      'reserved' => new TranslatableMarkup('This username is reserved and may not be used.'),
      'disallowed_term' => new TranslatableMarkup('Username may not contain the word "@word".', [
        '@word' => $violation['term'] ?? '',
      ]),
      default => new TranslatableMarkup('Username is not valid.'),
    };
  }

}
