<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions;

/**
 * Helper class for Nextcloud operations.
 *
 * Supports two credential flows:
 * - OIDC Bearer: createAppPassword (requires shared Keycloak client).
 * - Login Flow v2: credentials stored in Keycloak via Connect flow.
 */
class SodaScsNextcloudHelpers {

  /**
   * Keycloak attribute for stored Nextcloud username (profile scope).
   */
  public const ATTR_USERNAME = 'nextcloud_login_name';

  /**
   * Keycloak attribute for stored Nextcloud app password (profile scope).
   */
  public const ATTR_APP_PASSWORD = 'nextcloud_app_password';

  /**
   * Constructs a new SodaScsNextcloudHelpers object.
   *
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions $nextcloudServiceActions
   *   The Nextcloud service actions.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\openid_connect\OpenIDConnectSessionInterface $openIdConnectSession
   *   The OpenID Connect session for the current user's token.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers $keycloakHelpers
   *   The Keycloak helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers $projectHelpers
   *   The project helpers.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.nextcloud_service.actions')]
    protected SodaScsNextcloudServiceActions $nextcloudServiceActions,
    #[Autowire(service: 'current_user')]
    protected AccountProxyInterface $currentUser,
    #[Autowire(service: 'openid_connect.session')]
    protected OpenIDConnectSessionInterface $openIdConnectSession,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.helpers')]
    protected SodaScsKeycloakHelpers $keycloakHelpers,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    protected SodaScsProjectHelpers $projectHelpers,
  ) {}

  /**
   * Tests if stored credentials work against Nextcloud.
   *
   * @param string $username
   *   Nextcloud username.
   * @param string $appPassword
   *   Nextcloud app password.
   *
   * @return bool
   *   TRUE if the credentials work.
   */
  public function testStoredCredentials(string $username, string $appPassword): bool {
    $requestParams = [
      'appName' => 'test',
      'username' => $username,
      'password' => $appPassword,
    ];
    $getRequest = $this->nextcloudServiceActions->buildGetRequest($requestParams);
    $getRequest['timeout'] = 10;
    $getResponse = $this->nextcloudServiceActions->makeRequest($getRequest);
    return $getResponse['success'] ?? FALSE;
  }

  /**
   * Gets stored Nextcloud credentials from Keycloak user attributes.
   *
   * Credentials are stored when the user completes the Connect Nextcloud
   * flow (Login Flow v2).
   *
   * @param \Drupal\user\UserInterface $owner
   *   The user (e.g. component owner).
   *
   * @return array|null
   *   Array with 'username' and 'appPassword' keys, or NULL if not stored.
   */
  public function getStoredNextcloudCredentials(UserInterface $owner): ?array {
    $keycloakUserId = $this->projectHelpers->getUserSsoUuid($owner);
    if (empty($keycloakUserId)) {
      return NULL;
    }
    $attributes = $this->keycloakHelpers->getKeycloakUserAttributes($keycloakUserId);
    $username = $attributes[self::ATTR_USERNAME][0] ?? NULL;
    $appPassword = $attributes[self::ATTR_APP_PASSWORD][0] ?? NULL;
    if (empty($username) || empty($appPassword)) {
      return NULL;
    }
    return [
      'username' => $username,
      'appPassword' => $appPassword,
    ];
  }

  /**
   * Tests if the current user's OIDC token is accepted by Nextcloud.
   *
   * Only verifies the GET /ocs/v1.php/cloud/user endpoint. Does not create
   * an app password. Use for debugging OIDC Bearer token integration.
   *
   * Tries access token first, then ID token (some Nextcloud setups accept
   * either for Bearer validation).
   *
   * @param \Drupal\user\UserInterface $user
   *   The user (must be current user, logged in via OIDC).
   * @param int $timeout
   *   Timeout in seconds for the Nextcloud request (default 10).
   *
   * @return array
   *   Array with 'username' key on success.
   *
   * @throws \Exception
   *   When token is missing or Nextcloud rejects it.
   */
  public function testNextcloudToken(UserInterface $user, int $timeout = 10): array {
    if ((int) $this->currentUser->id() !== (int) $user->id()) {
      throw new \Exception('User must be the current user.');
    }

    $accessToken = $this->openIdConnectSession->retrieveAccessToken(FALSE);
    $idToken = $this->openIdConnectSession->retrieveIdToken(FALSE);

    if (empty($accessToken) && empty($idToken)) {
      throw new \Exception('No OIDC access token found. Log in via Keycloak first.');
    }

    $tokensToTry = array_filter([$accessToken, $idToken]);
    $lastError = NULL;

    foreach ($tokensToTry as $token) {
      try {
        return $this->testNextcloudTokenWithToken($token, $timeout);
      }
      catch (\Exception $e) {
        $lastError = $e;
      }
    }

    throw $lastError ?? new \Exception('Nextcloud rejected the token.');
  }

