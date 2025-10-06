<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ComputedField;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\soda_scs_manager\Entity\SodaScsProject;

/**
 * Computed item list for the project groupId field.
 */
class SodaScsGroupIdComputedItemList extends FieldItemList {
  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    $entity = $this->getEntity();
    if (!$entity) {
      return;
    }
    $entityId = $entity->id();
    if ($entityId === NULL) {
      return;
    }
    $computedGroupId = (int) $entityId + (int) SodaScsProject::GROUP_ID_START;
    $this->list[0] = $this->createItem(0, $computedGroupId);
  }

}
