<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;

/**
 * Defines the access control handler for the links tasks menu.
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
  public static function accessServiceLink(SodaScsStackInterface $soda_scs_stack): AccessResultInterface {
    return AccessResult::allowed();
  }

  /**
   * Determines access for the edit form.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $soda_scs_stack
   *   The SODa SCS Stack entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   TRUE if access is allowed, FALSE otherwise.
   */
  public static function accessEditForm(SodaScsStackInterface $soda_scs_stack): AccessResultInterface {
    $bundle = $soda_scs_stack->bundle();

    // Hide the edit task for specific stack types.
    $hiddenBundles = ['soda_scs_nextcloud_stack', 'soda_scs_jupyter_stack'];
    $result = in_array($bundle, $hiddenBundles, TRUE)
      ? AccessResult::forbidden()
      : AccessResult::allowed();

    return $result->addCacheableDependency($soda_scs_stack);
  }

  /**
   * Determines access for the delete form.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $soda_scs_stack
   *   The SODa SCS Stack entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   TRUE if access is allowed, FALSE otherwise.
   */
  public static function accessDeleteForm(SodaScsStackInterface $soda_scs_stack): AccessResultInterface {
    $bundle = $soda_scs_stack->bundle();

    // Hide the delete task for specific stack types.
    $hiddenBundles = ['soda_scs_nextcloud_stack', 'soda_scs_jupyter_stack'];
    $result = in_array($bundle, $hiddenBundles, TRUE)
      ? AccessResult::forbidden()
      : AccessResult::allowed();

    return $result->addCacheableDependency($soda_scs_stack);
  }

  /**
   * Determines access for the snapshot form.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $soda_scs_stack
   *   The SODa SCS Stack entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   TRUE if access is allowed, FALSE otherwise.
   */
  public static function accessSnapshotForm(SodaScsStackInterface $soda_scs_stack): AccessResultInterface {
    $bundle = $soda_scs_stack->bundle();

    // Hide the snapshot task for specific stack types.
    $hiddenBundles = ['soda_scs_nextcloud_stack', 'soda_scs_jupyter_stack'];
    $result = in_array($bundle, $hiddenBundles, TRUE)
      ? AccessResult::forbidden()
      : AccessResult::allowed();

    return $result->addCacheableDependency($soda_scs_stack);
  }

}
