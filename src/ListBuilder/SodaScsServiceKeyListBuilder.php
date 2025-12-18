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
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a class to build a listing of SODa SCS Service Key entities.
 *
 * @ingroup soda_scs_manager
 */
class SodaScsServiceKeyListBuilder extends EntityListBuilder {

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
      ->sort('type', 'ASC')
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
    $header['component'] = $this->t('Used by Component(s)');
    $header['owner']     = $this->t('Owner');
    $header['type']      = $this->t('Type');
    $header['password']  = $this->t('Password');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $entity */
    if ($entity->getOwnerId() !== \Drupal::currentUser()->id() && !\Drupal::currentUser()->hasPermission('soda scs manager admin')) {
      return [];
    }

    // Create a link to the entity.
    $row['label'] = Link::fromTextAndUrl(
      $entity->label(),
      Url::fromRoute('entity.soda_scs_service_key.canonical', [
        'soda_scs_service_key' => $entity->id(),
      ])
    )->toString();

    // ID.
    $row['id'] = $entity->id();

    // Component references.
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
      $row['owner'] = $this->t('Unknown (ID: @id)', ['@id' => $entity->getOwnerId()]);
    }

    // Type formatted.
    $row['type'] = $this->formatKeyType($entity->get('type')->value);

    // Password with special styling.
    $row['password'] = [
      'data' => [
        '#markup' => '<code class="soda-scs-manager--service-password">' . $entity->get('servicePassword')->value . '</code>',
      ],
    ];

    return $row + parent::buildRow($entity);
  }

  /**
   * Formats the key type to be more human-readable.
   *
   * @param string $type
   *   The type machine name.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The formatted type.
   */
  protected function formatKeyType(string $type): TranslatableMarkup|string {
    $typeMap = [
      'sql'        => $this->t('SQL'),
      'triplestore' => $this->t('Triplestore'),
      'webprotege' => $this->t('WebProtégé'),
    ];

    return $typeMap[$type] ?? $type;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOperations(EntityInterface $entity) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $entity */
    $operations = parent::buildOperations($entity);

    // Ensure delete operation exists.
    if (!isset($operations['#links']['delete'])) {
      $operations['#links']['delete'] = [
        'title' => $this->t('Delete'),
        'weight' => 100,
        'url' => Url::fromRoute('entity.soda_scs_service_key.delete_form', ['soda_scs_service_key' => $entity->id()]),
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();

    // Add security library for password handling.
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
