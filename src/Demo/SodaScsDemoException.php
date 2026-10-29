<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\soda_scs_manager\ValueObject\SodaScsResult;

/**
 * A demo template build or a demo provisioning step failed.
 */
class SodaScsDemoException extends \RuntimeException {

  /**
   * Throws when a helper result reports a failure.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  public static function assertSuccess(SodaScsResult $result, string $step): SodaScsResult {
    if (!$result->success) {
      throw new static(sprintf('%s: %s %s', $step, $result->message, (string) $result->error));
    }
    return $result;
  }

}
