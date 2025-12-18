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
 * Defines a class to build a listing of SODa SCS Component entities.
 *
 * @ingroup soda_scs_manager
 */
class SodaScsComponentListBuilder extends EntityListBuilder {

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
      ->sort('label', 'ASC');

    if (!$this->currentUser->hasPermission('soda scs manager admin')) {
      $entityQuery->condition('owner', $this->currentUser->id());
    }

    $entityQuery->pager(10);

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
    $header['machineName'] = $this->t('Machine Name');
    $header['owner']       = $this->t('Owner');
    $header['health']      = $this->t('Health Status');
    $header['created']     = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity */
    $bundle = $entity->bundle();

    // Create a link to the entity.
    $row['label'] = Link::fromTextAndUrl(
      $entity->label(),
      Url::fromRoute('entity.soda_scs_component.canonical', [
        'soda_scs_component' => $entity->id(),
      ])
    )->toString();

    // ID.
    $row['id'] = $entity->id();

    // Format the bundle type to be more readable.
    $row['type'] = $this->formatBundleType($bundle);

    // Machine name.
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

    // Health status - will be populated by JavaScript.
    // @todo Implement health status.
    $row['health'] = [
      'data' => [
        '#markup' => '<span class="component-health-status health-pending">(not yet implemented)</span>'
      ],
    ];

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
      'soda_scs_filesystem_component'  => $this->t('Inter-App folder'),
      'soda_scs_sql_component' => $this->t('SQL Database'),
      'soda_scs_triplestore_component' => $this->t('Triplestore'),
      'soda_scs_webprotege_component'  => $this->t('WebProtégé'),
      'soda_scs_wisski_component'  => $this->t('WissKI'),
    ];

    return $typeMap[$bundle] ?? $bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();

    // Add custom styling and health status JavaScript.
    $build['table']['#attributes']['class'][] = 'soda-scs-table-list';
    $build['table']['#attached']['library'][] = 'soda_scs_manager/componentListHealthStatus';

    // Add data attributes to each row for JavaScript.
    if (isset($build['table']['#rows'])) {
      foreach ($this->load() as $entity) {
        $entityId = $entity->id();
        if (isset($build['table']['#rows'][$entityId])) {
          $build['table']['#rows'][$entityId]['#attributes']['data-component-id'] = $entityId;
          $build['table']['#rows'][$entityId]['#attributes']['data-component-bundle'] = $entity->bundle();
        }
      }
    }

    // Add pager.
    $build['pager'] = [
      '#type' => 'pager',
    ];

    // Disable caching.
    $build['#cache']['max-age'] = 0;

    return $build;
  }

}
