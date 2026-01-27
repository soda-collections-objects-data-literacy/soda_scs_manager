<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Exception;

/**
 * Universal exception for helper class operations.
 *
 * Usage:
 * throw SodaScsHelpersException::snapshotFailed(
 *   'N-Quads transformation failed: ' . $error,
 *   'nquads_conversion',
 *   ['component' => $component->get('machineName')->value]
 * );
 * Catching and logging:
 * catch (SodaScsHelpersException $e) {
 *   Error::logException(
 *   $this->loggerFactory->get('soda_scs_manager'),
 *   $e,
 *   'Helper failed (Category: @cat, Operation: @op): @message',
 *   [
 *     '@cat' => $e->getOperationCategory(),
 *     '@op' => $e->getOperation(),
 *     '@message' => $e->getMessage(),
 *     // Optionally include structured context:
 *     '@context' => json_encode($e->getContext()),
 *   ]
 * );
 * }
 */
class SodaScsHelpersException extends \Exception {

  /**
   * The operation category that failed.
   *
   * @var string
   */
  protected string $operationCategory;

  /**
   * The specific operation that failed.
   *
   * @var string
   */
  protected string $operation;

  /**
   * Additional context data.
   *
   * @var array
   */
  protected array $context;

  /**
   * The calling class (auto-detected).
   *
   * @var string
   */
  protected string $callingClass;

  /**
   * The calling function (auto-detected).
   *
   * @var string
   */
  protected string $callingFunction;

  /**
   * Constructs a SodaScsHelpersException.
   *
   * @param string $message
   *   The exception message.
   * @param string $operationCategory
   *   The category of operation (e.g., 'snapshot', 'filesystem', 'data_processing').
   * @param string $operation
   *   The specific operation (e.g., 'nquads_conversion', 'file_writing').
   * @param array $context
   *   Additional context data.
   * @param int $code
   *   The exception code.
   * @param \Throwable|null $previous
   *   The previous exception.
   */
  public function __construct(
    string $message = "",
    string $operationCategory = 'unknown',
    string $operation = 'unknown',
    array $context = [],
    int $code = 0,
    ?\Throwable $previous = NULL,
  ) {
    $this->operationCategory = $operationCategory;
    $this->operation = $operation;
    $this->context = $context;

    // Auto-detect calling class and function.
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = $backtrace[1] ?? [];

    $this->callingClass = $caller['class'] ?? 'unknown';
    $this->callingFunction = $caller['function'] ?? 'unknown';

    $enhancedMessage = sprintf(
      '[%s::%s] %s (Category: %s, Operation: %s)',
      $this->getShortClassName($this->callingClass),
      $this->callingFunction,
      $message,
      $operationCategory,
      $operation
    );

    parent::__construct($enhancedMessage, $code, $previous);
  }

  /**
   * Creates an exception for snapshot-related failures.
   *
   * @param string $message
   *   The error message.
   * @param string $operation
   *   The specific snapshot operation.
   * @param array $context
   *   Additional context.
   * @param \Throwable|null $previous
   *   The previous exception.
   *
   * @return static
   *   The exception instance.
   */
  public static function snapshotFailed(
    string $message,
    string $operation,
    array $context = [],
    ?\Throwable $previous = NULL,
  ): self {
    return new self($message, 'snapshot', $operation, $context, 0, $previous);
  }

  /**
   * Creates an exception for filesystem-related failures.
   *
   * @param string $message
   *   The error message.
   * @param string $operation
   *   The specific filesystem operation.
   * @param array $context
   *   Additional context.
   * @param \Throwable|null $previous
   *   The previous exception.
   *
   * @return static
   *   The exception instance.
   */
  public static function fileSystemFailed(
    string $message,
    string $operation,
    array $context = [],
    ?\Throwable $previous = NULL,
  ): self {
    return new self($message, 'filesystem', $operation, $context, 0, $previous);
  }

  /**
   * Creates an exception for data-processing failures.
   *
   * @param string $message
   *   The error message.
   * @param string $operation
   *   The specific data-processing operation.
   * @param array $context
   *   Additional context.
   * @param \Throwable|null $previous
   *   The previous exception.
   *
   * @return static
   *   The exception instance.
   */
  public static function dataProcessingFailed(
    string $message,
    string $operation,
    array $context = [],
    ?\Throwable $previous = NULL,
  ): self {
    return new self($message, 'data_processing', $operation, $context, 0, $previous);
  }

  /**
   * Creates an exception for configuration-related failures.
   *
   * @param string $message
   *   The error message.
   * @param string $operation
   *   The specific configuration operation.
   * @param array $context
   *   Additional context.
   * @param \Throwable|null $previous
   *   The previous exception.
   *
   * @return static
   *   The exception instance.
   */
  public static function configurationFailed(
    string $message,
    string $operation,
    array $context = [],
    ?\Throwable $previous = NULL,
  ): self {
    return new self($message, 'configuration', $operation, $context, 0, $previous);
  }

  /**
   * Creates an exception for progress tracking failures.
   *
   * @param string $message
   *   The error message.
   * @param string $operation
   *   The specific progress operation.
   * @param array $context
   *   Additional context.
   * @param \Throwable|null $previous
   *   The previous exception.
   *
   * @return static
   *   The exception instance.
   */
  public static function progressFailed(
    string $message,
    string $operation,
    array $context = [],
    ?\Throwable $previous = NULL,
  ): self {
    return new self($message, 'progress', $operation, $context, 0, $previous);
  }

  /**
   * Gets the operation category.
   *
   * @return string
   *   The operation category.
   */
  public function getOperationCategory(): string {
    return $this->operationCategory;
  }

  /**
   * Gets the operation.
   *
   * @return string
   *   The operation.
   */
  public function getOperation(): string {
    return $this->operation;
  }

  /**
   * Gets the context data.
   *
   * @return array
   *   The context data.
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * Gets the calling class.
   *
   * @return string
   *   The calling class.
   */
  public function getCallingClass(): string {
    return $this->callingClass;
  }

  /**
   * Gets the calling function.
   *
   * @return string
   *   The calling function.
   */
  public function getCallingFunction(): string {
    return $this->callingFunction;
  }

  /**
   * Gets the short class name from a fully-qualified class name.
   *
   * @param string $fullClassName
   *   The full class name.
   *
   * @return string
   *   The short class name.
   */
  private function getShortClassName(string $fullClassName): string {
    if ($fullClassName === '' || strpos($fullClassName, '\\') === FALSE) {
      return $fullClassName;
    }
    $pos = strrpos($fullClassName, '\\');
    return $pos !== FALSE ? substr($fullClassName, $pos + 1) : $fullClassName;
  }

  /**
   * Creates an exception for OpenGDB-related failures.
   *
   * @param string $message
   *   The error message.
   * @param string $operation
   *   The specific OpenGDB operation.
   * @param array $context
   *   Additional context.
   * @param \Throwable|null $previous
   *   The previous exception.
   *
   * @return static
   *   The exception instance.
   */
  public static function opengdbFailed(
    string $message,
    string $operation,
    array $context = [],
    ?\Throwable $previous = NULL,
  ): self {
    return new self($message, 'opengdb', $operation, $context, 0, $previous);
  }

}
