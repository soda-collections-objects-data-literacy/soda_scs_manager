<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Session\AccountInterface;

/**
 * Accounts that must never be deleted or offboarded.
 *
 * The account with uid 1 administers the site; scs_demo owns the demo source
 * project, which every user demo is built from.
 */
final class SodaScsProtectedAccounts {

  /**
   * Account name of the service account that owns the demo source project.
   */
  public const DEMO_ACCOUNT_NAME = 'scs_demo';

  /**
   * Whether the account is a protected system account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return bool
   *   TRUE for uid 1 and the demo service account.
   */
  public static function isProtected(AccountInterface $account): bool {
    if ((int) $account->id() === 1) {
      return TRUE;
    }
    return (int) $account->id() > 1 && $account->getAccountName() === self::DEMO_ACCOUNT_NAME;
  }

}
