<?php

namespace Drupal\soda_scs_manager\ViewBuilder;

use Drupal\Core\Entity\EntityViewBuilder;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Theme\Registry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The View Builder for the SodaScsComponent entity.
 */
class SodaScsComponentViewBuilder extends EntityViewBuilder {

  /**
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
   */
  public function __construct(EntityTypeInterface $entity_type, EntityRepositoryInterface $entity_repository, LanguageManagerInterface $language_manager, Registry $theme_registry, EntityDisplayRepositoryInterface $entity_display_repository, $config) {
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
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity.repository'),
      $container->get('language_manager'),
      $container->get('theme.registry'),
      $container->get('entity_display.repository'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $build) {
    $build = parent::build($build);

    $build['#attached']['library'][] = 'soda_scs_manager/security';
    $build['#attached']['library'][] = 'soda_scs_manager/componentHelpers';
    $build['#attached']['drupalSettings']['componentInfo']['healthUrl'] = '/soda-scs-manager/health/' . $build['#soda_scs_component']->id();

    return $build;
  }

}
