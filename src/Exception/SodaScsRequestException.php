<?php

namespace Drupal\soda_scs_manager\Exception;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\GuzzleException;

use GuzzleHttp\Exception\RequestException;

/**
 * Custom exception for database-related errors in the Soda SCS Manager module.
 */
class SodaScsRequestException extends \Exception {

  /**
   * Constructs a new DatabaseException.
   *
   * @param \GuzzleHttp\Psr7\Response|\GuzzleHttp\Exception\RequestException $response
   *   The response or exception from the request.
   * @param \Exception|GuzzleException|null $previous
   *   The previous exception used for the exception chaining.
   */
  public function __construct(
    Response|RequestException $response,
    \Exception|GuzzleException|null $previous = NULL,
  ) {
    $message = '';
    $code = 0;

    if ($response instanceof Response) {
      $message = "HTTP Error: " . $response->getStatusCode();
      $code = $response->getStatusCode();
    }
    elseif ($response instanceof RequestException) {
      $message = $response->getMessage();
      $code = $response->getCode();
    }

    parent::__construct($message, $code, $previous);
  }

  /**
   * Returns an associative array.
   *
   * Containing the request error code,
   * the error message and the trace path.
   *
   * @return array
   *   An associative array containing the request error code,
   *   the error message and the trace path.
   */
  public function getErrorDetails(): array {
    $details = [];
    if ($this->getCode() !== 0) {
      $details['error_code'] = $this->getCode();
    }
    if ($this->getMessage() !== '') {
      $details['error_message'] = $this->getMessage();
    }
    if ($this->getTraceAsString() !== '') {
      $details['trace_path'] = $this->getTraceAsString();
    }
    return $details;
  }

}
