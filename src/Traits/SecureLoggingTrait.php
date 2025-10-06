<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Traits;

use Psr\Log\LogLevel;

/**
 * Trait for secure logging functionality.
 *
 * Provides methods to sanitize sensitive data from log messages
 * and context variables to prevent credential exposure.
 *
 * @todo Check if we use this trait everywhere needed.
 */
trait SecureLoggingTrait {

  /**
   * Data that should never be logged, even sanitized.
   */
  private const NEVER_LOG = [
    'raw_password',
    'private_key',
    'secret_key',
    'api_secret',
    'client_secret',
  ];

  /**
   * Sanitizes sensitive data from strings for logging.
   *
   * @param string $input
   *   The input string to sanitize.
   * @param array $additionalPatterns
   *   Additional regex patterns to sanitize.
   *
   * @return string
   *   The sanitized string.
   */
  protected function sanitizeForLogging(string $input, array $additionalPatterns = []): string {
    $defaultPatterns = [
      // MySQL password patterns.
      '/(-p)([^\s]+)/' => '$1[REDACTED]',
      '/(-p\s+)([^\s]+)/' => '$1[REDACTED]',

      // Various password= patterns.
      '/(password\s*=\s*[\'"]?)([^\'"\s;&]+)([\'"]?)/' => '$1[REDACTED]$3',
      '/(pwd\s*=\s*[\'"]?)([^\'"\s;&]+)([\'"]?)/' => '$1[REDACTED]$3',
      '/(pass\s*=\s*[\'"]?)([^\'"\s;&]+)([\'"]?)/' => '$1[REDACTED]$3',

      // Environment variable patterns.
      '/([A-Z_]*PASSWORD\s*=\s*[\'"]?)([^\'"\s;&]+)([\'"]?)/' => '$1[REDACTED]$3',
      '/([A-Z_]*SECRET\s*=\s*[\'"]?)([^\'"\s;&]+)([\'"]?)/' => '$1[REDACTED]$3',
      '/([A-Z_]*KEY\s*=\s*[\'"]?)([^\'"\s;&]+)([\'"]?)/' => '$1[REDACTED]$3',

      // API tokens and keys.
      '/(token\s*[:\=]\s*[\'"]?)([a-zA-Z0-9+\/=]{20,})([\'"]?)/' => '$1[REDACTED]$3',
      '/(api[_-]?key\s*[:\=]\s*[\'"]?)([a-zA-Z0-9+\/=]{20,})([\'"]?)/' => '$1[REDACTED]$3',

      // Database connection strings.
      '/(:\/\/)([^:@]+):([^@]+)(@)/' => '$1$2:[REDACTED]$4',

      // JSON password fields.
      '/("password"\s*:\s*")([^"]+)(")/' => '$1[REDACTED]$3',
      '/("secret"\s*:\s*")([^"]+)(")/' => '$1[REDACTED]$3',
      '/("key"\s*:\s*")([^"]+)(")/' => '$1[REDACTED]$3',
    ];

    // Merge with additional patterns.
    $patterns = array_merge($defaultPatterns, $additionalPatterns);

    foreach ($patterns as $pattern => $replacement) {
      $input = preg_replace($pattern, $replacement, $input);
    }

    return $input;
  }

  /**
   * Sanitizes command strings specifically for logging.
   *
   * @param string $command
   *   The command string to sanitize.
   *
   * @return string
   *   The sanitized command string.
   */
  protected function sanitizeCommandForLogging(string $command): string {
    $commandPatterns = [
      // MySQL specific patterns.
      '/(-p)([^\s]+)/' => '$1[REDACTED]',
      '/(-p\s+)([^\s]+)/' => '$1[REDACTED]',
      '/(--password=)([^\s]+)/' => '$1[REDACTED]',

      // PostgreSQL patterns.
      '/(PGPASSWORD=)([^\s]+)/' => '$1[REDACTED]',

      // General command patterns.
      '/(password=)([^\s;&]+)/' => '$1[REDACTED]',
      '/(pwd=)([^\s;&]+)/' => '$1[REDACTED]',
      '/(pass=)([^\s;&]+)/' => '$1[REDACTED]',
    ];

    return $this->sanitizeForLogging($command, $commandPatterns);
  }

