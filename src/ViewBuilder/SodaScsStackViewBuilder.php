<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ViewBuilder;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Theme\Registry;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Access\SodaScsStackLinksTasksAccessControlHandler;
use Drupal\soda_scs_manager\Form\SodaScsEntityNoteForm;
use Drupal\soda_scs_manager\Helpers\SodaScsHealthStatus;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Service\SodaScsApplicationCardBuilder;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The View Builder for the SodaScsStack entity.
 */
class SodaScsStackViewBuilder extends EntityViewBuilder {

  /**
   * Constructs a SodaScsStackViewBuilder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityRepositoryInterface $entity_repository,
    LanguageManagerInterface $language_manager,
    Registry $theme_registry,
    EntityDisplayRepositoryInterface $entity_display_repository,
    protected SodaScsServiceHelpers $sodaScsServiceHelpers,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SodaScsApplicationCardBuilder $applicationCardBuilder,
    protected FormBuilderInterface $formBuilder,
  ) {
    parent::__construct($entity_type, $entity_repository, $language_manager, $theme_registry, $entity_display_repository);
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
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.application_card_builder'),
      $container->get('form_builder'),
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
    $build['#health_status'] = SodaScsHealthStatus::fromStatus((string) ($entity->get('health')->value ?? ''));
    $build['#note_edit_form'] = $entity->access('update') ? $this->formBuilder->getForm(SodaScsEntityNoteForm::class, $entity) : NULL;
    $build['#attached']['library'][] = 'scs_custom_theme/global-scripts';
    // Match the delete-form route's own access check (some bundles are
    // excluded there even though generic entity delete access would allow it).
    $build['#delete_access'] = SodaScsStackLinksTasksAccessControlHandler::accessDeleteForm($entity)->isAllowed();
    $build['#delete_url'] = $entity->toUrl('delete-form')->toString();
    $build['#delete_destination'] = $this->resolveDeleteDestination($entity);
    try {
      $serviceUrls = $this->sodaScsServiceHelpers->getStackServiceAndLoginUrls($entity);
      if ($serviceUrls !== NULL && !empty($serviceUrls['loginUrl'])) {
        $build['#attached']['drupalSettings']['entityInfo']['serviceLoginUrl'] = $serviceUrls['loginUrl'];
      }
      if ($serviceUrls !== NULL && !empty($serviceUrls['url'])) {
        $build['#attached']['drupalSettings']['entityInfo']['serviceUrl'] = $serviceUrls['url'];
      }
    }
    catch (MissingDataException $e) {
      // Settings incomplete; health badge will not be linked.
    }

    if ($entity->hasField('includedComponents') && !$entity->get('includedComponents')->isEmpty()) {
      $build['includedComponentCards'] = [];
      foreach ($entity->get('includedComponents')->referencedEntities() as $component) {
        if ($component instanceof SodaScsComponentInterface) {
          $build['includedComponentCards'][] = $this->applicationCardBuilder->buildComponentCard($component, showDetailsLink: FALSE);
        }
      }
      // The cards are rendered from the stored health field, which is only
      // refreshed by polling; without this they stay on "Unknown".
      $build['#attached']['library'][] = 'soda_scs_manager/dashboardHealthStatus';
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

  /**
   * URL to return to after deleting this stack (its project, if any).
   */
  protected function resolveDeleteDestination(EntityInterface $entity): string {
    if ($entity->hasField('partOfProjects') && !$entity->get('partOfProjects')->isEmpty()) {
      $project = $entity->get('partOfProjects')->entity;
      if ($project) {
        return $project->toUrl()->toString();
      }
    }
    return Url::fromRoute('soda_scs_manager.dashboard')->toString();
  }

}
