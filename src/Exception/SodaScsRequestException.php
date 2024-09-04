<?php

namespace Drupal\soda_scs_manager\Exception;


use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\GuzzleException;

use Exception;
use GuzzleHttp\Exception\RequestException;

/**
 * Custom exception for database-related errors in the Soda SCS Manager module.
 */
class SodaScsRequestException extends Exception {

  /**
   * Constructs a new DatabaseException.
   *
   * @param Response|RequestException $response
   * @param \Exception|GuzzleException|null $previous
   *   The previous exception used for the exception chaining.
   */
  public function __construct(Response|RequestException $response, Exception|GuzzleException $previous = NULL) {
    parent::__construct($response, $previous);
  }

  /**
   * Returns an associative array containing the request error code, the error message and the trace path.
   *
   * @return array
   *   An associative array containing the request error code, the error message and the trace path.
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

