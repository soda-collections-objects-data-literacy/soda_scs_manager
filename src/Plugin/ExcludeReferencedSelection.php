<?php

namespace Drupal\soda_scs_manager\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;

/**
 * Reference selections that exclude already referenced entities.
 *
 * @EntityReferenceSelection(
 *   id = "default:exclude_referenced",
 *   label = @Translation("Exclude already referenced entities"),
 *   entity_types = {"soda_scs_component"},
 *   group = "default",
 *   weight = 1
 * )
 */
class ExcludeReferencedSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);

    // Add condition to exclude already referenced entities.
    $query->condition('referencedComponents', NULL, 'IS NULL');

    return $query;
  }

}
