<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds dashboard-style entity cards for project applications.
 */
final class SodaScsApplicationCardBuilder {

  use StringTranslationTrait;

  /**
   * Component bundles that run on a shared instance, one per project.
   *
   * Their service link needs the project context (WebProtégé opens the
   * project's ontology project).
   *
   * @var list<string>
   */
  public const PROJECT_SERVICE_COMPONENT_BUNDLES = [
    'soda_scs_webprotege_component',
  ];

  /**
   * Stack bundles that run on a shared instance, one per project.
   *
   * Their service link needs the project context (JupyterLab opens the
   * project's Team Folder in the viewer's notebook).
   *
   * @var list<string>
   */
  public const PROJECT_SERVICE_STACK_BUNDLES = [
    'soda_scs_jupyter_stack',
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
    #[Autowire(service: 'soda_scs_manager.helpers')]
    protected SodaScsHelpers $sodaScsHelpers,
    #[Autowire(service: 'soda_scs_manager.lifecycle.helpers')]
    protected SodaScsLifecycleHelpers $lifecycleHelpers,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds dashboard-style cards for applications linked to the project.
   *
   * Only applications that belong to the project are shown. Included
   * components of project stacks are omitted so bundled apps (e.g. WissKI +
   * SQL + triplestore) appear once. Project files (Nextcloud Team Folder)
   * are shown in the files block, not as a card.
   *
   * @return list<array<string, mixed>>
   *   Render arrays for entity cards.
   */
  public function buildForProject(SodaScsProjectInterface $project): array {
    return $this->buildProjectOwnedApplicationCards($project);
  }

  /**
   * Builds compact application summaries for a project dashboard card.
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
   * Builds cards for applications that belong to the project.
   *
   * @return list<array<string, mixed>>
   *   Render arrays for entity cards.
   */
  private function buildProjectOwnedApplicationCards(SodaScsProjectInterface $project): array {
    $cards = [];
    $stacks = $this->loadProjectStacks($project);
    $stackIncludedComponentIds = $this->collectStackIncludedComponentIds($stacks);

    foreach ($stacks as $stack) {
      $cards[] = $this->buildStackCard($stack, $this->isProjectServiceStack($stack) ? $project : NULL);
    }

    $connectedComponents = $project->get('connectedComponents');
    if ($connectedComponents->isEmpty()) {
      return $cards;
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
    foreach ($connectedComponents->referencedEntities() as $component) {
      if (isset($stackIncludedComponentIds[$component->id()])) {
        continue;
      }
      $cards[] = $this->buildComponentCard($component, $this->isProjectServiceComponent($component) ? $project : NULL);
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
   *   Optional project context for JupyterHub deep links.
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

    return [
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
      '#can_manage_lifecycle' => $this->lifecycleHelpers->currentUserCanManage($stack),
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['languages:language_interface', 'user'],
        'tags' => $cacheTags,
      ],
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
  public function buildComponentCard(SodaScsComponentInterface $component, ?SodaScsProjectInterface $project = NULL, bool $showDetailsLink = TRUE): array {
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
      '#details_link' => $showDetailsLink ? $detailsLink : NULL,
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
   * Whether a component runs on a shared instance, one per project.
   */
  public function isProjectServiceComponent(SodaScsComponentInterface $component): bool {
    return in_array($component->bundle(), self::PROJECT_SERVICE_COMPONENT_BUNDLES, TRUE);
  }

  /**
   * Whether a stack runs on a shared instance, one per project.
   */
  public function isProjectServiceStack(SodaScsStackInterface $stack): bool {
    return in_array($stack->bundle(), self::PROJECT_SERVICE_STACK_BUNDLES, TRUE);
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
