<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Drush;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\soda_scs_manager\Drush\Commands\SodaScsNextcloudGroupCommands;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudGroupHelpers;
use Drupal\Tests\UnitTestCase;
use Drush\Drush;
use Drush\Log\DrushLoggerManager;
use Drush\Style\DrushStyle;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The drush command that removes orphaned Nextcloud groups.
 *
 * Issue 3642180. Projects 119 (gid 10119) and the WissKI application
 * "wisski-live" exist; everything else in the Nextcloud list is a leftover
 * or a group the command must not touch.
 */
#[CoversMethod(SodaScsNextcloudGroupCommands::class, 'deleteOrphanedGroups')]
#[Group('soda_scs_manager')]
class SodaScsNextcloudGroupCommandsTest extends UnitTestCase {

  /**
   * Messages the command logged, as "level: message".
   *
   * @var list<string>
   */
  private array $logged = [];

  /**
   * Rows of the table the command printed, keyed by group id.
   *
   * @var array<string, array{0: string, 1: int, 2: string}>
   */
  private array $rows = [];

  /**
   * The Nextcloud group helpers double.
   */
  private SodaScsNextcloudGroupHelpers&MockObject $groupHelpers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (!function_exists('dt')) {
      // The helper drush defines when it runs; the commands call it.
      require_once dirname((new \ReflectionClass(Drush::class))->getFileName(), 2) . '/includes/output.inc';
    }
    $this->logged = [];
    $this->rows = [];
    $this->groupHelpers = $this->createMock(SodaScsNextcloudGroupHelpers::class);
  }

  /**
   * Only groups named like a gid or an access group of a deleted entity.
   */
  public function testListsOnlyGroupsOfDeletedProjectsAndApplications(): void {
    $this->groupHelpers->method('listGroupMemberCounts')->willReturn([
      // Leftovers.
      '10120' => 0,
      'sql-gone-admin' => 0,
      'ts-gone-user' => 0,
      'wisski-gone-admin' => 0,
      'keycloak-10121' => 0,
      'keycloak-wisski-gone-user' => 0,
      // Groups of existing entities, also with the old prefix.
      '10119' => 0,
      'keycloak-10119' => 0,
      'wisski-live-admin' => 0,
      'wisski-live-user' => 0,
      'keycloak-wisski-live-user' => 0,
      // Not named like a Manager group.
      'admin' => 1,
      '10000' => 0,
      '9999' => 0,
      'jupyter-x-admin' => 0,
      'sql-gone-owner' => 0,
      'wisski--admin' => 0,
      'keycloak-' => 0,
      'my-team' => 0,
    ]);

    $this->command()->deleteOrphanedGroups();

    $this->assertSame(
      ['10120', 'sql-gone-admin', 'ts-gone-user', 'wisski-gone-admin', 'keycloak-10121', 'keycloak-wisski-gone-user'],
      array_column(array_values($this->rows), 0),
    );
  }

  /**
   * Without --apply nothing is deleted.
   */
  public function testDryRunDeletesNothing(): void {
    $this->groupHelpers->method('listGroupMemberCounts')->willReturn(['10120' => 0, 'sql-gone-user' => 2]);
    $this->groupHelpers->expects($this->never())->method('deleteGroup');

    $this->assertSame(0, $this->command()->deleteOrphanedGroups());
    $this->assertSame(['10120', 0, 'would delete'], $this->rows['10120']);
    $this->assertSame(['sql-gone-user', 2, 'kept (has members)'], $this->rows['sql-gone-user']);
  }

  /**
   * With --apply only empty orphaned groups are deleted.
   */
  public function testApplyDeletesOnlyEmptyOrphanedGroups(): void {
    $this->groupHelpers->method('listGroupMemberCounts')->willReturn([
      '10120' => 0,
      'sql-gone-user' => 2,
      '10119' => 0,
      'wisski-live-admin' => 0,
      'admin' => 0,
    ]);
    $this->groupHelpers->expects($this->once())->method('deleteGroup')->with('10120')->willReturn(TRUE);

    $this->assertSame(0, $this->command()->deleteOrphanedGroups(['apply' => TRUE]));
    $this->assertSame(['10120', 0, 'deleted'], $this->rows['10120']);
    $this->assertSame(['sql-gone-user', 2, 'kept (has members)'], $this->rows['sql-gone-user']);
  }

  /**
   * A failed delete is reported and the command exits with failure.
   */
  public function testApplyReportsFailedDelete(): void {
    $this->groupHelpers->method('listGroupMemberCounts')->willReturn(['10120' => 0, '10122' => 0]);
    $this->groupHelpers->method('deleteGroup')->willReturnMap([['10120', FALSE], ['10122', TRUE]]);

    $this->assertSame(1, $this->command()->deleteOrphanedGroups(['apply' => TRUE]));
    $this->assertSame('delete failed', $this->rows['10120'][2]);
    $this->assertSame('deleted', $this->rows['10122'][2]);
  }

  /**
   * When Nextcloud cannot list its groups, nothing is deleted.
   */
  public function testListFailureDeletesNothing(): void {
    $this->groupHelpers->method('listGroupMemberCounts')->willReturn(NULL);
    $this->groupHelpers->expects($this->never())->method('deleteGroup');

    $this->assertSame(1, $this->command()->deleteOrphanedGroups(['apply' => TRUE]));
    $this->assertSame(['error: Could not list the Nextcloud groups; nothing was deleted.'], $this->logged);
  }

  /**
   * Builds the command with doubles and a non-interactive console.
   */
  private function command(): SodaScsNextcloudGroupCommands {
    $projectQuery = $this->query([119 => '119']);
    $projectStorage = $this->createMock(EntityStorageInterface::class);
    $projectStorage->method('getQuery')->willReturn($projectQuery);

    $component = $this->createMock(SodaScsComponentInterface::class);
    $machineName = $this->createMock(FieldItemListInterface::class);
    $machineName->method('__isset')->with('value')->willReturn(TRUE);
    $machineName->method('__get')->with('value')->willReturn('wisski-live');
    $component->method('get')->with('machineName')->willReturn($machineName);
    $componentStorage = $this->createMock(EntityStorageInterface::class);
    $componentStorage->method('getQuery')->willReturn($this->query([7 => '7']));
    $componentStorage->method('loadMultiple')->with([7 => '7'])->willReturn([7 => $component]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['soda_scs_project', $projectStorage],
      ['soda_scs_component', $componentStorage],
    ]);
    $command = new SodaScsNextcloudGroupCommands($entityTypeManager, $this->groupHelpers);

    $collector = new class ($this->logged) extends AbstractLogger {

      /**
       * Collects the entries as "level: message".
       *
       * @param list<string> $entries
       *   The list the entries go to.
       */
      public function __construct(private array &$entries) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->entries[] = $level . ': ' . $message;
      }

    };
    $logger = new DrushLoggerManager();
    $logger->add('test', $collector);
    $command->setLogger($logger);
    $style = $this->createMock(DrushStyle::class);
    $style->method('table')->willReturnCallback(function (array $headers, array $rows): void {
      foreach ($rows as $row) {
        $this->rows[$row[0]] = $row;
      }
    });
    $command->restoreState(new ArrayInput([]), new BufferedOutput(), $style);
    return $command;
  }

  /**
   * An entity query double that returns the given ids.
   *
   * @param array<int, string> $ids
   *   The ids the query returns.
   */
  private function query(array $ids): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn($ids);
    return $query;
  }

}
