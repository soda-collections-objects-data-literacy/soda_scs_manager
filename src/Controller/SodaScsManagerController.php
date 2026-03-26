<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\user\UserDataInterface;

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
   * Per-user key-value data (onboarding flags).
   *
   * @var \Drupal\user\UserDataInterface
   */
  protected UserDataInterface $userData;

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
   * @param \Drupal\user\UserDataInterface $userData
   *   User data for intro / onboarding flags.
   */
  public function __construct(
    EntityTypeBundleInfoInterface $bundleInfo,
    AccountInterface $currentUser,
    EntityTypeManagerInterface $entityTypeManager,
    SodaScsHelpers $sodaScsHelpers,
    UserDataInterface $userData,
  ) {
    $this->bundleInfo = $bundleInfo;
    $this->currentUser = $currentUser;
    $this->entityTypeManager = $entityTypeManager;
    $this->sodaScsHelpers = $sodaScsHelpers;
    $this->userData = $userData;
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
      $container->get('user.data'),
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

    // Load components of the projects.
    try {
      $projectStorage = $this->entityTypeManager->getStorage('soda_scs_project');

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

      // Sort Projects by label.
      /*uasort($projects, function ($a, $b) {
      return strnatcasecmp($a->label(), $b->label());
      });*/

      // Project components of the projects.
      $entitiesByProject = [];
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
      foreach ($projects as $project) {
        /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $connectedComponents */
        $connectedComponents = $project->get('connectedComponents');
        $projectEntities = $connectedComponents->referencedEntities();
        /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $projectEntity */
        foreach ($projectEntities as $projectEntity) {
          $projectBundleInfo = $this->bundleInfo->getBundleInfo($projectEntity->getEntityTypeId())[$projectEntity->bundle()];
          $projectLabel = $project->label();

          $url = Url::fromRoute('soda_scs_manager.component.service_link', [
            'soda_scs_component' => $projectEntity->id(),
          ]);

          if (in_array($projectEntity->bundle(), [
            'soda_scs_wisski_component',
            'soda_scs_wisski_stack',
            'soda_scs_sql_component',
            'soda_scs_triplestore_component',
          ])) {
            $detailsLink = Url::fromRoute('entity.' .
              $projectEntity->getEntityTypeId() .
              '.canonical',
              [
                'bundle' => $projectEntity->bundle(),
                $projectEntity->getEntityTypeId() => $projectEntity->id(),
              ]);
          }
          else {
            $detailsLink = NULL;
          }

          $entitiesByProject[$projectLabel][] = [
            '#theme' => 'soda_scs_manager__entity_card',
            '#title' => $this->t('@bundle', ['@bundle' => $projectEntity->label()]),
            '#type' => $projectBundleInfo['label']->render(),
            // Bundle description: translates with UI; stored field is English.
            '#description' => $projectBundleInfo['description']->render(),
            '#details_link' => $detailsLink,
            '#entity_id' => $projectEntity->id(),
            '#entity_type_id' => $projectEntity->getEntityTypeId(),
            '#health_status' => $projectEntity->get('health')->value ?? 'Unknown',
            '#imageUrl' => $projectBundleInfo['imageUrl'],
            '#learn_more_link' => $this->internalPathUrl('soda-scs-manager/app/' . $this->sodaScsHelpers->getEntityType($projectEntity->bundle())),
            '#url' => $url,
            '#tags' => $projectBundleInfo['tags'],
            '#cache' => [
              'max-age' => 0,
            ],
          ];
        }
      }
    }
    catch (EntityStorageException $e) {
      $this->messenger()->addError($this->t('Error loading projects: @error', ['@error' => $e->getMessage()]));
      return [];
    }

    // Sort projects by keys (= project title).
    ksort($entitiesByProject);

    // Load owned components.
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

    // Load stacks.
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

      // Additionally, include WissKI stacks from projects where the user is a
      // member.
      if (!empty($projects)) {
        $projectIds = array_keys($projects);
        $query = $stackStorage->getQuery()
          ->condition('bundle', 'soda_scs_wisski_stack')
          ->condition('partOfProjects', $projectIds, 'IN')
          ->accessCheck(TRUE);

        $additionalStackIds = $query->execute();
        if (!empty($additionalStackIds)) {
          $additionalStacks = $stackStorage->loadMultiple($additionalStackIds);
          // Merge while preserving existing stacks keyed by ID.
          $stacks = $stacks + $additionalStacks;
        }
      }
    }

    $stackIncludedComponentIds = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
    foreach ($stacks as $stack) {
      // Check if the stack has an includedComponents field.
      if ($stack->hasField('includedComponents') && !$stack->get('includedComponents')->isEmpty()) {
        // Get the referenced component IDs.
        /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $stackIncludedComponents */
        $stackIncludedComponents = $stack->get('includedComponents');
        foreach ($stackIncludedComponents->referencedEntities() as $component) {
          $stackIncludedComponentIds[$component->id()] = $component->id();
        }
      }
    }

    // Remove components that are already included in stacks.
    if (!empty($stackIncludedComponentIds)) {
      foreach ($stackIncludedComponentIds as $componentId) {
        if (isset($components[$componentId])) {
          unset($components[$componentId]);
        }
      }
    }

    $entities = array_merge($components, $stacks);

    $entitiesByUser = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity */
    foreach ($entities as $entity) {
      $bundleInfo = $this->bundleInfo->getBundleInfo($entity->getEntityTypeId())[$entity->bundle()];
      if ($entity->getOwner() !== NULL && $entity->getOwner()->getDisplayName() !== NULL) {
        $username = $entity->getOwner()->getDisplayName();
      }
      else {
        $username = 'deleted user';
      }

      // Use service link for the card URL when available.
      if ($entity->getEntityTypeId() === 'soda_scs_stack') {
        $url = Url::fromRoute('soda_scs_manager.stack.service_link', [
          'soda_scs_stack' => $entity->id(),
        ]);
      }
      else {
        $url = Url::fromRoute('soda_scs_manager.component.service_link', [
          'soda_scs_component' => $entity->id(),
        ]);
      }

      if (in_array($entity->bundle(), [
        'soda_scs_wisski_component',
        'soda_scs_wisski_stack',
        'soda_scs_sql_component',
        'soda_scs_triplestore_component',
      ])) {
        $detailsLink = Url::fromRoute('entity.' .
          $entity->getEntityTypeId() .
          '.canonical',
          [
            'bundle' => $entity->bundle(),
            $entity->getEntityTypeId() => $entity->id(),
          ]);
      }
      else {
        $detailsLink = NULL;
      }

      $entitiesByUser[$username][] = [
        '#theme' => 'soda_scs_manager__entity_card',
        '#title' => $this->t('@bundle', ['@bundle' => $entity->label()]),
        '#type' => $bundleInfo['label']->render(),
        // Bundle description: translates with UI; stored field is English.
        '#description' => $bundleInfo['description']->render(),
        '#details_link' => $detailsLink,
        '#entity_id' => $entity->id(),
        '#entity_type_id' => $entity->getEntityTypeId(),
        '#health_status' => $entity->get('health')->value ?? 'Unknown',
        '#imageUrl' => $bundleInfo['imageUrl'],
        '#learn_more_link' => $this->internalPathUrl('soda-scs-manager/app/' . $this->sodaScsHelpers->getEntityType($entity->bundle())),
        '#url' => $url,
        '#tags' => $bundleInfo['tags'],
        '#cache' => [
          'max-age' => 0,
        ],
      ];
    }

    // Ensure the current user has a section so "Your applications" and the (+)
    // control show even with zero apps.
    $currentUsername = $current_user->getDisplayName();
    if (!isset($entitiesByUser[$currentUsername])) {
      $entitiesByUser[$currentUsername] = [];
    }

    $modulePath = $this->moduleHandler()->getModule('soda_scs_manager')->getPath();
    $assetBase = '/' . $modulePath . '/assets/images/';

    // Sort entitiesByUser alphabetically by using the keys (= usernames).
    ksort($entitiesByUser);

    // Move current user to the top.
    $entitiesByUser = $this->moveKeyToFirstPosition($entitiesByUser, $currentUsername);

    $uid = (int) $current_user->id();

    $dashboardLibraries = [
      'soda_scs_manager/globalStyling',
      'soda_scs_manager/tagFilter',
      'soda_scs_manager/dashboardHealthStatus',
      'soda_scs_manager/addApplicationPopup',
    ];

    $build = [
      '#theme' => 'soda_scs_manager__dashboard',
      '#attributes' => ['class' => 'container soda-scs-manager--view--grid'],
      '#entitiesByUser' => $entitiesByUser,
      '#entitiesByProject' => $entitiesByProject,
      '#currentUsername' => $current_user->getDisplayName(),
      '#add_application_trigger' => [
        '#theme' => 'soda_scs_manager__add_application_heading_trigger',
        '#asset_base' => $assetBase,
        '#popup_url_mariadb' => $this->internalPathUrl('soda-scs-manager/app/mariadb'),
        '#popup_url_wisski' => $this->internalPathUrl('soda-scs-manager/app/wisski'),
        '#popup_url_open_gdb' => $this->internalPathUrl('soda-scs-manager/app/open-gdb'),
        '#cache' => [
          'max-age' => 0,
        ],
      ],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user'],
      ],
      '#attached' => [
        'library' => $dashboardLibraries,
      ],
    ];

    $this->attachCoworkingIntroForUser($build, $uid);

    return $build;
  }

  /**
   * Redirects the manager base URL to the dashboard.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the dashboard route.
   */
  public function redirectManagerRoot() {
    return $this->redirect('soda_scs_manager.dashboard');
  }

  /**
   * Start page for SCS Manager.
   *
   * @return array
   *   The page build array.
   */
  public function startPage(): array {

    // Get the current user.
    /** @var \Drupal\Core\Session\AccountInterface $currentUser */
    $currentUser = $this->currentUser();
    /** @var \Drupal\user\UserInterface $userEntity */
    $userEntity = $this->entityTypeManager->getStorage('user')->load($currentUser->id());
    $userFirstName = $currentUser && $userEntity && $userEntity->hasField('first_name') ? $userEntity->get('first_name')->value : $currentUser->getAccountName();
    $connectedAccountsUrl = Url::fromRoute('openid_connect.accounts_controller_index', [
      'user' => $currentUser->id(),
    ])->toString();

    $uid = (int) $currentUser->id();
    $build = [
      '#theme' => 'soda_scs_manager__start_page',
      '#attributes' => ['class' => ['container', 'mx-auto']],
      '#user' => $userFirstName,
      '#connected_accounts_url' => $connectedAccountsUrl,
      '#attached' => [
        'library' => [
          'soda_scs_manager/globalStyling',
          'soda_scs_manager/startPagePie',
          'soda_scs_manager/nextcloudConnect',
        ],
      ],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user'],
      ],
    ];

    $this->attachCoworkingIntroForUser($build, $uid);

    return $build;
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

  /**
   * Sets theme variables and assets for the co-working intro wizard.
   *
   * @param array $build
   *   Render array for dashboard or start page.
   * @param int $uid
   *   User ID whose completion flag is read (normally the current user).
   */
  protected function attachCoworkingIntroForUser(array &$build, int $uid): void {
    $lang = $this->languageManager()->getCurrentLanguage();
    $modulePath = $this->moduleHandler()->getModule('soda_scs_manager')->getPath();
    $assetBase = '/' . $modulePath . '/assets/images/';
    $introCompleted = (bool) $this->userData->get('soda_scs_manager', $uid, 'coworking_intro_completed');
    $showWizard = !$introCompleted;

    $connectedAccountsUrl = Url::fromRoute('openid_connect.accounts_controller_index', [
      'user' => $uid,
    ], [
      'language' => $lang,
    ])->toString();

    $build['#intro_coworking_wizard'] = $showWizard;
    $build['#intro_asset_base'] = $assetBase;
    $build['#intro_connected_accounts_url'] = $connectedAccountsUrl;

    if (!$showWizard) {
      return;
    }

    if (!isset($build['#attached']['library']) || !is_array($build['#attached']['library'])) {
      $build['#attached']['library'] = [];
    }
    $build['#attached']['library'][] = 'soda_scs_manager/coworkingIntroWizard';
    if (!in_array('soda_scs_manager/nextcloudConnect', $build['#attached']['library'], TRUE)) {
      $build['#attached']['library'][] = 'soda_scs_manager/nextcloudConnect';
    }

    $introCompleteUrl = Url::fromRoute('soda_scs_manager.coworking_intro_complete', [], [
      'language' => $lang,
    ])->toString();
    $introCreateWisskiUrl = $this->coworkingIntroWisskiQuickCreateUrl($lang);

    $build['#attached']['drupalSettings']['sodaScsManager']['throbberPrimaryMessage'] = (string) $this->t('Creating your WissKI environment. Please do not close this window.');
    $build['#attached']['drupalSettings']['sodaScsManager']['throbberInfo'] = (string) $this->t('Please note: After creating the WissKI Environment, it can take up to 5 minutes to setup everything.<br><br>Please check the health status to monitor the startup progress.');
    $build['#attached']['drupalSettings']['sodaScsManager']['coworkingIntro'] = [
      'completeUrl' => $introCompleteUrl,
      'wisskiQuickCreateUrl' => $introCreateWisskiUrl,
    ];
  }

  /**
   * Relative URL for an internal path, respecting interface language (prefix).
   *
   * @param string $path
   *   Path without leading slash, e.g. soda-scs-manager/app/wisski.
   *
   * @return string
   *   Generated path (may include a language prefix).
   */
  protected function internalPathUrl(string $path): string {
    $path = ltrim($path, '/');
    return Url::fromUri('internal:/' . $path, [
      'language' => $this->languageManager()->getCurrentLanguage(),
      'path_processing' => TRUE,
    ])->toString();
  }

  /**
   * Relative URL for the intro wizard POST endpoint that provisions WissKI.
   *
   * Uses the named route when the router knows it; otherwise falls back to the
   * path from soda_scs_manager.routing.yml so fetch() still works before
   * drush cr.
   */
  protected function coworkingIntroWisskiQuickCreateUrl(LanguageInterface $lang): string {
    try {
      return Url::fromRoute('soda_scs_manager.coworking_intro_create_wisski', [], [
        'language' => $lang,
      ])->toString();
    }
    catch (\Throwable) {
      try {
        return Url::fromUserInput('/soda-scs-manager/intro/coworking/create-wisski', [
          'language' => $lang,
        ])->toString();
      }
      catch (\Throwable) {
        return '';
      }
    }
  }

  /**
   * Add function to re-order Dashboard order for admin people.
   *
   * @param array $array
   *   The array to re-order.
   * @param string $key
   *   The key to move to the first position.
   *
   * @return array
   *   The re-ordered array.
   */
  public function moveKeyToFirstPosition(array $array, $key): array {
    // Check if the key exists.
    if (!array_key_exists($key, $array)) {
      // Key not found, return original array.
      return $array;
    }

    // Extract the target element as a single-key array.
    $targetElement = [$key => $array[$key]];

    // Remove the target element from the original array.
    unset($array[$key]);

    // Merge the target element with the remaining array.
    return $targetElement + $array;
  }

}
