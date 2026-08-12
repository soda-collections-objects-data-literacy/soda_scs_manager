<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ViewBuilder;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\Registry;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Service\SodaScsApplicationCardBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * View builder for SODa SCS Project entities.
 */
final class SodaScsProjectViewBuilder extends EntityViewBuilder {

  use StringTranslationTrait;

  /**
   * Constructs a SodaScsProjectViewBuilder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityRepositoryInterface $entity_repository,
    LanguageManagerInterface $language_manager,
    Registry $theme_registry,
    EntityDisplayRepositoryInterface $entity_display_repository,
    protected AccountProxyInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    ModuleHandlerInterface $module_handler,
    protected SodaScsApplicationCardBuilder $applicationCardBuilder,
  ) {
    parent::__construct($entity_type, $entity_repository, $language_manager, $theme_registry, $entity_display_repository);
    $this->moduleHandler = $module_handler;
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
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('soda_scs_manager.application_card_builder'),
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

    $ownerId = (int) ($project->getOwnerId() ?? 0);
    $isOwner = $ownerId > 0 && $ownerId === (int) $this->currentUser->id();

    $createUrls = [
      'mariadb' => Url::fromRoute('entity.soda_scs_component.add_form', [
        'bundle' => 'soda_scs_sql_component',
      ])->toString(),
      'wisski' => Url::fromRoute('entity.soda_scs_stack.add_form', [
        'bundle' => 'soda_scs_wisski_stack',
      ])->toString(),
      'openGdb' => Url::fromRoute('entity.soda_scs_component.add_form', [
        'bundle' => 'soda_scs_triplestore_component',
      ])->toString(),
    ];

    $modulePath = $this->moduleHandler->getModule('soda_scs_manager')->getPath();
    $assetBase = '/' . $modulePath . '/assets/images/';

    $build['#theme'] = 'soda_scs_project';
    $build['#label'] = $project->label();
    $build['#project_id'] = (int) $project->id();
    $build['#is_owner'] = $isOwner;
    $build['#owner'] = $project->get('owner')->view('default');
    $build['#members'] = $this->buildMembersDisplay($project);
    $build['#note'] = $project->get('note')->isEmpty() ? NULL : $project->get('note')->view('default');
    $applicationCards = $this->applicationCardBuilder->buildForProject($project);
    $build['#application_cards'] = $applicationCards;
    $build['#filter_tags'] = $this->applicationCardBuilder->collectFilterTags($applicationCards);
    $build['#add_application_popup'] = $isOwner ? [
      '#theme' => 'soda_scs_manager__add_application_popup',
      '#asset_base' => $assetBase,
      '#popup_url_mariadb' => $createUrls['mariadb'],
      '#popup_url_wisski' => $createUrls['wisski'],
      '#popup_url_open_gdb' => $createUrls['openGdb'],
      '#cache' => [
        'max-age' => 0,
      ],
    ] : NULL;
    $build['#attributes'] = ['class' => ['container', 'scs-manager--project-view']];
    $build['#cache'] = [
      'tags' => $project->getCacheTags(),
      'contexts' => ['user', 'languages:language_interface'],
      'max-age' => 0,
    ];

    $libraries = [
      'soda_scs_manager/globalStyling',
      'soda_scs_manager/dashboardHealthStatus',
      'soda_scs_manager/tagFilter',
    ];
    $drupalSettings = [
      'dashboardAdminMail' => (string) ($this->configFactory->get('system.site')->get('mail') ?? ''),
    ];
    if ($isOwner) {
      $libraries[] = 'soda_scs_manager/addApplicationPopup';
      $drupalSettings['addApplicationCreateUrls'] = $createUrls;
    }

    $build['#attached']['library'] = $libraries;
    $build['#attached']['drupalSettings']['sodaScsManager'] = $drupalSettings;

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

}
