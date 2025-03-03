<?php

namespace Drupal\soda_scs_manager\ListBuilder;

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
class SodaScsProjectListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['name'] = $this->t('Name');
    $header['machineName'] = $this->t('Machine name');
    $header['owner'] = $this->t('Owner');
    $header['members'] = $this->t('Members');
    $header['components'] = $this->t('Components');
    $header['rights'] = $this->t('Rights');
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
      $row['name'] = $entity->label();
      $row['machineName'] = $entity->get('machineName')->value;
      $row['owner'] = $entity->getOwner()->getDisplayName();
      $row['members'] = $entity->get('members')->value;
      $row['components'] = Markup::create($linksString);
      $row['rights'] = $entity->get('rights')->value;
      return $row + parent::buildRow($entity);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['table']['#attached']['library'][] = 'soda_scs_manager/security';
    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Why I have to do this, this should be in the parent class
   */
  public function buildOperations(EntityInterface $entity) {
    $operations = parent::buildOperations($entity);

    $operations['#links']['delete'] = [
      'title' => $this->t('Delete'),
      'weight' => 100,
      'url' => Url::fromRoute('entity.soda_scs_project.delete_form', ['soda_scs_project' => $entity->id()]),
    ];

    return $operations;
  }

}
