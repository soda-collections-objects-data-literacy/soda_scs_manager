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
   *
   * @todo Join ComponentDesk and Stack desk to generic Desk.
   */
  public function deskPage(): array {
    $current_user = $this->currentUser();
    try {
      $componentStorage = $this->entityTypeManager->getStorage('soda_scs_component');
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      // @todo Handle exception properly. */
      return [];
    }
    if ($current_user->hasPermission('manage soda scs manager')) {
      // If the user has the 'manage soda scs manager' permission,
      // load all components.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $components */
      $components = $componentStorage->loadMultiple();
    }
    else {
      // If the user does not have the 'manage soda scs manager'
      // permission, only load their own components.
      $components = $componentStorage->loadByProperties(['owner' => $current_user->id()]);
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
        '#entity_type' => 'soda_scs_component',
        '#url' => Url::fromRoute('entity.soda_scs_component.canonical', ['soda_scs_component' => $component->id()]),
        '#attached' => [
          'library' => ['soda_scs_manager/globalStyling'],
        ],
      ];
    }

    try {
      $stackStorage = $this->entityTypeManager->getStorage('soda_scs_stack');
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      // @todo Handle exception properly. */
      return [];
    }
    if ($current_user->hasPermission('manage soda scs manager')) {
      // If the user has the 'manage soda scs manager' permission,
      // load all components.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $components */
      $stacks = $stackStorage->loadMultiple();
    }
    else {
      // If the user does not have the 'manage soda scs manager'
      // permission, only load their own components.
      $stacks = $stackStorage->loadByProperties(['owner' => $current_user->id()]);
    }

    $stacksByUser = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
    foreach ($stacks as $stack) {
      $username = $stack->getOwner()->getDisplayName();
      $stacksByUser[$username][] = [
        '#theme' => 'soda_scs_manager__entity_card',
        '#title' => $this->t('@bundle', ['@bundle' => $stack->label()]),
        '#description' => $stack->get('description')->value,
        '#imageUrl' => $stack->get('imageUrl')->value,
        '#entity_type' => 'soda_scs_stack',
        '#url' => Url::fromRoute('entity.soda_scs_stack.canonical', ['soda_scs_stack' => $stack->id()]),
        '#attached' => [
          'library' => ['soda_scs_manager/globalStyling'],
        ],
      ];
    }

    $build = [
      '#theme' => 'soda_scs_manager__desk',
      '#attributes' => ['class' => 'container soda-scs-manager--view--grid'],
      '#entitiesByUser' => $componentsByUser + $stacksByUser,
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    return $build;
  }

  /**
   * List the available stacks.
   *
   * @return array
   *   The page build array.
   */
  public function storePage() {
    // @todo: make this more generic.
    // Create the build array.
    $build = [
      '#theme' => 'soda_scs_manager__store',
      '#attributes' => ['class' => 'container soda-scs-manager--view--grid'],
      '#components' => [],
      '#stacks' => [],
      '#attached' => [
        'library' => [
          'soda_scs_manager/globalStyling',
          'soda_scs_manager/tag_filter',
        ],
      ],
    ];

    // Get all component bundles.
    $stackBundles = $this->bundleInfo->getBundleInfo('soda_scs_stack');

    /** @var \Drupal\soda_scs_manager\Entity\Bundle\SodaScsStackBundle $stackBundle */
    foreach ($stackBundles as $id => $stackBundle) {

      // Add the card to the build array.
      $build['#stacks'][] = [
        '#theme' => 'soda_scs_manager__entity_card',
        '#title' => $this->t('@bundle', ['@bundle' => $stackBundle['label']]),
        '#description' => $stackBundle['description'],
        '#imageUrl' => $stackBundle['imageUrl'],
        '#tags' => $stackBundle['tags'],
        '#url' => Url::fromRoute('entity.soda_scs_stack.add_form', ['bundle' => $id]),
        '#attached' => [
          'library' => ['soda_scs_manager/globalStyling'],
        ],
      ];
    }

    // Get all component bundles.
    $componentBundles = $this->bundleInfo->getBundleInfo('soda_scs_component');

    /** @var \Drupal\soda_scs_manager\Entity\Bundle\SodaScsStackBundle $componentBundle */
    foreach ($componentBundles as $id => $componentBundle) {

      // Add the card to the build array.
      $build['#components'][] = [
        '#theme' => 'soda_scs_manager__entity_card',
        '#title' => $this->t('@bundle', ['@bundle' => $componentBundle['label']]),
        '#description' => $componentBundle['description'],
        '#imageUrl' => $componentBundle['imageUrl'],
        '#tags' => $componentBundle['tags'],
        '#url' => Url::fromRoute('entity.soda_scs_component.add_form', ['bundle' => $id]),
        '#attached' => [
          'library' => ['soda_scs_manager/globalStyling'],
        ],
      ];
    }

    return $build;
  }

  /**
   * Start page for SCS Manager.
   *
   * @return array
   *   The page build array.
   */
  public function startPage(): array {
    return [
      '#theme' => 'soda_scs_manager__start_page',
      '#attributes' => ['class' => ['container', 'mx-auto']],
      '#attached' => [
        'library' => ['soda_scs_manager/globalStyling'],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Page for the beginners tour.
   *
   * @return array
   *   The page build array.
   */
  public function tourPage(): array {
    return [
      '#theme' => 'soda_scs_manager__tour_page',
      '#attached' => [
        'library' => ['soda_scs_manager/globalStyling'],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
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
