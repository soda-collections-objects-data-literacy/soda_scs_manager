<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\Core\Session\AccountInterface;

/**
 * Shared admin bypass for SCS Manager owner-based access checks.
 */
final class SodaScsManagerAdminAccess {

  /**
   * Permission used for SCS Manager operators across the module.
   */
  public const SCS_MANAGER_ADMIN_PERMISSION = 'soda scs manager admin';

  /**
   * Whether the account bypasses owner-only restrictions.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param mixed $entityAdminPermission
   *   Optional entity-specific administer permission (Drupal may return false).
   */
  public static function isAdmin(AccountInterface $account, mixed $entityAdminPermission = NULL): bool {
    if ($account->hasPermission(self::SCS_MANAGER_ADMIN_PERMISSION)) {
      return TRUE;
    }

    return is_string($entityAdminPermission)
      && $entityAdminPermission !== ''
      && $account->hasPermission($entityAdminPermission);
  }

}
