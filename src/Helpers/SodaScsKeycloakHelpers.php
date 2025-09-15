<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;

/**
 * Helper class for Soda SCS keycloak operations.
 */
class SodaScsKeycloakHelpers {

  /**
   * Constructor.
   *
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions
   *   The Soda SCS keycloak service user actions.
   */
  public function __construct(
    protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions,
  ) {
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

}
