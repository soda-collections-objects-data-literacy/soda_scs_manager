<?php

namespace Drupal\soda_scs_manager\Exception;

use Exception;

/**
 * Custom exception for component-related errors in the Soda SCS Manager module.
 */
class SodaScsComponentException extends Exception
{

  /**
   * Constructs a new ComponentException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code. 0 generic error (default); 1 component doesn't exist; 2 permissions denied.
   * @param \Exception|null $previous
   *   The previous exception used for the exception chaining.
   */
  public function __construct(string $message = "", int $code = 0, Exception $previous = NULL)
  {
    parent::__construct($message, $code, $previous);
  }
}
