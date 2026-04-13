<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ListBuilder;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteMatchInterface;
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
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

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
    $instance->currentUser = $container->get('current_user');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->routeMatch = $container->get('current_route_match');
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

    if ($this->routeMatch->getRouteName() === 'soda_scs_manager.projects') {
      $build = ['projects_list_heading' => $this->buildProjectsListHeading()] + $build;
    }

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

  /**
   * Heading row for the user-facing projects page.
   *
   * Matches the dashboard "Your applications" + control layout.
   */
  private function buildProjectsListHeading(): array {
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'mb-8',
          'soda-scs-manager--dashboard-heading-with-add',
        ],
      ],
      '#cache' => [
        'contexts' => [
          'route',
          'user',
        ],
      ],
    ];

    $build['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Your projects'),
      '#attributes' => [
        'class' => [
          'soda-scs-manager--dashboard-heading-section',
          'soda-scs-manager--dashboard-heading-section--inline',
        ],
      ],
    ];

    $addUrl = Url::fromRoute('entity.soda_scs_project.add_form', ['bundle' => 'soda_scs_project']);
    if ($this->currentUser->hasPermission('create soda scs project') && $addUrl->access($this->currentUser)) {
      $build['add_link'] = Link::fromTextAndUrl(
        Markup::create('<span aria-hidden="true" class="soda-scs-manager--add-app-heading-plus">+</span>'),
        $addUrl,
      )->toRenderable();
      $build['add_link']['#attributes']['aria-label'] = (string) $this->t('Add new project');
      $build['add_link']['#attributes']['class'][] = 'soda-scs-manager--add-app-heading-button';
      $build['add_link']['#attributes']['title'] = (string) $this->t('Add new project');
    }

    return $build;
  }

}
