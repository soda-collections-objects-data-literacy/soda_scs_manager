<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\externalauth\AuthmapInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\soda_scs_manager\ValueObject\SodaScsKeycloakGroupData;

/**
 * Helper class for SodaSCS project operations.
 */
class SodaScsProjectHelpers {
  use StringTranslationTrait;

  /**
   * Default label for a user's first project.
   */
  public const DEFAULT_PROJECT_LABEL = 'Project 1';

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'externalauth.authmap')]
    protected AuthmapInterface $authmap,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    protected SodaScsServiceHelpers $sodaScsServiceHelpers,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.client.actions')]
    protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.group.actions')]
    protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.user.actions')]
    protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions,
    #[Autowire(service: 'soda_scs_manager.nextcloud_service.actions')]
    protected SodaScsNextcloudServiceActions $sodaScsNextcloudServiceActions,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->settings = $this->configFactory->getEditable('soda_scs_manager.settings');
    $this->entityTypeManager = $entityTypeManager;
    $this->authmap = $authmap;
    $this->sodaScsKeycloakServiceClientActions = $sodaScsKeycloakServiceClientActions;
    $this->sodaScsKeycloakServiceGroupActions = $sodaScsKeycloakServiceGroupActions;
    $this->sodaScsKeycloakServiceUserActions = $sodaScsKeycloakServiceUserActions;
    $this->sodaScsNextcloudServiceActions = $sodaScsNextcloudServiceActions;
    $this->logger = $loggerFactory->get('soda_scs_manager');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('externalauth.authmap'),
      $container->get('soda_scs_manager.service.helpers'),
      $container->get('soda_scs_manager.keycloak_service.client.actions'),
      $container->get('soda_scs_manager.keycloak_service.group.actions'),
      $container->get('soda_scs_manager.keycloak_service.user.actions'),
      $container->get('soda_scs_manager.nextcloud_service.actions'),
      $container->get('logger.factory'),
    );
  }

  /**
   * The settings configuration storage.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * Get the SSO UUID for a user from the external auth map.
   *
   * Selects the OpenID Connect provider using the configured Keycloak client
   * id at 'keycloak.keycloakTabs.generalSettings.fields.OpenIdConnectClientId'.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to resolve.
   *
   * @return string|null
   *   The SSO UUID (authname / sub) or NULL if not connected.
   */
  public function getUserSsoUuid(UserInterface $user): ?string {
    // Load client id from SodaScsServiceHelpers::initKeycloakGeneralSettings().
    $keycloakGeneralSettings = $this->sodaScsServiceHelpers->initKeycloakGeneralSettings();
    $clientId = (string) ($keycloakGeneralSettings['openIdConnectClientMachineName'] ?? '');
    if ($clientId === '') {
      return NULL;
    }
    $provider = 'openid_connect.' . $clientId;
    $authname = $this->authmap->get((int) $user->id(), $provider);
    return is_string($authname) && $authname !== '' ? $authname : NULL;
  }

  /**
   * Check if the project group exists.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project entity.
   *
   * @return bool
   *   TRUE if the project group exists, FALSE otherwise.
   */
  public function checkIfProjectGroupExists(SodaScsProjectInterface $project) {
    $projectGroupId = (string) ($project->get('groupId')->value ?? '');
    if ($projectGroupId === '') {
      return [
        'success' => FALSE,
        'data' => NULL,
        'error' => $this->t('No project group ID found for project @project.', [
          '@project' => $project->label(),
        ]),
        'message' => $this->t('No project group ID found for project @project.', [
          '@project' => $project->label(),
        ]),
      ];
    }
    // Get Keycloak token.
    $tokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
    $tokenResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($tokenRequest);
    if (!$tokenResponse['success']) {
      return [
        'success' => FALSE,
        'data' => NULL,
        'error' => $tokenResponse['error'],
        'message' => $this->t('Failed to get Keycloak token.'),
      ];
    }
    $tokenBody = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
    $kcToken = $tokenBody['access_token'] ?? NULL;

    // Double check if token is set.
    if (!$kcToken) {
      return [
        'success' => FALSE,
        'data' => NULL,
        'error' => $this->t('No Keycloak token found.'),
        'message' => $this->t('No Keycloak token found.'),
      ];
    }

    // Get Keycloak groups.
    $groupsReq = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
      'token' => $kcToken,
      'queryParams' => ['briefRepresentation' => 'false', 'populateHierarchy' => 'true'],
      'type' => 'groups',
    ]);
    $groupsRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($groupsReq);
    if (!$groupsRes['success']) {
      return [
        'success' => FALSE,
        'data' => NULL,
        'error' => $groupsRes['error'],
        'message' => $this->t('Failed to get Keycloak groups for project @project.', [
          '@project' => $project->label(),
        ]),
      ];
    }

    $groups = json_decode($groupsRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
    foreach ($groups as $group) {
      if ($group['name'] === $projectGroupId) {
        return [
          'success' => TRUE,
          'data' => $group,
          'error' => NULL,
          'message' => $this->t('Keycloak group @projectGroupId for project @project already exists.', [
            '@projectGroupId' => $projectGroupId,
            '@project' => $project->label(),
          ]),
        ];
      }
    }
    return [
      'success' => TRUE,
      'data' => NULL,
      'error' => NULL,
      'message' => $this->t('Keycloak group @projectGroupId for project @project does not exist.', [
        '@projectGroupId' => $projectGroupId,
        '@project' => $project->label(),
      ]),
    ];
  }

  /**
   * Create a project group.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project entity.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the operation.
   */
  public function createProjectGroup(SodaScsProjectInterface $project): SodaScsResult {
    // Data should be null if the group does not exist.
    if ($this->checkIfProjectGroupExists($project)['data']) {
      return SodaScsResult::failure(
        (string) $this->t('Keycloak group @projectGroupId for project @project already exists.'),
        (string) $this->t('Could not create Keycloak group @projectGroupId for project @project.', [
          '@projectGroupId' => $project->get('groupId')->value,
          '@project' => $project->label(),
        ]),
      );
    }

    // Check if the project group ID is set in the project entity.
    $projectGroupId = (string) ($project->get('groupId')->value ?? '');
    if ($projectGroupId === '') {
      return SodaScsResult::failure(
        (string) $this->t('No project group ID found for project @project.'),
        (string) $this->t('Could not create Keycloak group @projectGroupId for project @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    // Get Keycloak token.
    $tokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
    $tokenResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($tokenRequest);
    if (!$tokenResponse['success']) {
      return SodaScsResult::failure(
        $tokenResponse['error'],
        (string) $this->t('Could not create keycloak group with group id @projectGroupId for project @project.', [
          '@projectGroupId' => $projectGroupId,
          '@project' => $project->label(),
        ]),
      );
    }
    $tokenBody = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
    $kcToken = $tokenBody['access_token'] ?? NULL;

    // Double check if token is set.
    if (!$kcToken) {
      return SodaScsResult::failure(
        (string) $this->t('No Keycloak token found.'),
        (string) $this->t('Could not create keycloak group with group id @projectGroupId for project @project.', [
          '@projectGroupId' => $projectGroupId,
          '@project' => $project->label(),
        ]),
      );
    }

    // Create keycloak group (name = stable machine id; label is an attribute).
    $groupsReq = $this->sodaScsKeycloakServiceGroupActions->buildCreateRequest([
      'token' => $this->getKeycloakToken(),
      'body' => [
        'name' => $projectGroupId,
        'attributes' => $this->buildProjectGroupAttributes($project),
      ],
    ]);

    $groupsRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($groupsReq);
    if (!$groupsRes['success']) {
      return SodaScsResult::failure(
        $groupsRes['error'],
        (string) $this->t('Failed to create Keycloak group @projectGroupId for project @project.', [
          '@projectGroupId' => $projectGroupId,
          '@project' => $project->label(),
        ]),
      );
    }

    // Get keycloak group uuid.
    $getAllGroupsReq = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
      'token' => $this->getKeycloakToken(),
      'queryParams' => ['briefRepresentation' => 'false', 'populateHierarchy' => 'true'],
    ]);
    $getAllGroupsRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($getAllGroupsReq);
    if (!$getAllGroupsRes['success']) {
      return SodaScsResult::failure(
        $getAllGroupsRes['error'],
        (string) $this->t('Failed to get Keycloak groups for project @project.', [
          '@project' => $project->label(),
        ]),
      );
    }
    $getAllGroups = json_decode($getAllGroupsRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
    foreach ($getAllGroups as $group) {
      if ($group['name'] === $projectGroupId) {
        $keycloakGroup = $group;
        break;
      }
    }
    $keycloakUuid = $keycloakGroup['id'] ?? NULL;

    $groupData = SodaScsKeycloakGroupData::create(
      groupId: $projectGroupId,
      name: $project->label(),
      uuid: $keycloakUuid,
    );

    return SodaScsResult::success(
      data: ['keycloakGroupData' => $groupData],
      message: (string) $this->t('Created Keycloak group @projectGroupId for project @project.', [
        '@projectGroupId' => $projectGroupId,
        '@project' => $project->label(),
      ]),
    );
  }

  /**
   * Get the groups of the members of a project.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project entity.
   *
   * @return array
   *   The groups of the members of the project.
   */
  public function getGroupsOfMembers(SodaScsProjectInterface $project) {
    $projectGroupId = (string) ($project->get('groupId')->value ?? '');
    $projectGroups = [];
    $tokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
    $tokenResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($tokenRequest);
    if ($tokenResponse['success']) {
      $tokenBody = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
      $kcToken = $tokenBody['access_token'] ?? NULL;
    }
    if ($kcToken) {
      $groupsReq = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
        'token' => $kcToken,
        'queryParams' => ['briefRepresentation' => 'false', 'populateHierarchy' => 'true'],
      ]);
      $groupsRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($groupsReq);
      if ($groupsRes['success']) {
        $groups = json_decode($groupsRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
        foreach ($groups as $group) {
          $gidValues = $group['attributes']['gid'] ?? [];
          if (is_array($gidValues) && in_array($projectGroupId, $gidValues, TRUE)) {
            $projectGroups[] = $group;
          }
        }
      }
    }
    return $projectGroups;
  }

  /**
   * Add members to a project group.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project entity.
   */
  public function addMembersToProjectGroup(SodaScsProjectInterface $project) {
    $projectGroupId = (string) ($project->get('groupId')->value ?? '');
    if ($projectGroupId !== '') {
      // Get Keycloak admin token.
      $tokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
      $tokenResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($tokenRequest);
      if ($tokenResponse['success']) {
        $tokenBody = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
        $kcToken = $tokenBody['access_token'] ?? NULL;
        if ($kcToken) {
          // Fetch all groups and find the project group by gid attribute.
          $groupsReq = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
            'token' => $kcToken,
            'queryParams' => [
              // Ensure attributes (including gid) are included.
              'briefRepresentation' => 'false',
              'populateHierarchy' => 'true',
            ],
          ]);
          $groupsRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($groupsReq);
          if ($groupsRes['success']) {
            $groups = json_decode($groupsRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
            $projectGroup = NULL;
            foreach ($groups as $group) {
              $gidValues = $group['attributes']['gid'] ?? [];
              if (is_array($gidValues) && in_array($projectGroupId, $gidValues, TRUE)) {
                $projectGroup = $group;
                break;
              }
            }
            if ($projectGroup) {
              $members = $project->get('members')->getValue() ?? [];
              foreach ($members as $memberRef) {
                if (!is_array($memberRef) || !isset($memberRef['target_id'])) {
                  continue;
                }
                /** @var \Drupal\user\Entity\User $user */
                $user = $this->entityTypeManager->getStorage('user')->load($memberRef['target_id']);
                if (!$user) {
                  continue;
                }
                // Look up Keycloak user by username.
                $getUsersReq = $this->sodaScsKeycloakServiceUserActions->buildGetAllRequest([
                  'token' => $kcToken,
                  'queryParams' => ['username' => $user->getDisplayName()],
                ]);
                $getUsersRes = $this->sodaScsKeycloakServiceUserActions->makeRequest($getUsersReq);
                if (!$getUsersRes['success']) {
                  continue;
                }
                $kcUsers = json_decode($getUsersRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
                if (empty($kcUsers) || !isset($kcUsers[0]['id'])) {
                  continue;
                }
                $kcUserId = $kcUsers[0]['id'];
                // Add user to group (idempotent on Keycloak side).
                $addReq = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
                  'type' => 'group',
                  'routeParams' => [
                    'userId' => $kcUserId,
                    'groupId' => $projectGroup['id'],
                  ],
                  'token' => $kcToken,
                ]);
                $this->sodaScsKeycloakServiceUserActions->makeRequest($addReq);
              }
            }
          }
        }
      }
    }
  }

  /**
   * Update the project group.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project entity.
   */
  public function updateProjectGroup(SodaScsProjectInterface $project) {
    $projectGroupId = (string) ($project->get('groupId')->value ?? '');
    if ($projectGroupId !== '') {
      $tokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
      $tokenResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($tokenRequest);
      if ($tokenResponse['success']) {
        $tokenBody = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
        $kcToken = $tokenBody['access_token'] ?? NULL;
      }
    }
    if ($kcToken) {
      $groupsReq = $this->sodaScsKeycloakServiceGroupActions->buildUpdateRequest([
        'token' => $kcToken,
        'routeParams' => ['groupId' => $projectGroupId],
        'body' => ['name' => $projectGroupId],
      ]);
      $groupsRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($groupsReq);
      if ($groupsRes['success']) {
        return TRUE;
      }
      return FALSE;
    }
  }

  /**
   * Remove members from a project group.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project entity.
   */
  public function removeMembersFromProjectGroup(SodaScsProjectInterface $project) {
    $projectGroupId = (string) ($project->get('groupId')->value ?? '');
    if ($projectGroupId !== '') {
      // Get Keycloak admin token.
      $tokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
      $tokenResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($tokenRequest);
      if ($tokenResponse['success']) {
        $tokenBody = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
        $kcToken = $tokenBody['access_token'] ?? NULL;
        if ($kcToken) {
          // Fetch all groups and find the project group by gid attribute.
          $groupsReq = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
            'token' => $kcToken,
            'queryParams' => [
              // Ensure attributes (including gid) are included.
              'briefRepresentation' => 'false',
              'populateHierarchy' => 'true',
            ],
          ]);
          $groupsRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($groupsReq);
          if ($groupsRes['success']) {
            $groups = json_decode($groupsRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
            $projectGroup = NULL;
            foreach ($groups as $group) {
              $gidValues = $group['attributes']['gid'] ?? [];
              if (is_array($gidValues) && in_array($projectGroupId, $gidValues, TRUE)) {
                $projectGroup = $group;
                break;
              }
            }
            if ($projectGroup) {
              $members = $project->get('members')->getValue() ?? [];
              foreach ($members as $memberRef) {
                if (!is_array($memberRef) || !isset($memberRef['target_id'])) {
                  continue;
                }
                /** @var \Drupal\user\Entity\User $user */
                $user = $this->entityTypeManager->getStorage('user')->load($memberRef['target_id']);
                if (!$user) {
                  continue;
                }
                // Look up Keycloak user by username.
                $getUsersReq = $this->sodaScsKeycloakServiceUserActions->buildGetAllRequest([
                  'token' => $kcToken,
                  'queryParams' => ['username' => $user->getDisplayName()],
                ]);
                $getUsersRes = $this->sodaScsKeycloakServiceUserActions->makeRequest($getUsersReq);
                if (!$getUsersRes['success']) {
                  continue;
                }
                $kcUsers = json_decode($getUsersRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
                if (empty($kcUsers) || !isset($kcUsers[0]['id'])) {
                  continue;
                }
                $kcUserId = $kcUsers[0]['id'];
                // Remove user from group (idempotent on Keycloak side).
                $removeReq = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
                  'type' => 'group',
                  'routeParams' => [
                    'userId' => $kcUserId,
                    'groupId' => $projectGroup['id'],
                  ],
                  'token' => $kcToken,
                ]);
                $this->sodaScsKeycloakServiceUserActions->makeRequest($removeReq);
              }
            }
          }
        }
      }
    }
  }

  /**
   * Get the members of a group.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project entity.
   *
   * @return array
   *   The members of the group.
   */
  public function getMembersOfGroup(SodaScsProjectInterface $project) {
    $projectGroupId = (string) ($project->get('groupId')->value ?? '');
    $members = [];
    $tokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
    $tokenResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($tokenRequest);
    if ($tokenResponse['success']) {
      $tokenBody = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
      $kcToken = $tokenBody['access_token'] ?? NULL;
    }
    if ($kcToken) {
      $groupsReq = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
        'token' => $kcToken,
        'queryParams' => ['briefRepresentation' => 'false', 'populateHierarchy' => 'true'],
      ]);
      $groupsRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($groupsReq);
      if ($groupsRes['success']) {
        $groups = json_decode($groupsRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
        foreach ($groups as $group) {
          $gidValues = $group['attributes']['gid'] ?? [];
          if (is_array($gidValues) && in_array($projectGroupId, $gidValues, TRUE)) {
            $members = $group['members'] ?? [];
          }
        }
      }
    }
    return $members;
  }

  /**
   * Delete the project group from Keycloak.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project entity.
   */
  public function deleteProjectGroup(SodaScsProjectInterface $project) {
    $projectGroupUuid = (string) ($project->get('keycloakUuid')->value ?? '');
    if (!$projectGroupUuid) {
      return [
        'success' => FALSE,
        'data' => NULL,
        'error' => 'Project group UUID is empty',
        'message' => $this->t('Project group UUID is empty for project @project.', [
          '@project' => $project->label(),
        ]),
      ];
    }

    $deleteReq = $this->sodaScsKeycloakServiceGroupActions->buildDeleteRequest([
      'token' => $this->getKeycloakToken(),
      'routeParams' => ['groupId' => $projectGroupUuid],
    ]);

    $deleteRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($deleteReq);
    if (!$deleteRes['success']) {
      return [
        'success' => FALSE,
        'data' => NULL,
        'error' => $deleteRes['error'],
        'message' => $this->t('Failed to delete project group @projectGroupUuid from Keycloak for project @project.', [
          '@projectGroupUuid' => $projectGroupUuid,
          '@project' => $project->label(),
        ]),
      ];
    }

    return [
      'success' => TRUE,
      'data' => NULL,
      'error' => NULL,
      'message' => $this->t('Successfully deleted project group @projectGroupUuid from Keycloak for project @project.', [
        '@projectGroupUuid' => $projectGroupUuid,
        '@project' => $project->label(),
      ]),
    ];
  }

  /**
   * Sync the group members of a project.
   *
   * Keycloak group assignment rules:
   *  - Project group: owner + all members
   *  - Component -admin group (WissKI/SQL/Triplestore): owner only
   *  - Component -user group (WissKI/SQL/Triplestore): regular members only
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project entity.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the operation.
   */
  public function syncKeycloakGroupMembers(SodaScsProjectInterface $project): SodaScsResult {
    // Resolve the owner's Keycloak UUID.
    $owner = $project->get('owner')->entity;
    if (!$owner instanceof UserInterface) {
      return SodaScsResult::failure(
        error: 'Project has no owner',
        message: (string) $this->t('Project @project has no owner user; cannot sync Keycloak members.', [
          '@project' => $project->label(),
        ]),
      );
    }

    $ownerSsoUuid = $this->getUserSsoUuid($owner);

    if (!$ownerSsoUuid) {
      $getAllKeycloakUsersReq = $this->sodaScsKeycloakServiceUserActions->buildGetAllRequest([
        'token' => $this->getKeycloakToken(),
        'type' => 'user',
      ]);
      $getAllKeycloakUsersRes = $this->sodaScsKeycloakServiceUserActions->makeRequest($getAllKeycloakUsersReq);

      if ($getAllKeycloakUsersRes['success']) {
        $allKeycloakUsers = json_decode($getAllKeycloakUsersRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
        foreach ($allKeycloakUsers as $keycloakUser) {
          if ($keycloakUser['username'] === $owner->getDisplayName()) {
            $ownerSsoUuid = $keycloakUser['id'];
            break;
          }
        }
      }
    }

    if (!$ownerSsoUuid) {
      return SodaScsResult::failure(
        error: 'Owner has no SSO UUID',
        message: (string) $this->t('Owner @owner has no SSO UUID.', [
          '@owner' => $owner->getDisplayName(),
        ]),
      );
    }

    // Resolve regular members' Keycloak UUIDs (owner excluded).
    $memberSsoUuids = [];
    foreach ($project->get('members')->referencedEntities() as $projectMember) {
      $ssoUuid = $this->getUserSsoUuid($projectMember);
      if ($ssoUuid) {
        $memberSsoUuids[] = $ssoUuid;
      }
    }

    // Ensure the project's Keycloak group UUID is cached locally.
    if (!$project->get('keycloakUuid')->value) {
      $getAllGroupRequest = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
        'token' => $this->getKeycloakToken(),
        'queryParams' => ['search' => $project->get('groupId')->value],
      ]);
      $getAllGroupsResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($getAllGroupRequest);
      if ($getAllGroupsResponse['success']) {
        $groups = json_decode($getAllGroupsResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
        $project->set('keycloakUuid', $groups[0]['id']);
        $project->save();
      }
    }

    // Project group: owner + all members.
    $allMemberUuids = array_values(array_unique(array_merge([$ownerSsoUuid], $memberSsoUuids)));
    $syncResult = $this->syncUsersToKeycloakGroup(
      $project->get('keycloakUuid')->value,
      $allMemberUuids,
      $project->label(),
    );
    if (!$syncResult->success) {
      return $syncResult;
    }

    // Component access groups: owner → -admin, members → -user.
    $componentAccessGroupBundles = [
      'soda_scs_wisski_component',
      'soda_scs_sql_component',
      'soda_scs_triplestore_component',
    ];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $connectedComponent */
    foreach ($project->get('connectedComponents')->referencedEntities() as $connectedComponent) {
      if (!in_array($connectedComponent->bundle(), $componentAccessGroupBundles, TRUE)) {
        continue;
      }

      $machineName = $connectedComponent->get('machineName')->value;

      // Resolve -admin group UUID and sync the project owner into it.
      $adminGroupName = $machineName . '-admin';
      $adminGroupUuid = $this->resolveKeycloakGroupUuid($adminGroupName);
      if ($adminGroupUuid) {
        $syncResult = $this->syncUsersToKeycloakGroup($adminGroupUuid, [$ownerSsoUuid], $project->label());
        if (!$syncResult->success) {
          return $syncResult;
        }
      }

      // Resolve -user group UUID and sync regular members into it.
      $userGroupName = $machineName . '-user';
      $userGroupUuid = $this->resolveKeycloakGroupUuid($userGroupName);
      if ($userGroupUuid) {
        $syncResult = $this->syncUsersToKeycloakGroup($userGroupUuid, $memberSsoUuids, $project->label());
        if (!$syncResult->success) {
          return $syncResult;
        }
      }
    }

    return SodaScsResult::success(
      data: ['groupId' => $project->get('groupId')->value],
      message: (string) $this->t('Successfully synced keycloak group members for project @project.', [
        '@project' => $project->label(),
      ]),
    );
  }

  /**
   * Look up a Keycloak group UUID by name.
   *
   * @param string $groupName
   *   The Keycloak group name to search for.
   *
   * @return string|null
   *   The group UUID, or NULL if not found.
   */
  private function resolveKeycloakGroupUuid(string $groupName): ?string {
    $request = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
      'token' => $this->getKeycloakToken(),
      'queryParams' => ['search' => $groupName],
    ]);
    $response = $this->sodaScsKeycloakServiceGroupActions->makeRequest($request);
    if (!$response['success']) {
      return NULL;
    }
    $groups = json_decode($response['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
    return $groups[0]['id'] ?? NULL;
  }

  /**
   * Sync a set of users into a Keycloak group (add missing, remove stale).
   *
   * @param string $groupUuid
   *   The Keycloak group UUID.
   * @param string[] $desiredSsoUuids
   *   The Keycloak user UUIDs that should be members of the group.
   * @param string $projectLabel
   *   Used only for error messages.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success or the first error encountered.
   */
  private function syncUsersToKeycloakGroup(string $groupUuid, array $desiredSsoUuids, string $projectLabel): SodaScsResult {
    if ($groupUuid === '') {
      return SodaScsResult::failure(
        error: 'empty_group_uuid',
        message: (string) $this->t('Cannot sync group with empty UUID for project @project.', [
          '@project' => $projectLabel,
        ]),
      );
    }

    // Fetch current Keycloak group members.
    $membersReq = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
      'type' => 'members',
      'token' => $this->getKeycloakToken(),
      'routeParams' => ['groupId' => $groupUuid],
      'queryParams' => ['briefRepresentation' => 'false', 'populateHierarchy' => 'true'],
    ]);
    $membersRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($membersReq);

    if (!$membersRes['success']) {
      return SodaScsResult::failure(
        error: $membersRes['error'],
        message: (string) $this->t('Failed to get group members from Keycloak for project @project.', [
          '@project' => $projectLabel,
        ]),
      );
    }

    $currentMembers = json_decode($membersRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
    $currentUuids = array_column($currentMembers, 'id');

    $toAdd = array_values(array_diff($desiredSsoUuids, $currentUuids));
    $toRemove = array_values(array_diff($currentUuids, $desiredSsoUuids));

    foreach ($toAdd as $kcUserId) {
      if (!is_string($kcUserId) || $kcUserId === '') {
        continue;
      }
      $addReq = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
        'type' => 'addUserToGroup',
        'routeParams' => ['userId' => $kcUserId, 'groupId' => $groupUuid],
        'token' => $this->getKeycloakToken(),
      ]);
      $addRes = $this->sodaScsKeycloakServiceUserActions->makeRequest($addReq);
      if (!$addRes['success']) {
        return SodaScsResult::failure(
          error: $addRes['error'],
          message: (string) $this->t('Failed to add user @user to group @group in Keycloak for project @project.', [
            '@user' => $kcUserId,
            '@group' => $groupUuid,
            '@project' => $projectLabel,
          ]),
        );
      }
    }

    foreach ($toRemove as $kcUserUuid) {
      if (!is_string($kcUserUuid) || $kcUserUuid === '') {
        continue;
      }
      $removeReq = $this->sodaScsKeycloakServiceUserActions->buildDeleteRequest([
        'type' => 'removeUserFromGroup',
        'routeParams' => ['userId' => $kcUserUuid, 'groupId' => $groupUuid],
        'token' => $this->getKeycloakToken(),
      ]);
      $removeRes = $this->sodaScsKeycloakServiceUserActions->makeRequest($removeReq);
      if (!$removeRes['success']) {
        return SodaScsResult::failure(
          error: $removeRes['error'],
          message: (string) $this->t('Failed to remove user @user from group @group in Keycloak for project @project.', [
            '@user' => $kcUserUuid,
            '@group' => $groupUuid,
            '@project' => $projectLabel,
          ]),
        );
      }
    }

    return SodaScsResult::success(data: [], message: '');
  }

  /**
   * Create the Nextcloud Team Folder for a project.
   *
   * Mount/label is the project label; machine name is the Keycloak group id.
   */
  public function createProjectTeamFolder(SodaScsProjectInterface $project): SodaScsResult {
    $groupId = (string) ($project->get('groupId')->value ?? '');
    $projectId = (string) $project->id();
    $label = (string) $project->label();
    if ($groupId === '' || $projectId === '' || $label === '') {
      return SodaScsResult::failure(
        (string) $this->t('Missing project id, group id or label.'),
        (string) $this->t('Could not create Nextcloud Team Folder for project @project.', [
          '@project' => $label !== '' ? $label : $projectId,
        ]),
      );
    }

    $existingId = $this->findManagedFolderIdByExternalProjectId($projectId);
    if ($existingId !== NULL) {
      return SodaScsResult::success(
        data: ['folderId' => $existingId],
        message: (string) $this->t('Nextcloud Team Folder already exists for project @project.', [
          '@project' => $label,
        ]),
      );
    }

    $request = $this->sodaScsNextcloudServiceActions->buildCreateProjectFolderRequest([
      'label' => $label,
      'name' => $label,
      'machineName' => $groupId,
      'externalProjectId' => $projectId,
      'groups' => [$groupId],
      'createGroupFolder' => TRUE,
    ]);
    if (empty($request['success'])) {
      return SodaScsResult::failure(
        (string) ($request['error'] ?? 'Nextcloud request could not be built.'),
        (string) $this->t('Could not create Nextcloud Team Folder for project @project.', [
          '@project' => $label,
        ]),
      );
    }

    $response = $this->sodaScsNextcloudServiceActions->makeRequest($request);
    $data = $this->decodeNextcloudOcsData($response);
    if ($data === NULL) {
      $error = (string) ($response['error'] ?? 'Invalid Nextcloud response.');
      $this->logger->error('Failed to create Nextcloud Team Folder for project @project: @error', [
        '@project' => $label,
        '@error' => $error,
      ]);
      return SodaScsResult::failure(
        $error,
        (string) $this->t('Could not create Nextcloud Team Folder for project @project.', [
          '@project' => $label,
        ]),
      );
    }

    $folderId = (int) ($data['folder']['id'] ?? 0);
    return SodaScsResult::success(
      data: ['folderId' => $folderId, 'folder' => $data['folder'] ?? []],
      message: (string) $this->t('Created Nextcloud Team Folder for project @project.', [
        '@project' => $label,
      ]),
    );
  }

  /**
   * Delete the Nextcloud Team Folder registered for a project.
   */
  public function deleteProjectTeamFolder(SodaScsProjectInterface $project): SodaScsResult {
    $projectId = (string) $project->id();
    $folderId = $this->findManagedFolderIdByExternalProjectId($projectId);
    if ($folderId === NULL) {
      return SodaScsResult::success(
        data: ['removed' => FALSE],
        message: (string) $this->t('No Nextcloud Team Folder found for project @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    $request = $this->sodaScsNextcloudServiceActions->buildDeleteProjectFolderRequest([
      'folderId' => $folderId,
      'deleteStorage' => TRUE,
    ]);
    if (empty($request['success'])) {
      return SodaScsResult::failure(
        (string) ($request['error'] ?? 'Nextcloud request could not be built.'),
        (string) $this->t('Could not delete Nextcloud Team Folder for project @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    $response = $this->sodaScsNextcloudServiceActions->makeRequest($request);
    if (empty($response['success'])) {
      $error = (string) ($response['error'] ?? 'Nextcloud delete failed.');
      $this->logger->error('Failed to delete Nextcloud Team Folder @folder for project @project: @error', [
        '@folder' => $folderId,
        '@project' => $project->label(),
        '@error' => $error,
      ]);
      return SodaScsResult::failure(
        $error,
        (string) $this->t('Could not delete Nextcloud Team Folder for project @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    return SodaScsResult::success(
      data: ['removed' => TRUE, 'folderId' => $folderId],
      message: (string) $this->t('Deleted Nextcloud Team Folder for project @project.', [
        '@project' => $project->label(),
      ]),
    );
  }

  /**
   * Update Keycloak group attributes for a project (name/gid stay stable).
   */
  public function updateProjectGroupAttributes(SodaScsProjectInterface $project): SodaScsResult {
    $projectGroupUuid = (string) ($project->get('keycloakUuid')->value ?? '');
    if ($projectGroupUuid === '') {
      return SodaScsResult::failure(
        (string) $this->t('Project group UUID is empty.'),
        (string) $this->t('Could not update Keycloak attributes for project @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    $groupId = (string) ($project->get('groupId')->value ?? '');
    $updateReq = $this->sodaScsKeycloakServiceGroupActions->buildUpdateRequest([
      'token' => $this->getKeycloakToken(),
      'routeParams' => ['groupId' => $projectGroupUuid],
      'body' => [
        // Keycloak requires name on PUT; keep machine name (= groupId) stable.
        'name' => $groupId,
        'attributes' => $this->buildProjectGroupAttributes($project),
      ],
    ]);
    $updateRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($updateReq);
    if (!$updateRes['success']) {
      return SodaScsResult::failure(
        (string) ($updateRes['error'] ?? 'Keycloak update failed.'),
        (string) $this->t('Could not update Keycloak attributes for project @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    return SodaScsResult::success(
      data: [],
      message: (string) $this->t('Updated Keycloak attributes for project @project.', [
        '@project' => $project->label(),
      ]),
    );
  }

  /**
   * Sync project label to Nextcloud Team Folder mount and optionally groups ACL.
   */
  public function updateProjectTeamFolderLabel(SodaScsProjectInterface $project): SodaScsResult {
    $projectId = (string) $project->id();
    $groupId = (string) ($project->get('groupId')->value ?? '');
    $label = (string) $project->label();
    $folderId = $this->findManagedFolderIdByExternalProjectId($projectId);
    if ($folderId === NULL) {
      // Create if missing (e.g. projects created before Team Folder wiring).
      return $this->createProjectTeamFolder($project);
    }

    $request = $this->sodaScsNextcloudServiceActions->buildUpdateProjectFolderRequest([
      'folderId' => $folderId,
      'label' => $label,
      'groups' => $groupId !== '' ? [$groupId] : [],
    ]);
    if (empty($request['success'])) {
      return SodaScsResult::failure(
        (string) ($request['error'] ?? 'Nextcloud request could not be built.'),
        (string) $this->t('Could not update Nextcloud Team Folder for project @project.', [
          '@project' => $label,
        ]),
      );
    }

    $response = $this->sodaScsNextcloudServiceActions->makeRequest($request);
    $data = $this->decodeNextcloudOcsData($response);
    if ($data === NULL) {
      $error = (string) ($response['error'] ?? 'Invalid Nextcloud response.');
      $this->logger->error('Failed to update Nextcloud Team Folder for project @project: @error', [
        '@project' => $label,
        '@error' => $error,
      ]);
      return SodaScsResult::failure(
        $error,
        (string) $this->t('Could not update Nextcloud Team Folder for project @project.', [
          '@project' => $label,
        ]),
      );
    }

    return SodaScsResult::success(
      data: ['folderId' => $folderId, 'folder' => $data['folder'] ?? []],
      message: (string) $this->t('Updated Nextcloud Team Folder for project @project.', [
        '@project' => $label,
      ]),
    );
  }

  /**
   * Build Keycloak group attributes for a project.
   *
   * @return array<string, list<string>>
   *   Attribute map for the Keycloak Admin API.
   */
  protected function buildProjectGroupAttributes(SodaScsProjectInterface $project): array {
    $groupId = (string) ($project->get('groupId')->value ?? '');
    return [
      'gid' => [$groupId],
      'label' => [(string) $project->label()],
      'nextcloudTeamFolder' => [$groupId],
      // Placeholder for later service bindings (WissKI, Jupyter, WebProtégé, …).
      'containedApps' => ['[]'],
    ];
  }

  /**
   * Decode OCS data envelope from a Nextcloud makeRequest() response.
   *
   * @return array<string, mixed>|null
   *   Decoded ocs.data or NULL on failure.
   */
  protected function decodeNextcloudOcsData(array $response): ?array {
    if (empty($response['success'])) {
      return NULL;
    }
    try {
      $body = '';
      if (isset($response['data']['nextcloudResponse'])) {
        $body = (string) $response['data']['nextcloudResponse']->getBody()->getContents();
      }
      elseif (isset($response['data']['body'])) {
        $body = (string) $response['data']['body'];
      }
      elseif (is_string($response['data'] ?? NULL)) {
        $body = $response['data'];
      }
      if ($body === '') {
        return NULL;
      }
      $decoded = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable) {
      return NULL;
    }
    $data = $decoded['ocs']['data'] ?? NULL;
    return is_array($data) ? $data : NULL;
  }

  /**
   * List managed Nextcloud Team Folders keyed by external project id.
   *
   * Skips the platform share (`scs-platform-share`) and folders without an
   * external project id.
   *
   * @return array<string, array<string, mixed>>
   *   Map of Drupal project id (string) => folder payload from
   *   scs_manager_integration.
   */
  public function listManagedFoldersByExternalProjectId(): array {
    $request = $this->sodaScsNextcloudServiceActions->buildAdminProjectFoldersRequest([]);
    if (empty($request['success'])) {
      return [];
    }

    $response = $this->sodaScsNextcloudServiceActions->makeRequest($request);
    $data = $this->decodeNextcloudOcsData($response);
    if ($data === NULL) {
      return [];
    }

    $map = [];
    foreach ($data['folders'] ?? [] as $folder) {
      if (!is_array($folder)) {
        continue;
      }
      $externalProjectId = (string) ($folder['externalProjectId'] ?? '');
      if ($externalProjectId === '' || $externalProjectId === 'scs-platform-share') {
        continue;
      }
      $map[$externalProjectId] = $folder;
    }
    return $map;
  }

  /**
   * Look up scs_manager_integration folder id by Drupal project id.
   */
  protected function findManagedFolderIdByExternalProjectId(string $externalProjectId): ?int {
    if ($externalProjectId === '') {
      return NULL;
    }
    $folder = $this->listManagedFoldersByExternalProjectId()[$externalProjectId] ?? NULL;
    if ($folder === NULL) {
      return NULL;
    }
    return (int) ($folder['id'] ?? 0) ?: NULL;
  }

  /**
   * Get the Keycloak token.
   *
   * @return string|null
   *   The Keycloak token.
   */
  public function getKeycloakToken() : ?string {
    $kcToken = NULL;
    $tokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
    $tokenResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($tokenRequest);
    if ($tokenResponse['success']) {
      $tokenBody = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
      $kcToken = $tokenBody['access_token'] ?? NULL;
    }
    else {
      throw new \Exception('Failed to get Keycloak token');
    }
    return $kcToken;
  }

}
