<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\Entity\File;


/**
 * Class ComponentBundleController.
 */
class SodaScsComponentBundleController extends ControllerBase {

    /**
     * The Entity Type Manager service.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * The Renderer service.
     *
     * @var \Drupal\Core\Render\RendererInterface
     */
    protected $renderer;

    /**
     * {@inheritdoc}
     */
    public function __construct(RendererInterface $renderer, EntityTypeManagerInterface $entity_type_manager) {
    $this->renderer = $renderer;
    $this->entityTypeManager = $entity_type_manager;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container) {
    return new static(
        $container->get('renderer'),
        $container->get('entity_type.manager')
    );
    }

  /**
   * Display the markup.
   *
   * @return array
   */
  public function content() {

    // Create the build array
    $build = [
      '#theme' => 'container',
      '#attributes' => ['class' => 'd-flex justify-content-between'],
      '#children' => [],
    ];

    // Get all component bundles
    $bundles = $this->entityTypeManager->getStorage('soda_scs_component_bundle')->loadMultiple();
  

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentBundle $bundle */
    foreach ($bundles as $bundle) {

      

      // Add the card to the build array
      $build['#children'][] = [
        '#theme' => 'bundle_card',
        '#title' => $this->t('@bundle', ['@bundle' => $bundle->label()]),
        '#description' => $bundle->getDescription(),
        '#image_url' =>  $bundle->getImageUrl(),
        '#url' => Url::fromRoute('entity.soda_scs_component.add_form', ['soda_scs_component_bundle' => $bundle->id()]),
        '#attached' => [
          'library' => ['soda_scs_manager/globalStyling'],
        ],
      ];
    }

    return $build;
  }

  public function title() {
    return $this->t('Component Bundles');
  }

}