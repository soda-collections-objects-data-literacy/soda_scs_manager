<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Defines the access control handler for the links tasks menu.
 *
 */
final class SodaScsStackLinksTasksAccessControlHandler {
/**
   * Determines access for the service link.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $soda_scs_stack
   *   The SODa SCS Stack entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   TRUE if access is allowed, FALSE otherwise.
   */
  public static function accessServiceLink(SodaScsStackInterface $soda_scs_stack) {
    return AccessResult::allowed();
  }

}