  /**
   * Logs a message with automatic sanitization of sensitive data.
   *
   * @param string $level
   *   The log level.
   * @param string $message
   *   The message to log.
   * @param array $context
   *   Context variables.
   * @param array $sensitiveKeys
   *   Array of context keys that contain sensitive data.
   */
  protected function secureLog(string $level, string $message, array $context = [], array $sensitiveKeys = []): void {
    // Get configuration from the settings.
    $config = \Drupal::config('soda_scs_manager.settings');
    $sanitizeLogsEnabled = $config->get('security.logging.sanitize_logs') ?? TRUE;
    $minLogLevel = $config->get('security.logging.log_level') ?? LogLevel::INFO;

    if (!$this->shouldLog($level, $minLogLevel)) {
      return;
    }

    // Sanitize the message if sanitization is enabled.
    $sanitizedMessage = $sanitizeLogsEnabled ? $this->sanitizeForLogging($message) : $message;

    // Sanitize context values.
    $sanitizedContext = $this->sanitizeContext($context, $sensitiveKeys, $sanitizeLogsEnabled);

    $this->loggerFactory->get('soda_scs_manager')->log($level, $sanitizedMessage, $sanitizedContext);
  }

  /**
   * Determines if a log entry should be written based on level.
   *
   * @param string $level
   *   The log level to check.
   * @param string $minLevel
   *   The minimum log level.
   *
   * @return bool
   *   TRUE if the log entry should be written.
   */
  private function shouldLog(string $level, string $minLevel): bool {
    $levels = [
      LogLevel::DEBUG => 0,
      LogLevel::INFO => 1,
      LogLevel::NOTICE => 2,
      LogLevel::WARNING => 3,
      LogLevel::ERROR => 4,
      LogLevel::CRITICAL => 5,
      LogLevel::ALERT => 6,
      LogLevel::EMERGENCY => 7,
    ];

    return ($levels[$level] ?? 0) >= ($levels[$minLevel] ?? 0);
  }

  /**
   * Sanitizes context array for logging.
   *
   * @param array $context
   *   The context array to sanitize.
   * @param array $sensitiveKeys
   *   Array of context keys that contain sensitive data.
   * @param bool $sanitizeEnabled
   *   Whether sanitization is enabled.
   *
   * @return array
   *   The sanitized context array.
   */
  private function sanitizeContext(array $context, array $sensitiveKeys, bool $sanitizeEnabled): array {
    $sanitizedContext = [];

    foreach ($context as $key => $value) {
      // Never log certain types of data.
      if ($this->shouldNeverLog($key)) {
        $sanitizedContext[$key] = '[REMOVED]';
        continue;
      }

      // Skip sanitization if disabled.
      if (!$sanitizeEnabled) {
        $sanitizedContext[$key] = $value;
        continue;
      }

      // Sanitize explicitly marked sensitive keys.
      if (in_array($key, $sensitiveKeys)) {
        $sanitizedContext[$key] = is_string($value) ? $this->sanitizeForLogging($value) : '[REDACTED]';
        continue;
      }

      // Auto-detect and sanitize potentially sensitive data.
      if (is_string($value) && $this->mightContainSensitiveData($key, $value)) {
        $sanitizedContext[$key] = $this->sanitizeForLogging($value);
        continue;
      }

      // For arrays, recursively sanitize.
      if (is_array($value)) {
        $sanitizedContext[$key] = $this->sanitizeContext($value, $sensitiveKeys, $sanitizeEnabled);
        continue;
      }

      // Keep non-sensitive data as-is.
      $sanitizedContext[$key] = $value;
    }

    return $sanitizedContext;
  }

  /**
   * Checks if data should never be logged.
   *
   * @param string $key
   *   The context key to check.
   *
   * @return bool
   *   TRUE if the data should never be logged.
   */
  private function shouldNeverLog(string $key): bool {
    $keyLower = strtolower($key);
    foreach (self::NEVER_LOG as $neverLog) {
      if (strpos($keyLower, $neverLog) !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Determines if a context key/value might contain sensitive data.
   *
   * @param string $key
   *   The context key.
   * @param string $value
   *   The context value.
   *
   * @return bool
   *   TRUE if the data might be sensitive.
   */
  private function mightContainSensitiveData(string $key, string $value): bool {
    $sensitiveKeywords = [
      'password', 'pass', 'pwd', 'secret', 'key', 'token', 'auth',
      'credential', 'login', 'command', 'query', 'sql', 'exec',
    ];

    $keyLower = strtolower($key);
    foreach ($sensitiveKeywords as $keyword) {
      if (strpos($keyLower, $keyword) !== FALSE) {
        return TRUE;
      }
    }

    // Check if value looks like it contains database commands with passwords.
    if (preg_match('/(mysql|mariadb|psql).*-p/', $value)) {
      return TRUE;
    }

    // Check if value looks like a connection string.
    if (preg_match('/\w+:\/\/.*:.*@/', $value)) {
      return TRUE;
    }

    // Check if value looks like a long token or key.
    if (preg_match('/^[a-zA-Z0-9+\/=]{32,}$/', $value)) {
      return TRUE;
    }

    return FALSE;
  }

}
