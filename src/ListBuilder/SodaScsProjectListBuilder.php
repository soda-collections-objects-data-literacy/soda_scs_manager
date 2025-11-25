<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ListBuilder;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a class to build a listing of SODa SCS Project entities.
 *
 * @ingroup soda_scs_manager
 */
class SodaScsProjectListBuilder extends EntityListBuilder {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function load() {
    $entityQuery = $this->storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('owner', 'ASC')
      ->sort('label', 'ASC')
      ->pager(10);

    $entityIds = $entityQuery->execute();

    return $this->storage->loadMultiple($entityIds);
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label']      = $this->t('Name');
    $header['id']         = $this->t('ID');
    $header['owner']      = $this->t('Owner');
    $header['members']    = $this->t('Members');
    $header['components'] = $this->t('Components');
    $header['created']    = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $entity */
    if ($entity->getOwnerId() !== \Drupal::currentUser()->id() && !\Drupal::currentUser()->hasPermission('soda scs manager admin')) {
      return [];
    }

    // Create a link to the entity.
    $row['label'] = Link::fromTextAndUrl(
      $entity->label(),
      Url::fromRoute('entity.soda_scs_project.canonical', [
        'soda_scs_project' => $entity->id(),
      ])
    )->toString();

    // ID.
    $row['id'] = $entity->id();

    // Owner information.
    $owner = $entity->getOwner();
    if ($owner && $owner->id()) {
      $row['owner'] = Link::fromTextAndUrl(
        $owner->getDisplayName(),
        Url::fromRoute('entity.user.canonical', ['user' => $owner->id()])
      )->toString();
    }
    else {
      $row['owner'] = $this->t('Unknown');
    }

    // Members references.
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $members */
    $members = $entity->get('members');
    $referencedMembers = $members->referencedEntities();
    $memberLinks = [];

    /** @var \Drupal\user\Entity\User $referencedMember */
    foreach ($referencedMembers as $referencedMember) {
      $memberLinks[] = Link::fromTextAndUrl(
        $referencedMember->getDisplayName(),
        Url::fromRoute('entity.user.canonical', ['user' => $referencedMember->id()])
      )->toString();
    }

    $row['members'] = !empty($memberLinks) ? Markup::create(implode(', ', $memberLinks)) : $this->t('None');

    // Component references.
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $scsComponentValues */
    $scsComponentValues = $entity->get('connectedComponents');
    $referencedEntities = $scsComponentValues->referencedEntities();
    $componentLinks = [];

    foreach ($referencedEntities as $referencedEntity) {
      $componentLinks[] = Link::fromTextAndUrl(
        $referencedEntity->label(),
        Url::fromRoute('entity.soda_scs_component.canonical', ['soda_scs_component' => $referencedEntity->id()])
      )->toString();
    }

    $row['components'] = !empty($componentLinks) ? Markup::create(implode(', ', $componentLinks)) : $this->t('None');

    // Created date.
    $created = $entity->get('created')->value;
    $row['created'] = $this->dateFormatter->format($created, 'short');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function buildOperations(EntityInterface $entity) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $entity */
    $operations = parent::buildOperations($entity);

    // Ensure delete operation exists.
    if (!isset($operations['#links']['delete'])) {
      $operations['#links']['delete'] = [
        'title' => $this->t('Delete'),
        'weight' => 100,
        'url' => Url::fromRoute('entity.soda_scs_project.delete_form', ['soda_scs_project' => $entity->id()]),
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();

    // Add security library.
    $build['table']['#attached']['library'][] = 'soda_scs_manager/security';

    // Add custom styling.
    $build['table']['#attributes']['class'][] = 'soda-scs-table-list';

    // Add pager.
    $build['pager'] = [
      '#type' => 'pager',
    ];

    // Disable caching.
    $build['#cache']['max-age'] = 0;

    return $build;
  }

}
