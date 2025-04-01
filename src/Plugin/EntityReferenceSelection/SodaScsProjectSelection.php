<?php

namespace Drupal\soda_scs_manager\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides specific access control for SCS projects.
 *
 * @EntityReferenceSelection(
 *   id = "soda_scs_project_access",
 *   label = @Translation("SCS Project Selection"),
 *   entity_types = {"soda_scs_project"},
 *   group = "soda_scs_project_access",
 *   weight = 0
 * )
 */
class SodaScsProjectSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'target_type' => 'soda_scs_project',
      'sort' => [
        'field' => 'label',
        'direction' => 'ASC',
      ],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Remove the target bundles selector if not needed.
    if (isset($form['target_bundles'])) {
      unset($form['target_bundles']);
    }

    return $form;
  }

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
    if ($current_user->hasPermission('administer soda scs project entities')) {
      return;
    }

    $uid = $current_user->id();

    // Create an OR condition group.
    $or_group = $query->orConditionGroup();

    // Projects where the user is the owner.
    $or_group->condition('owner', $uid);

    // Projects where the user is a member.
    $or_group->condition('members', $uid);

    // Add the OR group to the main query.
    $query->condition($or_group);
  }

  /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    $target_type = $this->configuration['target_type'];

    $query = $this->buildEntityQuery($match, $match_operator);
    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $result = $query->execute();

    if (empty($result)) {
      return [];
    }

    $options = [];
    $entities = $this->entityTypeManager->getStorage($target_type)->loadMultiple($result);

    // Use a single default group for all entities.
    $bundle_key = 'default';
    $options[$bundle_key] = [];

    foreach ($entities as $entity_id => $entity) {
      $options[$bundle_key][$entity_id] = $entity->label();
    }

    return $options;
  }

}
