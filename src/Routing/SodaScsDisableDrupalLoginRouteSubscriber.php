<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class SodaScsDisableDrupalLoginRouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs a SodaScsDisableDrupalLoginRouteSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $sso = $this->isSsoConfigured();

    if ($sso) {
      // Deny default Drupal registration; Keycloak handles sign-up.
      if ($route = $collection->get('user.register')) {
        $route->setRequirement('_access', 'FALSE');
      }
      // Override the login route to disable default Drupal login form.
      if ($route = $collection->get('user.login')) {
        $route->setDefault('_form', '\Drupal\soda_scs_manager\Form\SodaScsDisableDrupalLoginForm');
      }
      // Password reset: Keycloak / SSO only; Drupal's form when SSO is off.
      if ($route = $collection->get('user.pass')) {
        $defaults = $route->getDefaults();
        unset($defaults['_form']);
        $defaults['_controller'] =
          '\Drupal\soda_scs_manager\Controller\SodaScsKeycloakForgotPasswordRedirectController::instructions';
        $route->setDefaults($defaults);
      }
    }

  }

  /**
   * OpenID Connect client machine name from SCS Manager settings.
   */
  protected function getOpenIdConnectClientMachineName(): string {
    $config = $this->configFactory->get('soda_scs_manager.settings');
    $machineName = $config->get('keycloak.keycloakTabs.generalSettings.fields.OpenIdConnectClientMachineName');
    if (is_string($machineName) && $machineName !== '') {
      return $machineName;
    }
    $keycloak = $config->get('keycloak');
    if (!is_array($keycloak)) {
      return '';
    }
    $nested = $keycloak['keycloakTabs']['generalSettings']['fields']['OpenIdConnectClientMachineName'] ?? '';
    return is_string($nested) ? $nested : '';
  }

  /**
   * Check if SSO OpenID Connect provider is configured.
   *
   * Uses config only (not the OIDC plugin manager) so optional DI cannot
   * leave the manager NULL and skip route alters while SSO is actually on.
   *
   * @return bool
   *   TRUE if SSO is configured, FALSE otherwise.
   */
  protected function isSsoConfigured(): bool {
    if (!$this->moduleHandler->moduleExists('openid_connect')) {
      return FALSE;
    }

    $clientMachineName = trim($this->getOpenIdConnectClientMachineName());
    if ($clientMachineName === '') {
      return FALSE;
    }

    try {
      $clientConfig = $this->configFactory->get('openid_connect.client.' . $clientMachineName);
      if ($clientConfig->isNew()) {
        return FALSE;
      }
      // Missing status defaults to enabled (ConfigEntityBase).
      $status = $clientConfig->get('status');
      if ($status === FALSE || $status === 0 || $status === '0') {
        return FALSE;
      }
      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

}
