<?php

namespace Drupal\soda_scs_manager\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;

/**
 * Provides entity reference selection for users excluding anonymous.
 *
 * @EntityReferenceSelection(
 *   id = "default:user_reference",
 *   label = @Translation("User reference excluding anonymous"),
 *   entity_types = {"user"},
 *   group = "default",
 *   weight = 1
 * )
 */
class UserReferenceSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);
    $query->condition('uid', 0, '>');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance($container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('current_user'),
      $container->get('core.entity_field.manager'),
      $container->get('core.entity_type.bundle.info'),
      $container->get('core.entity_type.repository')
    );
  }

}
