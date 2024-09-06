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

}
