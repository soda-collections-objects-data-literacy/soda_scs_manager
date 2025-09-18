<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Helper class for Soda SCS operations.
 */
class SodaScsHelpers {

  use StringTranslationTrait;

  /**
   * Adjusts the maxAttempts based on sleep interval.
   *
   * @param int $sleepInterval
   *   The sleep interval in seconds.
   *
   * @return int|false
   *   Returns the calculated maxAttempts, or FALSE if the timeout is too low.
   */
  public function adjustMaxAttempts($sleepInterval = 5) {
    // Read the global PHP request timeout setting from the server.
    $phpRequestTimeout = ini_get('max_execution_time');
    // If max_execution_time is 0, it means unlimited,
    // so return a default value.
    if ((int) $phpRequestTimeout === 0) {
      return 18;
    }
    if ((int) $phpRequestTimeout < $sleepInterval) {
      // Show error and return FALSE if timeout is too low.
      return FALSE;
    }
    // Calculate maxAttempts as the number of
    // sleep intervals that fit in the timeout.
    return (int) floor((int) $phpRequestTimeout / $sleepInterval);
  }

}
