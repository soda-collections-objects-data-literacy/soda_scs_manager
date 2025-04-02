<?php

namespace Drupal\soda_scs_manager\ListBuilder;

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
class SnapshotListBuilder extends EntityListBuilder {

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

    // Get the component entity if associated.
    $componentEntities = $entity->snapshotOfComponent->referencedEntities();

    foreach ($componentEntities as $componentEntity) {
      $componentLinks[] = $componentEntity->toUrl();
    }

    if ($componentLinks) {
      $row['component'] = $componentLinks;
    }
    else {
      $row['component'] = $this->t('N/A');
    }
    $row['created'] = $this->dateFormatter->format($entity->getCreatedTime(), 'short');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);

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
