<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Entity\SodaScsConversationInterface;

/**
 * Access to conversations between a user and the admin team.
 *
 * Admins see every conversation and may reply and delete. The user of a
 * conversation may view it and reply. Everyone else is denied.
 */
final class SodaScsConversationAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if (!$entity instanceof SodaScsConversationInterface) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $isParticipant = $account->isAuthenticated()
      && $entity->getParticipantId() === (int) $account->id();

    return match ($operation) {
      'view', 'reply' => AccessResult::allowedIf($isParticipant)
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity),
      default => AccessResult::forbidden()->cachePerPermissions(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'soda scs manager admin');
  }

}
