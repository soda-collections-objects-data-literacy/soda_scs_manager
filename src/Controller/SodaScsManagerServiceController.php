<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\soda_scs_manager\SodaScsStackActions;
use Drupal\soda_scs_manager\SodaScsStackActionsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The SODa SCS Manager service controller.
 */
class SodaScsManagerServiceController extends ControllerBase {

  /**
   * @var \Drupal\soda_scs_manager\SodaScsStackActionsInterface
   *  The SODa SCS Manager API actions service.
   */
  protected SodaScsStackActionsInterface $sodaScsStackActions;

  /**
   * Class constructor.
   */
  public function __construct(SodaScsStackActionsInterface $sodaScsStackActions, EntityTypeManagerInterface $entityTypeManager) {
    $this->sodaScsStackActions = $sodaScsStackActions;
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
   * Generate a URL based on the component ID.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   The redirect response.
   */
  public function generateUrl($component): TrustedRedirectResponse {
    $host = $this->config('soda_scs_manager.settings')->get('scsHost');
    $subdomain = $component->get('subdomain')->value;

    // Generate the URL.
    $url = 'https://' . $subdomain . '.' . $host;

    // Redirect to the generated URL.
    return new TrustedRedirectResponse($url);
  }

}
