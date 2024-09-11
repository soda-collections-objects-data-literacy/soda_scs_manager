<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The SODa SCS Manager info controller.
 */
class SodaScsManagerController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, AccountInterface $currentUser) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * Populate the reachable variables from services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The class container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Page for API documentation.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   *
   * @throws \Exception
   *   If the spec file is not found.
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
    $options['user'] = $this->currentUser()->id();
    // If the user is not an admin, filter the accounts to
    // only include their own.
    // $components = $this->dbActions->listComponents($options['uid']);.
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
  public function deskPage(): array {
    $current_user = $this->currentUser();
    try {
      $storage = $this->entityTypeManager->getStorage('soda_scs_stack');
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      // @todo Handle exception properly. */
      return [];
    }
    if ($current_user->hasPermission('manage soda scs manager')) {
      // If the user has the 'manage soda scs manager' permission,
      // load all components.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $components */
      $stacks = $storage->loadMultiple();
    }
    else {
      // If the user does not have the 'manage soda scs manager'
      // permission, only load their own components.
      $stacks = $storage->loadByProperties(['user' => $current_user->id()]);
    }

    $stacksByUser = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
    foreach ($stacks as $stack) {
      $username = $stack->getOwner()->getDisplayName();
      $stacksByUser[$username][] = $stack;
    }
    return [
      '#theme' => 'stacks_page',
      '#stacksByUser' => $stacksByUser,
    ];
  }

  /**
   * Display the markup.
   *
   * @return array
   *   The page build array.
   */
  public function storePage() {

    // Create the build array.
    $build = [
      '#theme' => 'container',
      '#attributes' => ['class' => 'd-flex justify-content-between'],
      '#children' => [],
    ];

    // Get all component bundles.
    $bundles = $this->entityTypeManager->getStorage('soda_scs_component_bundle')->loadMultiple();

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentBundle $bundle */
    foreach ($bundles as $bundle) {

      // Add the card to the build array.
      $build['#children'][] = [
        '#theme' => 'bundle_card',
        '#title' => $this->t('@bundle', ['@bundle' => $bundle->label()]),
        '#description' => $bundle->getDescription(),
        '#image_url' => $bundle->getImageUrl(),
        '#url' => Url::fromRoute('entity.soda_scs_stack.add_form', ['soda_scs_component_bundle' => $bundle->id()]),
        '#attached' => [
          'library' => ['soda_scs_manager/globalStyling'],
        ],
      ];
    }

    return $build;
  }

  /**
   * Page for healthcheck.
   *
   * @todo Implement healthcheckPage().
   */
  public function healthcheckPage(): array {
    return [
      '#theme' => 'soda_scs_manager_healthcheck_page',
    ];
  }

}
