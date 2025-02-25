<?php
// web/modules/custom/soda_scs_manager/src/Exception/SodaScsPortainerConnectionException.php
namespace Drupal\soda_scs_manager\Exception;

class SodaScsPortainerConnectionException extends SodaScsPortainerException {
  public function __construct(
    protected array $context = [],
    ?\Throwable $previous = null
  ) {
    parent::__construct(
      'Could not connect to container management service',
      503,
      $previous,
      $context
    );
  }
}