<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;

/**
 * Defines the access control handler for the links tasks menu.
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
  public static function accessServiceLink(SodaScsComponentInterface $soda_scs_component): AccessResultInterface {
    $bundle = $soda_scs_component->bundle();

    // Hide the "Show"/service link tab for filesystem components.
    $result = ($bundle === 'soda_scs_filesystem_component')
      ? AccessResult::forbidden()
      : AccessResult::allowed();

    return $result->addCacheableDependency($soda_scs_component);
  }

  /**
   * Determines access for the edit form.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $soda_scs_component
   *   The SODa SCS Component entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   TRUE if access is allowed, FALSE otherwise.
   */
  public static function accessEditForm(SodaScsComponentInterface $soda_scs_component): AccessResultInterface {
    $bundle = $soda_scs_component->bundle();

    // Hide the edit task for specific component types.
    $hiddenBundles = ['soda_scs_webprotege_component'];
    $result = in_array($bundle, $hiddenBundles, TRUE)
      ? AccessResult::forbidden()
      : AccessResult::allowed();

    return $result->addCacheableDependency($soda_scs_component);
  }

  /**
   * Determines access for the delete form.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $soda_scs_component
   *   The SODa SCS Component entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   TRUE if access is allowed, FALSE otherwise.
   */
  public static function accessDeleteForm(SodaScsComponentInterface $soda_scs_component): AccessResultInterface {
    $bundle = $soda_scs_component->bundle();

    // Hide the delete task for specific component types.
    $hiddenBundles = ['soda_scs_webprotege_component'];
    $result = in_array($bundle, $hiddenBundles, TRUE)
      ? AccessResult::forbidden()
      : AccessResult::allowed();

    return $result->addCacheableDependency($soda_scs_component);
  }

  /**
   * Determines access for the snapshot form.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $soda_scs_component
   *   The SODa SCS Component entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   TRUE if access is allowed, FALSE otherwise.
   */
  public static function accessSnapshotForm(SodaScsComponentInterface $soda_scs_component): AccessResultInterface {
    $bundle = $soda_scs_component->bundle();

    // Hide the snapshot task for specific component types.
    $hiddenBundles = ['soda_scs_webprotege_component'];
    $result = in_array($bundle, $hiddenBundles, TRUE)
      ? AccessResult::forbidden()
      : AccessResult::allowed();

    return $result->addCacheableDependency($soda_scs_component);
  }

}
