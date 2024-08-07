<?php

namespace Drupal\soda_scs_manager\Exception;

use Exception;

/**
 * Custom exception for database-related errors in the Soda SCS Manager module.
 */
class SodaScsDatabaseException extends Exception {

  /**
   * Constructs a new DatabaseException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param \Exception|null $previous
   *   The previous exception used for the exception chaining.
   */
  public function __construct(string $message = "", int $code = 0, Exception $previous = NULL) {
    parent::__construct($message, $code, $previous);
  }

}
