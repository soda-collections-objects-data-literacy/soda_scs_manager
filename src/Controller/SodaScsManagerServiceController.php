<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\SodaScsApiActions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The SODa SCS Manager service controller.
 */
class SodaScsManagerServiceController extends ControllerBase {

  /**
   * @var \Drupal\soda_scs_manager\SodaScsApiActions
   *  The SODa SCS Manager API actions service.
   */
  protected SodaScsApiActions $sodaScsApiActions;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   *  The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Class constructor
   */
  public function __construct(SodaScsApiActions $sodaScsApiActions, EntityTypeManagerInterface $entityTypeManager) {
    $this->sodaScsApiActions = $sodaScsApiActions;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Populate the reachable variables from services.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('soda_scs_manager.api.actions'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Get the status of a component.
   *
   * @param int $aid
   *   The account ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function componentStatus($componentId) {
    // Get component info
    $component = $this->entityTypeManager->getStorage('soda_scs_component')->load($componentId);
    $bundle = $component->bundle();
    $action = 'read';


    // Get the status of the component.
    $status = $this->sodaScsApiActions->crudComponent($bundle, $action, $options);
    // Return the status as a JSON response.
    return new JsonResponse(['status' => $status]);
  }

}
