<?php

namespace Drupal\soda_scs_manager\ListBuilder;

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a list builder for SODa SCS Snapshots.
 */
class SodaScsSnapshotListBuilder extends EntityListBuilder {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The redirect destination service.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $redirectDestination;

  /**
   * Constructs a new SnapshotListBuilder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entityType
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage class.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $redirectDestination
   *   The redirect destination service.
   */
  public function __construct(
    EntityTypeInterface $entityType,
    EntityStorageInterface $storage,
    DateFormatterInterface $dateFormatter,
    RedirectDestinationInterface $redirectDestination,
  ) {
    parent::__construct($entityType, $storage);
    $this->dateFormatter = $dateFormatter;
    $this->redirectDestination = $redirectDestination;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entityType) {
    return new static(
      $entityType,
      $container->get('entity_type.manager')->getStorage($entityType->id()),
      $container->get('date.formatter'),
      $container->get('redirect.destination')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header = [
      'id' => $this->t('ID'),
      'name' => $this->t('Name'),
      'component' => $this->t('Component'),
      'created' => $this->t('Created'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $entity */
    $row['id'] = $entity->id();
    $row['name'] = $entity->label();

    $referencedEntities = [];
    // Get the component entity if associated.
    if (!$entity->get('snapshotOfComponent')->isEmpty()) {
      $referencedEntities = array_merge($referencedEntities, $entity->snapshotOfComponent->referencedEntities());
    }

    $links = [];
    foreach ($referencedEntities as $referencedEntity) {
      $links[] = Link::fromTextAndUrl(
      $referencedEntity->id(),
      Url::fromRoute('entity.soda_scs_component.canonical', ['soda_scs_component' => $referencedEntity->id()])
      )->toString();
    }

    // Concatenate the links with a comma separator.
    $linksString = implode(', ', $links);
    $row['component'] = Markup::create($linksString);
    $row['created'] = $this->dateFormatter->format($entity->getCreatedTime(), 'short');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);

    // Add edit operation if missing
    if ($entity->hasLinkTemplate('edit-form') && !isset($operations['edit'])) {
      $operations['edit'] = [
        'title' => $this->t('Edit'),
        'weight' => 10,
        'url' => $entity->toUrl('edit-form'),
      ];
    }

    // Add delete operation if missing
    if ($entity->hasLinkTemplate('delete-form') && !isset($operations['delete'])) {
      $operations['delete'] = [
        'title' => $this->t('Delete'),
        'weight' => 100,
        'url' => $entity->toUrl('delete-form'),
      ];
    }

    $destination = $this->redirectDestination->getAsArray();
    foreach ($operations as $key => $operation) {
      $operations[$key]['query'] = $destination;
    }

    // Add custom operations if needed.
    if ($entity->access('view') && $entity->hasLinkTemplate('canonical')) {
      $operations['view'] = [
        'title' => $this->t('View'),
        'weight' => 0,
        'url' => $entity->toUrl('canonical'),
        'query' => $destination,
      ];
    }

    return $operations;
  }

}
