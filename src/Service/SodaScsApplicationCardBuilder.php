<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds dashboard-style entity cards for projects and central services.
 */
final class SodaScsApplicationCardBuilder {

  use StringTranslationTrait;

  /**
   * Bundles that receive a canonical details link on dashboard cards.
   *
   * @var list<string>
   */
  private const DETAILS_LINK_BUNDLES = [
    'soda_scs_nextcloud_stack',
    'soda_scs_sql_component',
    'soda_scs_triplestore_component',
    'soda_scs_wisski_component',
    'soda_scs_wisski_stack',
  ];

  /**
   * Component bundles provisioned centrally (not shown on project pages).
   *
   * @var list<string>
   */
  public const CENTRAL_COMPONENT_BUNDLES = [
    'soda_scs_webprotege_component',
  ];

  /**
   * Stack bundles provisioned centrally (not shown on project pages).
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
    #[Autowire(service: 'soda_scs_manager.helpers')]
    protected SodaScsHelpers $sodaScsHelpers,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds dashboard-style cards for applications linked to the project.
   *
   * Stacks linked to the project are shown as cards; their included components
   * are omitted so bundled apps (e.g. WissKI + SQL + triplestore) appear once.
   *
   * @return list<array<string, mixed>>
   *   Render arrays for entity cards.
   */
  public function buildForProject(SodaScsProjectInterface $project): array {
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
   * Builds compact application summaries for a project dashboard card.
   *
   * @return list<array<string, mixed>>
   *   Each item has label, bundle_label, health_status, is_online, url,
   *   entity_id, entity_type_id, and tags.
   */
  public function buildApplicationSummariesForProject(SodaScsProjectInterface $project): array {
    $summaries = [];

    foreach ($this->buildForProject($project) as $card) {
      $healthStatus = (string) ($card['#health_status'] ?? 'Unknown');
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
   * Builds cards for centrally provisioned apps owned by the given user.
   *
   * @param int|string $uid
   *   User ID.
   *
   * @return list<array<string, mixed>>
   *   Render arrays for entity cards.
   */
  public function buildCentralServiceCardsForUser(int|string $uid): array {
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
        $cards[] = $this->buildStackCard($stack);
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
        $cards[] = $this->buildComponentCard($component);
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
   * @return array<string, mixed>
   *   Entity card render array.
   */
  public function buildStackCard(SodaScsStackInterface $stack): array {
    $bundleInfo = $this->bundleInfo->getBundleInfo($stack->getEntityTypeId())[$stack->bundle()];

    $detailsLink = NULL;
    if (in_array($stack->bundle(), self::DETAILS_LINK_BUNDLES, TRUE)) {
      $detailsLink = Url::fromRoute(
        'entity.' . $stack->getEntityTypeId() . '.canonical',
        [
          'bundle' => $stack->bundle(),
          $stack->getEntityTypeId() => $stack->id(),
        ],
      );
    }

    return [
      '#theme' => 'soda_scs_manager__entity_card',
      '#title' => $this->t('@bundle', ['@bundle' => $stack->label()]),
      '#bundle_label' => $bundleInfo['label'],
      '#description' => $bundleInfo['description'],
      '#details_link' => $detailsLink,
      '#entity_id' => $stack->id(),
      '#entity_type_id' => $stack->getEntityTypeId(),
      '#health_status' => $stack->get('health')->value ?? 'Unknown',
      '#imageUrl' => $bundleInfo['imageUrl'],
      '#learn_more_link' => $this->sodaScsHelpers->internalPathUrl(
        'soda-scs-manager/app/' . $this->sodaScsHelpers->getEntityType($stack->bundle()),
      ),
      '#url' => Url::fromRoute('soda_scs_manager.stack.service_link', [
        'soda_scs_stack' => $stack->id(),
      ]),
      '#tags' => $bundleInfo['tags'],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['languages:language_interface'],
        'tags' => $stack->getCacheTags(),
      ],
    ];
  }

  /**
   * Builds a single dashboard card render array for a component.
   *
   * @return array<string, mixed>
   *   Entity card render array.
   */
  public function buildComponentCard(SodaScsComponentInterface $component): array {
    $bundleInfo = $this->bundleInfo->getBundleInfo($component->getEntityTypeId())[$component->bundle()];

    $detailsLink = NULL;
    if (in_array($component->bundle(), self::DETAILS_LINK_BUNDLES, TRUE)) {
      $detailsLink = Url::fromRoute(
        'entity.' . $component->getEntityTypeId() . '.canonical',
        [
          'bundle' => $component->bundle(),
          $component->getEntityTypeId() => $component->id(),
        ],
      );
    }

    return [
      '#theme' => 'soda_scs_manager__entity_card',
      '#title' => $this->t('@bundle', ['@bundle' => $component->label()]),
      '#bundle_label' => $bundleInfo['label'],
      '#description' => $bundleInfo['description'],
      '#details_link' => $detailsLink,
      '#entity_id' => $component->id(),
      '#entity_type_id' => $component->getEntityTypeId(),
      '#health_status' => $component->get('health')->value ?? 'Unknown',
      '#imageUrl' => $bundleInfo['imageUrl'],
      '#learn_more_link' => $this->sodaScsHelpers->internalPathUrl(
        'soda-scs-manager/app/' . $this->sodaScsHelpers->getEntityType($component->bundle()),
      ),
      '#url' => Url::fromRoute('soda_scs_manager.component.service_link', [
        'soda_scs_component' => $component->id(),
      ]),
      '#tags' => $bundleInfo['tags'],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['languages:language_interface'],
        'tags' => $component->getCacheTags(),
      ],
    ];
  }

  /**
   * Whether a health status value means the application is online.
   */
  public function isHealthOnline(string $healthStatus): bool {
    $normalized = strtolower($healthStatus);
    return in_array($normalized, ['running', 'healthy'], TRUE);
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
