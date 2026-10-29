<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

/**
 * Semantic demo template versions in the form "major.minor.patch".
 */
final class SodaScsDemoVersion {

  public const BUMP_MAJOR = 'major';
  public const BUMP_MINOR = 'minor';
  public const BUMP_PATCH = 'patch';

  /**
   * Version of the first template.
   */
  public const INITIAL = '1.0.0';

  /**
   * Three numeric parts without leading zeros, e.g. "1.10.0".
   */
  protected const PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D';

  /**
   * Raises one part of a version and resets the parts below it.
   *
   * @param string $version
   *   A valid version.
   * @param string $part
   *   One of the BUMP_* constants.
   *
   * @throws \InvalidArgumentException
   *   When the version or the part is invalid.
   */
  public static function bump(string $version, string $part): string {
    if (!self::isValid($version)) {
      throw new \InvalidArgumentException(sprintf('Invalid demo version "%s".', $version));
    }
    [$major, $minor, $patch] = array_map('intval', explode('.', $version));
    return match ($part) {
      self::BUMP_MAJOR => sprintf('%d.0.0', $major + 1),
      self::BUMP_MINOR => sprintf('%d.%d.0', $major, $minor + 1),
      self::BUMP_PATCH => sprintf('%d.%d.%d', $major, $minor, $patch + 1),
      default => throw new \InvalidArgumentException(sprintf('Unknown version part "%s".', $part)),
    };
  }

  /**
   * Compares two valid versions numerically (1.10.0 is newer than 1.9.0).
   *
   * @return int
   *   Negative, zero or positive like strcmp().
   */
  public static function compare(string $first, string $second): int {
    return version_compare($first, $second);
  }

  /**
   * Whether the string is a "major.minor.patch" version.
   */
  public static function isValid(string $version): bool {
    return preg_match(self::PATTERN, $version) === 1;
  }

  /**
   * Suggested next versions, keyed by BUMP_* constant.
   *
   * @param string|null $latest
   *   The highest existing version, NULL when there is none.
   *
   * @return array<string, string>
   *   Next patch, minor and major version; only the initial one without a
   *   previous version.
   */
  public static function suggestions(?string $latest): array {
    if ($latest === NULL || !self::isValid($latest)) {
      return [self::BUMP_MAJOR => self::INITIAL];
    }
    return [
      self::BUMP_PATCH => self::bump($latest, self::BUMP_PATCH),
      self::BUMP_MINOR => self::bump($latest, self::BUMP_MINOR),
      self::BUMP_MAJOR => self::bump($latest, self::BUMP_MAJOR),
    ];
  }

}
