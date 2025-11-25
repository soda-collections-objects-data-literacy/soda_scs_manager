<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a breadcrumb builder for SODa SCS Manager list pages.
 */
final class SodaScsManagerListBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $routeMatch): bool {
    $routeName = $routeMatch->getRouteName();

    // Apply to all entity collection routes for soda_scs_manager entities.
    return in_array($routeName, [
      'entity.soda_scs_component.collection',
      'entity.soda_scs_project.collection',
      'entity.soda_scs_service_key.collection',
      'entity.soda_scs_snapshot.collection',
      'entity.soda_scs_stack.collection',
    ], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $routeMatch): Breadcrumb {
    $breadcrumb = new Breadcrumb();

    // Add cache context for the route.
    $breadcrumb->addCacheContexts(['route']);

    // Home link.
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));

    // Structure link.
    $breadcrumb->addLink(Link::createFromRoute($this->t('Structure'), 'system.admin_structure'));

    // SCS Administration link.
    $breadcrumb->addLink(Link::createFromRoute(
      $this->t('SCS Administration'),
      'soda_scs_manager.administration'
    ));

    return $breadcrumb;
  }

}

