<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;

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

  /**
   * Determines access for the edit form.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $sodaScsProject
   *   The SODa SCS Project entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function accessEditForm(SodaScsProjectInterface $soda_scs_project): AccessResultInterface {
    $account = \Drupal::currentUser();
    /** @var \Drupal\soda_scs_manager\Access\SodaScsProjectAccessControlHandler $handler */
    $handler = \Drupal::entityTypeManager()->getAccessControlHandler('soda_scs_project');
    return $handler->checkAccess($soda_scs_project, 'update', $account);
  }

  /**
   * Determines access for the delete form.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $sodaScsProject
   *   The SODa SCS Project entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function accessDeleteForm(SodaScsProjectInterface $soda_scs_project): AccessResultInterface {
    $account = \Drupal::currentUser();
    /** @var \Drupal\soda_scs_manager\Access\SodaScsProjectAccessControlHandler $handler */
    $handler = \Drupal::entityTypeManager()->getAccessControlHandler('soda_scs_project');
    return $handler->checkAccess($soda_scs_project, 'delete', $account);
  }

  /**
   * Determines access for the leave form.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $sodaScsProject
   *   The SODa SCS Project entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function accessLeaveForm(SodaScsProjectInterface $soda_scs_project): AccessResultInterface {
    $account = \Drupal::currentUser();
    $accountId = (int) $account->id();

    // Admins cannot leave projects (they have full access anyway).
    if ($account->hasPermission('administer soda scs project entities')) {
      return AccessResult::forbidden()->addCacheableDependency($soda_scs_project);
    }

    // Owners cannot leave their own project.
    if ((int) $soda_scs_project->getOwnerId() === $accountId) {
      return AccessResult::forbidden()->addCacheableDependency($soda_scs_project);
    }

    // Check if user is a member.
    $isMember = FALSE;
    if ($soda_scs_project->hasField('members') && !$soda_scs_project->get('members')->isEmpty()) {
      foreach ($soda_scs_project->get('members')->getValue() as $memberItem) {
        if ((int) ($memberItem['target_id'] ?? 0) === $accountId) {
          $isMember = TRUE;
          break;
        }
      }
    }

    // Only members (not owners) can leave.
    return $isMember
      ? AccessResult::allowed()->addCacheableDependency($soda_scs_project)
      : AccessResult::forbidden()->addCacheableDependency($soda_scs_project);
  }

}
