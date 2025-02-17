<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ComponentBundleController.
 */
class SodaScsComponentController extends ControllerBase {

  /**
   * The SODa SCS Manager component helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected $sodaScsComponentHelpers;

  /**
   * {@inheritDoc}
   */
  public function __construct(SodaScsComponentHelpers $sodaScsComponentHelpers) {
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('soda_scs_manager.component.helpers')
    );
  }

  /**
   * Check the status of a component.
   */
  public function componentStatus($bundle, $subdomain) {
    $status = $this->sodaScsComponentHelpers
      ->healthCheck($bundle, $subdomain);
    return new JsonResponse(['status' => $status]);
  }

}
