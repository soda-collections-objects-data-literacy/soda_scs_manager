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
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a class to build a listing of SODa SCS Snapshot entities.
 *
 * @ingroup soda_scs_manager
 */
class SodaScsSnapshotListBuilder extends EntityListBuilder {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->currentUser   = $container->get('current_user');
    // RedirectDestination is available from parent class.
    $instance->redirectDestination = $container->get('redirect.destination');
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

    if (!$this->currentUser->hasPermission('soda scs manager admin')) {
      $entityQuery->condition('owner', $this->currentUser->id());
    }

    $entityIds = $entityQuery->execute();

    return $this->storage->loadMultiple($entityIds);
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label']     = $this->t('Name');
    $header['id']        = $this->t('ID');
    $header['component'] = $this->t('Component');
    $header['owner']     = $this->t('Owner');
    $header['created']   = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $entity */
    if ($entity->getOwnerId() !== \Drupal::currentUser()->id() && !\Drupal::currentUser()->hasPermission('soda scs manager admin')) {
      return [];
    }

    // Create a link to the entity.
    $row['label'] = Link::fromTextAndUrl(
      $entity->label(),
      Url::fromRoute('entity.soda_scs_snapshot.canonical', [
        'soda_scs_snapshot' => $entity->id(),
      ])
    )->toString();

    // ID.
    $row['id'] = $entity->id();

    // Component references.
    $referencedEntities = [];
    if (!$entity->get('snapshotOfComponent')->isEmpty()) {
      $referencedEntities = array_merge($referencedEntities, $entity->snapshotOfComponent->referencedEntities());
    }

    $links = [];
    foreach ($referencedEntities as $referencedEntity) {
      $links[] = Link::fromTextAndUrl(
        $referencedEntity->label(),
        Url::fromRoute('entity.soda_scs_component.canonical', ['soda_scs_component' => $referencedEntity->id()])
      )->toString();
    }

    $row['component'] = !empty($links) ? Markup::create(implode(', ', $links)) : $this->t('None');

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

    // Created date.
    $row['created'] = $this->dateFormatter->format($entity->getCreatedTime(), 'short');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    $destination = $this->redirectDestination->getAsArray();

    // Add redirect destination to all operations.
    foreach ($operations as $key => $operation) {
      $operations[$key]['query'] = $destination;
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();

    // Add empty message.
    $build['table']['#empty'] = $this->t('No snapshots available.');

    // Add pager.
    $build['pager'] = [
      '#type' => 'pager',
    ];

    // Add custom styling.
    $build['table']['#attributes']['class'][] = 'soda-scs-table-list';

    // Disable caching.
    $build['#cache']['max-age'] = 0;

    return $build;
  }

}
