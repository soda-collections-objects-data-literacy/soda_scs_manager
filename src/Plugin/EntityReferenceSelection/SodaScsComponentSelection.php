<?php

declare(strict_types=1);

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
    $handler_settings = $this->configuration['handler_settings'] ?? [];

    // If user has admin permission, don't restrict access unless specific owner filter is set.
    if ($current_user->hasPermission('soda scs manager admin') && empty($handler_settings['filter']['owner'])) {
      return;
    }

    // If a specific owner is set in the filter, use that instead of the current user.
    if (!empty($handler_settings['filter']['owner'])) {
      $uid = $handler_settings['filter']['owner'];
      $query->condition('owner', $uid);
      return;
    }

    $uid = (int) $current_user->id();

    // When restrict_to_owner is set (e.g. connectedComponents on project), non-admin
    // users only see their own components. Otherwise, owner OR project members.
    if (!empty($handler_settings['restrict_to_owner'])) {
      $query->condition('owner', $uid);
      return;
    }

    // Create an OR condition group: owner OR components in user's projects.
    $or_group = $query->orConditionGroup();
    $or_group->condition('owner', $uid);

    $project_query = \Drupal::entityQuery('soda_scs_project')
      ->accessCheck(TRUE);

    $project_or = $project_query->orConditionGroup()
      ->condition('owner', $uid)
      ->condition('members', $uid);

    $project_query->condition($project_or);
    $project_ids = $project_query->execute();

    if (!empty($project_ids)) {
      $or_group->condition('partOfProjects', $project_ids, 'IN');
    }

    $query->condition($or_group);
  }

}
