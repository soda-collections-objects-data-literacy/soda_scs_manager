<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives every approved platform user one demo project.
 *
 * Sign-in calls this. The project shell is created at once so onboarding can
 * point at the card; filling WissKI, JupyterHub and WebProtégé stays on the
 * demo queue and runs in the background.
 */
final class SodaScsAutomaticDemoProject {

  /**
   * Role of accounts that passed registration and may use the platform.
   */
  public const PLATFORM_ROLE = 'scs_user';

  /**
   * Constructs the service.
   */
  public function __construct(
    protected SodaScsDemoManager $demoManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Queues a demo when this account may use the platform and has none yet.
   *
   * A missing demo version or an existing demo is not an error. The next
   * sign-in tries again. A failure here never blocks sign-in.
   *
   * @return bool
   *   TRUE when a demo was just queued.
   */
  public function ensureFor(UserInterface $account): bool {
    if (!$account->hasRole(self::PLATFORM_ROLE)) {
      return FALSE;
    }

    try {
      return $this->demoManager->requestDemoProject($account)->success;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Could not create the automatic demo project for user @uid: @message', [
        '@uid' => $account->id(),
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
  }

}
