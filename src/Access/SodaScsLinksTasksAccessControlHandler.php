<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Defines the access control handler for the links tasks menu.
 *
 */
final class SodaScsLinksTasksAccessControlHandler {
/**
   * Determines access for the service link.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $soda_scs_component
   *   The entity being checked.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   TRUE if access is allowed, FALSE otherwise.
   */
  public static function accessServiceLink(SodaScsComponentInterface $soda_scs_component) {
    // Replace 'triplestore_bundle' with the actual bundle ID for triplestore.
    if  ($soda_scs_component->bundle() == 'soda_scs_triplestore_component') {
      return AccessResult::forbidden();
    }
    return AccessResult::allowed();
  }

}