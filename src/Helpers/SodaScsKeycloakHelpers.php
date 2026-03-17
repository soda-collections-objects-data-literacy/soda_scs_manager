<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceGroupActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;

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
   * Get Keycloak user attributes by user ID (sub from OIDC).
   *
   * @param string $keycloakUserId
   *   The Keycloak user ID (OIDC sub claim).
   *
   * @return array|null
   *   The user attributes map, or NULL if user not found or request failed.
   */
  public function getKeycloakUserAttributes(string $keycloakUserId): ?array {
    $request = $this->sodaScsKeycloakServiceUserActions->buildGetRequest([
      'routeParams' => ['userId' => $keycloakUserId],
      'token' => $this->getKeycloakToken(),
    ]);
    $response = $this->sodaScsKeycloakServiceUserActions->makeRequest($request);
    if (!$response['success']) {
      return NULL;
    }
    $body = json_decode($response['data']['keycloakResponse']->getBody()->getContents(), TRUE);
    return $body['attributes'] ?? [];
  }

  /**
   * Set Keycloak user attributes (merges with existing).
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
    $existing = $this->getKeycloakUserAttributes($keycloakUserId);
    if ($existing === NULL) {
      return FALSE;
    }
    $merged = array_merge($existing, $attributes);
    $request = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
      'type' => 'updateUser',
      'routeParams' => ['userId' => $keycloakUserId],
      'body' => ['attributes' => $merged],
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

}
