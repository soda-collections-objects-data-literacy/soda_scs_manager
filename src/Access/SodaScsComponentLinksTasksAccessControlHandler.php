<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Defines the access control handler for the links tasks menu.
 *
 */
final class SodaScsComponentLinksTasksAccessControlHandler {
/**
   * Determines access for the service link.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $soda_scs_component
   *   The SODa SCS Component entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   TRUE if access is allowed, FALSE otherwise.
   */
  public static function accessServiceLink(SodaScsComponentInterface $soda_scs_component) {
    return AccessResult::allowed();
  }

}
