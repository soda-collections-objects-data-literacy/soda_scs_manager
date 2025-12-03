<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the soda scs project entity type.
 *
 * phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
 *
 * @see https://www.drupal.org/project/coder/issues/3185082
 */
final class SodaScsProjectAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $entity */
    switch ($operation) {
      case 'view':
        // Allow users to view projects they own or are members of.
        if ($entity->getOwnerId() == $account->id()) {
          return AccessResult::allowed()->cachePerUser()->addCacheableDependency($entity);
        }

        // Check if user is a member of the project.
        $members = $entity->get('members')->getValue();
        foreach ($members as $member) {
          if ($member['target_id'] == $account->id()) {
            return AccessResult::allowed()->cachePerUser()->addCacheableDependency($entity);
          }
        }

        return AccessResult::forbidden()->cachePerUser()->addCacheableDependency($entity);

      case 'update':
        // Only owners can edit projects.
        if ($entity->getOwnerId() == $account->id()) {
          return AccessResult::allowed()->cachePerUser()->addCacheableDependency($entity);
        }
        return AccessResult::forbidden()->cachePerUser()->addCacheableDependency($entity);
      case 'delete':
        // Only owners can edit or delete projects.
        if ($entity->getOwnerId() == $account->id()) {
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
    return AccessResult::allowedIfHasPermissions($account, ['create soda scs project', 'administer soda scs project entities'], 'OR');
  }

}
