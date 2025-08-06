<?php

namespace Drupal\soda_scs_manager\ListBuilder;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;

/**
 * Defines a class to build a listing of SODa SCS Component entities.
 *
 * @ingroup soda_scs_manager
 */
class SodaScsStackListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['type'] = $this->t('Type');
    $header['machineName'] = $this->t('Domain');
    $header['operations'] = $this->t('Operations');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity */
    $bundle = $entity->bundle();
    $row['type'] = $bundle;
    $row['machineName'] = $entity->get('machineName')->value;
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);

    // Update the URL for operations to include bundle.
    if (isset($operations['edit'])) {
      $operations['edit']['url'] = $this->ensureBundleParameter($operations['edit']['url'], $entity);
    }
    if (isset($operations['delete'])) {
      $operations['delete']['url'] = $this->ensureBundleParameter($operations['delete']['url'], $entity);
    }
    if (isset($operations['view'])) {
      $operations['view']['url'] = $this->ensureBundleParameter($operations['view']['url'], $entity);
    }

    return $operations;
  }

  /**
   * Ensures the bundle parameter is included in URLs.
   *
   * @param \Drupal\Core\Url $url
   *   The URL object.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\Url
   *   The URL with bundle parameter.
   */
  protected function ensureBundleParameter(Url $url, EntityInterface $entity) {
    $route_name = $url->getRouteName();
    $route_parameters = $url->getRouteParameters();

    // Add bundle parameter if the route requires it.
    if (strpos($route_name, 'entity.soda_scs_stack.') === 0 && !isset($route_parameters['bundle'])) {
      $route_parameters['bundle'] = $entity->bundle();
      $url = Url::fromRoute($route_name, $route_parameters);
    }

    return $url;
  }

}
