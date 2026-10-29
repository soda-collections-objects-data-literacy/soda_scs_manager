<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

/**
 * Lifecycle of a user demo project, stored in the project field demoStatus.
 */
enum SodaScsDemoProjectStatus: string {

  case Failed = 'failed';
  case Provisioning = 'provisioning';
  case Ready = 'ready';
  case Resetting = 'resetting';

  /**
   * Whether a queue job is creating or resetting the demo right now.
   */
  public function isBusy(): bool {
    return $this === self::Provisioning || $this === self::Resetting;
  }

}
