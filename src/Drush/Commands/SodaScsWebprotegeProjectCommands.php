<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Drush\Commands;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Maintains WebProtégé ontology projects of SCS projects.
 *
 * WebProtégé is an opt-in project application: only projects with a
 * WebProtégé component have an ontology project. Safe to re-run on this
 * environment and later on scs-prime. Requires the WebProtégé API key in SCS
 * Manager settings and update 11028 ({@code webprotegeProjectId}).
 */
final class SodaScsWebprotegeProjectCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.webprotege.helpers')]
    protected SodaScsWebprotegeHelpers $webprotegeHelpers,
    #[Autowire(service: 'soda_scs_manager.project_application.helpers')]
    protected SodaScsProjectApplicationHelpers $projectApplicationHelpers,
    protected EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    parent::__construct();
  }

  /**
   * Create missing WebProtégé projects and grant EDIT to members.
   *
   * Only projects with a WebProtégé component are processed.
   *
   * @param array{dry-run?: bool, project?: string|null, all?: bool, limit?: int|null} $options
   *   Command options.
   */
  #[CLI\Command(name: 'soda_scs_manager:backfill-webprotege-projects', aliases: ['scs-backfill-webprotege'])]
  #[CLI\Option(name: 'dry-run', description: 'List planned create/share actions without calling WebProtégé.')]
  #[CLI\Option(name: 'project', description: 'Drupal project entity id to backfill.')]
  #[CLI\Option(name: 'all', description: 'Backfill every soda_scs_project.')]
  #[CLI\Option(name: 'limit', description: 'Process at most N projects (after selection).')]
  #[CLI\Usage(name: 'drush soda_scs_manager:backfill-webprotege-projects --dry-run --all', description: 'Inventory SCS projects with WebProtégé and report missing ontology projects.')]
  #[CLI\Usage(name: 'drush soda_scs_manager:backfill-webprotege-projects --project=6', description: 'Create or sync WebProtégé for a single SCS project.')]
  #[CLI\Usage(name: 'drush soda_scs_manager:backfill-webprotege-projects --all', description: 'Create missing ontology projects and grant EDIT to members (also for scs-prime).')]
  public function backfillWebprotegeProjects(array $options = [
    'dry-run' => FALSE,
    'project' => NULL,
    'all' => FALSE,
    'limit' => NULL,
  ]): int {
    $projectOption = $options['project'] ?? NULL;
    $all = !empty($options['all']);
    $dryRun = !empty($options['dry-run']);
    $limit = isset($options['limit']) && $options['limit'] !== NULL && $options['limit'] !== ''
      ? (int) $options['limit']
      : NULL;

    if (!$all && ($projectOption === NULL || $projectOption === '')) {
      $this->logger()->error('Specify --project=ID or --all.');
      return self::EXIT_FAILURE;
    }

    if (!$this->webprotegeHelpers->isApiConfigured()) {
      $this->logger()->error('WebProtégé API key is not configured. Set it in SCS Manager settings (WebProtégé tab), then re-run.');
      return self::EXIT_FAILURE;
    }

    $projects = $this->loadProjects($projectOption, $all);
    if ($projects === NULL) {
      return self::EXIT_FAILURE;
    }
    if ($projects === []) {
      $this->logger()->warning('No projects matched.');
      return self::EXIT_SUCCESS;
    }

    $projects = array_filter($projects, function (SodaScsProjectInterface $project): bool {
      if ($this->hasWebprotegeComponent($project)) {
        return TRUE;
      }
      $this->output()->writeln(sprintf(
        '— project=%s label=%s skipped: no WebProtégé component',
        (string) $project->id(),
        (string) $project->label(),
      ));
      return FALSE;
    });

    if ($limit !== NULL && $limit > 0) {
      $projects = array_slice($projects, 0, $limit, TRUE);
    }

    $created = 0;
    $synced = 0;
    $failed = 0;

    $this->output()->writeln(sprintf(
      '<info>Projects selected: %d | dry-run=%s</info>',
      count($projects),
      $dryRun ? 'yes' : 'no',
    ));

    foreach ($projects as $project) {
      if (!$project->hasField('webprotegeProjectId')) {
        $this->logger()->error('Field webprotegeProjectId is missing. Run `drush updb` (update 11028) first.');
        return self::EXIT_FAILURE;
      }

      $owner = $project->getOwner();
      $ownerName = $owner instanceof UserInterface ? $owner->getAccountName() : '?';
      $ownerWp = $owner instanceof UserInterface
        ? SodaScsWebprotegeHelpers::sanitizeUserName($owner->getAccountName())
        : '';
      $linkedId = trim((string) ($project->get('webprotegeProjectId')->value ?? ''));
      $memberNames = $this->memberAccountNames($project);

      $this->output()->writeln(sprintf(
        '— project=%s label=%s owner=%s wpOwner=%s linked=%s members=%s',
        (string) $project->id(),
        (string) $project->label(),
        $ownerName,
        $ownerWp !== '' ? $ownerWp : '?',
        $linkedId !== '' ? $linkedId : 'no',
        $memberNames !== [] ? implode(',', $memberNames) : '(none)',
      ));

      if ($dryRun) {
        if ($linkedId === '') {
          $this->output()->writeln('  [dry-run] would: POST /data/projects (owner CAN_MANAGE) then PUT EDIT for members');
          $created++;
        }
        else {
          $this->output()->writeln('  [dry-run] would: PUT EDIT for members (ontology project already linked)');
          $synced++;
        }
        continue;
      }

      $alreadyLinked = $linkedId !== '';
      $result = $this->webprotegeHelpers->syncOntologyProject($project);
      if (!$result->success) {
        $this->logger()->error(sprintf(
          '  FAILED: %s',
          $result->error ?: $result->message,
        ));
        $failed++;
        continue;
      }

      $this->output()->writeln(sprintf('  %s', $result->message));
      foreach ($result->data['members'] ?? [] as $row) {
        if (!empty($row['skipped'])) {
          continue;
        }
        $this->output()->writeln(sprintf(
          '  grantEdit %s: %s',
          $row['accountName'] ?? '?',
          !empty($row['success']) ? 'OK' : ('FAILED ' . ($row['error'] ?? '')),
        ));
      }

      if ($alreadyLinked) {
        $synced++;
      }
      else {
        $created++;
      }
    }

    $this->output()->writeln(sprintf(
      '<info>Done. created=%d synced=%d failed=%d</info>',
      $created,
      $synced,
      $failed,
    ));

    return $failed > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Links used ontology projects to a component and trashes unused ones.
   *
   * Handles projects that got an ontology project automatically (before
   * WebProtégé became opt-in) but have no WebProtégé component. An ontology
   * project counts as used when it has a revision or was modified after it
   * was created.
   *
   * @param array{dry-run?: bool, project?: string|null, all?: bool} $options
   *   Command options.
   */
  #[CLI\Command(name: 'soda_scs_manager:reconcile-webprotege-projects', aliases: ['scs-reconcile-webprotege'])]
  #[CLI\Option(name: 'dry-run', description: 'Report revision counts and planned actions without changing anything.')]
  #[CLI\Option(name: 'project', description: 'Drupal project entity id to reconcile.')]
  #[CLI\Option(name: 'all', description: 'Reconcile every soda_scs_project.')]
  #[CLI\Usage(name: 'drush soda_scs_manager:reconcile-webprotege-projects --dry-run --all', description: 'Show which linked ontology projects are used and what would happen.')]
  #[CLI\Usage(name: 'drush soda_scs_manager:reconcile-webprotege-projects --all', description: 'Add a WebProtégé component for used ontology projects, trash unused ones.')]
  public function reconcileWebprotegeProjects(array $options = [
    'dry-run' => FALSE,
    'project' => NULL,
    'all' => FALSE,
  ]): int {
    $projectOption = $options['project'] ?? NULL;
    $all = !empty($options['all']);
    $dryRun = !empty($options['dry-run']);

    if (!$all && ($projectOption === NULL || $projectOption === '')) {
      $this->logger()->error('Specify --project=ID or --all.');
      return self::EXIT_FAILURE;
    }

    if (!$this->webprotegeHelpers->isApiConfigured()) {
      $this->logger()->error('WebProtégé API key is not configured. Set it in SCS Manager settings (WebProtégé tab), then re-run.');
      return self::EXIT_FAILURE;
    }

    $projects = $this->loadProjects($projectOption, $all);
    if ($projects === NULL) {
      return self::EXIT_FAILURE;
    }

    $linked = 0;
    $trashed = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($projects as $project) {
      $linkedId = trim((string) ($project->get('webprotegeProjectId')->value ?? ''));
      if ($linkedId === '' || $this->hasWebprotegeComponent($project)) {
        $skipped++;
        continue;
      }

      $revisions = $this->webprotegeHelpers->listRevisions($project);
      if (!$revisions->success) {
        $this->logger()->error(sprintf(
          '— project=%s label=%s wp=%s FAILED reading revisions: %s',
          (string) $project->id(),
          (string) $project->label(),
          $linkedId,
          $revisions->error ?: $revisions->message,
        ));
        $failed++;
        continue;
      }

      $details = $this->webprotegeHelpers->getProjectDetails($project);
      if (!$details->success) {
        $this->logger()->error(sprintf(
          '— project=%s label=%s wp=%s FAILED reading details: %s',
          (string) $project->id(),
          (string) $project->label(),
          $linkedId,
          $details->error ?: $details->message,
        ));
        $failed++;
        continue;
      }

      $count = (int) $revisions->data['count'];
      $latest = $revisions->data['latest'];
      $createdAt = (string) ($details->data['details']['createdAt'] ?? '');
      $modifiedAt = (string) ($details->data['details']['modifiedAt'] ?? '');
      $used = $count > 0 || $createdAt === '' || $modifiedAt !== $createdAt;
      $this->output()->writeln(sprintf(
        '— project=%s label=%s wp=%s revisions=%d createdAt=%s modifiedAt=%s latest=%s',
        (string) $project->id(),
        (string) $project->label(),
        $linkedId,
        $count,
        $createdAt !== '' ? $createdAt : '?',
        $modifiedAt !== '' ? $modifiedAt : '?',
        is_array($latest) ? json_encode(array_intersect_key($latest, array_flip(['revisionNumber', 'timestamp', 'userId', 'highLevelDescription'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '-',
      ));

      if ($used) {
        if ($dryRun) {
          $this->output()->writeln('  [dry-run] would: add WebProtégé component (used)');
          $linked++;
          continue;
        }
        $this->createWebprotegeComponent($project);
        $this->webprotegeHelpers->syncMemberEditAccess($project);
        $this->output()->writeln('  added WebProtégé component');
        $linked++;
        continue;
      }

      if ($dryRun) {
        $this->output()->writeln('  [dry-run] would: move ontology project to trash and unlink (unused)');
        $trashed++;
        continue;
      }
      $trash = $this->webprotegeHelpers->trashOntologyProject($project);
      if (!$trash->success) {
        $this->logger()->error(sprintf('  FAILED trashing: %s', $trash->error ?: $trash->message));
        $failed++;
        continue;
      }
      $project->set('webprotegeProjectId', NULL);
      $project->save();
      $this->output()->writeln('  moved ontology project to trash and unlinked it');
      $trashed++;
    }

    $this->output()->writeln(sprintf(
      '<info>Done%s. linked=%d trashed=%d skipped=%d failed=%d</info>',
      $dryRun ? ' (dry-run)' : '',
      $linked,
      $trashed,
      $skipped,
      $failed,
    ));

    return $failed > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Whether the project has a WebProtégé component.
   */
  private function hasWebprotegeComponent(SodaScsProjectInterface $project): bool {
    return $this->projectApplicationHelpers->projectHasApplication($project->id(), 'soda_scs_component', 'soda_scs_webprotege_component');
  }

  /**
   * Creates the project's WebProtégé component for an existing ontology project.
   */
  private function createWebprotegeComponent(SodaScsProjectInterface $project): void {
    $bundleInfo = $this->bundleInfo->getBundleInfo('soda_scs_component')['soda_scs_webprotege_component'] ?? [];
    $this->entityTypeManager->getStorage('soda_scs_component')->create([
      'bundle' => 'soda_scs_webprotege_component',
      'label' => (string) ($bundleInfo['label'] ?? 'WebProtégé'),
      'machineName' => 'webprotege-project-' . $project->id(),
      'description' => (string) ($bundleInfo['description'] ?? ''),
      'imageUrl' => (string) ($bundleInfo['imageUrl'] ?? ''),
      'owner' => $project->getOwnerId(),
      'partOfProjects' => [['target_id' => $project->id()]],
    ])->save();
  }

  /**
   * @return array<int, \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface>|null
   */
  private function loadProjects(mixed $projectOption, bool $all): ?array {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project');

    if (!$all) {
      $project = $storage->load((int) $projectOption);
      if (!$project instanceof SodaScsProjectInterface) {
        $this->logger()->error(sprintf('Project %s not found.', (string) $projectOption));
        return NULL;
      }
      return [(int) $project->id() => $project];
    }

    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    if ($ids === []) {
      return [];
    }
    /** @var array<int, \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface> $projects */
    $projects = $storage->loadMultiple($ids);
    return $projects;
  }

  /**
   * @return list<string>
   */
  private function memberAccountNames(SodaScsProjectInterface $project): array {
    $names = [];
    if (!$project->hasField('members') || $project->get('members')->isEmpty()) {
      return $names;
    }
    foreach ($project->get('members')->referencedEntities() as $member) {
      if ($member instanceof UserInterface) {
        $names[] = $member->getAccountName();
      }
    }
    return $names;
  }

}
