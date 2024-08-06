<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
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

  /**
   * Generate a URL based on the component ID.
   *
   * @param $soda_scs_component
   *
   * @return TrustedRedirectResponse
   *  The redirect response.
   */
  public function generateUrl($soda_scs_component): TrustedRedirectResponse {
    // Generate the URL based on the component ID.
    $entity = $this->entityTypeManager->getStorage('soda_scs_component')->load($soda_scs_component);
    $host = $this->config('soda_scs_manager.settings')->get('scsHost');
    $subdomain = $entity->get('subdomain')->value;

    // Generate the URL.
    $url = 'https://' . $subdomain . '.' . $host;

    // Redirect to the generated URL.
    return new TrustedRedirectResponse($url);
  }

}
