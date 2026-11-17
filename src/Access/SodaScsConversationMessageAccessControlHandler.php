<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Entity\SodaScsConversationMessageInterface;

/**
 * Access to single messages of a conversation.
 *
 * Viewing follows the conversation. Only the author edits a message; the
 * author and admins (moderation) delete it. A deleted message can be neither
 * edited nor deleted again.
 */
final class SodaScsConversationMessageAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof SodaScsConversationMessageInterface) {
      return AccessResult::forbidden()->cachePerPermissions();
    }
    $conversation = $entity->getConversation();
    if ($conversation === NULL) {
      return AccessResult::forbidden()->addCacheableDependency($entity);
    }

    $canView = $conversation->access('view', $account, TRUE);
    if ($operation === 'view') {
      return $canView->addCacheableDependency($entity);
    }

    $isAdmin = $account->hasPermission('soda scs manager admin');
    $isAuthor = $account->isAuthenticated() && $entity->getAuthorId() === (int) $account->id();
    $isOpen = !$entity->isDeleted();

    $result = match ($operation) {
      'update' => AccessResult::allowedIf($isAuthor && $isOpen),
      'delete' => AccessResult::allowedIf(($isAuthor || $isAdmin) && $isOpen),
      default => AccessResult::forbidden(),
    };
    return $result
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheableDependency($entity)
      ->andIf($canView);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    // Messages are created through SodaScsConversationHelpers, which checks
    // the 'reply' access of the conversation.
    return AccessResult::allowedIfHasPermission($account, 'soda scs manager user');
  }

}
