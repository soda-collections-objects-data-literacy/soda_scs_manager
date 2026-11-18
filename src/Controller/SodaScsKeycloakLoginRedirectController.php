<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\openid_connect\OpenIDConnectClaims;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Drupal\openid_connect\OpenIDConnectClientEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Starts Keycloak authentication directly from the login route.
 */
final class SodaScsKeycloakLoginRedirectController extends ControllerBase {

  public function __construct(
    protected ConfigFactoryInterface $configFactoryService,
    protected EntityTypeManagerInterface $entityTypeManagerService,
    protected OpenIDConnectClaims $openIdConnectClaims,
    protected OpenIDConnectSessionInterface $openIdConnectSession,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('openid_connect.claims'),
      $container->get('openid_connect.session'),
    );
  }

  /**
   * Saves the requested destination and redirects to the configured provider.
   *
   * The login route is also the site's 403 page, so a background request of
   * an expired session (health or notification polling) ends up here. It
   * cannot follow a redirect to Keycloak (cross-origin), so it gets a plain
   * 403 instead: no authorize request, no saved destination, and the polling
   * script can see that the session is gone.
   */
  public function redirectToKeycloak(Request $request): Response {
    if ($this->isBackgroundRequest($request)) {
      // A plain JsonResponse is not cacheable, so the page cache skips it.
      return new JsonResponse(
        ['message' => (string) $this->t('Your session has expired. Reload the page to log in again.')],
        Response::HTTP_FORBIDDEN,
      );
    }

    $settings = $this->configFactoryService->get('soda_scs_manager.settings');
    $clientId = $settings->get('keycloak.keycloakTabs.generalSettings.fields.OpenIdConnectClientMachineName');
    if (!is_string($clientId) || $clientId === '') {
      $keycloakSettings = $settings->get('keycloak');
      $clientId = is_array($keycloakSettings)
        ? ($keycloakSettings['keycloakTabs']['generalSettings']['fields']['OpenIdConnectClientMachineName'] ?? '')
        : '';
    }

    $client = is_string($clientId) && $clientId !== ''
      ? $this->entityTypeManagerService->getStorage('openid_connect_client')->load($clientId)
      : NULL;
    if (!$client instanceof OpenIDConnectClientEntityInterface || !$client->status()) {
      throw new NotFoundHttpException();
    }

    $plugin = $client->getPlugin();
    $this->openIdConnectSession->saveDestination();
    $this->openIdConnectSession->saveOp('login');

    return $plugin->authorize($this->openIdConnectClaims->getScopes($plugin));
  }

  /**
   * Whether the request comes from a script rather than a page navigation.
   */
  private function isBackgroundRequest(Request $request): bool {
    if ($request->isXmlHttpRequest()) {
      return TRUE;
    }
    $acceptedTypes = $request->getAcceptableContentTypes();
    return ($acceptedTypes[0] ?? '') === 'application/json';
  }

}
