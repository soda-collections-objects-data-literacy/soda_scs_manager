<?php

declare(strict_types=1);

/**
 * Replaces sleep() for the code under test, so polling loops do not wait.
 *
 * An unqualified sleep() call in the namespace of the WissKI component actions
 * resolves to the function below once this file is loaded. It records the
 * requested seconds and returns at once.
 */

namespace Drupal\soda_scs_manager\ComponentActions {

  use Drupal\Tests\soda_scs_manager\Unit\ComponentActions\SodaScsSleepStub;

  /**
   * Records the wait instead of sleeping.
   */
  function sleep(int $seconds): int {
    SodaScsSleepStub::$seconds[] = $seconds;
    return 0;
  }

}

namespace Drupal\Tests\soda_scs_manager\Unit\ComponentActions {

  /**
   * The waits the code under test asked for.
   */
  final class SodaScsSleepStub {

    /**
     * Seconds of every sleep() call, in order.
     *
     * @var int[]
     */
    public static array $seconds = [];

  }

}
