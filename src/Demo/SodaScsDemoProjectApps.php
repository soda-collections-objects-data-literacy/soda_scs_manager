<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * Finds the applications of a project that take part in a demo.
 *
 * A demo has one WissKI stack, one Jupyter stack and one WebProtégé
 * component. The database and triplestore inside the WissKI stack stay
 * part of that stack.
 */
class SodaScsDemoProjectApps {

  public const JUPYTER_STACK_BUNDLE = 'soda_scs_jupyter_stack';
  public const SQL_COMPONENT_BUNDLE = 'soda_scs_sql_component';
  public const TRIPLESTORE_COMPONENT_BUNDLE = 'soda_scs_triplestore_component';
  public const WEBPROTEGE_COMPONENT_BUNDLE = 'soda_scs_webprotege_component';
  public const WISSKI_COMPONENT_BUNDLE = 'soda_scs_wisski_component';
  public const WISSKI_STACK_BUNDLE = 'soda_scs_wisski_stack';

  /**
   * Constructs the inventory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The Jupyter stack of a project, if any.
   */
  public function jupyterStack(SodaScsProjectInterface $project): ?SodaScsStackInterface {
    $stacks = $this->projectStacks($project, self::JUPYTER_STACK_BUNDLE);
    return reset($stacks) ?: NULL;
  }

  /**
   * The component of a given bundle inside a stack.
   */
  public function stackComponent(SodaScsStackInterface $stack, string $bundle): ?SodaScsComponentInterface {
    foreach ($stack->get('includedComponents')->referencedEntities() as $component) {
      if ($component instanceof SodaScsComponentInterface && $component->bundle() === $bundle) {
        return $component;
      }
    }
    return NULL;
  }

  /**
   * The WebProtégé component of a project, if any; a project has at most one.
   */
  public function webprotegeComponent(SodaScsProjectInterface $project): ?SodaScsComponentInterface {
    $ids = $this->entityTypeManager->getStorage('soda_scs_component')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', self::WEBPROTEGE_COMPONENT_BUNDLE)
      ->condition('partOfProjects', $project->id())
      ->sort('id')
      ->range(0, 1)
      ->execute();
    $component = $ids === [] ? NULL : $this->entityTypeManager->getStorage('soda_scs_component')->load(reset($ids));
    return $component instanceof SodaScsComponentInterface ? $component : NULL;
  }

  /**
   * The WissKI stack of a project, if any.
   */
  public function wisskiStack(SodaScsProjectInterface $project): ?SodaScsStackInterface {
    $stacks = $this->projectStacks($project, self::WISSKI_STACK_BUNDLE);
    return reset($stacks) ?: NULL;
  }

  /**
   * Stacks of a bundle in a project, ordered by id.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[]
   *   The stacks.
   */
  protected function projectStacks(SodaScsProjectInterface $project, string $bundle): array {
    $ids = $this->entityTypeManager->getStorage('soda_scs_stack')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', $bundle)
      ->condition('partOfProjects', $project->id())
      ->sort('id')
      ->execute();
    return array_values($this->entityTypeManager->getStorage('soda_scs_stack')->loadMultiple($ids));
  }

}
