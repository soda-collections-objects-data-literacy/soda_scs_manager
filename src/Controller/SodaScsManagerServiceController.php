<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpersInterface;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The SODa SCS Manager service controller.
 */
class SodaScsManagerServiceController extends ControllerBase {

  /**
   * The SODa SCS Manager API actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsStackActionsInterface
   */
  protected SodaScsStackActionsInterface $sodaScsStackActions;

  /**
   * The SODa SCS Manager service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpersInterface
   */
  protected SodaScsServiceHelpersInterface $sodaScsServiceHelpers;
  /**
   * Class constructor.
   */
  public function __construct(
    SodaScsStackActionsInterface $sodaScsStackActions,
    EntityTypeManagerInterface $entityTypeManager,
    SodaScsServiceHelpersInterface $sodaScsServiceHelpers
  ) {
    $this->sodaScsStackActions = $sodaScsStackActions;
    $this->entityTypeManager = $entityTypeManager;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
  }

  /**
   * Populate the reachable variables from services.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.service_helpers'),
      $container->get('soda_scs_manager.stack.actions'),
    );
  }

  /**
   * Generate a URL based on the component ID.
   *
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface | Drupal\soda_scs_manager\Entity\SodaScsStackInterface $entity
   *   The SODa SCS Component or Stack entity.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   The redirect response.
   *
   * @todo Make this more flexible with a single parameter.
   */
  public function generateUrl($entity): TrustedRedirectResponse {

    $host = $this->sodaScsServiceHelpers->;

    switch ($entity->get('bundle')->value) {
      case 'soda_scs_wisski_component':
        $machineName = $entity->get('machineName')->value;
        $url = 'https://' . $machineName . '.wisski.' . str_replace('https://', '', $host);
        break;

      case 'soda_scs_sql_component':
        $url = 'https://adminer-db.' . $management_host;
        break;

      default:
        throw new \Exception('Unknown component type: ' . $entity->get('bundle')->value);
    }

    // Redirect to the generated URL.
    return new TrustedRedirectResponse($url);
  }

}
