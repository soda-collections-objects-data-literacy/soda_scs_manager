<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for the Service Key entity type.
 *
 * @see \Drupal\soda_scs_manager\Entity\SodaScsServiceKey
 *
 * @ingroup soda_scs_manager
 */
final class SodaScsServiceKeyAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $entity */
    switch ($operation) {
      case 'view':
        // Allow users to view service keys they own.
        if ($entity->getOwnerId() == $account->id()) {
          return AccessResult::allowed()->cachePerUser()->addCacheableDependency($entity);
        }

        return AccessResult::allowedIfHasPermission($account, 'view soda scs service key')
          ->cachePerUser()
          ->addCacheableDependency($entity);

      case 'update':
        // Allow owners to edit their own service keys.
        if ($entity->getOwnerId() == $account->id()) {
          return AccessResult::allowed()->cachePerUser()->addCacheableDependency($entity);
        }

        return AccessResult::allowedIfHasPermission($account, 'edit soda scs service key')
          ->cachePerUser()
          ->addCacheableDependency($entity);

      case 'delete':
        // Allow owners to delete their own service keys.
        if ($entity->getOwnerId() == $account->id()) {
          return AccessResult::allowed()->cachePerUser()->addCacheableDependency($entity);
        }

        return AccessResult::allowedIfHasPermission($account, 'delete soda scs service key')
          ->cachePerUser()
          ->addCacheableDependency($entity);

      default:
        return AccessResult::neutral();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermissions($account, ['create soda scs service key', 'administer soda scs service key entities'], 'OR');
  }

}
