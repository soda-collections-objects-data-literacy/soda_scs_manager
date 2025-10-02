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
use Drupal\user\Entity\User;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;

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
   * The Soda SCS helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsHelpers
   */
  protected $sodaScsHelpers;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsHelpers $sodaScsHelpers
   *   The Soda SCS helpers.
   */
  public function __construct(
    EntityTypeBundleInfoInterface $bundleInfo,
    AccountInterface $currentUser,
    EntityTypeManagerInterface $entityTypeManager,
    SodaScsHelpers $sodaScsHelpers,
  ) {
    $this->bundleInfo = $bundleInfo;
    $this->currentUser = $currentUser;
    $this->entityTypeManager = $entityTypeManager;
    $this->sodaScsHelpers = $sodaScsHelpers;
  }

  /**
   * Populate the reachable variables from services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The class container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.helpers'),
    );
  }

  /**
   * Page for component management.
   *
   * @return array
   *   The page build array.
   *
   * @todo Join ComponentDesk and Stack dashboard to generic Dashboard.
   * @todo Make admin permission more generic.
   */
  public function dashboardPage(): array {
    $current_user = $this->currentUser();

    try {
      $projectStorage = $this->entityTypeManager->getStorage('soda_scs_project');
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      // @todo Handle exception properly. */
      return [];
    }

    if ($current_user->hasPermission('soda scs manager admin')) {
      // If the user has the 'manage soda scs manager' permission,
      // load all projects.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsProject $projects */
      $projects = $projectStorage->loadMultiple();
    }

    else {
      // If the user does not have the 'manage soda scs manager'
      // permission, only load their own projects.
      $projects = $projectStorage->loadByProperties(['members' => $current_user->id()]);
    }

    try {
      $componentStorage = $this->entityTypeManager->getStorage('soda_scs_component');
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      // @todo Handle exception properly. */
      return [];
    }
    if ($current_user->hasPermission('soda scs manager admin')) {
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

    // Get all component IDs that are included in projects.
    $includedComponentIds = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    foreach ($projects as $project) {
      // Check if the project has an includedComponents field.
      if ($project->hasField('connectedComponents') && !$project->get('connectedComponents')->isEmpty()) {
        // Get the referenced component IDs.
        /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $includedComponents */
        $includedComponents = $project->get('connectedComponents');
        foreach ($includedComponents->referencedEntities() as $component) {
          $includedComponentIds[$component->id()] = $component->id();
        }
      }
    }

    // @todo This removes too much components from the dashboard.
    // Remove components that are already included in projects.
#    if (!empty($includedComponentIds)) {
#      foreach ($includedComponentIds as $componentId) {
#        if (isset($components[$componentId])) {
#          unset($components[$componentId]);
#        }
#        else {
#          $components[$componentId] = $this->entityTypeManager->getStorage('soda_scs_component')->load($componentId);
#        }
#      }
#    }

    try {
      $stackStorage = $this->entityTypeManager->getStorage('soda_scs_stack');
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      // @todo Handle exception properly. */
      return [];
    }
    if ($current_user->hasPermission('soda scs manager admin')) {
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

    // Get all component IDs that are included in stacks.
    $includedComponentIds = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
    foreach ($stacks as $stack) {
      // Check if the stack has an includedComponents field.
      if ($stack->hasField('includedComponents') && !$stack->get('includedComponents')->isEmpty()) {
        // Get the referenced component IDs.
        /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $includedComponents */
        $includedComponents = $stack->get('includedComponents');
        foreach ($includedComponents->referencedEntities() as $component) {
          $includedComponentIds[$component->id()] = $component->id();
        }
      }
    }

    // Remove components that are already included in stacks.
    if (!empty($includedComponentIds)) {
      foreach ($includedComponentIds as $componentId) {
        if (isset($components[$componentId])) {
          unset($components[$componentId]);
        }
      }
    }

    $entities = array_merge($components, $stacks);

    $entitiesByUser = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
    foreach ($entities as $entity) {
      $bundleInfo = $this->bundleInfo->getBundleInfo($entity->getEntityTypeId())[$entity->bundle()];
      if ($entity->getOwner() !== NULL && $entity->getOwner()->getDisplayName() !== NULL) {
        $username = $entity->getOwner()->getDisplayName();
      }
      else {
        $username = 'deleted user';
      }
      $entitiesByUser[$username][] = [
        '#theme' => 'soda_scs_manager__entity_card',
        '#title' => $this->t('@bundle', ['@bundle' => $entity->label()]),
        '#type' => $bundleInfo['label']->render(),
        '#description' => $entity->get('description')->value,
        '#imageUrl' => $bundleInfo['imageUrl'],
        '#learn_more_link' => '/app/' . $this->sodaScsHelpers->getEntityType($entity->bundle()),
        '#url' => Url::fromRoute('entity.' .
          $entity->getEntityTypeId() .
          '.canonical',
          [
            'bundle' => $entity->bundle(),
            $entity->getEntityTypeId() => $entity->id(),
          ]),
        '#tags' => $bundleInfo['tags'],
        '#cache' => [
          'max-age' => 0,
        ],
      ];
    }

    $build = [
      '#theme' => 'soda_scs_manager__dashboard',
      '#attributes' => ['class' => 'container soda-scs-manager--view--grid'],
      '#entitiesByUser' => $entitiesByUser,
      '#cache' => [
        'max-age' => 0,
      ],
      '#attached' => [
        'library' => [
          'soda_scs_manager/globalStyling',
          'soda_scs_manager/tagFilter',
        ],
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
  public function cataloguePage() {
    // @todo Make this more generic.
    // Create the build array.
    $build = [
      '#theme' => 'soda_scs_manager__catalogue',
      '#attributes' => ['class' => 'container soda-scs-manager--view--grid'],
      '#components' => [],
      '#stacks' => [],
      '#attached' => [
        'library' => [
          'soda_scs_manager/globalStyling',
          'soda_scs_manager/tagFilter',
        ],
      ],
    ];

    // Get all component bundles.
    $stackBundles = $this->bundleInfo->getBundleInfo('soda_scs_stack');

    // Filter stack bundles to only include 'soda_scs_wisski_stack'.
    $stackBundles = array_intersect_key($stackBundles, ['soda_scs_wisski_stack' => TRUE]);

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
        '#learn_more_link' => '/app/' . $this->sodaScsHelpers->getEntityType($id),
        '#attached' => [
          'library' => ['soda_scs_manager/globalStyling'],
        ],
      ];
    }

    // Get all component bundles.
    $componentBundles = $this->bundleInfo->getBundleInfo('soda_scs_component');

    // Filter component bundles to only include
    // 'soda_scs_filesystem_component',
    // 'soda_scs_sql_component',
    // 'soda_scs_triplestore_component'.
    $componentBundles = array_intersect_key($componentBundles, [
      'soda_scs_filesystem_component' => TRUE,
      'soda_scs_sql_component' => TRUE,
      'soda_scs_triplestore_component' => TRUE,
    ]);

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
        '#learn_more_link' => '/app/' . $this->sodaScsHelpers->getEntityType($id),
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

    $currentUser = $this->currentUser();
    /** @var \Drupal\user\UserInterface $user */
    $user = User::load($currentUser->id());
    return [
      '#theme' => 'soda_scs_manager__start_page',
      '#attributes' => ['class' => ['container', 'mx-auto']],
      '#user' => $user ? $user->get('first_name')->value : 'SCS User',
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
