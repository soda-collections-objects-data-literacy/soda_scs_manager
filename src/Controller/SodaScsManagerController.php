<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Drupal\soda_scs_manager\Service\SodaScsApplicationCardBuilder;
use Drupal\user\UserDataInterface;
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
   * Builds dashboard entity cards.
   *
   * @var \Drupal\soda_scs_manager\Service\SodaScsApplicationCardBuilder
   */
  protected SodaScsApplicationCardBuilder $applicationCardBuilder;

  /**
   * Class constructor.
   *
   * @param \Drupal\soda_scs_manager\Service\SodaScsApplicationCardBuilder $applicationCardBuilder
   *   Application card builder.
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
    SodaScsApplicationCardBuilder $applicationCardBuilder,
    AccountInterface $currentUser,
    EntityTypeManagerInterface $entityTypeManager,
    SodaScsHelpers $sodaScsHelpers,
    UserDataInterface $userData,
  ) {
    $this->applicationCardBuilder = $applicationCardBuilder;
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
      $container->get('soda_scs_manager.application_card_builder'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.helpers'),
      $container->get('user.data'),
    );
  }

  /**
   * Page for project-centric application management.
   *
   * @return array
   *   The page build array.
   */
  public function dashboardPage(): array {
    $current_user = $this->currentUser();
    $uid = (int) $current_user->id();

    try {
      $projects = $this->loadDashboardProjects($current_user);
    }
    catch (EntityStorageException $e) {
      $this->messenger()->addError($this->t('Error loading projects: @error', ['@error' => $e->getMessage()]));
      return [];
    }

    $centralServiceCards = $this->applicationCardBuilder->buildCentralServiceCardsForUser($uid);

    $projectCards = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    foreach ($projects as $project) {
      $ownerId = (int) ($project->getOwnerId() ?? 0);
      $applications = $this->applicationCardBuilder->buildApplicationSummariesForProject($project);
      $tags = [];
      foreach ($applications as $application) {
        foreach ($application['tags'] as $tag) {
          if (is_string($tag) && $tag !== '') {
            $tags[$tag] = $tag;
          }
        }
      }
      $sortedTags = array_values($tags);
      sort($sortedTags);

      $memberIds = [];
      if ($ownerId > 0) {
        $memberIds[$ownerId] = $ownerId;
      }
      if ($project->hasField('members') && !$project->get('members')->isEmpty()) {
        foreach ($project->get('members')->getValue() as $memberItem) {
          $memberId = (int) ($memberItem['target_id'] ?? 0);
          if ($memberId > 0) {
            $memberIds[$memberId] = $memberId;
          }
        }
      }
      $membersCount = count($memberIds);

      $projectCards[] = [
        '#theme' => 'soda_scs_manager__project_card',
        '#project_id' => (int) $project->id(),
        '#label' => $project->label(),
        '#url' => Url::fromRoute('entity.soda_scs_project.canonical', [
          'soda_scs_project' => $project->id(),
        ])->toString(),
        '#is_owner' => $ownerId === $uid,
        '#members_count' => $membersCount,
        '#applications' => $applications,
        '#tags' => $sortedTags,
        '#cache' => [
          'max-age' => 0,
          'contexts' => ['user', 'languages:language_interface'],
          'tags' => $project->getCacheTags(),
        ],
      ];
    }

    usort($projectCards, static function (array $a, array $b): int {
      return strnatcasecmp((string) $a['#label'], (string) $b['#label']);
    });

    $build = [
      '#theme' => 'soda_scs_manager__dashboard',
      '#attributes' => ['class' => 'container soda-scs-manager--view--grid'],
      '#central_service_cards' => $centralServiceCards,
      '#project_cards' => $projectCards,
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user', 'languages:language_interface'],
      ],
      '#attached' => [
        'library' => [
          'soda_scs_manager/globalStyling',
          'soda_scs_manager/tagFilter',
          'soda_scs_manager/dashboardHealthStatus',
        ],
        'drupalSettings' => [
          'sodaScsManager' => [
            'dashboardAdminMail' => (string) ($this->config('system.site')->get('mail') ?? ''),
          ],
        ],
      ],
    ];

    $this->attachCoworkingIntroForUser($build, $uid);

    return $build;
  }

  /**
   * Loads projects visible on the dashboard for the given account.
   *
   * Admins see all projects; others see projects they own or are members of.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface[]
   *   Projects keyed by entity ID.
   */
  protected function loadDashboardProjects(AccountInterface $account): array {
    $projectStorage = $this->entityTypeManager->getStorage('soda_scs_project');

    if ($account->hasPermission('soda scs manager admin')) {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface[] $projects */
      $projects = $projectStorage->loadMultiple();
      return $projects;
    }

    $query = $projectStorage->getQuery()->accessCheck(TRUE);
    $orGroup = $query->orConditionGroup()
      ->condition('owner', $account->id())
      ->condition('members', $account->id());
    $projectIds = $query->condition($orGroup)->execute();

    if (empty($projectIds)) {
      return [];
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface[] $projects */
    $projects = $projectStorage->loadMultiple($projectIds);
    return $projects;
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
    _soda_scs_manager_attach_nextcloud_connect_settings($build);

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

    $build['#intro_gif_src']   = _soda_scs_manager_coworking_intro_gif_src($modulePath);
    $build['#intro_mp4_src']   = _soda_scs_manager_coworking_intro_mp4_src($modulePath);
    $build['#intro_mp4_src_2'] = _soda_scs_manager_coworking_intro_mp4_src_2($modulePath);
    $build['#intro_mp4_src_3'] = _soda_scs_manager_coworking_intro_mp4_src_3($modulePath);

    if (!isset($build['#attached']['library']) || !is_array($build['#attached']['library'])) {
      $build['#attached']['library'] = [];
    }
    $build['#attached']['library'][] = 'soda_scs_manager/coworkingIntroWizard';
    if (!in_array('soda_scs_manager/nextcloudConnect', $build['#attached']['library'], TRUE)) {
      $build['#attached']['library'][] = 'soda_scs_manager/nextcloudConnect';
    }
    _soda_scs_manager_attach_nextcloud_connect_settings($build);

    $introCompleteUrl = Url::fromRoute('soda_scs_manager.coworking_intro_complete', [], [
      'language' => $lang,
    ])->toString();
    $introCreateWisskiUrl = $this->coworkingIntroWisskiQuickCreateUrl($lang);
    $introCheckWisskiNameUrl = $this->coworkingIntroWisskiNameCheckUrl($lang);

    $build['#attached']['drupalSettings']['sodaScsManager']['throbberPrimaryMessage'] = (string) $this->t('Creating your WissKI environment. Please do not close this window.');
    $build['#attached']['drupalSettings']['sodaScsManager']['throbberInfo'] = (string) $this->t('Please note: After creating the WissKI Environment, it can take up to 5 minutes to setup everything.<br><br>Please check the health status to monitor the startup progress.');
    $build['#attached']['drupalSettings']['sodaScsManager']['coworkingIntro'] = [
      'completeUrl' => $introCompleteUrl,
      'wisskiQuickCreateUrl' => $introCreateWisskiUrl,
      'wisskiNameCheckUrl' => $introCheckWisskiNameUrl,
    ];
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
   * Relative URL for the intro wizard GET that checks WissKI name availability.
   */
  protected function coworkingIntroWisskiNameCheckUrl(LanguageInterface $lang): string {
    try {
      return Url::fromRoute('soda_scs_manager.coworking_intro_check_wisski_name', [], [
        'language' => $lang,
      ])->toString();
    }
    catch (\Throwable) {
      try {
        return Url::fromUserInput('/soda-scs-manager/intro/coworking/check-wisski-name', [
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
