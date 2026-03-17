<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for Nextcloud Connect flow (Login Flow v2).
 *
 * User logs in to Nextcloud once via popup, we receive app password,
 * store in Keycloak user attributes for later use (e.g. WissKI creation).
 *
 * @see https://docs.nextcloud.com/server/stable/developer_manual/client_apis/LoginFlow/
 */
class SodaScsNextcloudConnectController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected SodaScsNextcloudServiceActions $nextcloudServiceActions,
    protected SodaScsKeycloakHelpers $keycloakHelpers,
    protected SodaScsNextcloudHelpers $nextcloudHelpers,
    protected SodaScsProjectHelpers $projectHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.nextcloud_service.actions'),
      $container->get('soda_scs_manager.keycloak_service.helpers'),
      $container->get('soda_scs_manager.nextcloud.helpers'),
      $container->get('soda_scs_manager.project.helpers'),
    );
  }

  /**
   * Initiates Nextcloud Login Flow v2.
   *
   * POST to Nextcloud /index.php/login/v2, returns login URL and poll token.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with loginUrl, pollToken, pollEndpoint.
   */
  public function init(): JsonResponse {
    if ($this->currentUser()->isAnonymous()) {
      return new JsonResponse(['error' => 'Unauthorized'], 401);
    }

    $appName = 'SCS Manager (' . $this->currentUser()->getAccountName() . ')';
    $request = $this->nextcloudServiceActions->buildLoginFlowV2InitRequest($appName);
    $response = $this->nextcloudServiceActions->makeRequest($request);

    if (!$response['success']) {
      return new JsonResponse([
        'error' => $response['error'] ?? 'Failed to initiate Nextcloud login',
      ], 502);
    }

    $body = json_decode(
      (string) $response['data']['nextcloudResponse']->getBody(),
      TRUE
    );
    $login = $body['login'] ?? NULL;
    $poll = $body['poll'] ?? NULL;

    if (empty($login) || empty($poll['token']) || empty($poll['endpoint'])) {
      return new JsonResponse([
        'error' => 'Invalid Nextcloud login flow response',
      ], 502);
    }

    return new JsonResponse([
      'loginUrl' => $login,
      'pollToken' => $poll['token'],
      'pollEndpoint' => $poll['endpoint'],
    ]);
  }

  /**
   * Polls Nextcloud for login completion and stores credentials in Keycloak.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request with pollToken and pollEndpoint (POST body or query).
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   JSON with success or error.
   */
  public function poll(Request $request): Response {
    if ($this->currentUser()->isAnonymous()) {
      return new JsonResponse(['error' => 'Unauthorized'], 401);
    }

    $pollToken = $request->request->get('pollToken') ?? $request->query->get('pollToken');
    $pollEndpoint = $request->request->get('pollEndpoint') ?? $request->query->get('pollEndpoint');

    if (empty($pollToken) || empty($pollEndpoint)) {
      return new JsonResponse([
        'error' => 'Missing pollToken or pollEndpoint',
      ], 400);
    }

    $appName = 'SCS Manager (' . $this->currentUser()->getAccountName() . ')';
    $pollRequest = $this->nextcloudServiceActions->buildLoginFlowV2PollRequest(
      $pollEndpoint,
      $pollToken,
      $appName
    );
    $response = $this->nextcloudServiceActions->makeRequest($pollRequest);

    $statusCode = $response['statusCode'] ?? 0;
    if (!$response['success']) {
      // 404 = user has not completed login yet, keep polling.
      if ($statusCode === 404) {
        return new JsonResponse(['pending' => TRUE], 200);
      }
      return new JsonResponse([
        'error' => $response['error'] ?? 'Poll request failed',
      ], 200);
    }

    if ($statusCode === 404) {
      return new JsonResponse(['pending' => TRUE], 200);
    }

    if ($statusCode !== 200) {
      return new JsonResponse([
        'error' => 'Unexpected poll response: ' . $statusCode,
      ], 502);
    }

    $body = json_decode(
      (string) $response['data']['nextcloudResponse']->getBody(),
      TRUE
    );
    $loginName = $body['loginName'] ?? $body['login_name'] ?? NULL;
    $appPassword = $body['appPassword'] ?? $body['app_password'] ?? NULL;

    if (empty($loginName) || empty($appPassword)) {
      return new JsonResponse([
        'error' => 'Invalid credentials in poll response',
      ], 502);
    }

    $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    if (!$user) {
      return new JsonResponse(['error' => 'User not found'], 500);
    }

    $keycloakUserId = $this->projectHelpers->getUserSsoUuid($user);
    if (empty($keycloakUserId)) {
      return new JsonResponse([
        'error' => 'User is not linked to Keycloak. Log in via Keycloak first.',
      ], 400);
    }

    $attributes = [
      $this->nextcloudHelpers->getKeycloakUsernameAttr() => [$loginName],
      $this->nextcloudHelpers->getKeycloakAppPasswordAttr() => [$appPassword],
    ];
    if (!$this->keycloakHelpers->setKeycloakUserAttributes($keycloakUserId, $attributes)) {
      return new JsonResponse([
        'error' => 'Failed to store credentials in Keycloak',
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'loginName' => $loginName,
    ]);
  }

  /**
   * Checks Nextcloud connection status.
   *
   * Tries Bearer token (OIDC) first. If that fails, falls back to stored
   * credentials (Login Flow v2). Returns connection method so the UI can
   * show "Connected via SSO" (bearer) or "Connected via app password" (stored).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with connected, method ('bearer'|'stored'), and optionally username.
   */
  public function status(): JsonResponse {
    if ($this->currentUser()->isAnonymous()) {
      return new JsonResponse([
        'connected' => FALSE,
        'method' => NULL,
      ], 200);
    }

    $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    if (!$user) {
      return new JsonResponse([
        'connected' => FALSE,
        'method' => NULL,
      ], 200);
    }

    // Primary: try Bearer token (OIDC) when enabled - skip when disabled.
    $bearerError = NULL;
    if ($this->nextcloudServiceActions->getUseBearerToken()) {
      try {
        $result = $this->nextcloudHelpers->testNextcloudToken($user);
        return new JsonResponse([
          'connected' => TRUE,
          'method' => 'bearer',
          'username' => $result['username'] ?? NULL,
        ], 200);
      }
      catch (\Exception $e) {
        $bearerError = $e->getMessage();
        $this->getLogger('soda_scs_manager')->debug(
          'Nextcloud Bearer check failed: @error',
          ['@error' => $bearerError]
        );
        // Bearer failed; fall back to stored credentials (Login Flow v2).
      }
    }

    $keycloakUserId = $this->projectHelpers->getUserSsoUuid($user);
    if (empty($keycloakUserId)) {
      return new JsonResponse([
        'connected' => FALSE,
        'method' => NULL,
        'bearer_error' => $bearerError,
      ], 200);
    }

    $attributes = $this->keycloakHelpers->getKeycloakUserAttributes($keycloakUserId) ?? [];
    $username = $attributes[$this->nextcloudHelpers->getKeycloakUsernameAttr()][0] ?? NULL;
    $appPassword = $attributes[$this->nextcloudHelpers->getKeycloakAppPasswordAttr()][0] ?? NULL;

    $storedConnected = !empty($username) && !empty($appPassword);

    // Only report "stored" if stored credentials work against Nextcloud.
    if ($storedConnected) {
      $storedConnected = $this->nextcloudHelpers->testStoredCredentials($username, $appPassword);
    }

    $displayUsername = $storedConnected
      ? $this->nextcloudHelpers->normalizeNextcloudUsername($username)
      : NULL;

    return new JsonResponse([
      'connected' => $storedConnected,
      'method' => $storedConnected ? 'stored' : NULL,
      'username' => $displayUsername,
      'bearer_error' => $bearerError,
    ], 200);
  }

}
