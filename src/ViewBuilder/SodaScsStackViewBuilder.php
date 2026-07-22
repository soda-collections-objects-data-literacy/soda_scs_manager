<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ViewBuilder;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Theme\Registry;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The View Builder for the SodaScsStack entity.
 */
class SodaScsStackViewBuilder extends EntityViewBuilder {

  /**
   * Service URL helpers (public service + login link for the health badge).
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  /**
   * Constructs a SodaScsStackViewBuilder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityRepositoryInterface $entity_repository,
    LanguageManagerInterface $language_manager,
    Registry $theme_registry,
    EntityDisplayRepositoryInterface $entity_display_repository,
    SodaScsServiceHelpers $soda_scs_service_helpers,
  ) {
    parent::__construct($entity_type, $entity_repository, $language_manager, $theme_registry, $entity_display_repository);
    $this->sodaScsServiceHelpers = $soda_scs_service_helpers;
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
      $container->get('soda_scs_manager.service.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $build) {
    $build = parent::build($build);
    $stackHealthUrl = Url::fromRoute('soda_scs_manager.stack.health_check', [
      'stack_id' => $build['#soda_scs_stack']->id(),
    ])->toString();

    // Hide the flavours field if it exists in the build array.
    if (isset($build['flavours'])) {
      $build['flavours']['#access'] = FALSE;
    }
    $build['#attached']['library'][] = 'soda_scs_manager/entityHelpers';
    $build['#attached']['drupalSettings']['entityInfo']['healthUrl'] = $stackHealthUrl;
    $build['#attached']['drupalSettings']['entityInfo']['bundle'] = $build['#soda_scs_stack']->bundle();
    $entity = $build['#soda_scs_stack'];
    try {
      $serviceUrls = $this->sodaScsServiceHelpers->getStackServiceAndLoginUrls($entity);
      if ($serviceUrls !== NULL && !empty($serviceUrls['loginUrl'])) {
        $build['#attached']['drupalSettings']['entityInfo']['serviceLoginUrl'] = $serviceUrls['loginUrl'];
      }
    }
    catch (MissingDataException $e) {
      // Settings incomplete; health badge will not be linked.
    }

    // Disable caching.
    $build['#cache'] = [
      'max-age' => 0,
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode) {
    $build = parent::getBuildDefaults($entity, $view_mode);

    $build['#theme'] = 'soda_scs_entity';
    $build['#soda_scs_stack'] = $entity;
    $build['#entity_type'] = 'stack';
    $build['#view_mode'] = $view_mode;

    return $build;
  }

}
