<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProtectedAccounts;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives every approved platform user one demo project.
 *
 * OpenID Connect sign-in calls this once the access token is in the session.
 * The project shell is created at once so onboarding can point at the card;
 * filling WissKI, JupyterHub and WebProtégé stays on the demo queue and runs
 * in the background.
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
    protected SodaScsNextcloudHelpers $nextcloudHelpers,
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
    if (!$account->hasRole(self::PLATFORM_ROLE) || SodaScsProtectedAccounts::isProtected($account)) {
      return FALSE;
    }

    try {
      // Only a sign-in that will queue a demo pays for the Nextcloud calls.
      if ($this->demoManager->activeTemplate() !== NULL && $this->demoManager->findDemoProject($account) === NULL) {
        $this->prepareNextcloudCredentials($account);
      }
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

  /**
   * Stores the user's Nextcloud app password while their token is at hand.
   *
   * The demo queue runs in a background drush process without the user's
   * session, so it can only use stored credentials; a new user has none until
   * this runs. Without Bearer, credentials come only from the Login Flow the
   * user starts. A failure is logged and never blocks sign-in.
   */
  protected function prepareNextcloudCredentials(UserInterface $account): void {
    if (!$this->nextcloudHelpers->isBearerEnabled()) {
      return;
    }
    try {
      $credentials = $this->nextcloudHelpers->ensureCredentials($account);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not prepare the Nextcloud credentials of user @uid for the demo: @message', [
        '@uid' => $account->id(),
        '@message' => $exception->getMessage(),
      ]);
      return;
    }
    if ($credentials === NULL) {
      $this->logger->warning('No Nextcloud credentials for user @uid at sign-in, so the demo WissKI cannot mount Nextcloud.', [
        '@uid' => $account->id(),
      ]);
    }
  }

}
