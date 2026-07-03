<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * Defines the access control handler for the soda scs stack entity type.
 *
 * phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
 *
 * @see https://www.drupal.org/project/coder/issues/3185082
 */
final class SodaScsStackAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if (SodaScsManagerAdminAccess::isAdmin($account, $this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $entity */
    switch ($operation) {
      case 'view':
        return AccessResult::allowedIfHasPermission($account, 'view soda scs stack')
          ->cachePerPermissions();

      case 'update':
      case 'delete':
        if ((int) $entity->getOwnerId() === (int) $account->id()) {
          return AccessResult::allowed()->cachePerUser()->addCacheableDependency($entity);
        }
        return AccessResult::forbidden()->cachePerUser()->addCacheableDependency($entity);

      default:
        return AccessResult::neutral();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermissions($account, ['create soda scs stack', 'administer soda scs component types'], 'OR');
  }

  /**
   * Determines access for the edit form.
   */
  public static function accessEditForm(SodaScsStackInterface $soda_scs_stack): AccessResultInterface {
    $account = \Drupal::currentUser();
    /** @var \Drupal\soda_scs_manager\Access\SodaScsStackAccessControlHandler $handler */
    $handler = \Drupal::entityTypeManager()->getAccessControlHandler('soda_scs_stack');
    return $handler->checkAccess($soda_scs_stack, 'update', $account);
  }

  /**
   * Determines access for the delete form.
   */
  public static function accessDeleteForm(SodaScsStackInterface $soda_scs_stack): AccessResultInterface {
    $account = \Drupal::currentUser();
    /** @var \Drupal\soda_scs_manager\Access\SodaScsStackAccessControlHandler $handler */
    $handler = \Drupal::entityTypeManager()->getAccessControlHandler('soda_scs_stack');
    return $handler->checkAccess($soda_scs_stack, 'delete', $account);
  }

  /**
   * Determines access for the snapshot form.
   */
  public static function accessSnapshotForm(SodaScsStackInterface $soda_scs_stack): AccessResultInterface {
    $account = \Drupal::currentUser();
    if (SodaScsManagerAdminAccess::isAdmin($account, 'administer soda scs stack entities')) {
      return AccessResult::allowed()->cachePerPermissions()->addCacheableDependency($soda_scs_stack);
    }
    if ((int) $soda_scs_stack->getOwnerId() === (int) $account->id()) {
      return AccessResult::allowed()->cachePerUser()->addCacheableDependency($soda_scs_stack);
    }
    return AccessResult::forbidden()->cachePerUser()->addCacheableDependency($soda_scs_stack);
  }

}
