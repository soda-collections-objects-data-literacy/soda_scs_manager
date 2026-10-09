<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;

/**
 * Looks up applications that may exist only once per project.
 *
 * WebProtégé and JupyterHub run on shared instances; a project gets one
 * WebProtégé ontology project and one Jupyter entry, owned by the project
 * owner and usable by all members.
 */
class SodaScsProjectApplicationHelpers {

  /**
   * Bundles a project may contain at most once, keyed by entity type.
   *
   * @var array<string, list<string>>
   */
  public const SINGLE_INSTANCE_BUNDLES = [
    'soda_scs_component' => ['soda_scs_webprotege_component'],
    'soda_scs_stack' => ['soda_scs_jupyter_stack'],
  ];

  /**
   * Constructs the helper.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Whether a project may contain the bundle at most once.
   */
  public function isSingleInstanceBundle(string $entityTypeId, ?string $bundle): bool {
    return $bundle !== NULL
      && in_array($bundle, self::SINGLE_INSTANCE_BUNDLES[$entityTypeId] ?? [], TRUE);
  }

  /**
   * Loads the project's application of the given bundle, if any.
   *
   * @param int|string $projectId
   *   The project entity ID.
   * @param string $entityTypeId
   *   Either soda_scs_component or soda_scs_stack.
   * @param string $bundle
   *   The application bundle.
   * @param int|string|null $excludeId
   *   An entity ID to ignore (e.g. the entity being saved).
   */
  public function loadProjectApplication(int|string $projectId, string $entityTypeId, string $bundle, int|string|null $excludeId = NULL): ?EntityInterface {
    $query = $this->entityTypeManager
      ->getStorage($entityTypeId)
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', $bundle)
      ->condition('partOfProjects', $projectId)
      ->sort('id')
      ->range(0, 1);
    if ($excludeId !== NULL) {
      $query->condition('id', $excludeId, '<>');
    }
    $ids = $query->execute();
    if (empty($ids)) {
      return NULL;
    }

    return $this->entityTypeManager->getStorage($entityTypeId)->load(reset($ids));
  }

  /**
   * Loads all once-per-project applications of a project.
   *
   * @return list<\Drupal\Core\Entity\EntityInterface>
   *   WebProtégé components and Jupyter stacks linked to the project.
   */
  public function loadProjectApplications(int|string $projectId): array {
    $applications = [];
    foreach (self::SINGLE_INSTANCE_BUNDLES as $entityTypeId => $bundles) {
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('bundle', $bundles, 'IN')
        ->condition('partOfProjects', $projectId)
        ->execute();
      if (!empty($ids)) {
        array_push($applications, ...array_values($storage->loadMultiple($ids)));
      }
    }
    return $applications;
  }

  /**
   * Whether the project already has an application of the given bundle.
   */
  public function projectHasApplication(int|string $projectId, string $entityTypeId, string $bundle): bool {
    return $this->loadProjectApplication($projectId, $entityTypeId, $bundle) !== NULL;
  }

  /**
   * Loads the project an application belongs to.
   */
  public function loadPartOfProject(FieldableEntityInterface $entity): ?SodaScsProjectInterface {
    if (!$entity->hasField('partOfProjects') || $entity->get('partOfProjects')->isEmpty()) {
      return NULL;
    }
    $project = $entity->get('partOfProjects')->entity;
    return $project instanceof SodaScsProjectInterface ? $project : NULL;
  }

}