  /**
   * Tests a specific token against Nextcloud.
   *
   * @param string $token
   *   OIDC access or ID token.
   * @param int $timeout
   *   Timeout in seconds.
   *
   * @return array
   *   Array with 'username' on success.
   *
   * @throws \Exception
   *   When the request fails.
   */
  protected function testNextcloudTokenWithToken(string $token, int $timeout): ?array {
    $requestParams = [
      'appName' => 'test',
      'token' => $token,
    ];

    $getRequest = $this->nextcloudServiceActions->buildGetRequest($requestParams);
    $getRequest['timeout'] = $timeout;
    $getResponse = $this->nextcloudServiceActions->makeRequest($getRequest);
    if (!$getResponse['success']) {
      throw new \Exception('Nextcloud rejected the token: ' . $getResponse['error']);
    }

    $userBody = json_decode((string) $getResponse['data']['nextcloudResponse']->getBody()->getContents(), TRUE);
    $data = $userBody['ocs']['data'] ?? [];
    $username = $data['id'] ?? $data['userid'] ?? $data['user_id'] ?? NULL;
    if (empty($username)) {
      throw new \Exception('Failed to get Nextcloud username from response.');
    }

    return ['username' => $username];
  }

  /**
   * Ensures Nextcloud credentials exist for the owner.
   *
   * Tries stored credentials first (Login Flow v2). If not found and the
   * current user is the owner with an active OIDC session, automatically
   * creates an app password via Bearer token (Flow B) and persists it to
   * Keycloak so subsequent calls return the cached value.
   *
   * @param \Drupal\user\UserInterface $owner
   *   The component owner.
   * @param string $appName
   *   App name label used when creating a new app password.
   *
   * @return array|null
   *   Array with 'username' and 'appPassword', or NULL if credentials could
   *   not be obtained by either method.
   */
  public function ensureCredentials(UserInterface $owner, string $appName = 'SCS Manager'): ?array {
    // Fast path: already stored.
    $stored = $this->getStoredNextcloudCredentials($owner);
    if ($stored !== NULL) {
      return $stored;
    }

    // Flow B: auto-create via OIDC Bearer if the owner is the current user.
    if ((int) $this->currentUser->id() !== (int) $owner->id()) {
      return NULL;
    }

    $token = $this->openIdConnectSession->retrieveAccessToken(FALSE);
    if (empty($token)) {
      return NULL;
    }

    try {
      $credentials = $this->createAppPassword($appName, $owner);
    }
    catch (\Exception $e) {
      return NULL;
    }

    if (empty($credentials)) {
      return NULL;
    }

    // Persist to Keycloak so future calls hit the fast path.
    $keycloakUserId = $this->projectHelpers->getUserSsoUuid($owner);
    if (!empty($keycloakUserId)) {
      $this->keycloakHelpers->setKeycloakUserAttributes($keycloakUserId, [
        self::ATTR_USERNAME => [$credentials['username']],
        self::ATTR_APP_PASSWORD => [$credentials['appPassword']],
      ]);
    }

    return $credentials;
  }

  /**
   * Creates a Nextcloud app password for the component owner.
   *
   * Uses the owner's OIDC token from their session. The current user must be
   * the owner (logged in via OIDC) for this to succeed.
   *
   * @param string $appName
   *   The app name (e.g. WissKI component machine name). Used as the token
   *   label in Nextcloud → Settings → Security.
   * @param \Drupal\user\UserInterface $owner
   *   The component owner. Must be the current user (logged in via OIDC).
   *
   * @return array|null
   *   Array with keys 'username' and 'appPassword' on success, NULL if skipped.
   *
   * @throws \Exception
   *   When the current user is not the owner, or when no OIDC token is
   *   available, or when the Nextcloud request fails.
   */
  public function createAppPassword(string $appName, UserInterface $owner): ?array {
    if ((int) $this->currentUser->id() !== (int) $owner->id()) {
      throw new \Exception('Nextcloud app password can only be created when the component owner is the current user. The owner must create the component while logged in via OIDC.');
    }

    $token = $this->openIdConnectSession->retrieveAccessToken(FALSE);
    if (empty($token)) {
      throw new \Exception('No OIDC access token found. The component owner must be logged in via OpenID Connect (Keycloak) to create a Nextcloud app password.');
    }

    $requestParams = [
      'appName' => $appName,
      'token' => $token,
    ];

    // Resolve the Nextcloud username for the authenticated token.
    $getRequest = $this->nextcloudServiceActions->buildGetRequest($requestParams);
    $getResponse = $this->nextcloudServiceActions->makeRequest($getRequest);
    if (!$getResponse['success']) {
      throw new \Exception('Failed to get Nextcloud user info: ' . $getResponse['error']);
    }

    $userBody = json_decode((string) $getResponse['data']['nextcloudResponse']->getBody()->getContents(), TRUE);
    $username = $userBody['ocs']['data']['id'] ?? NULL;
    if (empty($username)) {
      throw new \Exception('Failed to get Nextcloud username');
    }

    // Create the app password.
    $createRequest = $this->nextcloudServiceActions->buildCreateRequest($requestParams);
    $createResponse = $this->nextcloudServiceActions->makeRequest($createRequest);
    if (!$createResponse['success']) {
      throw new \Exception('Failed to create Nextcloud app password: ' . $createResponse['error']);
    }

    $createBody = json_decode((string) $createResponse['data']['nextcloudResponse']->getBody()->getContents(), TRUE);
    $appPassword = $createBody['ocs']['data']['apppassword'] ?? NULL;
    if (empty($appPassword)) {
      throw new \Exception('Failed to get Nextcloud app password');
    }

    return [
      'username' => $username,
      'appPassword' => $appPassword,
    ];
  }

}
