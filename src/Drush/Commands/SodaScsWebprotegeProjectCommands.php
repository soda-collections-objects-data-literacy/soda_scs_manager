<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Backfill WebProtégé ontology projects for existing SCS projects.
 *
 * Safe to re-run on this environment and later on scs-prime. Requires the
 * WebProtégé API key in SCS Manager settings and update 11028
 * ({@code webprotegeProjectId}).
 */
final class SodaScsWebprotegeProjectCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.webprotege.helpers')]
    protected SodaScsWebprotegeHelpers $webprotegeHelpers,
  ) {
    parent::__construct();
  }

  /**
   * Create missing WebProtégé projects and grant EDIT to members.
   *
   * @param array{dry-run?: bool, project?: string|null, all?: bool, limit?: int|null} $options
   *   Command options.
   */
  #[CLI\Command(name: 'soda_scs_manager:backfill-webprotege-projects', aliases: ['scs-backfill-webprotege'])]
  #[CLI\Option(name: 'dry-run', description: 'List planned create/share actions without calling WebProtégé.')]
  #[CLI\Option(name: 'project', description: 'Drupal project entity id to backfill.')]
  #[CLI\Option(name: 'all', description: 'Backfill every soda_scs_project.')]
  #[CLI\Option(name: 'limit', description: 'Process at most N projects (after selection).')]
  #[CLI\Usage(name: 'drush soda_scs_manager:backfill-webprotege-projects --dry-run --all', description: 'Inventory SCS projects and report missing WebProtégé ontology projects.')]
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
