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
class SodaScsServiceKeyListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['id'] = $this->t('Id');
    $header['label'] = $this->t('Label');
    $header['component'] = $this->t('Used by component(s)');
    $header['owner'] = $this->t('Owner');
    $header['type'] = $this->t('Type');
    $header['password'] = $this->t('Password');
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
        $referencedEntity->id(),
        Url::fromRoute('entity.soda_scs_component.canonical', ['soda_scs_component' => $referencedEntity->id()])
        )->toString();
      }

      // Concatenate the links with a comma separator.
      $linksString = implode(', ', $links);

      // Markup::create to ensure the HTML is not escaped.
      $row['id'] = $entity->id();
      $row['label'] = $entity->label();
      $row['scsComponent'] = Markup::create($linksString);
      $row['owner'] = $entity->getOwner()->getDisplayName();
      $row['type'] = $entity->get('type')->value;
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
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $entity */
    $operations = parent::buildOperations($entity);

    $operations['#links']['delete'] = [
      'title' => $this->t('Delete'),
      'weight' => 100,
      'url' => Url::fromRoute('entity.soda_scs_service_key.delete_form', ['soda_scs_service_key' => $entity->id()]),
    ];

    // Only show the renew operation for SQL components.
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $referencedComponentsList */
    #$referencedComponentsList = $entity->get('scsComponent');
    #$referencedComponentsEntities = $referencedComponentsList->referencedEntities();
    #$referencedComponentsEntity = $referencedComponentsEntities[0];
    #if ($referencedComponentsEntity->bundle() === 'soda_scs_sql_component') {
    #  $operations['#links']['renew'] = [
    #    'title' => $this->t('Renew'),
    #    'weight' => 100,
    #    'url' => Url::fromRoute('entity.soda_scs_service_key.renew_confirm_form', ['soda_scs_service_key' => $entity->id()]),
    #  ];
    #}

    return $operations;
  }

}
