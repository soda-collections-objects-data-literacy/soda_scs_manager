<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;

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
   * The bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountInterface $currentUser,
    EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->bundleInfo = $bundleInfo;
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
      $container->get('current_user'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * Page for component management.
   *
   * @return array
   *   The page build array.
   */
  public function componentDeskPage(): array {
    $current_user = $this->currentUser();
    try {
      $storage = $this->entityTypeManager->getStorage('soda_scs_component');
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      // @todo Handle exception properly. */
      return [];
    }
    if ($current_user->hasPermission('manage soda scs manager')) {
      // If the user has the 'manage soda scs manager' permission,
      // load all components.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $components */
      $components = $storage->loadMultiple();
    }
    else {
      // If the user does not have the 'manage soda scs manager'
      // permission, only load their own components.
      $components = $storage->loadByProperties(['user' => $current_user->id()]);
    }

    $componentsByUser = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
    foreach ($components as $component) {
      $username = $component->getOwner()->getDisplayName();
      $componentsByUser[$username][] = [
        '#theme' => 'soda_scs_manager__entity_card',
        '#title' => $this->t('@bundle', ['@bundle' => $component->label()]),
        '#description' => $component->get('description')->value,
        '#imageUrl' => $component->get('imageUrl')->value,
        '#url' => Url::fromRoute('entity.soda_scs_component.canonical', ['soda_scs_component' => $component->id()]),
        '#attached' => [
          'library' => ['soda_scs_manager/globalStyling'],
        ],
      ];
    }

    $build = [
      '#theme' => 'soda_scs_manager__desk',
      '#entitiesByUser' => $componentsByUser,
      '#entity_type' => 'soda_scs_component',
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    return $build;
  }

  /**
   * Page for component management.
   *
   * @return array
   *   The page build array.
   */
  public function stackDeskPage(): array {
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
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsStack $stack */
      $stack = $storage->loadMultiple();
    }
    else {
      // If the user does not have the 'manage soda scs manager'
      // permission, only load their own components.
      $stack = $storage->loadByProperties(['user' => $current_user->id()]);
    }

    $stacksByUser = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
    foreach ($stack as $stack) {
      $username = $stack->getOwner()->getDisplayName();
      $stacksByUser[$username][] = [
        '#theme' => 'soda_scs_manager__entity_card',
        '#title' => $this->t('@bundle', ['@bundle' => $stack->label()]),
        '#description' => $stack->get('description')->value,
        '#imageUrl' => $stack->get('imageUrl')->value,
        '#url' => Url::fromRoute('entity.soda_scs_stack.canonical', ['soda_scs_stack' => $stack->id()]),
      ];
    }
    return [
      '#theme' => 'soda_scs_manager__desk',
      '#entitiesByUser' => $stacksByUser,
      '#entity_type' => 'soda_scs_stack',
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Page for entity management.
   *
   * @return array
   *   The page build array.
   */
  public function entityDeskPage($entity_type): array {
    $current_user = $this->currentUser();
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      // @todo Handle exception properly. */
      return [];
    }
    if ($current_user->hasPermission('manage soda scs manager')) {
      // If the user has the 'manage soda scs manager' permission,
      // load all components.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsStack|\Drupal\soda_scs_manager\Entity\SodaScsComponent $entities */
      $entities = $storage->loadMultiple();
    }
    else {
      // If the user does not have the 'manage soda scs manager'
      // permission, only load their own components.
      $entities = $storage->loadByProperties(['user' => $current_user->id()]);
    }

    $entitiesByUser = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity */
    foreach ($entities as $entity) {
      $username = $entity->getOwner()->getDisplayName();
      $entitiesByUser[$username][] = $entity;
    }
    return [
      '#theme' => 'soda_scs_manager__entity_desk',
      '#entitiesByUser' => $entitiesByUser,
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * List the available components.
   *
   * @return array
   *   The page build array.
   */
  public function componentStorePage() {

    // Create the build array.
    $build = [
      '#theme' => 'soda_scs_manager__store',
      '#attributes' => ['class' => 'container soda-scs-manager--view--grid'],
      '#components' => [],
    ];

    // Get all component bundles.
    $bundles = $this->bundleInfo->getBundleInfo('soda_scs_component');

    // Remove components that only works in a stack.
    unset($bundles['soda_scs_collabora_component']);
    unset($bundles['soda_scs_drawio_component']);
    unset($bundles['soda_scs_jupyter_component']);
    unset($bundles['soda_scs_nextcloud_component']);
    unset($bundles['soda_scs_wisski_component']);
    unset($bundles['soda_scs_openrefine_component']);

    /** @var \Drupal\soda_scs_manager\Entity\Bundle\SodaScsStackBundle $bundle */
    foreach ($bundles as $id => $bundle) {

      // Add the card to the build array.
      $build['#components'][] = [
        '#theme' => 'component_card',
        '#title' => $this->t('@bundle', ['@bundle' => $bundle['label']]),
        '#description' => $bundle['description'],
        '#imageUrl' => $bundle['imageUrl'],
        '#url' => Url::fromRoute('entity.soda_scs_component.add_form', ['bundle' => $id]),
        '#attached' => [
          'library' => ['soda_scs_manager/globalStyling'],
        ],
      ];
    }

    return $build;
  }

  /**
   * List the available stacks.
   *
   * @return array
   *   The page build array.
   */
  public function stackStorePage() {
    // @todo: make this more generic.
    // Create the build array.
    $build = [
      '#theme' => 'soda_scs_manager__store',
      '#attributes' => ['class' => 'container soda-scs-manager--view--grid'],
      '#components' => [],
    ];

    // Get all component bundles.
    $bundles = $this->bundleInfo->getBundleInfo('soda_scs_stack');

    /** @var \Drupal\soda_scs_manager\Entity\Bundle\SodaScsStackBundle $bundle */
    foreach ($bundles as $id => $bundle) {

      // Add the card to the build array.
      $build['#components'][] = [
        '#theme' => 'component_card',
        '#title' => $this->t('@bundle', ['@bundle' => $bundle['label']]),
        '#description' => $bundle['description'],
        '#imageUrl' => $bundle['imageUrl'],
        '#url' => Url::fromRoute('entity.soda_scs_stack.add_form', ['bundle' => $id]),
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
