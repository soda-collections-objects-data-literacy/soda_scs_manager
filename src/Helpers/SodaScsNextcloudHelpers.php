<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions;

/**
 * Helper class for Nextcloud operations.
 *
 * Supports two credential flows:
 * - OIDC Bearer: validate token, then create app password (OCS or occ fallback).
 * - Login Flow v2: credentials stored in Keycloak via Connect flow.
 */
class SodaScsNextcloudHelpers {

  use StringTranslationTrait;

  /**
   * Default Docker container for occ app-password provisioning.
   */
  public const DEFAULT_OCC_CONTAINER = 'nextcloud--nextcloud';

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\openid_connect\OpenIDConnectSessionInterface $openIdConnectSession
   *   The OpenID Connect session for the current user's token.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers $keycloakHelpers
   *   The Keycloak helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers $projectHelpers
   *   The project helpers.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers $containerHelpers
   *   Docker exec helper for occ fallbacks on SSO-only accounts.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.nextcloud_service.actions')]
    protected SodaScsNextcloudServiceActions $nextcloudServiceActions,
    #[Autowire(service: 'config.factory')]
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'current_user')]
    protected AccountProxyInterface $currentUser,
    #[Autowire(service: 'messenger')]
    protected MessengerInterface $messenger,
    #[Autowire(service: 'openid_connect.session')]
    protected OpenIDConnectSessionInterface $openIdConnectSession,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.helpers')]
    protected SodaScsKeycloakHelpers $keycloakHelpers,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    protected SodaScsProjectHelpers $projectHelpers,
    protected LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
  ) {}

  /**
   * User-facing message when Drive SSO or credential provisioning fails.
   */
  public function getUserFacingSsoConnectionError(): string {
    return (string) $this->t(
      'Connecting Drive via SSO failed. Please try again. If the problem persists, contact your site administrator.'
    );
  }

  /**
   * Logs technical Nextcloud SSO failure details for administrators.
   */
  protected function logNextcloudSsoFailure(string $operation, string $detail): void {
    $this->loggerFactory->get('soda_scs_manager')->warning(
      'Nextcloud SSO @operation failed: @detail',
      ['@operation' => $operation, '@detail' => $detail],
    );
  }

  /**
   * Returns the Keycloak attribute name for storing the Nextcloud username.
   */
  public function getKeycloakUsernameAttr(): string {
    return $this->configFactory->get('soda_scs_manager.settings')
      ->get('nextcloud.generalSettings.keycloakUsernameAttr') ?? self::ATTR_USERNAME;
  }

  /**
   * Returns the Keycloak attribute name for storing the Nextcloud app password.
   */
  public function getKeycloakAppPasswordAttr(): string {
    return $this->configFactory->get('soda_scs_manager.settings')
      ->get('nextcloud.generalSettings.keycloakAppPasswordAttr') ?? self::ATTR_APP_PASSWORD;
  }

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
    $username = $this->resolveOccUsername($username);
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
   * Returns whether Bearer token flow is enabled for Nextcloud.
   *
   * @return bool
   *   TRUE when Bearer should be used; FALSE for Login Flow v2 only.
   */
  public function isBearerEnabled(): bool {
    return $this->nextcloudServiceActions->getUseBearerToken();
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
    return $this->getValidatedStoredNextcloudCredentials($owner);
  }

  /**
   * Returns stored credentials only when they match the Keycloak sub.
   *
   * Stale credentials (e.g. after Keycloak user recreation) are cleared from
   * Keycloak and treated as missing so the Connect flow can run again.
   *
   * @param \Drupal\user\UserInterface $owner
   *   The Drupal user.
   *
   * @return array|null
   *   Array with 'username' and 'appPassword', or NULL.
   */
  public function getValidatedStoredNextcloudCredentials(UserInterface $owner): ?array {
    $keycloakUserId = $this->projectHelpers->getUserSsoUuid($owner);
    if (empty($keycloakUserId)) {
      return NULL;
    }

    $raw = $this->readStoredNextcloudCredentials($keycloakUserId);
    if ($raw === NULL) {
      return NULL;
    }

    if (!$this->storedUsernameMatchesKeycloakSub($keycloakUserId, $raw['username'])) {
      $this->clearStoredNextcloudCredentials($keycloakUserId);
      $this->loggerFactory->get('soda_scs_manager')->notice(
        'Cleared stale Nextcloud credentials for Keycloak user @id (stored @stored, expected @expected).',
        [
          '@id' => $keycloakUserId,
          '@stored' => $raw['username'],
          '@expected' => $this->expectedNextcloudUsernameForKeycloakId($keycloakUserId),
        ]
      );
      return NULL;
    }

    if (!$this->testStoredCredentials($raw['username'], $raw['appPassword'])) {
      $this->clearStoredNextcloudCredentials($keycloakUserId);
      $this->loggerFactory->get('soda_scs_manager')->notice(
        'Cleared invalid Nextcloud app password for Keycloak user @id.',
        ['@id' => $keycloakUserId]
      );
      return NULL;
    }

    return $raw;
  }

  /**
   * Clears stored Nextcloud credentials when they no longer match Keycloak sub.
   *
   * Called on login after Keycloak user recreation or SSO relinking.
   *
   * @param \Drupal\user\UserInterface $owner
   *   The Drupal user.
   *
   * @return bool
   *   TRUE when stale credentials were cleared.
   */
  public function invalidateMismatchedStoredCredentials(UserInterface $owner): bool {
    $keycloakUserId = $this->projectHelpers->getUserSsoUuid($owner);
    if (empty($keycloakUserId)) {
      return FALSE;
    }

    $raw = $this->readStoredNextcloudCredentials($keycloakUserId);
    if ($raw === NULL) {
      return FALSE;
    }

    if ($this->storedUsernameMatchesKeycloakSub($keycloakUserId, $raw['username'])) {
      return FALSE;
    }

    $this->clearStoredNextcloudCredentials($keycloakUserId);
    $this->loggerFactory->get('soda_scs_manager')->notice(
      'Invalidated Nextcloud credentials for Drupal user @name after Keycloak SSO change.',
      ['@name' => $owner->getAccountName()]
    );
    return TRUE;
  }

  /**
   * Deletes the Nextcloud account(s) linked to a Keycloak user.
   *
   * Revokes the stored app password when present, then deletes the expected
   * user_oidc account via the OCS API when admin credentials are configured.
   * Also attempts to delete a previously stored username when it differs
   * (orphaned account from an old Keycloak sub).
   *
   * @param string $keycloakUserId
   *   Keycloak user ID (OIDC sub).
   *
   * @return bool
   *   TRUE when cleanup ran without fatal errors.
   */
  public function deleteNextcloudAccountForKeycloakUser(string $keycloakUserId): bool {
    $logger = $this->loggerFactory->get('soda_scs_manager');
    $ok = TRUE;
    $raw = $this->readStoredNextcloudCredentials($keycloakUserId);

    if ($raw !== NULL && !empty($raw['appPassword'])) {
      $revoke = $this->nextcloudServiceActions->buildDeleteRequest([
        'username' => $raw['username'],
        'password' => $raw['appPassword'],
      ]);
      if ($revoke['success'] ?? FALSE) {
        $response = $this->nextcloudServiceActions->makeRequest($revoke);
        if (!$response['success']) {
          $logger->warning('Could not revoke Nextcloud app password for @user: @error', [
            '@user' => $raw['username'],
            '@error' => $response['error'] ?? 'unknown',
          ]);
        }
      }
    }

    $usernames = array_unique(array_filter([
      $this->expectedNextcloudUsernameForKeycloakId($keycloakUserId),
      $raw['username'] ?? NULL,
    ]));

    foreach ($usernames as $username) {
      $delete = $this->nextcloudServiceActions->buildDeleteUserRequest(['userId' => $username]);
      if (!($delete['success'] ?? FALSE)) {
        $logger->notice('Skipping Nextcloud user delete for @user: @error', [
          '@user' => $username,
          '@error' => $delete['error'] ?? 'not configured',
        ]);
        continue;
      }
      $response = $this->nextcloudServiceActions->makeRequest($delete);
      if ($response['success']) {
        $logger->notice('Deleted Nextcloud user @user for Keycloak id @id.', [
          '@user' => $username,
          '@id' => $keycloakUserId,
        ]);
      }
      else {
        $ok = FALSE;
        $logger->warning('Failed to delete Nextcloud user @user: @error', [
          '@user' => $username,
          '@error' => $response['error'] ?? 'unknown',
        ]);
      }
    }

    $this->clearStoredNextcloudCredentials($keycloakUserId);
    return $ok;
  }

  /**
   * Returns the Nextcloud username expected for a Keycloak user ID.
   */
  public function expectedNextcloudUsernameForKeycloakId(string $keycloakUserId): string {
    return $this->normalizeNextcloudUsername($keycloakUserId);
  }

  /**
   * Checks whether a stored Nextcloud username belongs to the Keycloak sub.
   */
  public function storedUsernameMatchesKeycloakSub(string $keycloakUserId, string $storedUsername): bool {
    $expected = $this->expectedNextcloudUsernameForKeycloakId($keycloakUserId);
    $normalized = $this->normalizeNextcloudUsername($storedUsername);
    if (strcasecmp($normalized, $expected) === 0) {
      return TRUE;
    }
    // Legacy Drive accounts (e.g. sociallogin) may use the raw Keycloak sub.
    return strcasecmp($storedUsername, $keycloakUserId) === 0;
  }

  /**
   * Removes Nextcloud credential attributes from Keycloak.
   */
  public function clearStoredNextcloudCredentials(string $keycloakUserId): bool {
    return $this->keycloakHelpers->removeKeycloakUserAttributes($keycloakUserId, [
      $this->getKeycloakUsernameAttr(),
      $this->getKeycloakAppPasswordAttr(),
    ]);
  }

  /**
   * Reads Nextcloud credentials from Keycloak without validation.
   *
   * @return array{username: string, appPassword: string}|null
   *   Credentials or NULL when not stored.
   */
  protected function readStoredNextcloudCredentials(string $keycloakUserId): ?array {
    $attributes = $this->keycloakHelpers->getKeycloakUserAttributes($keycloakUserId) ?? [];
    $username = $attributes[$this->getKeycloakUsernameAttr()][0] ?? NULL;
    $appPassword = $attributes[$this->getKeycloakAppPasswordAttr()][0] ?? NULL;
    if (empty($username) || empty($appPassword)) {
      return NULL;
    }

    return [
      'username' => $username,
      'appPassword' => $appPassword,
    ];
  }

  /**
   * Resolves the Nextcloud account id used for occ and Basic auth.
   *
   * Prefer the raw id from cloud/user. Legacy sociallogin users often use the
   * Keycloak sub without the user_oidc prefix.
   */
  protected function resolveOccUsername(string $cloudUserId): string {
    $prefix = $this->nextcloudServiceActions->getOidcUsernamePrefix();
    if ($prefix !== '' && str_starts_with($cloudUserId, $prefix)) {
      $without = substr($cloudUserId, strlen($prefix));
      if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $without)) {
        return $without;
      }
    }
    return $cloudUserId;
  }

  /**
   * Creates an app password via occ (SSO accounts cannot use OCS getapppassword).
   */
  protected function createAppPasswordViaOcc(string $cloudUserId): ?string {
    $container = (string) ($this->configFactory->get('soda_scs_manager.settings')
      ->get('nextcloud.generalSettings.occContainerName') ?? '');
    if ($container === '') {
      $container = self::DEFAULT_OCC_CONTAINER;
    }

    $occUser = $this->resolveOccUsername($cloudUserId);
    $result = $this->containerHelpers->executeDockerExecCommand([
      'cmd' => [
        'php',
        '/var/www/html/occ',
        'user:add-app-password',
        $occUser,
        '-n',
        '--no-warnings',
      ],
      'containerName' => $container,
      'user' => 'www-data',
    ]);

    if (!$result->success) {
      $this->logNextcloudSsoFailure(
        'occ app password',
        (string) ($result->error ?? 'docker exec failed')
      );
      return NULL;
    }

    $output = (string) ($result->data['output'] ?? '');
    if (preg_match('/app password:\s*(\S+)/i', $output, $matches)) {
      return $matches[1];
    }

    $this->logNextcloudSsoFailure('occ app password', 'could not parse occ output');
    return NULL;
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
    if (empty($accessToken)) {
      throw new \Exception('No OIDC access token found. Log in via Keycloak first.');
    }

    return $this->testNextcloudTokenWithToken($accessToken, $timeout);
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
      $this->logNextcloudSsoFailure(
        'token validation',
        (string) ($getResponse['error'] ?? 'unknown error')
      );
      throw new \Exception($this->getUserFacingSsoConnectionError());
    }

    $userBody = json_decode((string) $getResponse['data']['nextcloudResponse']->getBody()->getContents(), TRUE);
    $data = $userBody['ocs']['data'] ?? [];
    $username = $data['id'] ?? $data['userid'] ?? $data['user_id'] ?? NULL;
    if (empty($username)) {
      $this->logNextcloudSsoFailure('token validation', 'cloud/user response missing username');
      throw new \Exception($this->getUserFacingSsoConnectionError());
    }

    return ['username' => $this->resolveOccUsername($username)];
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
    $stored = $this->getValidatedStoredNextcloudCredentials($owner);
    if ($stored !== NULL) {
      return $stored;
    }

    // When Bearer is disabled, only use stored credentials (Login Flow v2).
    if (!$this->nextcloudServiceActions->getUseBearerToken()) {
      return NULL;
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
        $this->getKeycloakUsernameAttr() => [$credentials['username']],
        $this->getKeycloakAppPasswordAttr() => [$credentials['appPassword']],
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
      'appName' => 'SCS Manager (' . $owner->getAccountName() . ')',
      'token' => $token,
    ];

    // Resolve the Nextcloud username for the authenticated token.
    $getRequest = $this->nextcloudServiceActions->buildGetRequest($requestParams);
    $getResponse = $this->nextcloudServiceActions->makeRequest($getRequest);
    if (!$getResponse['success']) {
      $this->logNextcloudSsoFailure(
        'app password user lookup',
        (string) ($getResponse['error'] ?? 'unknown error')
      );
      throw new \Exception($this->getUserFacingSsoConnectionError());
    }

    $userBody = json_decode((string) $getResponse['data']['nextcloudResponse']->getBody()->getContents(), TRUE);
    $username = $userBody['ocs']['data']['id'] ?? NULL;
    if (empty($username)) {
      $this->logNextcloudSsoFailure('app password user lookup', 'cloud/user response missing username');
      throw new \Exception($this->getUserFacingSsoConnectionError());
    }
    $authUsername = $this->resolveOccUsername($username);

    // Create the app password (OCS getapppassword does not work for SSO bearer).
    $createRequest = $this->nextcloudServiceActions->buildCreateRequest($requestParams);
    $createResponse = $this->nextcloudServiceActions->makeRequest($createRequest);
    $appPassword = NULL;
    if ($createResponse['success']) {
      $createBody = json_decode((string) $createResponse['data']['nextcloudResponse']->getBody()->getContents(), TRUE);
      $appPassword = $createBody['ocs']['data']['apppassword'] ?? NULL;
    }
    else {
      $this->logNextcloudSsoFailure(
        'app password creation (OCS)',
        (string) ($createResponse['error'] ?? 'unknown error')
      );
    }

    if (empty($appPassword)) {
      $appPassword = $this->createAppPasswordViaOcc($username);
    }

    if (empty($appPassword)) {
      throw new \Exception($this->getUserFacingSsoConnectionError());
    }

    return [
      'username' => $authUsername,
      'appPassword' => $appPassword,
    ];
  }

  /**
   * Normalizes Nextcloud username for user_oidc (adds prefix if raw UUID).
   *
   * When cloud/user returns a raw UUID, user_oidc expects the full username
   * with provider prefix (e.g. keycloak-{uuid}) for WebDAV/Basic auth.
   *
   * @param string $username
   *   Username from cloud/user (id field).
   *
   * @return string
   *   Normalized username with prefix if applicable.
   */
  public function normalizeNextcloudUsername(string $username): string {
    $prefix = $this->nextcloudServiceActions->getOidcUsernamePrefix();
    if (empty($prefix)) {
      return $username;
    }
    // If already has the prefix, return as-is.
    if (str_starts_with($username, $prefix)) {
      return $username;
    }
    // If it looks like a raw UUID (8-4-4-4-12 hex), add the prefix.
    if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $username)) {
      return $prefix . $username;
    }
    return $username;
  }

}
