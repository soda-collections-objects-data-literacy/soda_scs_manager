<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Demo\SodaScsDemoStatusBuilder;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Drupal\soda_scs_manager\Service\SodaScsApplicationCardBuilder;
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
   * Builds dashboard entity cards.
   *
   * @var \Drupal\soda_scs_manager\Service\SodaScsApplicationCardBuilder
   */
  protected SodaScsApplicationCardBuilder $applicationCardBuilder;

  /**
   * Builds the demo badge of demo projects.
   *
   * @var \Drupal\soda_scs_manager\Demo\SodaScsDemoStatusBuilder
   */
  protected SodaScsDemoStatusBuilder $demoStatusBuilder;

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
   * @param \Drupal\soda_scs_manager\Demo\SodaScsDemoStatusBuilder $demoStatusBuilder
   *   Demo badge builder.
   */
  public function __construct(
    SodaScsApplicationCardBuilder $applicationCardBuilder,
    AccountInterface $currentUser,
    EntityTypeManagerInterface $entityTypeManager,
    SodaScsHelpers $sodaScsHelpers,
    SodaScsDemoStatusBuilder $demoStatusBuilder,
  ) {
    $this->applicationCardBuilder = $applicationCardBuilder;
    $this->currentUser = $currentUser;
    $this->entityTypeManager = $entityTypeManager;
    $this->sodaScsHelpers = $sodaScsHelpers;
    $this->demoStatusBuilder = $demoStatusBuilder;
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
      $container->get('soda_scs_manager.demo.status_builder'),
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
      $memberProfiles = [];
      $userStorage = $this->entityTypeManager->getStorage('user');
      foreach (array_values($memberIds) as $memberId) {
        /** @var \Drupal\user\UserInterface|null $member */
        $member = $userStorage->load($memberId);
        if (!$member) {
          continue;
        }

        $firstName = $member->hasField('first_name') ? trim((string) $member->get('first_name')->value) : '';
        $lastName = $member->hasField('last_name') ? trim((string) $member->get('last_name')->value) : '';
        $memberName = trim($firstName . ' ' . $lastName);
        if ($memberName === '') {
          $memberName = (string) $member->getDisplayName();
        }

        $initials = '';
        if ($firstName !== '') {
          $initials .= mb_substr($firstName, 0, 1);
        }
        if ($lastName !== '') {
          $initials .= mb_substr($lastName, 0, 1);
        }
        if ($initials === '') {
          $initials = mb_substr($memberName, 0, 1);
        }

        $memberProfiles[] = [
          'name' => $memberName,
          'initials' => mb_strtoupper($initials),
          'is_owner' => $memberId === $ownerId,
          'picture' => $member->hasField('user_picture') && !$member->get('user_picture')->isEmpty()
            ? $member->get('user_picture')->view(['label' => 'hidden'])
            : [],
        ];
      }

      $projectCards[] = [
        '#theme' => 'soda_scs_manager__project_card',
        '#project_id' => (int) $project->id(),
        '#project_updated' => $project->get('updated')->value,
        '#label' => $project->label(),
        '#owner_id' => $ownerId,
        '#url' => Url::fromRoute('entity.soda_scs_project.canonical', [
          'soda_scs_project' => $project->id(),
        ])->toString(),
        '#is_owner' => $ownerId === $uid,
        '#members_ids' => $memberIds,
        '#member_profiles' => $memberProfiles,
        '#members_count' => $membersCount,
        '#other_member_names' => array_values(array_filter(array_map(
          static fn(array $profile): string => empty($profile['is_owner']) ? (string) ($profile['name'] ?? '') : '',
          $memberProfiles,
        ))),
        '#applications' => $applications,
        '#tags' => $sortedTags,
        '#demo_status' => $this->demoStatusBuilder->build($current_user, $project, SodaScsDemoStatusBuilder::VARIANT_BADGE),
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
    // An empty first name (e.g. admin or fresh SSO accounts) would reach the
    // template as a NULL placeholder; fall back to the account name.
    $userFirstName = $userEntity && $userEntity->hasField('first_name') ? trim((string) $userEntity->get('first_name')->value) : '';
    if ($userFirstName === '') {
      $userFirstName = $currentUser->getAccountName();
    }
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
