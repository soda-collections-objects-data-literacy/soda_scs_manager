<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;

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
      $container->get('soda_scs_manager.stack.actions'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Generate a URL based on the component ID.
   *
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $soda_scs_component
   *   The SODa SCS Component entity.
   * @param Drupal\soda_scs_manager\Entity\SodaScsStackInterface $soda_scs_stack
   *   The SODa SCS Stack entity.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   The redirect response.
   *
   * @todo Make this more flexible with a single parameter.
   */
  public function generateUrl($soda_scs_component, $soda_scs_stack): TrustedRedirectResponse {
    if (empty($this->config('soda_scs_manager.settings')->get('scsHost'))) {
      throw new MissingDataException('SODa SCS host is not set.');
    }

    if (empty($this->config('soda_scs_manager.settings')->get('dbManagementHost'))) {
      throw new MissingDataException('Database management host is not set.');
    }

    $host = $this->config('soda_scs_manager.settings')->get('scsHost');
    $management_host = $this->config('soda_scs_manager.settings')->get('dbManagementHost');

    if ($soda_scs_component) {
      $entity = $soda_scs_component;
    }
    elseif ($soda_scs_stack) {
      $entity = $soda_scs_stack;
    }
    else {
      throw new \Exception('No entity provided.');
    }

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
