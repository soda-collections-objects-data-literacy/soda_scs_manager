<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Backfill Nextcloud Team Folders for legacy Drupal projects.
 */
final class SodaScsProjectTeamFolderCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    protected SodaScsProjectHelpers $projectHelpers,
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
    #[Autowire(service: 'soda_scs_manager.nextcloud.helpers')]
    protected SodaScsNextcloudHelpers $nextcloudHelpers,
  ) {
    parent::__construct();
  }

  /**
   * Backfill Keycloak attrs, members, and Nextcloud Team Folders for projects.
   *
   * @param array{dry-run?: bool, project?: string|null, all?: bool, limit?: int|null, grant-nc-access?: bool} $options
   *   Command options.
   */
  #[CLI\Command(name: 'soda_scs_manager:backfill-project-team-folders', aliases: ['scs-backfill-project-tf'])]
  #[CLI\Option(name: 'dry-run', description: 'List planned actions without calling Keycloak or Nextcloud write APIs.')]
  #[CLI\Option(name: 'project', description: 'Drupal project entity id to backfill.')]
  #[CLI\Option(name: 'all', description: 'Backfill every soda_scs_project.')]
  #[CLI\Option(name: 'limit', description: 'Process at most N projects (after selection).')]
  #[CLI\Option(name: 'grant-nc-access', description: 'Also run occ group:adduser for owner and members (immediate Drive visibility).')]
  #[CLI\Usage(name: 'drush soda_scs_manager:backfill-project-team-folders --dry-run --all', description: 'Inventory all projects and report missing Team Folders.')]
  #[CLI\Usage(name: 'drush soda_scs_manager:backfill-project-team-folders --project=6', description: 'Backfill a single legacy project.')]
  #[CLI\Usage(name: 'drush soda_scs_manager:backfill-project-team-folders --all --grant-nc-access', description: 'Backfill all projects and grant Nextcloud group membership.')]
  public function backfillProjectTeamFolders(array $options = [
    'dry-run' => FALSE,
    'project' => NULL,
    'all' => FALSE,
    'limit' => NULL,
    'grant-nc-access' => FALSE,
  ]): int {
    $projectOption = $options['project'] ?? NULL;
    $all = !empty($options['all']);
    $dryRun = !empty($options['dry-run']);
    $grantNcAccess = !empty($options['grant-nc-access']);
    $limit = isset($options['limit']) && $options['limit'] !== NULL && $options['limit'] !== ''
      ? (int) $options['limit']
      : NULL;

    if (!$all && ($projectOption === NULL || $projectOption === '')) {
      $this->logger()->error('Specify --project=ID or --all.');
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

    $existingFolders = $this->projectHelpers->listManagedFoldersByExternalProjectId();
    $ok = 0;
    $failed = 0;
    $skippedExisting = 0;

    $this->output()->writeln(sprintf(
      '<info>Projects selected: %d | Existing project Team Folders: %d | dry-run=%s grant-nc-access=%s</info>',
      count($projects),
      count($existingFolders),
      $dryRun ? 'yes' : 'no',
      $grantNcAccess ? 'yes' : 'no',
    ));

    foreach ($projects as $project) {
      $projectId = (string) $project->id();
      $groupId = (string) ($project->get('groupId')->value ?? '');
      $label = (string) $project->label();
      $hasFolder = isset($existingFolders[$projectId]);

      $this->output()->writeln(sprintf(
        '— project=%s groupId=%s label=%s hasTeamFolder=%s',
        $projectId,
        $groupId !== '' ? $groupId : '?',
        $label,
        $hasFolder ? 'yes' : 'no',
      ));

      if ($dryRun) {
        $this->output()->writeln('  [dry-run] would: updateProjectGroupAttributes → syncKeycloakGroupMembers → createProjectTeamFolder → updateProjectTeamFolderLabel'
          . ($grantNcAccess ? ' → occ group:adduser' : ''));
        if ($hasFolder) {
          $skippedExisting++;
        }
        $ok++;
        continue;
      }

      $result = $this->backfillOneProject($project, $grantNcAccess);
      if ($result) {
        $ok++;
        if ($hasFolder) {
          $skippedExisting++;
        }
        // Refresh map after creates so later grant/status stays accurate.
        if (!$hasFolder) {
          $existingFolders = $this->projectHelpers->listManagedFoldersByExternalProjectId();
        }
      }
      else {
        $failed++;
      }
    }

    $this->output()->writeln(sprintf(
      '<info>Done. ok=%d failed=%d already-had-folder=%d</info>',
      $ok,
      $failed,
      $skippedExisting,
    ));

    return $failed > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * @return array<int, \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface>|null
   *   Projects keyed by id, or NULL on hard error.
   */
  protected function loadProjects(mixed $projectOption, bool $all): ?array {
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
   * Run the four helpers (+ optional NC grants) for one project.
   */
  protected function backfillOneProject(SodaScsProjectInterface $project, bool $grantNcAccess): bool {
    // Member sync can fail on orphan projects (deleted owner / missing KC user)
    // without blocking Team Folder creation.
    $softFailSteps = ['syncKeycloakGroupMembers' => TRUE];
    $steps = [
      'updateProjectGroupAttributes' => fn () => $this->projectHelpers->updateProjectGroupAttributes($project),
      'syncKeycloakGroupMembers' => fn () => $this->projectHelpers->syncKeycloakGroupMembers($project),
      'createProjectTeamFolder' => fn () => $this->projectHelpers->createProjectTeamFolder($project),
      'updateProjectTeamFolderLabel' => fn () => $this->projectHelpers->updateProjectTeamFolderLabel($project),
    ];

    foreach ($steps as $name => $callback) {
      try {
        $result = $callback();
      }
      catch (\Throwable $e) {
        $this->logger()->error(sprintf('  %s EXCEPTION: %s', $name, $e->getMessage()));
        if (!empty($softFailSteps[$name])) {
          $this->logger()->warning(sprintf('  %s failed; continuing with Team Folder steps.', $name));
          continue;
        }
        return FALSE;
      }

      if (!$result->success) {
        if (!empty($softFailSteps[$name])) {
          $this->logger()->warning(sprintf(
            '  %s FAILED (continuing): %s',
            $name,
            $result->error ?: $result->message,
          ));
          continue;
        }
        $this->logger()->error(sprintf(
          '  %s FAILED: %s',
          $name,
          $result->error ?: $result->message,
        ));
        return FALSE;
      }
      $this->output()->writeln(sprintf('  %s: OK (%s)', $name, $result->message ?: 'ok'));
    }

    if ($grantNcAccess) {
      $this->grantNextcloudGroupAccess($project);
    }

    return TRUE;
  }

  /**
   * Add project owner/members to the Nextcloud ACL group via occ.
   */
  protected function grantNextcloudGroupAccess(SodaScsProjectInterface $project): void {
    $groupId = (string) ($project->get('groupId')->value ?? '');
    if ($groupId === '') {
      $this->logger()->warning('  grant-nc-access skipped: empty groupId.');
      return;
    }

    $users = [];
    $owner = $project->get('owner')->entity;
    if ($owner instanceof UserInterface) {
      $users[] = $owner;
    }
    foreach ($project->get('members')->referencedEntities() as $member) {
      if ($member instanceof UserInterface) {
        $users[] = $member;
      }
    }

    $seen = [];
    foreach ($users as $user) {
      $ssoUuid = $this->projectHelpers->getUserSsoUuid($user);
      if ($ssoUuid === NULL || $ssoUuid === '') {
        $this->logger()->warning(sprintf(
          '  grant-nc-access: no SSO UUID for Drupal user %s',
          $user->getAccountName(),
        ));
        continue;
      }

      // occ / groupfolders use the raw user_oidc id (Keycloak sub), not the
      // prefixed WebDAV username.
      $ncUid = $ssoUuid;
      if (isset($seen[$ncUid])) {
        continue;
      }
      $seen[$ncUid] = TRUE;

      $ok = $this->occGroupAddUser($groupId, $ncUid);
      if ($ok) {
        $this->output()->writeln(sprintf(
          '  grant-nc-access: group:adduser %s %s (Drupal %s)',
          $groupId,
          $ncUid,
          $user->getAccountName(),
        ));
      }
      else {
        $this->logger()->warning(sprintf(
          '  grant-nc-access FAILED for %s → group %s (user may need Drive login first)',
          $user->getAccountName(),
          $groupId,
        ));
      }
    }
  }

  /**
   * Run `occ group:adduser` in the Nextcloud container.
   */
  protected function occGroupAddUser(string $groupId, string $ncUid): bool {
    $settings = $this->configFactory->get('soda_scs_manager.settings')->get('nextcloud')['generalSettings'] ?? [];
    $container = (string) ($settings['occContainerName'] ?? '');
    if ($container === '') {
      $container = SodaScsNextcloudHelpers::DEFAULT_OCC_CONTAINER;
    }

    $result = $this->containerHelpers->executeDockerExecCommand([
      'cmd' => [
        'php',
        '/var/www/html/occ',
        'group:adduser',
        $groupId,
        $ncUid,
      ],
      'containerName' => $container,
      'user' => 'www-data',
    ]);

    if ($result->success) {
      return TRUE;
    }

    // Already a member is still success for our purposes.
    $error = strtolower((string) ($result->error ?? '') . ' ' . (string) ($result->data['output'] ?? ''));
    if (str_contains($error, 'already') || str_contains($error, 'exist')) {
      return TRUE;
    }

    return FALSE;
  }

}
