<?php

namespace Drupal\soda_scs_manager\ComputedField;

use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TypedData\TraversableTypedDataInterface;

/**
 * Computed field that provides the SCS components referencing this project.
 */
class ScsComponentsReferenceField extends EntityReferenceFieldItemList {

  use ComputedItemListTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance($definition, $name = NULL, ?TraversableTypedDataInterface $parent = NULL) {
    $instance = parent::createInstance($definition, $name, $parent);
    $instance->entityTypeManager = \Drupal::service('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    $entity = $this->getEntity();

    // Only compute if we have an entity with an ID.
    if ($entity && !$entity->isNew()) {
      // Query for components that reference this project.
      $query = $this->entityTypeManager->getStorage('soda_scs_component')->getQuery()
        ->condition('partOfProject', $entity->id())
        ->accessCheck(TRUE);
      $component_ids = $query->execute();

      // Add each component as a reference.
      $delta = 0;
      foreach ($component_ids as $component_id) {
        $this->list[$delta] = $this->createItem($delta, ['target_id' => $component_id]);
        $delta++;
      }
    }
  }
}
