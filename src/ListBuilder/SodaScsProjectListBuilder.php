<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ListBuilder;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
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
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Component bundles counted in the project list Applications column.
   *
   * @var list<string>
   */
  private const APPLICATION_COMPONENT_BUNDLES = [
    'soda_scs_sql_component',
    'soda_scs_wisski_component',
  ];

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
      ->sort('label', 'ASC')
      ->pager(10);

    if (!$this->currentUser->hasPermission('soda scs manager admin')) {
      $currentUserId = (int) $this->currentUser->id();

      // Show projects where the current user is owner OR member.
      $accessGroup = $entityQuery->orConditionGroup()
        ->condition('owner', $currentUserId)
        ->condition('members', $currentUserId);

      $entityQuery->condition($accessGroup);
    }

    $entityIds = $entityQuery->execute();

    return $this->storage->loadMultiple($entityIds);
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label']        = $this->t('Name');
    $header['id']           = $this->t('ID');
    $header['owner']        = $this->t('Owner');
    $header['members']      = $this->t('Members');
    $header['applications'] = $this->t('Applications');
    $header['created']      = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $entity */
    $currentUserId = (int) $this->currentUser->id();
    $isAdmin = $this->currentUser->hasPermission('soda scs manager admin');

    if (!$isAdmin) {
      $isOwner = (int) $entity->getOwnerId() === $currentUserId;

      $isMember = FALSE;
      if ($entity->hasField('members') && !$entity->get('members')->isEmpty()) {
        foreach ($entity->get('members')->getValue() as $memberItem) {
          if ((int) ($memberItem['target_id'] ?? 0) === $currentUserId) {
            $isMember = TRUE;
            break;
          }
        }
      }

      // Skip rows where the user is neither owner nor member.
      if (!$isOwner && !$isMember) {
        return [];
      }
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

    // WissKI + SQL only; full component list is on the project page.
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $scsComponentValues */
    $scsComponentValues = $entity->get('connectedComponents');
    $applicationCount = 0;
    if (!$scsComponentValues->isEmpty()) {
      foreach ($scsComponentValues->referencedEntities() as $referencedComponent) {
        $bundle = $referencedComponent->bundle();
        if (in_array($bundle, self::APPLICATION_COMPONENT_BUNDLES, TRUE)) {
          $applicationCount++;
        }
      }
    }

    $row['applications'] = $applicationCount > 0
      ? $this->formatPlural($applicationCount, '1', '@count')
      : $this->t('None');

    // Created date.
    $created = $entity->get('created')->value;
    $row['created'] = $this->dateFormatter->format($created, 'short');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $entity */
    $operations = parent::getDefaultOperations($entity);

    // Ensure view operation is always available for members and owners.
    $currentUserId = (int) $this->currentUser->id();
    $isAdmin = $this->currentUser->hasPermission('soda scs manager admin');
    $isOwner = (int) $entity->getOwnerId() === $currentUserId;

    $isMember = FALSE;
    if ($entity->hasField('members') && !$entity->get('members')->isEmpty()) {
      foreach ($entity->get('members')->getValue() as $memberItem) {
        if ((int) ($memberItem['target_id'] ?? 0) === $currentUserId) {
          $isMember = TRUE;
          break;
        }
      }
    }

    // If user is admin, owner, or member, ensure view operation exists.
    if (($isAdmin || $isOwner || $isMember) && !isset($operations['view'])) {
      $operations['view'] = [
        'title' => $this->t('View'),
        'weight' => 0,
        'url' => Url::fromRoute('entity.soda_scs_project.canonical', [
          'soda_scs_project' => $entity->id(),
        ]),
      ];
    }
    // Only members (not owners or admins) can leave.
    if ($isMember && !$isOwner && !$isAdmin) {
      $operations['leave'] = [
        'title' => $this->t('Leave'),
        'weight' => 50,
        'url' => Url::fromRoute('entity.soda_scs_project.leave_form', [
          'soda_scs_project' => $entity->id(),
        ]),
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOperations(EntityInterface $entity) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $entity */
    $operations = parent::buildOperations($entity);

    // Add "Leave Project" operation for members (not owners).
    $currentUserId = (int) $this->currentUser->id();
    $isAdmin = $this->currentUser->hasPermission('soda scs manager admin');
    $isOwner = (int) $entity->getOwnerId() === $currentUserId;

    $isMember = FALSE;
    if ($entity->hasField('members') && !$entity->get('members')->isEmpty()) {
      foreach ($entity->get('members')->getValue() as $memberItem) {
        if ((int) ($memberItem['target_id'] ?? 0) === $currentUserId) {
          $isMember = TRUE;
          break;
        }
      }
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
