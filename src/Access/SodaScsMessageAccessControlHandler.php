<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Entity\SodaScsMessageInterface;

/**
 * Access control handler for in-app messages.
 *
 * Recipients may view and delete their own inbox copies. Creating messages is
 * done by application code (invitations today; later also person/group send).
 */
final class SodaScsMessageAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if (!$entity instanceof SodaScsMessageInterface) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $recipient = $entity->getRecipient();
    $isRecipient = $recipient !== NULL && (int) $recipient->id() === (int) $account->id();

    return match ($operation) {
      'view', 'update', 'delete' => AccessResult::allowedIf($isRecipient)
        ->cachePerUser()
        ->addCacheableDependency($entity),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'soda scs manager user');
  }

}
