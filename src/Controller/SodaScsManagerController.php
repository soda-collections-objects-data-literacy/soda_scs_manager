<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\SodaScsApiActions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;

/**
 * The SODa SCS Manager info controller.
 */
class SodaScsManagerController extends ControllerBase {

  /**
   * @var \Drupal\soda_scs_manager\SodaScsApiActions
   *  The SODa SCS Manager API actions service.
   */
  protected SodaScsApiActions $ApiActions;


  /**
   * Class constructor.
   *
   * @param \Drupal\soda_scs_manager\SodaScsApiActions $ApiActions
   *   The SODa SCS Manager API actions service.
   *
   * @param \Drupal\soda_scs_manager\DbActions $dbActions
   *  The SODa SCS Manager database actions service.
   */
  public function __construct(SodaScsApiActions $ApiActions, EntityTypeManagerInterface $entityTypeManager) {
    $this->ApiActions = $ApiActions;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Populate the reachable variables from services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The class container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('soda_scs_manager.api.actions'),
      $container->get('entity_type.manager')
    );
  }

/**
 * Page for API documentation.
 *
 * @return \Symfony\Component\HttpFoundation\Response
 *  The response object.
 *
 * @throws \Exception
 * If the spec file is not found.
 *
 */
  public function apiSpec() {
    $spec_url = Url::fromUri('base:/modules/custom/soda-scs-manager/spec/soda-scs-api-spec.yaml')->toString();

    return [
      '#markup' => '<div id="swagger-ui">Swagger UI</div>',
      '#attached' => [
        'library' => [
          'soda_scs_manager/swagger_ui',
        ],
        'drupalSettings' => [
          'swaggerSpecUrl' => $spec_url,
        ],
      ],
    ];
  }
  /**
   * Page for user management.
   *
   * @return array
   *   The page build array.
   */
  public function usersPage(): array {
    $options['user'] = \Drupal::currentUser()->id();
    // If the user is not an admin, filter the accounts to only include their own.
    #$components = $this->dbActions->listComponents($options['uid']);
    return [
      '#theme' => 'users_page',
      '#users' => [],
      ];
  }

  /**
   * Page for component management.
   *
   * @return array
   *   The page build array.
   */
  public function componentsPage(): array {
    $current_user = \Drupal::currentUser();
    try {
      $storage = $this->entityTypeManager->getStorage('soda_scs_component');
    }
    catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      /** @todo Handle exception properly. */
      return [];
    }
    if ($current_user->hasPermission('manage soda scs manager')) {
      // If the user has the 'manage soda scs manager' permission, load all components.

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $components */
      $components = $storage->loadMultiple();
    } else {
      // If the user does not have the 'manage soda scs manager' permission, only load their own components.
      $components = $storage->loadByProperties(['user' => $current_user->id()]);
    }

    $componentsByUser = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $component */
    foreach ($components as $component) {
      $userName = $component->getOwner()->getDisplayName();
      $componentsByUser[$userName][] = $component;
    }
    return [
      '#theme' => 'components_page',
      '#componentsByUser' => $componentsByUser,
      ];
  }

  /**
   * Page for component store.
   *
   * @return array
   *   The page build array.
   */
  public function componentsStore(): array {

    $options['uid'] = \Drupal::currentUser()->id();
    // If the user is not an admin, filter the accounts to only include their own.
    $components = $this->ApiActions->crudComponent('read', $options);

    return [
      '#theme' => 'component_page',
      '#components' => $components,
      ];
  }


  // @todo Implement healthcheckPage().
  public function healthcheckPage(): array {
    return [
      '#theme' => 'soda_scs_manager_healthcheck_page',
      //'#healthCheck' => $this->ApiActions->healthCheck(),
    ];
  }

}
