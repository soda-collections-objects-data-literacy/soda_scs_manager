<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ViewBuilder;


use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Theme\Registry;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The View Builder for the SodaScsComponent entity.
 */
class SodaScsComponentViewBuilder extends EntityViewBuilder {

  /**
   * The config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The SodaScsManager settings.
   *
   * @var \Drupal\soda_scs_manager\SodaScsManagerSettings
   */
  protected $sodaScsManagerSettings;


  /**
   * Constructs a new EntityViewBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Theme\Registry $theme_registry
   *   The theme registry.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   * @param \Drupal\Core\Config\Config $config
   *   The config.
   */
  public function __construct(
    $config,
    EntityDisplayRepositoryInterface $entity_display_repository,
    EntityRepositoryInterface $entity_repository,
    LanguageManagerInterface $language_manager,
    SodaScsContainerHelpers $sodaScsContainerHelpers,
    Registry $theme_registry,
    EntityTypeInterface $entity_type,
  ) {
    $this->entityTypeId = $entity_type->id();
    $this->entityType = $entity_type;
    $this->entityRepository = $entity_repository;
    $this->languageManager = $language_manager;
    $this->themeRegistry = $theme_registry;
    $this->entityDisplayRepository = $entity_display_repository;
    $this->sodaScsManagerSettings = $config->get('soda_scs_manager.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(
    ContainerInterface $container,
    EntityTypeInterface $entity_type,
  ) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_display.repository'),
      $container->get('entity.repository'),
      $container->get('language_manager'),
      $container->get('soda_scs_manager.container.helpers'),
      $container->get('theme.registry'),
      $entity_type,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $build) {
    $build = parent::build($build);

    // Hide the flavours field if it exists in the build array.
    if (isset($build['flavours'])) {
      $build['flavours']['#access'] = FALSE;
    }

    $build['#attached']['library'][] = 'soda_scs_manager/security';
    $build['#attached']['library'][] = 'soda_scs_manager/entityHelpers';
    $build['#attached']['drupalSettings']['entityInfo']['healthUrl'] = '/soda-scs-manager/health/component/' . $build['#soda_scs_component']->id();

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode) {
    $build = parent::getBuildDefaults($entity, $view_mode);

    // Add the bundle to the build array.
    // Add a field with the bundle name.
    $build['bundleName'] = [
      '#type' => 'markup',
      '#markup' => $entity->bundle(),
      '#access' => TRUE,
      '#weight' => 10,
      '#title' => 'Bundle',
    ];

    // Add custom theme suggestions.
    $build['#theme'] = 'soda_scs_entity';
    $build['#soda_scs_component'] = $entity;
    $build['#entity_type'] = 'component';
    $build['#view_mode'] = $view_mode;

    return $build;
  }

}
