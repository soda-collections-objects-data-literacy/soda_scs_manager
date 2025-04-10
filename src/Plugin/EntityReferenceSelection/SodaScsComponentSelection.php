<?php

namespace Drupal\soda_scs_manager\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\Entity\Query\QueryInterface;

/**
 * Provides specific access control for SCS components.
 *
 * @EntityReferenceSelection(
 *   id = "soda_scs_component_access",
 *   label = @Translation("SCS Component Selection"),
 *   entity_types = {"soda_scs_component"},
 *   group = "soda_scs_component_access",
 *   weight = 0
 * )
 */
class SodaScsComponentSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);
    $this->addAccessConditions($query);
    return $query;
  }

  /**
   * Adds access conditions to the query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query to modify.
   */
  protected function addAccessConditions(QueryInterface $query) {
    $current_user = \Drupal::currentUser();

    // If user has admin permission, don't restrict access.
    if ($current_user->hasPermission('administer soda scs component entities')) {
      return;
    }

    $uid = $current_user->id();

    // Create an OR condition group.
    $or_group = $query->orConditionGroup();

    // Components where the user is the owner.
    $or_group->condition('owner', $uid);

    // Components in projects where the user is owner or member.
    $project_query = \Drupal::entityQuery('soda_scs_project')
      ->accessCheck(TRUE)
      ->condition('status', 1);

    $project_or = $project_query->orConditionGroup()
      ->condition('owner', $uid)
      ->condition('members', $uid);

    $project_query->condition($project_or);
    $project_ids = $project_query->execute();

    if (!empty($project_ids)) {
      // Add condition for components that belong to these projects.
      $or_group->condition('project', $project_ids, 'IN');
    }

    // Add the OR group to the main query.
    $query->condition($or_group);
  }

}
