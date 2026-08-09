<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceGroupActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Helper class for Soda SCS keycloak operations.
 */
class SodaScsKeycloakHelpers {

  /**
   * Constructor.
   *
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceGroupActions $sodaScsKeycloakServiceGroupActions
   *   The Soda SCS keycloak service group actions.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions
   *   The Soda SCS keycloak service user actions.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.keycloak_service.group.actions')]
    protected SodaScsKeycloakServiceGroupActions $sodaScsKeycloakServiceGroupActions,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.user.actions')]
    protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions,
  ) {
    $this->sodaScsKeycloakServiceGroupActions = $sodaScsKeycloakServiceGroupActions;
    $this->sodaScsKeycloakServiceUserActions = $sodaScsKeycloakServiceUserActions;
  }

  /**
   * Get the keycloak token.
   *
   * @return string
   *   The keycloak token.
   */
  public function getKeycloakToken() {
    $keycloakTokenRequest = $this->sodaScsKeycloakServiceUserActions->buildTokenRequest();
    $keycloakTokenResponse = $this->sodaScsKeycloakServiceUserActions->makeRequest($keycloakTokenRequest);
    if ($keycloakTokenResponse['success']) {
      return json_decode($keycloakTokenResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE)['access_token'];
    }
    return NULL;
  }

