<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProject;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudGroupHelpers;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes Nextcloud groups left behind by deleted projects and applications.
 *
 * Before the Manager deleted them, every deleted project left its gid group
 * and every deleted WissKI, SQL, or triplestore application its -admin and
 * -user groups in Nextcloud.
 */
final class SodaScsNextcloudGroupCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Prefix that an older user_oidc setting put before copied Keycloak groups.
   */
  private const LEGACY_OIDC_PREFIX = 'keycloak-';

  /**
   * Component bundles that have -admin and -user access groups.
   */
  private const ACCESS_GROUP_BUNDLES = [
    'soda_scs_sql_component',
    'soda_scs_triplestore_component',
    'soda_scs_wisski_component',
  ];

  /**
   * Names of the application access groups.
   */
  private const ACCESS_GROUP_PATTERN = '/^(sql|ts|wisski)-.+-(admin|user)$/';

  /**
   * Constructs the command.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.nextcloud_group.helpers')]
    protected SodaScsNextcloudGroupHelpers $nextcloudGroupHelpers,
  ) {
    parent::__construct();
  }

  /**
   * Lists, and with --apply deletes, orphaned Nextcloud groups.
   *
   * A group is orphaned when it is named like a project gid or an
   * application access group (optionally with the old "keycloak-" prefix)
   * and no project or application of that name exists. Only empty groups
   * are deleted; orphaned groups with members are listed and kept.
   *
   * @param array{apply?: bool} $options
   *   Command options.
   */
  #[CLI\Command(name: 'soda_scs_manager:delete-orphaned-nextcloud-groups', aliases: ['scs-nc-orphan-groups'])]
  #[CLI\Option(name: 'apply', description: 'Delete the empty orphaned groups. Without it, only list them.')]
  #[CLI\Usage(name: 'drush soda_scs_manager:delete-orphaned-nextcloud-groups', description: 'List orphaned Nextcloud groups.')]
  #[CLI\Usage(name: 'drush soda_scs_manager:delete-orphaned-nextcloud-groups --apply', description: 'Delete the empty orphaned Nextcloud groups.')]
  public function deleteOrphanedGroups(array $options = ['apply' => FALSE]): int {
    $memberCounts = $this->nextcloudGroupHelpers->listGroupMemberCounts();
    if ($memberCounts === NULL) {
      $this->logger()->error('Could not list the Nextcloud groups; nothing was deleted.');
      return self::EXIT_FAILURE;
    }

    $knownGroups = $this->knownGroupNames();
    $apply = !empty($options['apply']);
    $rows = [];
    $deleted = 0;
    $failed = 0;
    foreach ($memberCounts as $groupId => $memberCount) {
      $groupId = (string) $groupId;
      if (!$this->isOrphaned($groupId, $knownGroups)) {
        continue;
      }
      if ($memberCount > 0) {
        $action = 'kept (has members)';
      }
      elseif (!$apply) {
        $action = 'would delete';
      }
      elseif ($this->nextcloudGroupHelpers->deleteGroup($groupId)) {
        $action = 'deleted';
        $deleted++;
      }
      else {
        $action = 'delete failed';
        $failed++;
      }
      $rows[] = [$groupId, $memberCount, $action];
    }

    if ($rows === []) {
      $this->io()->success('No orphaned Nextcloud groups.');
      return self::EXIT_SUCCESS;
    }
    $this->io()->table(['Group', 'Members', 'Action'], $rows);
    if (!$apply) {
      $this->io()->note('Nothing deleted. Run again with --apply to delete the empty groups.');
      return self::EXIT_SUCCESS;
    }
    $this->io()->writeln(sprintf('Deleted %d groups, %d failed.', $deleted, $failed));
    return $failed === 0 ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Whether a group mirrors a project or application that no longer exists.
   *
   * @param string $groupId
   *   The Nextcloud group id.
   * @param array<string, true> $knownGroups
   *   Group names of the existing projects and applications.
   */
  private function isOrphaned(string $groupId, array $knownGroups): bool {
    $baseName = str_starts_with($groupId, self::LEGACY_OIDC_PREFIX)
      ? substr($groupId, strlen(self::LEGACY_OIDC_PREFIX))
      : $groupId;
    $isProjectGroup = ctype_digit($baseName) && (int) $baseName > SodaScsProject::GROUP_ID_START;
    $isAccessGroup = preg_match(self::ACCESS_GROUP_PATTERN, $baseName) === 1;
    if (!$isProjectGroup && !$isAccessGroup) {
      return FALSE;
    }
    // A prefixed copy of an existing group may still be shared with a
    // Team Folder; only the copy of a deleted one is orphaned.
    return !isset($knownGroups[$groupId]) && !isset($knownGroups[$baseName]);
  }

  /**
   * Group names of the existing projects and applications.
   *
   * @return array<string, true>
   *   Project gids and application -admin/-user group names.
   */
  private function knownGroupNames(): array {
    $known = [];
    $projectIds = $this->entityTypeManager->getStorage('soda_scs_project')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    foreach ($projectIds as $projectId) {
      $known[(string) ((int) $projectId + SodaScsProject::GROUP_ID_START)] = TRUE;
    }

    $componentStorage = $this->entityTypeManager->getStorage('soda_scs_component');
    $componentIds = $componentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', self::ACCESS_GROUP_BUNDLES, 'IN')
      ->execute();
    foreach ($componentStorage->loadMultiple($componentIds) as $component) {
      $machineName = (string) ($component->get('machineName')->value ?? '');
      if ($machineName !== '') {
        $known[$machineName . '-admin'] = TRUE;
        $known[$machineName . '-user'] = TRUE;
      }
    }
    return $known;
  }

}
