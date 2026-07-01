<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;

/**
 * Defines the access control handler for the soda scs snapshot entity type.
 *
 * phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
 *
 * @see https://www.drupal.org/project/coder/issues/3185082
 */
final class SodaScsSnapshotAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if (SodaScsManagerAdminAccess::isAdmin($account, $this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $entity */
    switch ($operation) {
      case 'view':
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
    return AccessResult::allowedIfHasPermission($account, 'create soda scs snapshot');
  }

}
