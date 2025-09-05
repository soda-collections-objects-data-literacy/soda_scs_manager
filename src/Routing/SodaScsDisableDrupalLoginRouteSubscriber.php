<?php

namespace Drupal\soda_scs_manager\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class SodaScsDisableDrupalLoginRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Outright deny access to Account related pages that aren't the login page.
    $routes = [
      'user.register',
      'user.pass',
    ];
    foreach ($routes as $route) {
      if ($route = $collection->get($route)) {
        $route->setRequirement('_access', 'FALSE');
      }
    }
    // Override the
    if ($route = $collection->get('user.login')) {
      $route->setDefault('_form', '\Drupal\soda_scs_manager\Form\SodaScsDisableDrupalLoginForm');
    }
  }
}