  /**
   * Get all keycloak users.
   *
   * @param string $keycloakToken
   *   The keycloak token.
   *
   * @return array
   *   The keycloak users.
   */
  public function getKeycloakUsers(string $keycloakToken) {
    $keycloakGetAllUsersRequest = $this->sodaScsKeycloakServiceUserActions->buildGetAllRequest([
      'type' => 'user',
      'token' => $keycloakToken,
    ]);
    $keycloakGetAllUsersResponse = $this->sodaScsKeycloakServiceUserActions->makeRequest($keycloakGetAllUsersRequest);

    if ($keycloakGetAllUsersResponse['success']) {
      return json_decode($keycloakGetAllUsersResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
    }
    return NULL;
  }

  /**
   * Get a keycloak user by name.
   *
   * @param string $keycloakToken
   *   The keycloak token.
   * @param string $username
   *   The name of the user.
   *
   * @return array
   *   The keycloak user.
   */
  public function getKeycloakUser(string $keycloakToken, string $username) {
    if (!$keycloakToken) {
      return NULL;
    }
    $keycloakUsers = $this->getKeycloakUsers($keycloakToken);
    foreach ($keycloakUsers as $keycloakUser) {
      if ($keycloakUser['username'] == $username) {
        return $keycloakUser;
      }
    }
    return NULL;
  }

  /**
   * Get Keycloak group by name.
   *
   * @param string $keycloakToken
   *   The keycloak token.
   * @param string $name
   *   The name of the group.
   *
   * @return array
   *   The keycloak group.
   */
  public function getKeycloakGroup(string $keycloakToken, string $name) {
    $keycloakGetGroupRequest = $this->sodaScsKeycloakServiceGroupActions->buildGetRequest([
      'token' => $keycloakToken,
      'routeParams' => ['groupId' => $name],
    ]);
    $keycloakGetGroupResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakGetGroupRequest);
    if ($keycloakGetGroupResponse['success']) {
      return json_decode($keycloakGetGroupResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
    }
    return NULL;
  }

  /**
   * Get Keycloak user by ID (sub from OIDC).
   *
   * @param string $keycloakUserId
   *   The Keycloak user ID (OIDC sub claim).
   *
   * @return array|null
   *   The full user representation, or NULL if not found or request failed.
   */
  public function getKeycloakUserById(string $keycloakUserId): ?array {
    $request = $this->sodaScsKeycloakServiceUserActions->buildGetRequest([
      'routeParams' => ['userId' => $keycloakUserId],
      'token' => $this->getKeycloakToken(),
    ]);
    $response = $this->sodaScsKeycloakServiceUserActions->makeRequest($request);
    if (!$response['success']) {
      return NULL;
    }
    $body = json_decode($response['data']['keycloakResponse']->getBody()->getContents(), TRUE);
    return is_array($body) ? $body : NULL;
  }

  /**
   * Get Keycloak user attributes by user ID (sub from OIDC).
   *
   * @param string $keycloakUserId
   *   The Keycloak user ID (OIDC sub claim).
   *
   * @return array|null
   *   The user attributes map, or NULL if user not found or request failed.
   */
  public function getKeycloakUserAttributes(string $keycloakUserId): ?array {
    $user = $this->getKeycloakUserById($keycloakUserId);
    return $user !== NULL ? ($user['attributes'] ?? []) : NULL;
  }

  /**
   * Set Keycloak user attributes (merges with existing).
   *
   * Keycloak 24+ PUT replaces the entire user. Sending only attributes wipes
   * firstName, lastName, email. We must fetch the full user, merge attributes,
   * and PUT the complete representation back.
   *
   * @param string $keycloakUserId
   *   The Keycloak user ID (OIDC sub claim).
   * @param array $attributes
   *   Attributes to set. Format: ['key' => ['value1', 'value2']].
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function setKeycloakUserAttributes(string $keycloakUserId, array $attributes): bool {
    $user = $this->getKeycloakUserById($keycloakUserId);
    if ($user === NULL) {
      return FALSE;
    }
    $existing = $user['attributes'] ?? [];
    $merged = array_merge($existing, $attributes);
    $user['attributes'] = $merged;

    $request = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
      'type' => 'updateUser',
      'routeParams' => ['userId' => $keycloakUserId],
      'body' => $user,
      'token' => $this->getKeycloakToken(),
    ]);
    $response = $this->sodaScsKeycloakServiceUserActions->makeRequest($request);
    return $response['success'];
  }

  /**
   * Removes Keycloak user attributes (merges with existing user representation).
   *
   * @param string $keycloakUserId
   *   The Keycloak user ID (OIDC sub claim).
   * @param array $attributeNames
   *   Attribute keys to remove.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function removeKeycloakUserAttributes(string $keycloakUserId, array $attributeNames): bool {
    $user = $this->getKeycloakUserById($keycloakUserId);
    if ($user === NULL) {
      return FALSE;
    }
    $existing = $user['attributes'] ?? [];
    foreach ($attributeNames as $name) {
      unset($existing[$name]);
    }
    $user['attributes'] = $existing;

    $request = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
      'type' => 'updateUser',
      'routeParams' => ['userId' => $keycloakUserId],
      'body' => $user,
      'token' => $this->getKeycloakToken(),
    ]);
    $response = $this->sodaScsKeycloakServiceUserActions->makeRequest($request);
    return $response['success'];
  }

  /**
   * Add user to keycloak group.
   *
   * @param string $userId
   *   The user id.
   * @param string $groupId
   *   The group id.
   *
   * @return array
   *   The keycloak user.
   */
  public function addUserToKeycloakGroup(string $userId, string $groupId) {
    $keycloakAddUserToGroupRequest = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
      'type' => 'addUserToGroup',
      'routeParams' => [
        'userId' => $userId,
        'groupId' => $groupId,
      ],
      'token' => $this->getKeycloakToken(),
    ]);
    $keycloakAddUserToGroupResponse = $this->sodaScsKeycloakServiceUserActions->makeRequest($keycloakAddUserToGroupRequest);
    if ($keycloakAddUserToGroupResponse['success']) {
      return $keycloakAddUserToGroupResponse['data']['keycloakResponse']->getBody()->getContents();
    }
    return NULL;
  }

  /**
   * Creates {machineName}-admin and {machineName}-user groups and assigns users.
   *
   * Owner is added to -admin only; project members are added to -user only.
   *
   * @param string $machineName
   *   Component machine name (e.g. sql-foo, ts-foo, wisski-foo).
   * @param string|null $ownerSsoUuid
   *   Keycloak user UUID of the component owner, or NULL to skip.
   * @param string[] $memberSsoUuids
   *   Keycloak user UUIDs of project members (non-owner).
   *
   * @return array{
   *   adminGroupName: string,
   *   userGroupName: string,
   *   adminGroupId: string,
   *   userGroupId: string
   * }
   *   Created group names and IDs.
   *
   * @throws \Exception
   *   When Keycloak create/lookup/assign requests fail.
   */
  public function createAndPopulateComponentAccessGroups(
    string $machineName,
    ?string $ownerSsoUuid,
    array $memberSsoUuids = [],
  ): array {
    $token = $this->getKeycloakToken();
    if (!$token) {
      throw new \Exception('Keycloak token request failed.');
    }

    $adminGroupName = $machineName . '-admin';
    $userGroupName = $machineName . '-user';

    $createAdminResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest(
      $this->sodaScsKeycloakServiceGroupActions->buildCreateRequest([
        'body' => [
          'name' => $adminGroupName,
          'path' => '/' . $adminGroupName,
        ],
        'token' => $token,
      ])
    );
    if (!$createAdminResponse['success']) {
      throw new \Exception('Keycloak create admin group request failed: ' . ($createAdminResponse['error'] ?? ''));
    }

    $createUserResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest(
      $this->sodaScsKeycloakServiceGroupActions->buildCreateRequest([
        'body' => [
          'name' => $userGroupName,
          'path' => '/' . $userGroupName,
        ],
        'token' => $token,
      ])
    );
    if (!$createUserResponse['success']) {
      throw new \Exception('Keycloak create user group request failed: ' . ($createUserResponse['error'] ?? ''));
    }

    $adminGroup = $this->findGroupByName($adminGroupName, $token);
    $userGroup = $this->findGroupByName($userGroupName, $token);
    if (!$adminGroup || empty($adminGroup['id'])) {
      throw new \Exception('Keycloak admin group was created but could not be resolved: ' . $adminGroupName);
    }
    if (!$userGroup || empty($userGroup['id'])) {
      throw new \Exception('Keycloak user group was created but could not be resolved: ' . $userGroupName);
    }

    if ($ownerSsoUuid) {
      $addOwnerResponse = $this->addUserToKeycloakGroup($ownerSsoUuid, $adminGroup['id']);
      if ($addOwnerResponse === NULL) {
        throw new \Exception('Keycloak add owner to admin group request failed.');
      }
    }

    foreach ($memberSsoUuids as $memberSsoUuid) {
      if (!$memberSsoUuid || $memberSsoUuid === $ownerSsoUuid) {
        continue;
      }
      $addMemberResponse = $this->addUserToKeycloakGroup($memberSsoUuid, $userGroup['id']);
      if ($addMemberResponse === NULL) {
        throw new \Exception('Keycloak add member to user group request failed.');
      }
    }

    return [
      'adminGroupName' => $adminGroupName,
      'userGroupName' => $userGroupName,
      'adminGroupId' => $adminGroup['id'],
      'userGroupId' => $userGroup['id'],
    ];
  }

  /**
   * Deletes {machineName}-admin and {machineName}-user Keycloak groups.
   *
   * Missing groups are skipped so legacy components without groups can still
   * be deleted.
   *
   * @param string $machineName
   *   Component machine name.
   *
   * @return array{
   *   adminGroupName: string,
   *   userGroupName: string,
   *   adminDeleted: bool,
   *   userDeleted: bool
   * }
   *   Deletion outcome per group.
   *
   * @throws \Exception
   *   When a present group cannot be deleted.
   */
  public function deleteComponentAccessGroups(string $machineName): array {
    $token = $this->getKeycloakToken();
    if (!$token) {
      throw new \Exception('Keycloak token request failed.');
    }

    $adminGroupName = $machineName . '-admin';
    $userGroupName = $machineName . '-user';
    $adminDeleted = FALSE;
    $userDeleted = FALSE;

    $adminGroup = $this->findGroupByName($adminGroupName, $token);
    if ($adminGroup && !empty($adminGroup['id'])) {
      $deleteAdminResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest(
        $this->sodaScsKeycloakServiceGroupActions->buildDeleteRequest([
          'routeParams' => ['groupId' => $adminGroup['id']],
          'token' => $token,
        ])
      );
      if (!$deleteAdminResponse['success']) {
        throw new \Exception('Cannot delete Keycloak admin group ' . $adminGroupName . ': ' . ($deleteAdminResponse['error'] ?? ''));
      }
      $adminDeleted = TRUE;
    }

    $userGroup = $this->findGroupByName($userGroupName, $token);
    if ($userGroup && !empty($userGroup['id'])) {
      $deleteUserResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest(
        $this->sodaScsKeycloakServiceGroupActions->buildDeleteRequest([
          'routeParams' => ['groupId' => $userGroup['id']],
          'token' => $token,
        ])
      );
      if (!$deleteUserResponse['success']) {
        throw new \Exception('Cannot delete Keycloak user group ' . $userGroupName . ': ' . ($deleteUserResponse['error'] ?? ''));
      }
      $userDeleted = TRUE;
    }

    return [
      'adminGroupName' => $adminGroupName,
      'userGroupName' => $userGroupName,
      'adminDeleted' => $adminDeleted,
      'userDeleted' => $userDeleted,
    ];
  }

  /**
   * Finds a Keycloak group by exact name.
   *
   * @param string $groupName
   *   Exact group name.
   * @param string|null $token
   *   Optional Keycloak token; fetched when NULL.
   *
   * @return array|null
   *   Group representation, or NULL if not found.
   */
  public function findGroupByName(string $groupName, ?string $token = NULL): ?array {
    $token = $token ?? $this->getKeycloakToken();
    if (!$token) {
      return NULL;
    }

    $response = $this->sodaScsKeycloakServiceGroupActions->makeRequest(
      $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
        'token' => $token,
      ])
    );
    if (!$response['success']) {
      return NULL;
    }

    $groups = json_decode($response['data']['keycloakResponse']->getBody()->getContents(), TRUE) ?? [];
    foreach ($groups as $group) {
      if (($group['name'] ?? NULL) === $groupName) {
        return $group;
      }
    }
    return NULL;
  }

}
