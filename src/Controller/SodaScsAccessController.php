<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKey;

/**
 * Controller that manages access to SCS manager routes.
 */
class SodaScsAccessController {

  /**
   * For accessing service key routes.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account that preformed the request.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsServiceKey $sodaScsServiceKey
   *   The Service key that the user is trying to access.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Wether or not the user is allowed to access the route.
   */
  public function accessServiceKey(AccountInterface $account, SodaScsServiceKey $sodaScsServiceKey): AccessResult {
    /** @var \Drupal\user\Entity\User */
    $user = $sodaScsServiceKey->get('user')->entity;
    return AccessResult::allowedIf($account->getAccountName() == $user->getAccountName());
  }

}
