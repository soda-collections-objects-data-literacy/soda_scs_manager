<?php

/**
 * @file
 * Contains \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers.
 */

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\externalauth\AuthmapInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;

/**
 * Helper class for SodaSCS project operations.
 */
class SodaScsProjectHelpers {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AuthmapInterface $authmap,
    protected SodaScsServiceHelpers $sodaScsServiceHelpers,
    protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions,
    protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions,
    protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions,
  ) {
    $this->settings = $this->configFactory->getEditable('soda_scs_manager.settings');
    $this->entityTypeManager = $entityTypeManager;
    $this->authmap = $authmap;
    $this->sodaScsKeycloakServiceClientActions = $sodaScsKeycloakServiceClientActions;
    $this->sodaScsKeycloakServiceGroupActions = $sodaScsKeycloakServiceGroupActions;
    $this->sodaScsKeycloakServiceUserActions = $sodaScsKeycloakServiceUserActions;
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
    // double check if token is set.
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
   * @return bool
   *   TRUE if the project group was created, FALSE otherwise.
   */
  public function createProjectGroup(SodaScsProjectInterface $project) {
    // Data should be null if the group does not exist.
    if ($this->checkIfProjectGroupExists($project)['data']) {
      return [
        'success' => FALSE,
        'data' => NULL,
        'error' => $this->t('Keycloak group @projectGroupId for project @project already exists.'),
        'message' => $this->t('Could not create Keycloak group @projectGroupId for project @project.', [
          '@projectGroupId' => $project->get('groupId')->value,
          '@project' => $project->label(),
        ]),
      ];
    }

    // Check if the project group ID is set in the project entity.
    $projectGroupId = (string) ($project->get('groupId')->value ?? '');
    if ($projectGroupId === '') {
      return [
        'success' => FALSE,
        'data' => NULL,
        'error' => $this->t('No project group ID found for project @project.', [
          '@project' => $project->label(),
        ]),
        'message' => $this->t('Could not create Keycloak group @projectGroupId for project @project.', [
          '@projectGroupId' => $project->get('groupId')->value,
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
        'message' => $this->t('Could not create keycloak group with group id @projectGroupId for project @project.', [
          '@projectGroupId' => $projectGroupId,
          '@project' => $project->label()
        ]),
      ];
    }
    $tokenBody = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
    $kcToken = $tokenBody['access_token'] ?? NULL;

    // double check if token is set.
    if (!$kcToken) {
      return [
        'success' => FALSE,
        'data' => NULL,
        'error' =>  $this->t('No Keycloak token found.'),
        'message' => $this->t('Could not create keycloak group with group id @projectGroupId for project @project.', [
          '@projectGroupId' => $projectGroupId,
          '@project' => $project->label()
        ]),
      ];
    }

    // Create keycloak group.
    $groupsReq = $this->sodaScsKeycloakServiceGroupActions->buildCreateRequest([
      'token' => $kcToken,
      'body' => ['name' => $projectGroupId],
    ]);
    $groupsRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($groupsReq);
    if (!$groupsRes['success']) {
      return [
        'success' => FALSE,
        'data' => NULL,
        'error' => $groupsRes['error'],
        'message' => $this->t('Failed to create Keycloak group @projectGroupId for project @project.', [
          '@projectGroupId' => $projectGroupId,
          '@project' => $project->label(),
        ]),
      ];
    }

    // Get keycloak group uuid.
    $getAllGroupsReq = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
      'token' => $kcToken,
      'queryParams' => ['briefRepresentation' => 'false', 'populateHierarchy' => 'true'],
    ]);
    $getAllGroupsRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($getAllGroupsReq);
    if (!$getAllGroupsRes['success']) {
      return [
        'success' => FALSE,
        'data' => NULL,
        'error' => $getAllGroupsRes['error'],
        'message' => $this->t('Failed to get Keycloak groups for project @project.', [
          '@project' => $project->label(),
        ]),
      ];
    }
    $getAllGroups = json_decode($getAllGroupsRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
    foreach ($getAllGroups as $group) {
      if ($group['name'] === $projectGroupId) {
        $keycloakGroup = $group;
        break;
      }
    }
    $keycloakUuid = $keycloakGroup['id'] ?? NULL;


    $groupData = [
      'groupId' => $projectGroupId,
      'name' => $project->label(),
      'uuid' => $keycloakUuid,
    ];

    return [
      'success' => TRUE,
      'data' => $groupData,
      'error' => NULL,
      'message' => $this->t('Created Keycloak group @projectGroupId for project @project.', [
        '@projectGroupId' => $projectGroupId,
        '@project' => $project->label(),
      ]),
    ];
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
   * Sync the group members of a project.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project entity.
   */
  public function syncKeycloakGroupMembers(SodaScsProjectInterface $project) {
    $projectMemberNamesFromProjectEntity = [];

    $owner = $project->get('owner')->entity;
    $ownerSsoUuid = $this->getUserSsoUuid($owner);
    $projectMemberNamesFromProjectEntity[$owner->id()] = $ownerSsoUuid;

    /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $membersField */
    $membersField = $project->get('members');

    /** @var \Drupal\user\UserInterface $projectMember */
    foreach ($membersField->referencedEntities() as $projectMember) {
      $ssoUuid = $this->getUserSsoUuid($projectMember);
      $projectMemberNamesFromProjectEntity[$projectMember->id()] = $ssoUuid;
    }

    // Get Group members from Keycloak.
    // Get Keycloak token.
    $tokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
    $tokenResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($tokenRequest);
    if ($tokenResponse['success']) {
      $tokenBody = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
      $kcToken = $tokenBody['access_token'] ?? NULL;
    }

    // Get group members
    if ($kcToken) {
      // Get all groups to find the Keycloak group UUID for this project.
      $groupsReq = $this->sodaScsKeycloakServiceGroupActions->buildGetRequest([
        'token' => $kcToken,
        'routeParams' => ['groupId' => $project->get('keycloakUuid')->value],
      ]);
      $groupsRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($groupsReq);
      $groupUuid = NULL;
      if ($groupsRes['success']) {
        $groups = json_decode($groupsRes['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
        foreach ($groups as $group) {
          $gidValues = $group['attributes']['gid'] ?? [];
          if ($group['name'] === $project->get('groupId')->value) {
            $groupUuid = $group['id'];
            break;
          }
        }
      }

      if ($groupUuid) {
        // Prepare the payload to update the group's 'members' attribute.
        // This assumes the Keycloak group has an attribute 'members' that is a list of SSO UUIDs.
        $projectMemberNames = array_values(array_filter($projectMemberNamesFromProjectEntity));
        $updateReq = $this->sodaScsKeycloakServiceGroupActions->buildUpdateRequest([
          'token' => $kcToken,
          'routeParams' => ['groupId' => $groupUuid],
          'body' => [
            'attributes' => [
              'members' => $projectMemberNames,
            ],
          ],
        ]);
        $updateRes = $this->sodaScsKeycloakServiceGroupActions->makeRequest($updateReq);
        if ($updateRes['success']) {
          return TRUE;
        }
        return FALSE;
      }
    }
  }

}

