<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\soda_scs_manager\Entity\SodaScsComponent;
use Drupal\soda_scs_manager\Entity\SodaScsStack;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The SODa SCS Manager service controller.
 */
class SodaScsManagerServiceController extends ControllerBase {

  /**
   * The SODa SCS Manager API actions service.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface
   */
  protected SodaScsStackActionsInterface $sodaScsStackActions;

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
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();
    $triplestoreSettings = $this->sodaScsServiceHelpers->initTriplestoreServiceSettings();
    $webprotegeSettings = $this->sodaScsServiceHelpers->initWebprotegeInstanceSettings();
    $wisskiSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();

    switch ($soda_scs_component->bundle()) {
      case 'soda_scs_sql_component':
        $url = $databaseSettings['managementHost'];
        break;

      case 'soda_scs_triplestore_component':
        $url = $triplestoreSettings['host'];
        break;

      case 'soda_scs_webprotege_component':
        $url = $webprotegeSettings['host'];
        break;

      case 'soda_scs_wisski_component':
        $machineName = $soda_scs_component->get('machineName')->value;
        $url = str_replace('{instanceId}', "raw." . $machineName, $wisskiSettings['baseUrl']);
        break;

      default:
        throw new \Exception('Unknown component type: ' . $soda_scs_component->bundle());
    }

    return new TrustedRedirectResponse($url);
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
    $jupyterSettings = $this->sodaScsServiceHelpers->initJupyterHubSettings();
    $nextcloudSettings = $this->sodaScsServiceHelpers->initNextcloudSettings();
    $wisskiSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();

    switch ($soda_scs_stack->bundle()) {
      case 'soda_scs_wisski_stack':
        // @todo This is a stupid hack,
        // replace it with a proper solution.
        $machineName = $soda_scs_stack->get('machineName')->value;
        $machineName = preg_replace('/^stack-/', 'wisski-', $machineName, 1);
        $url = str_replace('{instanceId}', $machineName, $wisskiSettings['baseUrl']);
        break;

      case 'soda_scs_jupyter_stack':
        $url = $jupyterSettings['baseUrl'];
        break;

      case 'soda_scs_nextcloud_stack':
        $url = $nextcloudSettings['baseUrl'];
        break;

      default:
        throw new \Exception('Unknown stack type: ' . $soda_scs_stack->bundle());
    }
    return new TrustedRedirectResponse($url);
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
    $databaseSettings = $this->sodaScsServiceHelpers->initDatabaseServiceSettings();
    $triplestoreSettings = $this->sodaScsServiceHelpers->initTriplestoreServiceSettings();
    $webprotegeSettings = $this->sodaScsServiceHelpers->initWebprotegeInstanceSettings();
    $wisskiSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();

    switch ($soda_scs_component->bundle()) {
      case 'soda_scs_sql_component':
        $url = $databaseSettings['managementHost'];
        break;

      case 'soda_scs_triplestore_component':
        $url = $triplestoreSettings['host'];
        break;

      case 'soda_scs_webprotege_component':
        $url = $webprotegeSettings['host'];
        break;

      case 'soda_scs_wisski_component':
        $machineName = $soda_scs_component->get('machineName')->value;
        $url = str_replace('{instanceId}', "raw." . $machineName, $wisskiSettings['baseUrl']);
        break;

      default:
        return new JsonResponse([
          'error' => 'Unknown component type: ' . $soda_scs_component->bundle(),
        ], 400);
    }

    return new JsonResponse(['url' => $url]);
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
    $jupyterSettings = $this->sodaScsServiceHelpers->initJupyterHubSettings();
    $nextcloudSettings = $this->sodaScsServiceHelpers->initNextcloudSettings();
    $wisskiSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();

    switch ($soda_scs_stack->bundle()) {
      case 'soda_scs_wisski_stack':
        // @todo This is a stupid hack,
        // replace it with a proper solution.
        $machineName = $soda_scs_stack->get('machineName')->value;
        $machineName = preg_replace('/^stack-/', 'wisski-', $machineName, 1);
        $url = str_replace('{instanceId}', $machineName, $wisskiSettings['baseUrl']);
        break;

      case 'soda_scs_jupyter_stack':
        $url = $jupyterSettings['baseUrl'];
        break;

      case 'soda_scs_nextcloud_stack':
        $url = $nextcloudSettings['baseUrl'];
        break;

      default:
        return new JsonResponse([
          'error' => 'Unknown stack type: ' . $soda_scs_stack->bundle(),
        ], 400);
    }

    return new JsonResponse(['url' => $url]);
  }

}
