<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\openid_connect\Plugin\OpenIDConnectClientManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class SodaScsDisableDrupalLoginRouteSubscriber extends RouteSubscriberBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The OpenID Connect client plugin manager.
   *
   * @var \Drupal\openid_connect\Plugin\OpenIDConnectClientManager|null
   */
  protected ?OpenIDConnectClientManager $openIdConnectClientManager = NULL;

  /**
   * Constructs a SodaScsDisableDrupalLoginRouteSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\openid_connect\Plugin\OpenIDConnectClientManager|null $openIdConnectClientManager
   *   The OpenID Connect client plugin manager.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    ModuleHandlerInterface $moduleHandler,
    ?OpenIDConnectClientManager $openIdConnectClientManager = NULL,
  ) {
    $this->configFactory = $configFactory;
    $this->moduleHandler = $moduleHandler;
    $this->openIdConnectClientManager = $openIdConnectClientManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $openIdConnectClientManager = NULL;
    if ($container->has('plugin.manager.openid_connect_client')) {
      $openIdConnectClientManager = $container->get('plugin.manager.openid_connect_client');
    }
    return new static(
      $container->get('config.factory'),
      $container->get('module_handler'),
      $openIdConnectClientManager,
    );
  }

  /**
   * Check if SSO OpenID Connect provider is configured.
   *
   * @return bool
   *   TRUE if SSO is configured, FALSE otherwise.
   */
  protected function isSsoConfigured(): bool {
    // Check if OpenID Connect module is enabled.
    if (!$this->moduleHandler->moduleExists('openid_connect')) {
      return FALSE;
    }

    // Check if plugin manager is available.
    if (!$this->openIdConnectClientManager) {
      return FALSE;
    }

    // Get the OpenID Connect client machine name from configuration.
    $config = $this->configFactory->get('soda_scs_manager.settings');
    $clientMachineName = $config->get('keycloak.keycloakTabs.generalSettings.fields.OpenIdConnectClientMachineName');

    // If no client machine name is configured, SSO is not configured.
    if (empty($clientMachineName)) {
      return FALSE;
    }

    // Check if the OpenID Connect client plugin exists.
    try {
      $pluginId = 'openid_connect.' . $clientMachineName;
      return $this->openIdConnectClientManager->hasDefinition($pluginId);
    }
    catch (\Exception $e) {
      // If we can't check the plugin, assume SSO is not configured.
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Only deny access to Account related pages if SSO is configured.
    if ($this->isSsoConfigured()) {
      // Deny access to default registration and password reset pages when using SSO.
      $routes = [
        'user.register',
        'user.pass',
      ];
      foreach ($routes as $route) {
        if ($route = $collection->get($route)) {
          $route->setRequirement('_access', 'FALSE');
        }
      }
      // Override the login route to disable default Drupal login form.
      if ($route = $collection->get('user.login')) {
        $route->setDefault('_form', '\Drupal\soda_scs_manager\Form\SodaScsDisableDrupalLoginForm');
      }
    }

  }

}
