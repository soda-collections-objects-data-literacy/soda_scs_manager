<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Exception;

/**
 * Custom exception for component-related errors in the Soda SCS Manager module.
 */
class SodaScsComponentActionsException extends \Exception {

  /**
   * The operation category that failed.
   *
   * @var string
   */
  protected string $operationCategory = 'unknown';

  /**
   * The specific operation that failed.
   *
   * @var string
   */
  protected string $operation = 'unknown';

  /**
   * Additional context data.
   *
   * @var array
   */
  protected array $context = [];

  /**
   * The calling class (auto-detected).
   *
   * @var string
   */
  protected string $callingClass = 'unknown';

  /**
   * The calling function (auto-detected).
   *
   * @var string
   */
  protected string $callingFunction = 'unknown';

  /**
   * Constructs a new ComponentActionsException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code. 0 generic error (default); 1 component doesn't exist; 2 permissions denied; 3 snapshot failed; 4 triplestore failed; 5 wisski failed; 6 sql failed; 7 container failed.
   * @param \Exception|null $previous
   *   The previous exception used for the exception chaining.
   */
  public function __construct(string $message = "", int $code = 0, ?\Exception $previous = NULL) {
    parent::__construct($message, $code, $previous);
  }

  /**
   * Creates an exception for a component action failure.
   *
   * @param string $message
   *   The error message.
   * @param string $operationCategory
   *   The category of operation (e.g., 'triplestore', 'wisski', 'sql', 'snapshot').
   * @param string $operation
   *   The specific operation (e.g., 'create_snapshot', 'dump_repository').
   * @param array $context
   *   Additional context data.
   * @param int $code
   *   The exception code.
   * @param \Throwable|null $previous
   *   The previous exception.
   *
   * @return static
   *   The exception instance.
   */
  public static function actionFailed(
    string $message,
    string $operationCategory,
    string $operation,
    array $context = [],
    int $code = 0,
    ?\Throwable $previous = NULL
  ): self {
    [$callingClass, $callingFunction] = self::detectCaller();
    $enhancedMessage = sprintf(
      '[%s::%s] %s (Category: %s, Operation: %s)',
      self::shortClassName($callingClass),
      $callingFunction,
      $message,
      $operationCategory,
      $operation
    );

    $ex = new self($enhancedMessage, $code, $previous);
    $ex->operationCategory = $operationCategory;
    $ex->operation = $operation;
    $ex->context = $context;
    $ex->callingClass = $callingClass;
    $ex->callingFunction = $callingFunction;
    return $ex;
  }

  /**
   * Convenience factory for snapshot-related failures.
   */
  public static function snapshotFailed(string $message, string $operation, array $context = [], ?\Throwable $previous = NULL): self {
    return self::actionFailed($message, 'snapshot', $operation, $context, 0, $previous);
  }

  /**
   * Convenience factory for triplestore-related failures.
   */
  public static function triplestoreFailed(string $message, string $operation, array $context = [], ?\Throwable $previous = NULL): self {
    return self::actionFailed($message, 'triplestore', $operation, $context, 0, $previous);
  }

  /**
   * Convenience factory for WissKI-related failures.
   */
  public static function wisskiFailed(string $message, string $operation, array $context = [], ?\Throwable $previous = NULL): self {
    return self::actionFailed($message, 'wisski', $operation, $context, 0, $previous);
  }

  /**
   * Convenience factory for SQL-related failures.
   */
  public static function sqlFailed(string $message, string $operation, array $context = [], ?\Throwable $previous = NULL): self {
    return self::actionFailed($message, 'sql', $operation, $context, 0, $previous);
  }

  /**
   * Convenience factory for container-related failures.
   */
  public static function containerFailed(string $message, string $operation, array $context = [], ?\Throwable $previous = NULL): self {
    return self::actionFailed($message, 'container', $operation, $context, 0, $previous);
  }

  /**
   * Gets the operation category.
   */
  public function getOperationCategory(): string {
    return $this->operationCategory;
  }

  /**
   * Gets the operation.
   */
  public function getOperation(): string {
    return $this->operation;
  }

  /**
   * Gets the context data.
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * Gets the calling class.
   */
  public function getCallingClass(): string {
    return $this->callingClass;
  }

  /**
   * Gets the calling function.
   */
  public function getCallingFunction(): string {
    return $this->callingFunction;
  }

  /**
   * Detects the caller (class and function) via backtrace.
   *
   * @return array{0:string,1:string}
   *   The class and function names.
   */
  private static function detectCaller(): array {
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    // Level 0 = this method, 1 = factory, 2 = caller of factory (desired).
    $frame = $bt[2] ?? ($bt[1] ?? []);
    $class = isset($frame['class']) ? (string) $frame['class'] : 'unknown';
    $function = isset($frame['function']) ? (string) $frame['function'] : 'unknown';
    return [$class, $function];
  }

  /**
   * Returns short class name without namespace.
   */
  private static function shortClassName(string $full): string {
    $pos = strrpos($full, '\\');
    return $pos === FALSE ? $full : substr($full, $pos + 1);
  }
}
