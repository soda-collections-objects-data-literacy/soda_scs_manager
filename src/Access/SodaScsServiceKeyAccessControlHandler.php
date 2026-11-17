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
      case 'update':
      case 'delete':
        // A key holds a service password or token: only its owner (and the
        // admins above) may see or change it, whatever the role permissions.
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
    return AccessResult::allowedIfHasPermissions($account, ['create soda scs service key', 'soda scs manager admin'], 'OR');
  }

}
