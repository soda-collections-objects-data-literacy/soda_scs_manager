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

    return match($operation) {
      'create' => AccessResult::allowedIfHasPermission($account, 'create soda scs service key'),
      'view' => AccessResult::allowedIfHasPermission($account, 'view soda scs service key'),
      'update' => AccessResult::allowedIfHasPermission($account, 'edit soda scs service key'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete soda scs service key'),
      default => AccessResult::neutral(),
    };
  }

}
