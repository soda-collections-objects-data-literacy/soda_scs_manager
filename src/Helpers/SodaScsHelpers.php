<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Helper class for Soda SCS operations.
 */
class SodaScsHelpers {

  use StringTranslationTrait;

  /**
   * Constructs a SodaScsHelpers object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Adjusts the maxAttempts based on sleep interval.
   *
   * @param int $sleepInterval
   *   The sleep interval in seconds.
   *
   * @return int|false
   *   Returns the calculated maxAttempts, or FALSE if the timeout is too low.
   */
  public function adjustMaxAttempts($sleepInterval = 5) {
    // Read the global PHP request timeout setting from the server.
    $phpRequestTimeout = ini_get('max_execution_time');
    // If max_execution_time is 0, it means unlimited,
    // so return a default value.
    if ((int) $phpRequestTimeout === 0) {
      return 18;
    }
    if ((int) $phpRequestTimeout < $sleepInterval) {
      // Show error and return FALSE if timeout is too low.
      return FALSE;
    }
    // Calculate maxAttempts as the number of
    // sleep intervals that fit in the timeout.
    return (int) floor((int) $phpRequestTimeout / $sleepInterval);
  }

  /**
   * Parse and clean Docker exec command output.
   *
   * Docker exec output often contains stream multiplexing headers and control
   * characters that need to be stripped to get clean command output.
   *
   * @param string $rawOutput
   *   The raw output from Docker exec.
   *
   * @return string
   *   The cleaned output.
   */
  public function parseDockerExecOutput(string $rawOutput): string {
    // Docker stream multiplexing format:
    // - First 8 bytes are header: [stream_type, 0, 0, 0, size_bytes].
    // - stream_type: 1=stdout, 2=stderr, 0=stdin.
    // - size_bytes: 4-byte big-endian integer for payload size.
    $cleanedOutput = '';
    $offset = 0;
    $outputLength = strlen($rawOutput);

    while ($offset < $outputLength) {
      // Check if we have at least 8 bytes for a header.
      if ($offset + 8 > $outputLength) {
        // Not enough bytes for a complete header, treat rest as plain text.
        $cleanedOutput .= substr($rawOutput, $offset);
        break;
      }

      // Extract the first byte (stream type).
      $streamType = ord($rawOutput[$offset]);

      // Check if this looks like a Docker stream header.
      // Valid stream types are 0, 1, 2 and next 3 bytes should be 0.
      if ($streamType <= 2 &&
          ord($rawOutput[$offset + 1]) === 0 &&
          ord($rawOutput[$offset + 2]) === 0 &&
          ord($rawOutput[$offset + 3]) === 0) {

        // Extract payload size (big-endian 4-byte integer).
        $payloadSize = unpack('N', substr($rawOutput, $offset + 4, 4))[1];

        // Skip the 8-byte header.
        $offset += 8;

        // Extract the payload.
        if ($offset + $payloadSize <= $outputLength) {
          $payload = substr($rawOutput, $offset, $payloadSize);
          $cleanedOutput .= $payload;
          $offset += $payloadSize;
        }
        else {
          // Invalid payload size, treat rest as plain text.
          $cleanedOutput .= substr($rawOutput, $offset);
          break;
        }
      }
      else {
        // Not a Docker stream header, treat as plain text.
        // Look for the next potential header or end of string.
        $nextHeaderPos = $offset + 1;
        while ($nextHeaderPos < $outputLength - 8) {
          $nextStreamType = ord($rawOutput[$nextHeaderPos]);
          if ($nextStreamType <= 2 &&
              ord($rawOutput[$nextHeaderPos + 1]) === 0 &&
              ord($rawOutput[$nextHeaderPos + 2]) === 0 &&
              ord($rawOutput[$nextHeaderPos + 3]) === 0) {
            break;
          }
          $nextHeaderPos++;
        }

        // Add the plain text portion.
        $cleanedOutput .= substr($rawOutput, $offset, $nextHeaderPos - $offset);
        $offset = $nextHeaderPos;
      }
    }

    // Remove any remaining control characters and normalize line endings.
    $cleanedOutput = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cleanedOutput);
    $cleanedOutput = str_replace(["\r\n", "\r"], "\n", $cleanedOutput);

