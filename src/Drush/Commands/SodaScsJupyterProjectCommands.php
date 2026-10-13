<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for the shared JupyterLab of each project.
 */
final class SodaScsJupyterProjectCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.jupyter.helpers')]
    protected SodaScsJupyterHelpers $jupyterHelpers,
    #[Autowire(service: 'soda_scs_manager.nextcloud_project_mount.manager')]
    protected NextcloudProjectMountManager $projectMountManager,
  ) {
    parent::__construct();
  }

  /**
   * Set up the project server of every project with JupyterHub.
   *
   * Mounts the Team Folder and creates the hub user project-<gid>; both steps
   * are idempotent.
   */
  #[CLI\Command(name: 'soda_scs_manager:jupyter-backfill-project-servers', aliases: ['scs:jupyter-backfill-project-servers'])]
  #[CLI\Option(name: 'dry-run', description: 'Only list the projects.')]
  #[CLI\Usage(name: 'drush scs:jupyter-backfill-project-servers --dry-run', description: 'Show which projects would be set up.')]
  public function backfill(array $options = ['dry-run' => FALSE]): int {
    $failures = 0;
    foreach ($this->loadProjectsWithJupyter() as $project) {
      $hubUser = SodaScsJupyterHelpers::projectServerUserName($project);
      if ($options['dry-run']) {
        $this->io()->writeln(sprintf('[dry-run] %s "%s" → hub user %s', $project->id(), $project->label(), $hubUser));
        continue;
      }
      $mounted = $this->projectMountManager->ensureProjectMounted($project);
      $user = $this->jupyterHelpers->ensureProjectServerUser($project);
      if ($mounted && $user->success) {
        $this->logger()->success(dt('Project @id "@label": mounted, hub user @user ready.', [
          '@id' => $project->id(),
          '@label' => $project->label(),
          '@user' => $hubUser,
        ]));
        continue;
      }
      $failures++;
      $this->logger()->error(dt('Project @id "@label": mount @mount, hub user @user.', [
        '@id' => $project->id(),
        '@label' => $project->label(),
        '@mount' => $mounted ? 'ok' : 'failed',
        '@user' => $user->success ? 'ok' : $user->error,
      ]));
    }
    return $failures > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Show project mount and server state of every project with JupyterHub.
   */
  #[CLI\Command(name: 'soda_scs_manager:jupyter-project-status', aliases: ['scs:jupyter-project-status'])]
  public function status(): int {
    $rows = [];
    foreach ($this->loadProjectsWithJupyter() as $project) {
      $rows[] = [
        $project->id(),
        $project->label(),
        SodaScsJupyterHelpers::projectServerUserName($project),
        $this->projectMountManager->projectMountState($project),
        $this->jupyterHelpers->projectServerState($project) ?? 'unknown',
      ];
    }
    if ($rows === []) {
      $this->io()->writeln('No project has JupyterHub.');
      return self::EXIT_SUCCESS;
    }
    $this->io()->table(['id', 'label', 'hub user', 'mount', 'server'], $rows);
    return self::EXIT_SUCCESS;
  }

  /**
   * Loads the projects that have a Jupyter stack.
   *
   * @return list<\Drupal\soda_scs_manager\Entity\SodaScsProjectInterface>
   *   The projects.
   */
  protected function loadProjectsWithJupyter(): array {
    $projects = [];
    $storage = $this->entityTypeManager->getStorage('soda_scs_project');
    foreach ($storage->loadMultiple() as $project) {
      if ($project instanceof SodaScsProjectInterface && $this->projectMountManager->needsProjectMount($project)) {
        $projects[] = $project;
      }
    }
    return $projects;
  }

}
