<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsComponent;
use Drupal\soda_scs_manager\Entity\SodaScsProject;
use Drupal\soda_scs_manager\Entity\SodaScsStack;

/**
 * Breadcrumbs for SODa SCS Manager entity pages.
 *
 * Trail: Dashboard → [Project] → current page title (no link).
 */
final class SodaScsManagerEntityBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  /**
   * Route entity parameter names handled by this builder.
   *
   * @var list<string>
   */
  private const ENTITY_PARAMS = [
    'soda_scs_project',
    'soda_scs_component',
    'soda_scs_stack',
    'soda_scs_service_key',
    'soda_scs_snapshot',
  ];

  public function __construct(
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $routeMatch): bool {
    return $this->getRouteEntity($routeMatch) instanceof EntityInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $routeMatch): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route', 'user.permissions']);

    $breadcrumb->addLink(Link::createFromRoute($this->t('Dashboard'), 'soda_scs_manager.dashboard'));

    $entity = $this->getRouteEntity($routeMatch);
    assert($entity instanceof EntityInterface);
    $breadcrumb->addCacheableDependency($entity);

    $routeName = (string) $routeMatch->getRouteName();
    $isCanonical = str_ends_with($routeName, '.canonical');
    $language = $this->languageManager->getCurrentLanguage();

    // Optional project parent for applications bound to a project.
    if (!$entity instanceof SodaScsProject) {
      $project = $this->getRelatedProject($entity);
      if ($project instanceof SodaScsProject) {
        $breadcrumb->addCacheableDependency($project);
        $breadcrumb->addLink(Link::fromTextAndUrl(
          $project->label(),
          $project->toUrl('canonical', ['language' => $language]),
        ));
      }
    }

    if ($isCanonical) {
      // Current page: entity label, not linked.
      $breadcrumb->addLink(Link::fromTextAndUrl($entity->label(), Url::fromRoute('<nolink>')));
      return $breadcrumb;
    }

    // Sub-pages (edit/delete/…): link the entity, then the page title.
    if ($entity->hasLinkTemplate('canonical')) {
      $breadcrumb->addLink(Link::fromTextAndUrl(
        $entity->label(),
        $entity->toUrl('canonical', ['language' => $language]),
      ));
    }
    else {
      $breadcrumb->addLink(Link::fromTextAndUrl($entity->label(), Url::fromRoute('<nolink>')));
    }

    $title = $routeMatch->getRouteObject()?->getDefault('_title');
    if (is_string($title) && $title !== '') {
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      $breadcrumb->addLink(Link::fromTextAndUrl($this->t($title), Url::fromRoute('<nolink>')));
    }

    return $breadcrumb;
  }

  /**
   * Returns the SCS entity from the route match, if any.
   */
  private function getRouteEntity(RouteMatchInterface $routeMatch): ?EntityInterface {
    foreach (self::ENTITY_PARAMS as $param) {
      $entity = $routeMatch->getParameter($param);
      if ($entity instanceof EntityInterface) {
        return $entity;
      }
    }
    return NULL;
  }

  /**
   * Resolves the (single) related project for components/stacks when present.
   */
  private function getRelatedProject(EntityInterface $entity): ?SodaScsProject {
    if (!$entity instanceof SodaScsComponent && !$entity instanceof SodaScsStack) {
      return NULL;
    }

    if (!$entity->hasField('partOfProjects') || $entity->get('partOfProjects')->isEmpty()) {
      return NULL;
    }

    $project = $entity->get('partOfProjects')->entity;
    return $project instanceof SodaScsProject ? $project : NULL;
  }

}
