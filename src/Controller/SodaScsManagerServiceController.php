<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\soda_scs_manager\Entity\SodaScsComponent;
use Drupal\soda_scs_manager\Entity\SodaScsStack;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The SODa SCS Manager service controller.
 */
class SodaScsManagerServiceController extends ControllerBase {

  /**
   * The SODa SCS Manager service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  /**
   * Class constructor.
   */
  public function __construct(
    SodaScsServiceHelpers $sodaScsServiceHelpers,
  ) {
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;

  }

  /**
   * Populate the reachable variables from services.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('soda_scs_manager.service.helpers'),
    );
  }

  /**
   * Generate a URL based on the component ID.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponent $soda_scs_component
   *   The SODa SCS Component or Stack entity.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   The redirect response.
   *
   * @todo Make this more flexible with a single parameter.
   */
  public function generateComponentUrl(SodaScsComponent $soda_scs_component): TrustedRedirectResponse {
    $urls = $this->sodaScsServiceHelpers->getComponentServiceAndLoginUrls($soda_scs_component);
    if ($urls === NULL) {
      throw new \Exception('Unknown component type: ' . $soda_scs_component->bundle());
    }
    return new TrustedRedirectResponse($urls['url']);
  }

  /**
   * Generate a URL based on the stack ID.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStack $soda_scs_stack
   *   The SODa SCS Stack entity.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   The redirect response.
   */
  public function generateStackUrl(SodaScsStack $soda_scs_stack): TrustedRedirectResponse {
    $urls = $this->sodaScsServiceHelpers->getStackServiceAndLoginUrls($soda_scs_stack);
    if ($urls === NULL) {
      throw new \Exception('Unknown stack type: ' . $soda_scs_stack->bundle());
    }
    return new TrustedRedirectResponse($urls['url']);
  }

  /**
   * Get component service URL as JSON.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponent $soda_scs_component
   *   The SODa SCS Component entity.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the service URL.
   */
  public function getComponentServiceUrl(SodaScsComponent $soda_scs_component): JsonResponse {
    $urls = $this->sodaScsServiceHelpers->getComponentServiceAndLoginUrls($soda_scs_component);
    if ($urls === NULL) {
      return new JsonResponse([
        'error' => 'Unknown component type: ' . $soda_scs_component->bundle(),
      ], 400);
    }
    return new JsonResponse(['url' => $urls['url'], 'loginUrl' => $urls['loginUrl']]);
  }

  /**
   * Get stack service URL as JSON.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStack $soda_scs_stack
   *   The SODa SCS Stack entity.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the service URL.
   */
  public function getStackServiceUrl(SodaScsStack $soda_scs_stack): JsonResponse {
    $urls = $this->sodaScsServiceHelpers->getStackServiceAndLoginUrls($soda_scs_stack);
    if ($urls === NULL) {
      return new JsonResponse([
        'error' => 'Unknown stack type: ' . $soda_scs_stack->bundle(),
      ], 400);
    }
    return new JsonResponse(['url' => $urls['url'], 'loginUrl' => $urls['loginUrl']]);
  }

}
