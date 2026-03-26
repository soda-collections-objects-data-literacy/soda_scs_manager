<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ensures OIDC login before WissKI component or stack creation.
 *
 * When a user opens the Create WissKI component or Create WissKI stack form
 * without an OIDC token, redirects to OIDC login with destination back to the
 * form. Keycloak SSO typically satisfies this without showing a login form.
 */
class WisskiOidcAccessSubscriber implements EventSubscriberInterface {

  /**
   * Routes that require OIDC token for WissKI creation.
   *
   * @var array<string, string>
   *   Route name => bundle parameter key.
   */
  protected const WISSKI_ROUTES = [
    'entity.soda_scs_component.add_form' => 'bundle',
    'entity.soda_scs_stack.add_form' => 'bundle',
  ];

  /**
   * Bundle values that require OIDC.
   *
   * @var array<string>
   */
  protected const WISSKI_BUNDLES = [
    'soda_scs_wisski_component',
    'soda_scs_wisski_stack',
  ];

  /**
   * Constructs a WisskiOidcAccessSubscriber.
   *
   * @param \Drupal\openid_connect\OpenIDConnectSessionInterface $openIdConnectSession
   *   The OpenID Connect session.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    protected OpenIDConnectSessionInterface $openIdConnectSession,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected RouteMatchInterface $routeMatch,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['checkWisskiOidcAccess', 35],
    ];
  }

  /**
   * Redirects to OIDC login when WissKI creation is accessed without a token.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function checkWisskiOidcAccess(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if (!$this->isSsoConfigured()) {
      return;
    }

    $routeName = $this->routeMatch->getRouteName();
    if ($routeName === NULL || !isset(self::WISSKI_ROUTES[$routeName])) {
      return;
    }

    $bundleParam = self::WISSKI_ROUTES[$routeName];
    $bundle = $this->routeMatch->getParameter($bundleParam);
    if (!in_array($bundle, self::WISSKI_BUNDLES, TRUE)) {
      return;
    }

    $token = $this->openIdConnectSession->retrieveAccessToken(FALSE);
    if (!empty($token)) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    $destination = $request ? $request->getPathInfo() : '/soda-scs-manager/dashboard';

    // Redirect to OIDC login (Keycloak) with destination back to this form.
    // openid_connect.login shows the client buttons; Keycloak SSO often
    // satisfies without a login form when the user already has a session.
    $loginUrl = Url::fromRoute('openid_connect.login', [], [
      'query' => ['destination' => $destination],
      'absolute' => TRUE,
    ]);

    $event->setResponse(new RedirectResponse($loginUrl->toString()));
  }

  /**
   * Checks if SSO (Keycloak OIDC) is configured.
   *
   * @return bool
   *   TRUE if configured, FALSE otherwise.
   */
  protected function isSsoConfigured(): bool {
    if (!$this->moduleHandler->moduleExists('openid_connect')) {
      return FALSE;
    }

    $config = $this->configFactory->get('soda_scs_manager.settings');
    $clientMachineName = $config->get('keycloak.keycloakTabs.generalSettings.fields.OpenIdConnectClientMachineName');
    if (empty($clientMachineName)) {
      return FALSE;
    }

    try {
      $clientConfig = $this->configFactory->get('openid_connect.client.' . $clientMachineName);
      return !$clientConfig->isNew() && $clientConfig->get('status');
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

}
