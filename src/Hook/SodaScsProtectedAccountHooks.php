<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\soda_scs_manager\Helpers\SodaScsProtectedAccounts;
use Drupal\user\UserInterface;

/**
 * Stops the cancellation of protected accounts.
 */
final class SodaScsProtectedAccountHooks {

  /**
   * Implements hook_user_cancel().
   *
   * Core runs this hook for the block methods before the batch starts, and
   * other modules act in it (openid_connect removes the SSO link), so it
   * runs first and throws. Paths without an access check, such as
   * drush user:cancel, then fail before anything changes; the delete method
   * is stopped in userPredelete().
   *
   * @param array $edit
   *   The cancel form values.
   * @param \Drupal\user\UserInterface $account
   *   The account being cancelled.
   * @param string $method
   *   The cancel method.
   *
   * @throws \LogicException
   *   For protected accounts.
   */
  #[Hook('user_cancel', order: Order::First)]
  public function userCancel(array $edit, UserInterface $account, string $method): void {
    if (SodaScsProtectedAccounts::isProtected($account)) {
      throw new \LogicException(sprintf('Account %s is protected and cannot be cancelled.', $account->getAccountName()));
    }
  }

  /**
   * Implements hook_user_predelete().
   *
   * Runs before other modules remove content of the account; the exception
   * rolls back the delete.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account being deleted.
   *
   * @throws \LogicException
   *   For protected accounts.
   */
  #[Hook('user_predelete', order: Order::First)]
  public function userPredelete(UserInterface $account): void {
    if (SodaScsProtectedAccounts::isProtected($account)) {
      throw new \LogicException(sprintf('Account %s is protected and cannot be deleted.', $account->getAccountName()));
    }
  }

}
