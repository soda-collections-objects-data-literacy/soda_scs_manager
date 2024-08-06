<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\soda_scs_manager\SodaScsApiActions;

/**
 * Defines a class to build a listing of SODa SCS Component entities.
 *
 * @ingroup soda_scs_manager
 */
class SodaScsComponentListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['type'] = $this->t('Type');
    $header['subdomain'] = $this->t('Domain');
    $header['status'] = $this->t('Status');
    $header['operations'] = $this->t('Operations');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $bundle = $entity->bundle();
    $action = 'status';
    $options = [
      'subdomain' => $entity->get('subdomain')->value,
      'componentId' => $entity->id(),
      'user' => $entity->get('user')->value,
    ];

    $row['type'] = $bundle;
    $row['subdomain'] = $entity->get('subdomain')->value;
    $row['status'] =\Drupal::service('soda_scs_manager.api.actions')->readComponent($entity->bundle(), $options);
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    // Add custom operations.
    $operations['create'] = [
      'title' => $this->t('Create'),
      'url' => Url::fromRoute('soda_scs_manager.service.action', ['soda_scs_component_id' => $entity->id(), 'action' => 'create']),
    ];
    $operations['start'] = [
      'title' => $this->t('Start'),
      'url' => Url::fromRoute('soda_scs_manager.service.action', ['soda_scs_component_id' => $entity->id(), 'action' => 'start']),
    ];
    $operations['stop'] = [
      'title' => $this->t('Stop'),
      'url' => Url::fromRoute('soda_scs_manager.service.action', ['soda_scs_component_id' => $entity->id(), 'action' => 'stop']),
    ];
    $operations['restart'] = [
      'title' => $this->t('Restart'),
      'url' => Url::fromRoute('soda_scs_manager.service.action', ['soda_scs_component_id' => $entity->id(), 'action' => 'restart']),
    ];
    $operations['delete'] = [
      'title' => $this->t('Delete'),
      'url' => Url::fromRoute('soda_scs_manager.service.action', ['soda_scs_component_id' => $entity->id(), 'action' => 'delete']),
    ];
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['table']['#prefix'] = Link::fromTextAndUrl($this->t('Add bundle'), Url::fromRoute('entity.soda_scs_component_bundle.add_form'))->toString();
    return $build;
  }


}
