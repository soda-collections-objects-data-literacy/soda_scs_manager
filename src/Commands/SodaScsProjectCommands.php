<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Commands;

use Drupal\soda_scs_manager\Entity\SodaScsProject;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for project management.
 */
class SodaScsProjectCommands extends DrushCommands {

  /**
   * Remove orphaned component references from projects (one-time cleanup).
   *
   * @command soda-scs:projects:clean-orphans
   * @aliases soda-scs-clean-orphans
   * @usage soda-scs:projects:clean-orphans
   *   Remove orphaned component references from all projects.
   */
  public function cleanOrphans(): void {
    $this->output()->writeln('<info>Cleaning orphaned component references from projects...</info>');

    $projectStorage = \Drupal::entityTypeManager()->getStorage('soda_scs_project');
    $componentStorage = \Drupal::entityTypeManager()->getStorage('soda_scs_component');

    $existingComponentIds = array_flip(
      $componentStorage->getQuery()->accessCheck(FALSE)->execute()
    );

    $projectIds = $projectStorage->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    if (empty($projectIds)) {
      $this->output()->writeln('<success>No projects to clean.</success>');
      return;
    }

    /** @var SodaScsProject[] $projects */
    $projects = $projectStorage->loadMultiple($projectIds);
    $removedTotal = 0;

    foreach ($projects as $project) {
      $refs = $project->get('connectedComponents')->getValue();
      if (empty($refs)) {
        continue;
      }

      $validRefs = [];
      foreach ($refs as $ref) {
        $targetId = (int) ($ref['target_id'] ?? 0);
        if ($targetId && isset($existingComponentIds[$targetId])) {
          $validRefs[] = $ref;
        }
        else {
          $removedTotal++;
        }
      }

      if (count($validRefs) !== count($refs)) {
        $project->set('connectedComponents', $validRefs);
        $project->save();
      }
    }

    $this->output()->writeln(sprintf(
      '<success>Removed %d orphaned component reference(s) from projects.</success>',
      $removedTotal
    ));
  }

}
