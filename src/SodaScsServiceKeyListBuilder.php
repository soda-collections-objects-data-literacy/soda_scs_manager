<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;

/**
 * Defines a class to build a listing of SODa SCS Component entities.
 *
 * @ingroup soda_scs_manager
 */
class SodaScsServiceKeyListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['component'] = $this->t('Component');
    $header['bundle'] = $this->t('bundle');
    $header['owner'] = $this->t('Owner');
    $header['password'] = $this->t('Password');
    $header['operations'] = $this->t('Operations');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    // @todo Make it private to user?
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $entity */
    if ($entity->getOwnerId() === \Drupal::currentUser()->id() || \Drupal::currentUser()->hasPermission('soda scs manager admin')) {

      /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $scsComponentValues */
      $scsComponentValues = $entity->get('scsComponent');
      $referencedEntities = $scsComponentValues->referencedEntities();
      $links = [];
      foreach ($referencedEntities as $referencedEntity) {
        $links[] = Link::fromTextAndUrl(
        $referencedEntity->label(),
        Url::fromRoute('entity.soda_scs_component.canonical', ['soda_scs_component' => $referencedEntity->id()])
        )->toString();
      }

      // Concatenate the links with a comma separator.
      $linksString = implode(',</br>', $links);

      // Markup::create to ensure the HTML is not escaped.
      $row['scsComponent'] = Markup::create($linksString);
      $row['bundle'] = $entity->get('bundle')->target_id;
      $row['owner'] = $entity->getOwner()->getDisplayName();
      $row['servicePassword'] = [
        'data' => $entity->get('servicePassword')->value,
        'class' => ['soda-scs-manager--service-password'],
      ];
      return $row + parent::buildRow($entity);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['table']['#attached']['library'][] = 'soda_scs_manager/security';
    return $build;
  }

}
