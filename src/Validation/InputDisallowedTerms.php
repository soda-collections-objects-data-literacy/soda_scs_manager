<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Validation;

/**
 * Central blacklist for personal names, usernames, and machine names.
 *
 * SQL keywords are forbidden as exact values (machine names) or as name tokens
 * (personal names). Coding literals such as null and empty are blocked for names.
 */
final class InputDisallowedTerms {

  /**
   * Minimum Unicode letter count for personal name fields.
   */
  public const PERSONAL_NAME_MIN_LETTERS = 3;

  /**
   * Minimum character count for usernames.
   */
  public const USERNAME_MIN_LENGTH = 3;

  /**
   * Whether a machine name equals a reserved SQL keyword (case-insensitive).
   */
  public static function isSqlMachineName(string $machineName): bool {
    $key = mb_strtolower(trim($machineName));
    return isset(self::sqlKeywordLookup()[$key]);
  }

  /**
   * Whether a username is reserved for system or infrastructure use.
   */
  public static function isReservedUsername(string $username): bool {
    $key = mb_strtolower(trim($username));
    return isset(self::reservedUsernameLookup()[$key]);
  }

  /**
   * Returns the matched blacklist term for a personal name, if any.
   *
   * Checks the full normalized value and each name token (words separated by
   * spaces, hyphens, apostrophes, or periods).
   */
  public static function matchedPersonalNameTerm(string $value): ?string {
    $normalized = mb_strtolower(trim($value));
    if ($normalized === '') {
      return NULL;
    }

    $lookup = self::personalNameLookup();
    if (isset($lookup[$normalized])) {
      return $lookup[$normalized];
    }

    $tokens = preg_split('/[\s\-\'\.]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($tokens)) {
      return NULL;
    }

    foreach ($tokens as $token) {
      if (isset($lookup[$token])) {
        return $lookup[$token];
      }
    }

    return NULL;
  }

  /**
   * Whether a username equals a forbidden SQL or coding term.
   */
  public static function isForbiddenUsernameTerm(string $username): bool {
    $key = mb_strtolower(trim($username));
    return isset(self::personalNameLookup()[$key]);
  }

  /**
   * @return list<string>
   */
  public static function sqlKeywords(): array {
    return [
      'all',
      'alter',
      'and',
      'any',
      'between',
      'case',
      'create',
      'delete',
      'drop',
      'else',
      'end',
      'exists',
      'false',
      'from',
      'group',
      'having',
      'if',
      'in',
      'insert',
      'is',
      'join',
      'like',
      'limit',
      'not',
      'null',
      'offset',
      'order',
      'or',
      'regexp',
      'rlike',
      'select',
      'some',
      'then',
      'truncate',
      'true',
      'union',
      'update',
      'where',
      'when',
      'xor',
    ];
  }

  /**
   * @return list<string>
   */
  public static function codingTerms(): array {
    return [
      'empty',
      'nan',
      'nil',
      'none',
      'undefined',
      'void',
    ];
  }

  /**
   * @return list<string>
   */
  public static function reservedUsernames(): array {
    return [
      'admin',
      'administrator',
      'anonymous',
      'editor',
      'root',
      'scs-manager',
      'scs-user',
      'scs_manager',
      'scs_user',
      'wisski-admin',
      'wisski-user',
      'wisski_admin',
      'wisski_user',
    ];
  }

  /**
   * @return array<string, string>
   */
  private static function sqlKeywordLookup(): array {
    static $map = NULL;
    if ($map === NULL) {
      $map = [];
      foreach (self::sqlKeywords() as $term) {
        $map[mb_strtolower($term)] = $term;
      }
    }
    return $map;
  }

  /**
   * @return array<string, string>
   */
  private static function personalNameLookup(): array {
    static $map = NULL;
    if ($map === NULL) {
      $map = [];
      foreach (array_merge(self::sqlKeywords(), self::codingTerms()) as $term) {
        $map[mb_strtolower($term)] = $term;
      }
    }
    return $map;
  }

  /**
   * @return array<string, true>
   */
  private static function reservedUsernameLookup(): array {
    static $map = NULL;
    if ($map === NULL) {
      $map = array_fill_keys(
        array_map(static fn (string $term): string => mb_strtolower($term), self::reservedUsernames()),
        TRUE,
      );
    }
    return $map;
  }

}
