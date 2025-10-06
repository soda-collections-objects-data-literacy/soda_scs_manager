<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ValueObject;

/**
 * Response object for project group operations.
 *
 * @todo Use this class for all responses.
 */
final readonly class SodaScsResult {

  /**
   * Constructor.
   *
   * @param bool $success
   *   Whether the operation was successful.
   * @param array|null $data
   *   The data, null on failure.
   * @param string|null $error
   *   Error message if operation failed.
   * @param string $message
   *   Human-readable message about the operation.
   */
  public function __construct(
    public bool $success,
    public ?array $data,
    public ?string $error,
    public string $message,
  ) {}

  /**
   * Create a successful response.
   *
   * @param array|null $data
   *   The data.
   * @param string $message
   *   Success message.
   *
   * @return self
   *   The SodaScsResult object.
   */
  public static function success(array $data, string $message): self {
    return new self(
      success: TRUE,
      data: $data,
      error: NULL,
      message: $message,
    );
  }

  /**
   * Create a failure response.
   *
   * @param string $error
   *   Error message.
   * @param string $message
   *   Human-readable message.
   *
   * @return self
   *   The SodaScsResult object.
   */
  public static function failure(string $error, string $message): self {
    return new self(
      success: FALSE,
      data: NULL,
      error: $error,
      message: $message,
    );
  }

}
