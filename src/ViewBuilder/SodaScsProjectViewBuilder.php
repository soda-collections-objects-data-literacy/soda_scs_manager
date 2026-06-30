<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ViewBuilder;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\Registry;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * View builder for SODa SCS Project entities.
 */
final class SodaScsProjectViewBuilder extends EntityViewBuilder {

  use StringTranslationTrait;

  /**
   * Bundles that receive a canonical details link on dashboard cards.
   *
   * @var list<string>
   */
  private const DETAILS_LINK_BUNDLES = [
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
  private const CENTRAL_COMPONENT_BUNDLES = [
    'soda_scs_webprotege_component',
  ];

  /**
   * Stack bundles provisioned centrally (not shown on project pages).
   *
   * @var list<string>
   */
  private const CENTRAL_STACK_BUNDLES = [
    'soda_scs_jupyter_stack',
    'soda_scs_nextcloud_stack',
  ];

  /**
   * Constructs a SodaScsProjectViewBuilder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityRepositoryInterface $entity_repository,
    LanguageManagerInterface $language_manager,
    Registry $theme_registry,
    EntityDisplayRepositoryInterface $entity_display_repository,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SodaScsHelpers $sodaScsHelpers,
  ) {
    parent::__construct($entity_type, $entity_repository, $language_manager, $theme_registry, $entity_display_repository);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity.repository'),
      $container->get('language_manager'),
      $container->get('theme.registry'),
      $container->get('entity_display.repository'),
      $container->get('config.factory'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $build): array {
    $build = parent::build($build);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $build['#soda_scs_project'];

    foreach ([
      'connectedComponents',
      'created',
      'description',
      'groupId',
      'keycloakUuid',
      'label',
      'members',
      'note',
      'owner',
      'rights',
      'updated',
    ] as $fieldName) {
      if (isset($build[$fieldName])) {
        $build[$fieldName]['#access'] = FALSE;
      }
    }

    $build['#theme'] = 'soda_scs_project';
    $build['#label'] = $project->label();
    $build['#owner'] = $project->get('owner')->view('default');
    $build['#members'] = $this->buildMembersDisplay($project);
    $build['#note'] = $project->get('note')->isEmpty() ? NULL : $project->get('note')->view('default');
    $build['#application_cards'] = $this->buildApplicationCards($project);
    $build['#attributes'] = ['class' => ['container', 'scs-manager--project-view']];
    $build['#cache'] = [
      'tags' => $project->getCacheTags(),
      'contexts' => ['user', 'languages:language_interface'],
      'max-age' => 0,
    ];
    $build['#attached']['library'] = [
      'soda_scs_manager/globalStyling',
      'soda_scs_manager/dashboardHealthStatus',
    ];
    $build['#attached']['drupalSettings']['sodaScsManager'] = [
      'dashboardAdminMail' => (string) ($this->configFactory->get('system.site')->get('mail') ?? ''),
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode): array {
    $build = parent::getBuildDefaults($entity, $view_mode);
    $build['#theme'] = 'soda_scs_project';

    return $build;
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
  protected function buildApplicationCards(SodaScsProjectInterface $project): array {
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
   * Builds a single dashboard card render array for a stack.
   *
   * @return array<string, mixed>
   *   Entity card render array.
   */
  protected function buildStackCard(SodaScsStackInterface $stack): array {
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
   * Collects component IDs bundled inside project stacks.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[] $stacks
   *   Stacks linked to the project.
   *
   * @return array<int|string, int|string>
   *   Component IDs keyed by ID.
   */
  protected function collectStackIncludedComponentIds(array $stacks): array {
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
   * Whether a component is a centrally provisioned app (hidden on project pages).
   */
  protected function isCentralComponent(SodaScsComponentInterface $component): bool {
    return in_array($component->bundle(), self::CENTRAL_COMPONENT_BUNDLES, TRUE);
  }

  /**
   * Whether a stack is a centrally provisioned app (hidden on project pages).
   */
  protected function isCentralStack(SodaScsStackInterface $stack): bool {
    return in_array($stack->bundle(), self::CENTRAL_STACK_BUNDLES, TRUE);
  }

  /**
   * Loads stacks that belong to the given project.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[]
   *   Stacks keyed by entity ID.
   */
  protected function loadProjectStacks(SodaScsProjectInterface $project): array {
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

  /**
   * Builds the members field for the project info section.
   *
   * @return array<string, mixed>
   *   Members field render array.
   */
  protected function buildMembersDisplay(SodaScsProjectInterface $project): array {
    $membersField = $project->get('members');
    if (!$membersField->isEmpty() && $membersField->referencedEntities() !== []) {
      return $membersField->view('default');
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'field',
          'field--name-members',
          'field--type-entity-reference',
          'field--label-above',
        ],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['field__label']],
        '#value' => $this->t('Members'),
      ],
      'items' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['field__items']],
        'item' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['field__item']],
          '#value' => $this->t('No members'),
        ],
      ],
    ];
  }

  /**
   * Builds a single dashboard card render array for a component.
   *
   * @return array<string, mixed>
   *   Entity card render array.
   */
  protected function buildComponentCard(SodaScsComponentInterface $component): array {
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

}
