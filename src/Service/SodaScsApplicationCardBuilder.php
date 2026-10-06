<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsHealthStatus;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsLifecycleHelpers;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds dashboard-style entity cards for projects and central services.
 */
final class SodaScsApplicationCardBuilder {

  use StringTranslationTrait;

  /**
   * Component bundles provisioned per user (dashboard + project pages).
   *
   * @var list<string>
   */
  public const CENTRAL_COMPONENT_BUNDLES = [
    'soda_scs_webprotege_component',
  ];

  /**
   * Stack bundles provisioned per user (dashboard + project pages).
   *
   * On project pages the service link opens the project's Team Folder
   * (JupyterLab / Nextcloud Files). The Nextcloud card also shows the
   * Team Folder activity feed from scs_manager_integration.
   *
   * @var list<string>
   */
  public const CENTRAL_STACK_BUNDLES = [
    'soda_scs_jupyter_stack',
    'soda_scs_nextcloud_stack',
  ];

  /**
   * Tags omitted from filter UI (aligned with entity card template).
   *
   * @var list<string>
   */
  private const TAGS_HIDDEN_FROM_UI = [
    'data-science',
    'publishing',
  ];

  /**
   * Constructs a SodaScsApplicationCardBuilder.
   */
  public function __construct(
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    #[Autowire(service: 'soda_scs_manager.helpers')]
    protected SodaScsHelpers $sodaScsHelpers,
    #[Autowire(service: 'soda_scs_manager.lifecycle.helpers')]
    protected SodaScsLifecycleHelpers $lifecycleHelpers,
    #[Autowire(service: 'soda_scs_manager.nextcloud.preview')]
    protected SodaScsNextcloudPreview $nextcloudPreview,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds dashboard-style cards for applications linked to the project.
   *
   * The current user's central services (JupyterHub, Nextcloud, WebProtégé)
   * are shown first, with project-scoped deep links. Stacks linked to the
   * project follow; their included components are omitted so bundled apps
   * (e.g. WissKI + SQL + triplestore) appear once.
   *
   * @return list<array<string, mixed>>
   *   Render arrays for entity cards.
   */
  public function buildForProject(SodaScsProjectInterface $project): array {
    return array_merge(
      $this->buildCentralServiceCardsForUser($this->currentUser->id(), $project),
      $this->buildProjectOwnedApplicationCards($project),
    );
  }

  /**
   * Builds compact application summaries for a project dashboard card.
   *
   * Central services are omitted here; they belong on the project page.
   *
   * @return list<array<string, mixed>>
   *   Each item has label, bundle_label, health_status, is_online, url,
   *   entity_id, entity_type_id, and tags.
   */
  public function buildApplicationSummariesForProject(SodaScsProjectInterface $project): array {
    $summaries = [];

    foreach ($this->buildProjectOwnedApplicationCards($project) as $card) {
      $healthStatus = SodaScsHealthStatus::fromStatus((string) ($card['#health_status'] ?? ''));
      $url = $card['#url'] ?? NULL;
      if ($url instanceof Url) {
        $url = $url->toString();
      }

      $summaries[] = [
        'label' => $card['#title'] ?? '',
        'bundle_label' => $card['#bundle_label'] ?? '',
        'health_status' => $healthStatus,
        'is_online' => $this->isHealthOnline($healthStatus),
        'url' => $url,
        'entity_id' => $card['#entity_id'] ?? NULL,
        'entity_type_id' => $card['#entity_type_id'] ?? NULL,
        'tags' => is_array($card['#tags'] ?? NULL) ? $card['#tags'] : [],
      ];
    }

    return $summaries;
  }

  /**
   * Builds cards for applications that belong to the project (not per-user apps).
   *
   * @return list<array<string, mixed>>
   *   Render arrays for entity cards.
   */
  private function buildProjectOwnedApplicationCards(SodaScsProjectInterface $project): array {
    $cards = [];
    $stacks = $this->loadProjectStacks($project);
    $stackIncludedComponentIds = $this->collectStackIncludedComponentIds($stacks);

    foreach ($stacks as $stack) {
      if ($this->isCentralStack($stack)) {
        continue;
      }
      $cards[] = $this->buildStackCard($stack);
    }

    $connectedComponents = $project->get('connectedComponents');
    if ($connectedComponents->isEmpty()) {
      return $cards;
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
    foreach ($connectedComponents->referencedEntities() as $component) {
      if ($this->isCentralComponent($component)) {
        continue;
      }
      if (isset($stackIncludedComponentIds[$component->id()])) {
        continue;
      }
      $cards[] = $this->buildComponentCard($component);
    }

    return $cards;
  }

  /**
   * Builds cards for centrally provisioned apps owned by the given user.
   *
   * @param int|string $uid
   *   User ID.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface|null $project
   *   When set, JupyterHub, Nextcloud, and WebProtégé cards deep-link into this project.
   *
   * @return list<array<string, mixed>>
   *   Render arrays for entity cards.
   */
  public function buildCentralServiceCardsForUser(int|string $uid, ?SodaScsProjectInterface $project = NULL): array {
    $cards = [];

    $stackIds = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->getQuery()
      ->condition('owner', $uid)
      ->condition('bundle', self::CENTRAL_STACK_BUNDLES, 'IN')
      ->accessCheck(TRUE)
      ->sort('label')
      ->execute();

    if (!empty($stackIds)) {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[] $stacks */
      $stacks = $this->entityTypeManager
        ->getStorage('soda_scs_stack')
        ->loadMultiple($stackIds);
      foreach ($stacks as $stack) {
        $cards[] = $this->buildStackCard($stack, $project);
      }
    }

    $componentIds = $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->getQuery()
      ->condition('owner', $uid)
      ->condition('bundle', self::CENTRAL_COMPONENT_BUNDLES, 'IN')
      ->accessCheck(TRUE)
      ->sort('label')
      ->execute();

    if (!empty($componentIds)) {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface[] $components */
      $components = $this->entityTypeManager
        ->getStorage('soda_scs_component')
        ->loadMultiple($componentIds);
      foreach ($components as $component) {
        $cards[] = $this->buildComponentCard($component, $project);
      }
    }

    return $cards;
  }

  /**
   * Collects unique filterable tags from application card render arrays.
   *
   * @param list<array<string, mixed>> $cards
   *   Entity card render arrays.
   *
   * @return list<string>
   *   Sorted tag machine names.
   */
  public function collectFilterTags(array $cards): array {
    $tags = [];

    foreach ($cards as $card) {
      if (empty($card['#tags']) || !is_array($card['#tags'])) {
        continue;
      }

      foreach ($card['#tags'] as $tag) {
        if (!is_string($tag) || $tag === '') {
          continue;
        }
        if (in_array($tag, self::TAGS_HIDDEN_FROM_UI, TRUE)) {
          continue;
        }
        $tags[$tag] = $tag;
      }
    }

    $sortedTags = array_values($tags);
    sort($sortedTags);

    return $sortedTags;
  }

  /**
   * Builds a single dashboard card render array for a stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The stack entity.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface|null $project
   *   Optional project context for JupyterHub / Nextcloud deep links.
   *
   * @return array<string, mixed>
   *   Entity card render array.
   */
  public function buildStackCard(SodaScsStackInterface $stack, ?SodaScsProjectInterface $project = NULL): array {
    $bundleInfo = $this->bundleInfo->getBundleInfo($stack->getEntityTypeId())[$stack->bundle()];

    $detailsLink = Url::fromRoute(
      'entity.' . $stack->getEntityTypeId() . '.canonical',
      [
        'bundle' => $stack->bundle(),
        $stack->getEntityTypeId() => $stack->id(),
      ],
    );

    $urlOptions = [];
    if ($project !== NULL) {
      $urlOptions['query']['project'] = $project->id();
    }

    $cacheTags = $stack->getCacheTags();
    if ($project !== NULL) {
      $cacheTags = array_merge($cacheTags, $project->getCacheTags());
    }

    $feedStatus = NULL;
    $activities = NULL;
    if ($stack->bundle() === 'soda_scs_nextcloud_stack' && $project !== NULL) {
      $feed = $this->buildNextcloudProjectFeed($project);
      $feedStatus = $feed['status'];
      $activities = $feed['activities'];
    }

    $card = [
      '#theme' => 'soda_scs_manager__entity_card',
      '#title' => $this->cardTitle($stack->bundle(), (string) $stack->label()),
      '#bundle_label' => $bundleInfo['label'],
      '#bundle_id' => $stack->bundle(),
      '#description' => $bundleInfo['description'],
      '#details_link' => $detailsLink,
      '#entity_id' => $stack->id(),
      '#entity_type_id' => $stack->getEntityTypeId(),
      '#health_status' => SodaScsHealthStatus::fromStatus((string) ($stack->get('health')->value ?? '')),
      '#imageUrl' => $bundleInfo['imageUrl'],
      '#learn_more_link' => $this->sodaScsHelpers->internalPathUrl(
        'soda-scs-manager/app/' . $this->sodaScsHelpers->getEntityType($stack->bundle()),
      ),
      '#url' => Url::fromRoute('soda_scs_manager.stack.service_link', [
        'soda_scs_stack' => $stack->id(),
      ], $urlOptions),
      '#tags' => $bundleInfo['tags'],
      '#feed_status' => $feedStatus,
      '#activities' => $activities,
      '#can_manage_lifecycle' => $this->lifecycleHelpers->currentUserCanManage($stack),
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['languages:language_interface', 'user'],
        'tags' => $cacheTags,
      ],
    ];

    if ($feedStatus === 'needs_connect') {
      $card['#attached']['library'][] = 'soda_scs_manager/nextcloudConnect';
      _soda_scs_manager_attach_nextcloud_connect_settings($card);
    }

    return $card;
  }

  /**
   * Loads the project Team Folder activity feed for the current user.
   *
   * @return array{status: string, activities: array{status: string, items: list<array<string, mixed>>}}
   *   Preview-shaped feed payload.
   */
  private function buildNextcloudProjectFeed(SodaScsProjectInterface $project): array {
    $empty = [
      'status' => 'needs_connect',
      'activities' => [
        'status' => 'empty',
        'items' => [],
      ],
    ];

    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if (!$user instanceof UserInterface) {
      return $empty;
    }

    $preview = $this->nextcloudPreview->buildProjectFeedData(
      $user,
      (string) $project->id(),
      (string) $project->label(),
    );

    return [
      'status' => (string) ($preview['status'] ?? 'needs_connect'),
      'activities' => is_array($preview['activities'] ?? NULL) ? $preview['activities'] : $empty['activities'],
    ];
  }

  /**
   * Builds a single dashboard card render array for a component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component entity.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface|null $project
   *   Optional project context for WebProtégé deep links.
   *
   * @return array<string, mixed>
   *   Entity card render array.
   */
  public function buildComponentCard(SodaScsComponentInterface $component, ?SodaScsProjectInterface $project = NULL): array {
    $bundleInfo = $this->bundleInfo->getBundleInfo($component->getEntityTypeId())[$component->bundle()];

    $detailsLink = Url::fromRoute(
      'entity.' . $component->getEntityTypeId() . '.canonical',
      [
        'bundle' => $component->bundle(),
        $component->getEntityTypeId() => $component->id(),
      ],
    );

    $urlOptions = [];
    if ($project !== NULL && $component->bundle() === 'soda_scs_webprotege_component') {
      $urlOptions['query']['project'] = $project->id();
    }

    $cacheTags = $component->getCacheTags();
    if ($project !== NULL) {
      $cacheTags = array_merge($cacheTags, $project->getCacheTags());
    }

    return [
      '#theme' => 'soda_scs_manager__entity_card',
      '#title' => $this->cardTitle($component->bundle(), (string) $component->label()),
      '#bundle_label' => $bundleInfo['label'],
      '#bundle_id' => $component->bundle(),
      '#description' => $bundleInfo['description'],
      '#details_link' => $detailsLink,
      '#entity_id' => $component->id(),
      '#entity_type_id' => $component->getEntityTypeId(),
      '#health_status' => SodaScsHealthStatus::fromStatus((string) ($component->get('health')->value ?? '')),
      '#imageUrl' => $bundleInfo['imageUrl'],
      '#learn_more_link' => $this->sodaScsHelpers->internalPathUrl(
        'soda-scs-manager/app/' . $this->sodaScsHelpers->getEntityType($component->bundle()),
      ),
      '#url' => Url::fromRoute('soda_scs_manager.component.service_link', [
        'soda_scs_component' => $component->id(),
      ], $urlOptions),
      '#tags' => $bundleInfo['tags'],
      '#can_manage_lifecycle' => $this->lifecycleHelpers->currentUserCanManage($component),
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['languages:language_interface', 'user'],
        'tags' => $cacheTags,
      ],
    ];
  }