    return trim($cleanedOutput);
  }

  /**
   * Simple method to clean command output by removing control characters.
   *
   * This is a simpler alternative to parseDockerExecOutput for cases where
   * you just need to remove basic control characters and normalize output.
   *
   * @param string $rawOutput
   *   The raw output to clean.
   *
   * @return string
   *   The cleaned output.
   */
  public function cleanCommandOutput(string $rawOutput): string {
    // Remove common control characters including the Docker stream prefix.
    $cleanedOutput = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawOutput);

    // Normalize line endings.
    $cleanedOutput = str_replace(["\r\n", "\r"], "\n", $cleanedOutput);

    // Remove any leading/trailing whitespace.
    return trim($cleanedOutput);
  }

  /**
   * Get type of entity.
   *
   * @param string $bundleId
   *   The bundle ID.
   *
   * @return string
   *   The type of entity.
   */
  public function getEntityType(string $bundleId): string {
    switch ($bundleId) {
      case 'soda_scs_wisski_stack':
        return 'wisski';

      case 'soda_scs_jupyter_stack':
        return 'jupyter';

      case 'soda_scs_nextcloud_stack':
        return 'nextcloud';

      case 'soda_scs_filesystem_component':
        return 'shared-folder';

      case 'soda_scs_sql_component':
        return 'mariadb';

      case 'soda_scs_triplestore_component':
        return 'open-gdb';

      case 'soda_scs_webprotege_component':
        return 'webprotege';

      case 'soda_scs_wisski_component':
        return 'wisski';

      default:
        return 'unknown';
    }
  }

  /**
   * Validates a backup path for safe deletion operations.
   *
   * This method performs security checks to ensure the path is safe for
   * deletion operations. It validates path structure, prevents traversal
   * attacks, and ensures hierarchical safety requirements are met.
   *
   * @param string $path
   *   The path to validate.
   * @param array $forbiddenPaths
   *   Array of forbidden absolute paths that should not be deleted.
   *   Defaults to ['/', '/opt/drupal', '/opt/drupal/'].
   * @param array $requiredPatterns
   *   Array of regex patterns that must match at least one path segment.
   *   Defaults to ['/\b(bkp|backup|snapshot)\b/i'].
   * @param bool $requireAbsolute
   *   Whether the path must be absolute. Defaults to TRUE.
   *
   * @return array
   *   Validation result array with keys: isValid (bool), errorCode
   *   (string|null), errorMessage (string|null), normalizedPath (string).
   */
  public function validatePathForSafeDeletion(
    string $path,
    array $forbiddenPaths = ['/', '/opt/drupal', '/opt/drupal/'],
    array $requiredPatterns = ['/\b(bkp|backup|snapshot|tmp|cache)\b/i'],
    bool $requireAbsolute = TRUE,
  ): array {
    $normalizedPath = rtrim($path, '/');

    // Validate path is not empty.
    if (empty($normalizedPath)) {
      return [
        'isValid' => FALSE,
        'errorCode' => 'empty',
        'errorMessage' => 'Path cannot be empty.',
        'normalizedPath' => $normalizedPath,
      ];
    }

    // Validate path is not root directory.
    if ($normalizedPath === '/') {
      return [
        'isValid' => FALSE,
        'errorCode' => 'root',
        'errorMessage' => 'Cannot delete root directory.',
        'normalizedPath' => $normalizedPath,
      ];
    }

    // Validate path is not in forbidden paths list.
    foreach ($forbiddenPaths as $forbiddenPath) {
      $normalizedForbidden = rtrim($forbiddenPath, '/');
      if ($normalizedPath === $normalizedForbidden) {
        return [
          'isValid' => FALSE,
          'errorCode' => 'forbidden',
          'errorMessage' => sprintf('Path "%s" is forbidden for deletion.', htmlspecialchars($path)),
          'normalizedPath' => $normalizedPath,
        ];
      }
    }

    // Validate path is absolute if required.
    if ($requireAbsolute && $normalizedPath[0] !== '/') {
      return [
        'isValid' => FALSE,
        'errorCode' => 'relative',
        'errorMessage' => 'Path must be absolute.',
        'normalizedPath' => $normalizedPath,
      ];
    }

    // Validate path does not contain traversal sequences.
    if (
      strpos($normalizedPath, '../') !== FALSE ||
      strpos($normalizedPath, '/..') !== FALSE ||
      strpos($normalizedPath, '..') !== FALSE
    ) {
      return [
        'isValid' => FALSE,
        'errorCode' => 'traversal',
        'errorMessage' => 'Path contains directory traversal sequences.',
        'normalizedPath' => $normalizedPath,
      ];
    }

    // Validate hierarchical safety: at least one segment must match required
    // patterns.
    if (!empty($requiredPatterns)) {
      $segments = explode('/', trim($normalizedPath, '/'));
      $matchesPattern = FALSE;
      foreach ($segments as $segment) {
        foreach ($requiredPatterns as $pattern) {
          if (preg_match($pattern, $segment)) {
            $matchesPattern = TRUE;
            break 2;
          }
        }
      }

      if (!$matchesPattern) {
        return [
          'isValid' => FALSE,
          'errorCode' => 'hierarchy',
          'errorMessage' => sprintf(
            'Path does not contain required hierarchy patterns: %s',
            htmlspecialchars($path),
          ),
          'normalizedPath' => $normalizedPath,
        ];
      }
    }

    return [
      'isValid' => TRUE,
      'errorCode' => NULL,
      'errorMessage' => NULL,
      'normalizedPath' => $normalizedPath,
    ];
  }

}
