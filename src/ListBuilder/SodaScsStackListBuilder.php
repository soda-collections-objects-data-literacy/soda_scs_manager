<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ListBuilder;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a class to build a listing of SODa SCS Stack entities.
 *
 * @ingroup soda_scs_manager
 */
class SodaScsStackListBuilder extends EntityListBuilder {

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
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function load() {
    $entityQuery = $this->storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('owner', 'ASC')
      ->sort('bundle', 'ASC')
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
    $header['label']       = $this->t('Name');
    $header['id']          = $this->t('ID');
    $header['type']        = $this->t('Type');
    $header['machineName'] = $this->t('Domain');
    $header['owner']       = $this->t('Owner');
    $header['created']     = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $entity */
    if ($entity->getOwnerId() !== \Drupal::currentUser()->id() && !\Drupal::currentUser()->hasPermission('soda scs manager admin')) {
      return [];
    }

    $bundle = $entity->bundle();

    // Create a link to the entity.
    $row['label'] = Link::fromTextAndUrl(
      $entity->label(),
      Url::fromRoute('entity.soda_scs_stack.canonical', [
        'soda_scs_stack' => $entity->id(),
      ])
    )->toString();

    // ID.
    $row['id'] = $entity->id();

    // Format the bundle type to be more readable.
    $row['type'] = $this->formatBundleType($bundle);

    // Machine name / Domain.
    $row['machineName'] = [
      'data' => [
        '#markup' => '<code>' . $entity->get('machineName')->value . '</code>',
      ],
    ];

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
    $created = $entity->get('created')->value;
    $row['created'] = $this->dateFormatter->format($created, 'short');

    return $row + parent::buildRow($entity);
  }

  /**
   * Formats the bundle type to be more human-readable.
   *
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The formatted bundle type.
   */
  protected function formatBundleType(string $bundle): TranslatableMarkup|string {
    $typeMap = [
      'soda_scs_stack' => $this->t('Stack'),
    ];

    return $typeMap[$bundle] ?? $bundle;
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
  protected function ensureBundleParameter(Url $url, EntityInterface $entity): Url {
    $routeName = $url->getRouteName();
    $routeParameters = $url->getRouteParameters();

    // Add bundle parameter if the route requires it.
    if (strpos($routeName, 'entity.soda_scs_stack.') === 0 && !isset($routeParameters['bundle'])) {
      $routeParameters['bundle'] = $entity->bundle();
      $url = Url::fromRoute($routeName, $routeParameters);
    }

    return $url;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();

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