  /**
   * Card heading: product name for shared apps, instance label otherwise.
   */
  private function cardTitle(string $bundle, string $entityLabel): string|TranslatableMarkup {
    return match ($bundle) {
      'soda_scs_jupyter_stack' => $this->t('JupyterHub'),
      'soda_scs_nextcloud_stack' => $this->t('Nextcloud'),
      'soda_scs_webprotege_component' => $this->t('WebProtégé'),
      default => $entityLabel,
    };
  }

  /**
   * Whether a health status value means the application is online.
   */
  public function isHealthOnline(string $healthStatus): bool {
    return SodaScsHealthStatus::isRunning($healthStatus);
  }

  /**
   * Whether a component is a centrally provisioned app.
   */
  public function isCentralComponent(SodaScsComponentInterface $component): bool {
    return in_array($component->bundle(), self::CENTRAL_COMPONENT_BUNDLES, TRUE);
  }

  /**
   * Whether a stack is a centrally provisioned app.
   */
  public function isCentralStack(SodaScsStackInterface $stack): bool {
    return in_array($stack->bundle(), self::CENTRAL_STACK_BUNDLES, TRUE);
  }

  /**
   * Collects component IDs bundled inside project stacks.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[] $stacks
   *   Stacks linked to the project.
   *
   * @return array<int|string, int|string>
   *   Component IDs keyed by ID.
   */
  private function collectStackIncludedComponentIds(array $stacks): array {
    $componentIds = [];

    foreach ($stacks as $stack) {
      if ($this->isCentralStack($stack)) {
        continue;
      }
      if (!$stack->hasField('includedComponents') || $stack->get('includedComponents')->isEmpty()) {
        continue;
      }

      foreach ($stack->get('includedComponents')->referencedEntities() as $component) {
        $componentIds[$component->id()] = $component->id();
      }
    }

    return $componentIds;
  }

  /**
   * Loads stacks that belong to the given project.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[]
   *   Stacks keyed by entity ID.
   */
  private function loadProjectStacks(SodaScsProjectInterface $project): array {
    $stackIds = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->getQuery()
      ->condition('partOfProjects', $project->id())
      ->accessCheck(TRUE)
      ->sort('label')
      ->execute();

    if (empty($stackIds)) {
      return [];
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[] $stacks */
    $stacks = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->loadMultiple($stackIds);

    return $stacks;
  }

}
