<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectDbAccessHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\Service\NextcloudMountManager;
use Drupal\soda_scs_manager\Service\SodaScsProjectOwnershipTransfer;
use Drupal\soda_scs_manager\Service\SodaScsWisskiDatabaseCredentials;
use Drupal\soda_scs_manager\Service\SodaScsWisskiTriplestoreTokenSync;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Handing a project with its applications to another account.
 *
 * Issue 3640913. Every service call (MariaDB, OpenGDB, WebProtégé, Nextcloud,
 * Portainer, the WissKI container) is a double; each test changes one answer
 * of an otherwise successful transfer of a project with a WissKI, a database
 * and a triplestore. Applications that a member added keep their owner.
 */
#[CoversMethod(SodaScsProjectOwnershipTransfer::class, 'transfer')]
#[CoversMethod(SodaScsProjectOwnershipTransfer::class, 'grantServiceAccess')]
#[CoversMethod(SodaScsProjectOwnershipTransfer::class, 'rebindWisski')]
#[CoversMethod(SodaScsProjectOwnershipTransfer::class, 'saveOwnership')]
#[CoversMethod(SodaScsProjectOwnershipTransfer::class, 'ownedApplications')]
#[CoversMethod(SodaScsProjectOwnershipTransfer::class, 'transferLocked')]
#[CoversMethod(SodaScsProjectOwnershipTransfer::class, 'remapServiceKeys')]
#[CoversMethod(SodaScsProjectOwnershipTransfer::class, 'transferSnapshots')]
#[CoversMethod(SodaScsProjectOwnershipTransfer::class, 'rebindAndSave')]
#[Group('soda_scs_manager')]
class SodaScsProjectOwnershipTransferTest extends UnitTestCase {

  private const PROJECT_ID = 5;

  private const PREVIOUS_OWNER_ID = 2;

  private const NEW_OWNER_ID = 9;

  /**
   * Steps in the order the service took them.
   *
   * @var list<string>
   */
  private array $steps = [];

  /**
   * Arguments of the calls that tests assert on, by step name.
   *
   * @var array<string, list<array<int, mixed>>>
   */
  private array $arguments = [];

  /**
   * Whether the project lock is acquired; a test sets this before the transfer.
   */
  private bool $lockAcquired = TRUE;

  /**
   * Whether the new owner is linked to a Keycloak account.
   */
  private bool $newOwnerHasKeycloakAccount = TRUE;

  /**
   * Whether the MariaDB user of the new owner can be granted access.
   */
  private bool $databaseAccessGranted = TRUE;

  /**
   * Whether creating the triplestore account of the new owner fails.
   */
  private bool $triplestoreAccountFails = FALSE;

  /**
   * Whether the WebProtégé grant for the new owner succeeds.
   */
  private bool $webprotegeGrantSucceeds = TRUE;

  /**
   * Whether the new owner joins the Nextcloud group of the project.
   */
  private bool $nextcloudGroupJoined = TRUE;

  /**
   * Whether the deployment of a WissKI can be captured.
   */
  private bool $deploymentCaptured = TRUE;

  /**
   * Whether the project has a second WissKI.
   */
  private bool $hasSecondWisski = FALSE;

  /**
   * Whether the project has a WissKI.
   */
  private bool $hasWisski = TRUE;

  /**
   * Whether the rebind of the second WissKI succeeds.
   */
  private bool $secondWisskiSucceeds = FALSE;

  /**
   * Database password of the new owner.
   */
  private string $newDatabasePassword = 'new-db-secret';

  /**
   * Triplestore token of the new owner.
   */
  private string $newToken = 'new-token';

  /**
   * Whether the database settings of a WissKI are replaced.
   */
  private bool $settingsReplaced = TRUE;

  /**
   * Whether the Team Folder of the project is ready.
   */
  private bool $teamFolderReady = TRUE;

  /**
   * Whether the redeploy of a WissKI succeeds.
   */
  private bool $redeployed = TRUE;

  /**
   * Whether the triplestore token is written to the WissKI.
   */
  private bool $tokenWritten = TRUE;

  /**
   * Whether saving the project fails.
   */
  private bool $projectSaveFails = FALSE;

  /**
   * Whether the Keycloak groups of the project are synced.
   */
  private bool $keycloakGroupsSynced = TRUE;

  /**
   * Nextcloud mount source that the captured WissKI deployment has.
   */
  private string $mountSourceOfWisski = '/mnt/alice/Team';

  /**
   * Names of WissKI update locks that another job holds.
   *
   * @var string[]
   */
  private array $busyUpdateLocks = [];

  /**
   * Whether the redeploy of a WissKI throws.
   */
  private bool $redeployThrows = FALSE;

  /**
   * Whether looking up the Team Folder mount throws.
   */
  private bool $mountThrows = FALSE;

  /**
   * Whether capturing the second WissKI throws.
   */
  private bool $secondCaptureThrows = FALSE;

  /**
   * Whether the new owner has a WissKI service key.
   */
  private bool $newOwnerHasWisskiKey = TRUE;

  /**
   * WissKI password of the new owner.
   */
  private string $newWisskiPassword = 'wisski-new-secret';

  /**
   * Owner ids of the other applications of the project, by component id.
   *
   * @var array<int, int>
   */
  private array $extraComponents = [];

  /**
   * Ids of the snapshots the previous owner has of the applications.
   *
   * @var int[]
   */
  private array $snapshotIds = [];

  /**
   * Service key references per component id.
   *
   * @var array<int, \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface[]>
   */
  private array $serviceKeys = [];

  /**
   * The project double.
   */
  private SodaScsProjectInterface&MockObject $project;

  /**
   * The previous owner double.
   */
  private UserInterface&MockObject $previousOwner;

  /**
   * The new owner double.
   */
  private UserInterface&MockObject $newOwner;

  /**
   * The transaction manager double.
   */
  private TransactionManagerInterface&MockObject $transactionManager;

  /**
   * The database access helpers, run on doubles of the MariaDB calls.
   */
  private SodaScsProjectDbAccessHelpers $dbAccessHelpers;

  /**
   * The SQL service actions double.
   */
  private SodaScsServiceActionsInterface&MockObject $sqlActions;

  /**
   * The triplestore helpers double.
   */
  private SodaScsTriplestoreHelpers&MockObject $triplestoreHelpers;

  /**
   * The OpenGDB helpers double.
   */
  private SodaScsOpenGdbHelpers&MockObject $openGdbHelpers;

  /**
   * The WebProtégé helpers double.
   */
  private SodaScsWebprotegeHelpers&MockObject $webprotegeHelpers;

  /**
   * The Nextcloud helpers double.
   */
  private SodaScsNextcloudHelpers&MockObject $nextcloudHelpers;

  /**
   * The Nextcloud mount manager double.
   */
  private NextcloudMountManager&MockObject $mountManager;

  /**
   * The WissKI component actions double.
   */
  private SodaScsWisskiComponentActions&MockObject $wisskiActions;

  /**
   * The WissKI database credentials double.
   */
  private SodaScsWisskiDatabaseCredentials&MockObject $databaseCredentials;

  /**
   * The WissKI triplestore token sync double.
   */
  private SodaScsWisskiTriplestoreTokenSync&MockObject $tokenSync;

  /**
   * The service key actions double.
   */
  private SodaScsServiceKeyActionsInterface&MockObject $serviceKeyActions;

  /**
   * The project helpers double.
   */
  private SodaScsProjectHelpers&MockObject $projectHelpers;

  /**
   * The lock backend double for the project lock.
   */
  private LockBackendInterface&MockObject $lock;

  /**
   * The lock backend double for the WissKI update locks.
   */
  private LockBackendInterface&MockObject $updateLock;

  /**
   * The logger channel double.
   */
  private LoggerChannelInterface&MockObject $logger;

  /**
   * The component helpers double.
   */
  private SodaScsComponentHelpers&MockObject $componentHelpers;

  /**
   * Components of the project.
   *
   * @var array<int, \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface>
   */
  private array $components = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->steps = [];
    $this->arguments = [];
    $this->extraComponents = [];
    $this->snapshotIds = [];
    $this->serviceKeys = [];

    // Failure results log through \Drupal::logger().
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);

    $this->previousOwner = $this->user(self::PREVIOUS_OWNER_ID, 'alice');
    $this->newOwner = $this->user(self::NEW_OWNER_ID, 'scs_demo');
    $this->transactionManager = $this->createMock(TransactionManagerInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->lock->method('acquire')->willReturnCallback(function (string $name): bool {
      $this->record('lock', $name);
      return $this->lockAcquired;
    });
    $this->lock->method('release')->willReturnCallback(function (string $name): void {
      $this->record('unlock', $name);
    });
    // The WissKI update locks use the volatile lock service, the project lock
    // the persistent one.
    $this->updateLock = $this->createMock(LockBackendInterface::class);
    $this->updateLock->method('acquire')->willReturnCallback(function (string $name): bool {
      $this->record('update-lock', $name);
      return !in_array($name, $this->busyUpdateLocks, TRUE);
    });
    $this->updateLock->method('release')->willReturnCallback(function (string $name): void {
      $this->record('update-unlock', $name);
    });
    $this->buildProject();
    $this->buildServiceDoubles();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * The new owner gets access first, then the WissKI is rebound, then owners.
   */
  public function testTransferGrantsAccessThenRebindsWisskiThenChangesOwners(): void {
    $result = $this->transfer();

    $this->assertTrue($result->success, (string) $result->error);
    $this->assertSame([
      'lock',
      'grant-database',
      'grant-triplestore-account',
      'grant-triplestore-repositories',
      'grant-webprotege',
      'grant-nextcloud-group',
      'update-lock',
      'capture',
      'settings',
      'redeploy',
      'token',
      'owner-component-12',
      'owner-component-13',
      'owner-component-14',
      'owner-stack-30',
      'owner-project',
      'update-unlock',
      'sync-keycloak',
      'unlock',
    ], $this->steps);
  }

  /**
   * The grants name the new owner and the repositories of the project.
   */
  public function testTransferGrantsTheNewOwnerAccessToTheProjectServices(): void {
    $this->transfer();

    $this->assertSame([['scs_demo']], $this->arguments['grant-database']);
    $this->assertSame([['scs_demo', 'sql-demo', ['ALL PRIVILEGES']]], $this->arguments['database-rights']);
    $this->assertSame([[$this->newOwner]], $this->arguments['grant-triplestore-account']);
    $this->assertSame([['scs_demo', ['ts-demo']]], $this->arguments['grant-triplestore-repositories']);
    $this->assertSame([[$this->project, $this->newOwner]], $this->arguments['grant-webprotege']);
    $this->assertSame([['group-1', $this->newOwner]], $this->arguments['grant-nextcloud-group']);
  }

  /**
   * Database account, triplestore account and mount of the WissKI are replaced.
   */
  public function testTransferPointsTheWisskiAtTheAccountsOfTheNewOwner(): void {
    $this->transfer();

    $this->assertSame([[$this->components[12], 'old-db-secret', 'alice', 'new-db-secret', 'scs_demo']], $this->arguments['settings']);
    $this->assertSame([
      [
        $this->components[12],
        [
          'DB_USER' => 'scs_demo',
          'DB_PASSWORD' => 'new-db-secret',
          'TS_USERNAME' => 'scs_demo',
          'TS_PASSWORD' => 'ts-password',
          'TS_TOKEN' => 'new-token',
          'NEXTCLOUD_USER_MOUNT_SOURCE' => '/mnt/scs_demo/Team',
        ],
      ],
    ], $this->arguments['redeploy']);
    $this->assertSame([[$this->components[12], $this->components[14], 'new-token']], $this->arguments['token']);
  }

  /**
   * Applications and project change owner; the previous owner stays admin.
   */
  public function testTransferChangesOwnersAndKeepsPreviousOwnerAsAdminMember(): void {
    $result = $this->transfer();

    $this->assertTrue($result->success);
    foreach (['component-12', 'component-13', 'component-14', 'stack-30', 'project'] as $name) {
      $this->assertSame([[self::NEW_OWNER_ID]], $this->arguments['owner-' . $name], $name);
    }
    // The new owner leaves the member list, the previous owner joins it.
    $this->assertSame([[3, self::PREVIOUS_OWNER_ID]], $this->arguments['members']);
    $this->assertSame([
      [
        '12' => ['3' => 'user', (string) self::PREVIOUS_OWNER_ID => 'admin'],
      ],
    ], $this->arguments['roles']);
  }

  /**
   * Without the keep option the previous owner leaves the project.
   */
  public function testTransferCanDropThePreviousOwner(): void {
    $result = $this->transfer(FALSE);

    $this->assertTrue($result->success);
    $this->assertSame([[3]], $this->arguments['members']);
    $this->assertSame([['12' => ['3' => 'user']]], $this->arguments['roles']);
  }

  /**
   * The result names the new owner; the transaction is not rolled back.
   */
  public function testTransferSucceedsWithoutWarnings(): void {
    $this->transactionManager->expects($this->never())->method('rollback');

    $result = $this->transfer();

    $this->assertSame([], $result->data['warnings']);
    $this->assertStringContainsString('scs_demo', $result->message);
  }

  /**
   * A failing Keycloak group sync is a warning, not a failure.
   */
  public function testTransferReportsKeycloakGroupSyncAsWarning(): void {
    $this->keycloakGroupsSynced = FALSE;

    $result = $this->transfer();

    $this->assertTrue($result->success);
    $this->assertCount(1, $result->data['warnings']);
    $this->assertSame([[self::NEW_OWNER_ID]], $this->arguments['owner-project']);
  }

  /**
   * A new owner that already owns the project changes nothing.
   */
  public function testTransferToTheCurrentOwnerDoesNothing(): void {
    $result = $this->service()->transfer($this->project, $this->previousOwner);

    $this->assertTrue($result->success);
    $this->assertSame([], $this->steps);
  }

  /**
   * A project without an owner is refused before any lock or call.
   */
  public function testProjectWithoutOwnerIsRefused(): void {
    $this->project = $this->createMock(SodaScsProjectInterface::class);
    $this->project->method('getOwner')->willReturn(NULL);

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame([], $this->steps);
  }

  /**
   * A new owner without Keycloak account is refused before the lock.
   */
  public function testNewOwnerWithoutKeycloakAccountIsRefused(): void {
    $this->newOwnerHasKeycloakAccount = FALSE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertStringContainsString('Keycloak', $result->message);
    $this->assertSame([], $this->steps);
  }

  /**
   * A project locked by another job stays untouched and its lock is kept.
   */
  public function testLockedProjectIsNotTouched(): void {
    $this->lockAcquired = FALSE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame(['lock'], $this->steps);
    $this->assertSame([[SodaScsDemoManager::PROJECT_LOCK_PREFIX . self::PROJECT_ID]], $this->arguments['lock']);
  }

  /**
   * The lock is released after a failure too.
   */
  public function testLockIsReleasedAfterFailure(): void {
    $this->databaseAccessGranted = FALSE;

    $this->transfer();

    $this->assertSame('unlock', end($this->steps));
  }

  /**
   * An application that also belongs to another project stops the transfer.
   */
  public function testApplicationSharedWithAnotherProjectStopsTheTransfer(): void {
    $this->components[13] = $this->component(
      13,
      'soda_scs_sql_component',
      self::PREVIOUS_OWNER_ID,
      [self::PROJECT_ID, 6],
    );

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertStringContainsString('also belongs to another project', $result->message);
    $this->assertSame(['lock', 'unlock'], $this->steps);
  }

  /**
   * An application a member added keeps its owner and its keys.
   */
  public function testApplicationOfMemberKeepsItsOwner(): void {
    $this->extraComponents[15] = 3;
    $result = $this->transfer();

    $this->assertTrue($result->success);
    $this->assertArrayNotHasKey('owner-component-15', $this->arguments);
    $this->assertSame([[self::NEW_OWNER_ID]], $this->arguments['owner-component-12']);
  }

  /**
   * A failing database grant stops before anything is changed.
   */
  public function testDatabaseGrantFailureChangesNothing(): void {
    $this->databaseAccessGranted = FALSE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame(['lock', 'grant-database', 'unlock'], $this->steps);
  }

  /**
   * A failing triplestore account stops before the WissKI is touched.
   */
  public function testTriplestoreAccountFailureChangesNothing(): void {
    $this->triplestoreAccountFails = TRUE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame(['lock', 'grant-database', 'grant-triplestore-account', 'unlock'], $this->steps);
  }

  /**
   * A refused WebProtégé right stops before the WissKI is touched.
   */
  public function testWebprotegeGrantFailureChangesNothing(): void {
    $this->webprotegeGrantSucceeds = FALSE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertNotContains('capture', $this->steps);
    $this->assertNotContains('owner-project', $this->steps);
  }

  /**
   * A new owner without Drive login yet does not stop the transfer.
   */
  public function testMissingNextcloudGroupMembershipIsOnlyLogged(): void {
    $this->nextcloudGroupJoined = FALSE;

    $result = $this->transfer();

    $this->assertTrue($result->success);
  }

  /**
   * A deployment that cannot be read stops before settings.php is changed.
   */
  public function testUnreadableDeploymentChangesNothing(): void {
    $this->deploymentCaptured = FALSE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertNotContains('settings', $this->steps);
    $this->assertNotContains('owner-project', $this->steps);
  }

  /**
   * A new owner without database password is refused before settings.php.
   */
  public function testNewOwnerWithoutDatabasePasswordChangesNothing(): void {
    $this->newDatabasePassword = '';

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertNotContains('settings', $this->steps);
    $this->assertNotContains('owner-project', $this->steps);
  }

  /**
   * A refused settings.php change leaves the WissKI as it is.
   */
  public function testRefusedSettingsChangeStopsBeforeRedeploy(): void {
    $this->settingsReplaced = FALSE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame(1, $this->countSteps('settings'));
    $this->assertNotContains('redeploy', $this->steps);
    $this->assertNotContains('owner-project', $this->steps);
  }

  /**
   * A new owner without triplestore token undoes the settings.php change.
   */
  public function testMissingTripleStoreTokenUndoesSettings(): void {
    $this->newToken = '';

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame(['alice', 'scs_demo'], array_map(static fn (array $call): string => $call[2], $this->arguments['settings']));
    $this->assertNotContains('redeploy', $this->steps);
    $this->assertNotContains('owner-project', $this->steps);
  }

  /**
   * A Team Folder that is not ready under the new mount undoes the settings.
   */
  public function testTeamFolderNotReadyUndoesSettings(): void {
    $this->teamFolderReady = FALSE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertCount(2, $this->arguments['settings']);
    $this->assertNotContains('redeploy', $this->steps);
    $this->assertNotContains('owner-project', $this->steps);
  }

  /**
   * A mount source of someone else is left alone.
   */
  public function testForeignMountSourceIsNotReplaced(): void {
    $this->mountSourceOfWisski = '/mnt/somebody-else/Team';

    $this->transfer();

    $this->assertArrayNotHasKey('NEXTCLOUD_USER_MOUNT_SOURCE', $this->arguments['redeploy'][0][1]);
  }

  /**
   * A failed redeploy restores the deployment and the settings.php values.
   */
  public function testFailedRedeployRestoresDeploymentAndSettings(): void {
    $this->redeployed = FALSE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $after = array_slice($this->steps, array_search('redeploy', $this->steps, TRUE) + 1);
    $this->assertSame(['restore', 'settings', 'update-unlock', 'unlock'], $after);
    $this->assertSame(
      [['alice', 'scs_demo'], ['scs_demo', 'alice']],
      array_map(static fn (array $call): array => [$call[2], $call[4]], $this->arguments['settings']),
    );
    $this->assertNotContains('owner-project', $this->steps);
    $this->assertNotContains('token', $this->steps);
  }

  /**
   * A failed token write restores the token, the deployment and the settings.
   */
  public function testFailedTokenWriteRestoresEverything(): void {
    $this->tokenWritten = FALSE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $after = array_slice($this->steps, array_search('token', $this->steps, TRUE) + 1);
    $this->assertSame(['token', 'restore', 'settings', 'update-unlock', 'unlock'], $after);
    $this->assertSame('old-token', $this->arguments['token'][1][2]);
    $this->assertNotContains('owner-project', $this->steps);
  }

  /**
   * A second WissKI that fails undoes the first one as well.
   */
  public function testFailureOfSecondWisskiUndoesTheFirst(): void {
    $this->hasSecondWisski = TRUE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $after = array_slice($this->steps, array_search('token', $this->steps, TRUE));
    $this->assertSame(['token', 'capture', 'token', 'restore', 'settings', 'update-unlock', 'update-unlock', 'unlock'], $after);
    $this->assertNotContains('owner-project', $this->steps);
  }

  /**
   * A failing save undoes the WissKI changes and rolls the transaction back.
   */
  public function testFailedSaveRollsBackTransactionAndWisski(): void {
    $this->projectSaveFails = TRUE;
    $this->transactionManager->expects($this->once())->method('rollback');

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertStringContainsString('Could not save the new owner', $result->message);
    $after = array_slice($this->steps, array_search('owner-project', $this->steps, TRUE) + 1);
    $this->assertSame(['token', 'restore', 'settings', 'update-unlock', 'unlock'], $after);
    $this->assertNotContains('sync-keycloak', $this->steps);
  }

  /**
   * Keys of the previous owner are replaced by the keys of the new owner.
   */
  public function testServiceKeysOfThePreviousOwnerMoveToTheNewOwner(): void {
    $previousKey = $this->key(self::PREVIOUS_OWNER_ID, 'soda_scs_sql_component', 'password', 'old-db-secret');
    $foreignKey = $this->key(3, 'soda_scs_sql_component', 'password', 'member-secret');
    $this->serviceKeys[13] = [$previousKey, $foreignKey];

    $result = $this->transfer();

    $this->assertTrue($result->success);
    $this->assertSame([[$this->newKeyOf('soda_scs_sql_component', 'password'), $foreignKey]], $this->arguments['component-13-keys']);
  }

  /**
   * The snapshots of the applications go to the new owner too.
   */
  public function testSnapshotsOfTheApplicationsGoToTheNewOwner(): void {
    $this->snapshotIds = [70, 71];

    $this->transfer();

    $this->assertSame([[self::NEW_OWNER_ID], [self::NEW_OWNER_ID]], $this->arguments['owner-snapshot']);
  }

  /**
   * A WissKI that is being updated stops the transfer before any change.
   */
  public function testBusyWisskiUpdateLockStopsTheTransferBeforeAnyChange(): void {
    $this->busyUpdateLocks = ['soda_scs_manager.wisski_update.12'];

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame('WissKI 12 is being updated; try again when the update is done.', $result->message);
    foreach (['capture', 'settings', 'redeploy', 'token', 'owner-project', 'sync-keycloak', 'update-unlock'] as $step) {
      $this->assertNotContains($step, $this->steps, $step);
    }
    $this->assertSame('unlock', end($this->steps));
  }

  /**
   * The update locks taken before the busy one are released again.
   */
  public function testBusyWisskiUpdateLockOfSecondWisskiReleasesTheFirst(): void {
    $this->hasSecondWisski = TRUE;
    $this->busyUpdateLocks = ['soda_scs_manager.wisski_update.16'];

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame('WissKI 16 is being updated; try again when the update is done.', $result->message);
    $this->assertSame([['soda_scs_manager.wisski_update.12']], $this->arguments['update-unlock']);
    $this->assertNotContains('capture', $this->steps);
    $this->assertNotContains('owner-project', $this->steps);
  }

  /**
   * Every changed WissKI is locked, and the locks are released after success.
   */
  public function testWisskiUpdateLocksAreTakenBeforeAndReleasedAfterTheRebind(): void {
    $this->hasSecondWisski = TRUE;
    $this->secondWisskiSucceeds = TRUE;

    $result = $this->transfer();

    $this->assertTrue($result->success, (string) $result->error);
    $names = [['soda_scs_manager.wisski_update.12'], ['soda_scs_manager.wisski_update.16']];
    $this->assertSame($names, $this->arguments['update-lock']);
    $this->assertSame($names, $this->arguments['update-unlock']);
    $lastLock = max(array_keys($this->steps, 'update-lock', TRUE));
    $this->assertLessThan(array_search('capture', $this->steps, TRUE), $lastLock);
    $this->assertSame('sync-keycloak', $this->steps[array_search('update-unlock', $this->steps, TRUE) + 2]);
  }

  /**
   * The update locks are released after a failed rebind too.
   */
  public function testWisskiUpdateLocksAreReleasedAfterFailure(): void {
    $this->redeployed = FALSE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame([['soda_scs_manager.wisski_update.12']], $this->arguments['update-lock']);
    $this->assertSame([['soda_scs_manager.wisski_update.12']], $this->arguments['update-unlock']);
  }

  /**
   * Without WissKI no update lock is taken.
   */
  public function testNoUpdateLockWithoutWisski(): void {
    $this->hasWisski = FALSE;

    $result = $this->transfer();

    $this->assertTrue($result->success, (string) $result->error);
    $this->assertArrayNotHasKey('update-lock', $this->arguments);
    $this->assertArrayNotHasKey('capture', $this->arguments);
  }

  /**
   * An exception from the redeploy restores the deployment and the settings.
   */
  public function testExceptionDuringRedeployRestoresDeploymentAndSettings(): void {
    $this->redeployThrows = TRUE;
    $this->logger->expects($this->once())->method('error')->with(
      'Rebinding WissKI @wisski to user @user failed: @message',
      ['@message' => 'portainer timeout', '@user' => self::NEW_OWNER_ID, '@wisski' => 12],
    );

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame('Could not point WissKI 12 at the accounts of the new owner.', $result->message);
    $this->assertSame(
      [['alice', 'scs_demo'], ['scs_demo', 'alice']],
      array_map(static fn (array $call): array => [$call[2], $call[4]], $this->arguments['settings']),
    );
    $this->assertNotContains('token', $this->steps);
    $this->assertNotContains('owner-project', $this->steps);
    // Portainer may have taken the new environment before the exception.
    $this->assertSame(['restore', 'settings', 'update-unlock', 'unlock'], array_slice($this->steps, -4));
  }

  /**
   * An exception from the mount manager undoes the settings.
   */
  public function testExceptionFromTheMountManagerUndoesSettingsBeforeRedeploy(): void {
    $this->mountThrows = TRUE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame('Could not point WissKI 12 at the accounts of the new owner.', $result->message);
    $this->assertCount(2, $this->arguments['settings']);
    $this->assertNotContains('redeploy', $this->steps);
    $this->assertNotContains('owner-project', $this->steps);
  }

  /**
   * An exception while rebinding a second WissKI undoes the first one.
   */
  public function testExceptionWhileRebindingSecondWisskiUndoesTheFirst(): void {
    $this->hasSecondWisski = TRUE;
    $this->secondCaptureThrows = TRUE;

    $result = $this->transfer();

    $this->assertFalse($result->success);
    $this->assertSame('Could not point WissKI 16 at the accounts of the new owner.', $result->message);
    $after = array_slice($this->steps, array_search('token', $this->steps, TRUE));
    $this->assertSame(['token', 'capture', 'token', 'restore', 'settings', 'update-unlock', 'update-unlock', 'unlock'], $after);
    $this->assertNotContains('owner-project', $this->steps);
  }

  /**
   * A new owner with a different WissKI password produces a warning.
   */
  public function testDifferentWisskiPasswordOfTheNewOwnerGivesWarning(): void {
    $this->serviceKeys[12] = [$this->key(self::PREVIOUS_OWNER_ID, 'soda_scs_wisski_component', 'password', 'admin-old-secret')];

    $result = $this->transfer();

    $this->assertTrue($result->success);
    $this->assertSame(
      ['WissKI 12 keeps its admin password, which differs from the stored WissKI password of the new owner.'],
      $result->data['warnings'],
    );
    $this->assertNotContains('create-key', $this->steps);
    $this->assertSame([[$this->newKeyOf('soda_scs_wisski_component', 'password')]], $this->arguments['component-12-keys']);
  }

  /**
   * An equal WissKI password of the new owner is no warning.
   */
  public function testEqualWisskiPasswordOfTheNewOwnerIsNoWarning(): void {
    $this->serviceKeys[12] = [$this->key(self::PREVIOUS_OWNER_ID, 'soda_scs_wisski_component', 'password', $this->newWisskiPassword)];

    $result = $this->transfer();

    $this->assertTrue($result->success);
    $this->assertSame([], $result->data['warnings']);
  }

  /**
   * A copied WissKI key matches by construction and is no warning.
   */
  public function testCopiedWisskiKeyIsNoWarning(): void {
    $this->newOwnerHasWisskiKey = FALSE;
    $this->serviceKeys[12] = [$this->key(self::PREVIOUS_OWNER_ID, 'soda_scs_wisski_component', 'password', 'admin-old-secret')];

    $result = $this->transfer();

    $this->assertTrue($result->success);
    $this->assertSame([], $result->data['warnings']);
    $this->assertSame([['soda_scs_wisski_component', 'password', self::NEW_OWNER_ID]], $this->arguments['create-key']);
  }

  /**
   * Differing passwords of other bundles are not reported.
   */
  public function testDifferentPasswordOfAnotherBundleIsNoWarning(): void {
    $this->serviceKeys[13] = [$this->key(self::PREVIOUS_OWNER_ID, 'soda_scs_sql_component', 'password', 'old-db-secret')];

    $result = $this->transfer();

    $this->assertTrue($result->success);
    $this->assertSame([], $result->data['warnings']);
  }

  /**
   * The warning stays next to the Keycloak sync warning.
   */
  public function testKeyWarningsAreKeptWhenTheKeycloakSyncFails(): void {
    $this->keycloakGroupsSynced = FALSE;
    $this->serviceKeys[12] = [$this->key(self::PREVIOUS_OWNER_ID, 'soda_scs_wisski_component', 'password', 'admin-old-secret')];

    $result = $this->transfer();

    $this->assertTrue($result->success);
    $this->assertCount(2, $result->data['warnings']);
    $this->assertStringContainsString('WissKI 12 keeps its admin password', $result->data['warnings'][0]);
  }

  /**
   * Runs the transfer with the current answers.
   */
  private function transfer(bool $keepPreviousOwner = TRUE): SodaScsResult {
    return $this->service()->transfer($this->project, $this->newOwner, $keepPreviousOwner);
  }

  /**
   * Counts how often a step was taken.
   */
  private function countSteps(string $name): int {
    return count(array_filter($this->steps, static fn (string $step): bool => $step === $name));
  }

  /**
   * Records a step and the arguments of the call.
   */
  private function record(string $step, mixed ...$arguments): void {
    $this->steps[] = $step;
    $this->arguments[$step][] = $arguments;
  }

  /**
   * Builds the service under test from the doubles.
   */
  private function service(): SodaScsProjectOwnershipTransfer {
    $this->buildProject();
    $storages = [
      'soda_scs_component' => $this->componentStorage(),
      'soda_scs_stack' => $this->stackStorage(),
      'soda_scs_snapshot' => $this->snapshotStorage(),
    ];
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(static fn (string $type): EntityStorageInterface => $storages[$type]);
    $database = $this->createMock(Connection::class);
    $connection = $this->createMock(Connection::class);
    $connection->method('transactionManager')->willReturn($this->transactionManager);
    $database->method('startTransaction')->willReturn(new Transaction($connection, 'drupal_transaction', 'transaction-1'));
    $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);
    $bundleInfo->method('getBundleInfo')->willReturn([]);

    return new SodaScsProjectOwnershipTransfer(
      $this->componentHelpers,
      $database,
      $this->dbAccessHelpers,
      $bundleInfo,
      $entityTypeManager,
      $this->updateLock,
      $this->logger,
      $this->nextcloudHelpers,
      $this->mountManager,
      $this->openGdbHelpers,
      $this->lock,
      $this->projectHelpers,
      $this->serviceKeyActions,
      $this->tokenSync,
      $this->triplestoreHelpers,
      $this->webprotegeHelpers,
      $this->wisskiActions,
      $this->databaseCredentials,
    );
  }

  /**
   * Builds the project and its components from the current settings.
   */
  private function buildProject(): void {
    $project = $this->project ?? NULL;
    if ($project !== NULL && $project->getOwner() === NULL) {
      // A test replaced the project with an ownerless one.
      return;
    }
    $this->components = [
      13 => $this->components[13] ?? $this->component(13, 'soda_scs_sql_component', self::PREVIOUS_OWNER_ID, [self::PROJECT_ID], 'sql-demo'),
      14 => $this->component(14, 'soda_scs_triplestore_component', self::PREVIOUS_OWNER_ID, [self::PROJECT_ID], 'ts-demo'),
    ];
    if ($this->hasWisski) {
      $this->components[12] = $this->component(12, 'soda_scs_wisski_component', self::PREVIOUS_OWNER_ID, [self::PROJECT_ID], 'wisski-demo');
      ksort($this->components);
    }
    if ($this->hasSecondWisski) {
      $this->components[16] = $this->component(16, 'soda_scs_wisski_component', self::PREVIOUS_OWNER_ID, [self::PROJECT_ID], 'wisski-second');
    }
    foreach ($this->extraComponents as $id => $ownerId) {
      $this->components[$id] = $this->component($id, 'soda_scs_sql_component', $ownerId, [self::PROJECT_ID], 'extra-' . $id);
    }

    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('id')->willReturn(self::PROJECT_ID);
    $project->method('label')->willReturn('Demo Projekt');
    $project->method('getOwner')->willReturn($this->previousOwner);
    $project->method('get')->willReturnCallback(fn (string $field): FieldItemList|EntityReferenceFieldItemListInterface => match ($field) {
      'webprotegeProjectId' => $this->field('wp-1'),
      'groupId' => $this->field('group-1'),
      'members' => $this->field(NULL, [['target_id' => 3], ['target_id' => self::NEW_OWNER_ID]]),
      'connectedComponents' => $this->references([$this->components[13]]),
      default => $this->field(NULL),
    });
    $project->method('set')->willReturnCallback(function (string $field, mixed $value) use ($project): SodaScsProjectInterface {
      if ($field === 'members') {
        $this->arguments['members'][] = $value;
      }
      return $project;
    });
    $project->method('setOwnerId')->willReturnCallback(function (int $ownerId) use ($project): SodaScsProjectInterface {
      $this->record('owner-project', $ownerId);
      return $project;
    });
    $project->method('save')->willReturnCallback(function (): int {
      if ($this->projectSaveFails) {
        throw new \RuntimeException('database is gone');
      }
      return 2;
    });
    $this->project = $project;
  }

  /**
   * The component storage, answering the lookup by project.
   */
  private function componentStorage(): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->with(['partOfProjects' => self::PROJECT_ID])->willReturn($this->components);
    return $storage;
  }

  /**
   * The stack storage with one stack of the previous owner.
   */
  private function stackStorage(): EntityStorageInterface {
    $stack = $this->createMock(SodaScsComponentInterface::class);
    $stack->method('id')->willReturn(30);
    $stack->method('getOwnerId')->willReturn(self::PREVIOUS_OWNER_ID);
    $stack->method('get')->willReturnCallback(fn (): FieldItemList => $this->field(NULL, [['target_id' => self::PROJECT_ID]]));
    $stack->method('setOwnerId')->willReturnCallback(function (int $ownerId) use ($stack): SodaScsComponentInterface {
      $this->record('owner-stack-30', $ownerId);
      return $stack;
    });
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([30 => $stack]);
    return $storage;
  }

  /**
   * The snapshot storage; the query answers with the configured ids.
   */
  private function snapshotStorage(): EntityStorageInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn($this->snapshotIds === [] ? [] : array_combine($this->snapshotIds, $this->snapshotIds));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturnCallback(function (array $ids): array {
      $snapshots = [];
      foreach ($ids as $id) {
        $snapshot = $this->createMock(SodaScsSnapshotInterface::class);
        $snapshot->method('setOwnerId')->willReturnCallback(function (int $ownerId) use ($snapshot): SodaScsSnapshotInterface {
          $this->record('owner-snapshot', $ownerId);
          return $snapshot;
        });
        $snapshots[$id] = $snapshot;
      }
      return $snapshots;
    });
    return $storage;
  }

  /**
   * Builds the doubles for the services the transfer calls.
   */
  private function buildServiceDoubles(): void {
    $this->projectHelpers = $this->createMock(SodaScsProjectHelpers::class);
    $this->projectHelpers->method('getUserSsoUuid')->willReturnCallback(fn (): ?string => $this->newOwnerHasKeycloakAccount ? 'keycloak-id' : NULL);
    $this->projectHelpers->method('getStoredComponentRoles')->willReturn([
      '12' => ['3' => 'user', (string) self::NEW_OWNER_ID => 'admin'],
    ]);
    $this->projectHelpers->method('getRoleAssignableComponents')->willReturnCallback(fn (): array => array_filter([$this->components[12] ?? NULL]));
    $this->projectHelpers->method('setStoredComponentRoles')->willReturnCallback(function (SodaScsProjectInterface $project, array $roles): void {
      $this->arguments['roles'][] = $roles;
    });
    $this->projectHelpers->method('syncKeycloakGroupMembers')->willReturnCallback(function (): SodaScsResult {
      $this->record('sync-keycloak');
      return $this->keycloakGroupsSynced
        ? SodaScsResult::success(data: [], message: 'synced')
        : SodaScsResult::failure(error: 'keycloak down', message: 'failed', log: FALSE);
    });

    $this->serviceKeyActions = $this->createMock(SodaScsServiceKeyActionsInterface::class);
    $this->serviceKeyActions->method('getServiceKey')->willReturnCallback(function (array $props): ?SodaScsServiceKeyInterface {
      if ((int) $props['userId'] !== self::NEW_OWNER_ID) {
        return NULL;
      }
      if ($props['bundle'] === 'soda_scs_wisski_component' && !$this->newOwnerHasWisskiKey) {
        return NULL;
      }
      $secret = match ($props['bundle'] . '/' . $props['type']) {
        'soda_scs_sql_component/password' => $this->newDatabasePassword,
        'soda_scs_triplestore_component/password' => 'ts-password',
        'soda_scs_triplestore_component/token' => $this->newToken,
        'soda_scs_wisski_component/password' => $this->newWisskiPassword,
        default => 'other-secret',
      };
      return $this->newKeys[$props['bundle'] . '/' . $props['type']] ??= $this->key(self::NEW_OWNER_ID, $props['bundle'], $props['type'], $secret);
    });

    $this->serviceKeyActions->method('createServiceKey')->willReturnCallback(function (array $props): SodaScsServiceKeyInterface {
      $this->record('create-key', $props['bundle'], $props['type'], $props['userId']);
      return $this->key((int) $props['userId'], $props['bundle'], $props['type'], (string) $props['password']);
    });

    // The helper is final: the real one runs on a double of the MariaDB calls.
    $this->sqlActions = $this->createMock(SodaScsServiceActionsInterface::class);
    $this->sqlActions->method('existServiceUser')->willReturnCallback(function (string $user): array {
      $this->record('grant-database', $user);
      return $this->databaseAccessGranted
        ? ['execStatus' => 0, 'result' => '1']
        : ['execStatus' => 1, 'output' => ['ERROR 2002']];
    });
    $this->sqlActions->method('renewUserPassword')->willReturn(['execStatus' => 0]);
    $this->sqlActions->method('grantServiceRights')->willReturnCallback(function (string $user, string $database, array $rights): array {
      $this->arguments['database-rights'][] = [$user, $database, $rights];
      return ['execStatus' => 0];
    });
    $sqlBundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);
    $sqlBundleInfo->method('getBundleInfo')->willReturn(['soda_scs_sql_component' => ['label' => 'SQL']]);
    $this->dbAccessHelpers = new SodaScsProjectDbAccessHelpers(
      $this->createMock(EntityTypeManagerInterface::class),
      $sqlBundleInfo,
      $this->projectHelpers,
      $this->sqlActions,
      $this->serviceKeyActions,
      $this->createMock(SodaScsKeycloakHelpers::class),
      $this->createMock(LoggerInterface::class),
    );

    $this->triplestoreHelpers = $this->createMock(SodaScsTriplestoreHelpers::class);
    $this->triplestoreHelpers->method('ensureUserAccount')->willReturnCallback(function (UserInterface $user): array {
      $this->record('grant-triplestore-account', $user);
      if ($this->triplestoreAccountFails) {
        throw new \RuntimeException('OpenGDB answered 500.');
      }
      return [];
    });
    $this->openGdbHelpers = $this->createMock(SodaScsOpenGdbHelpers::class);
    $this->openGdbHelpers->method('addUserRepositoryGrants')->willReturnCallback(function (string $username, array $repositories): void {
      $this->record('grant-triplestore-repositories', $username, $repositories);
    });

    $this->webprotegeHelpers = $this->createMock(SodaScsWebprotegeHelpers::class);
    $this->webprotegeHelpers->method('grantEdit')->willReturnCallback(function (SodaScsProjectInterface $project, UserInterface $user): SodaScsResult {
      $this->record('grant-webprotege', $project, $user);
      return $this->webprotegeGrantSucceeds
        ? SodaScsResult::success(data: [], message: 'granted')
        : SodaScsResult::failure(error: 'webprotege down', message: 'failed', log: FALSE);
    });

    $this->nextcloudHelpers = $this->createMock(SodaScsNextcloudHelpers::class);
    $this->nextcloudHelpers->method('addUserToGroup')->willReturnCallback(function (string $groupId, UserInterface $user): bool {
      $this->record('grant-nextcloud-group', $groupId, $user);
      return $this->nextcloudGroupJoined;
    });

    $this->mountManager = $this->createMock(NextcloudMountManager::class);
    $this->mountManager->method('userMountSource')->willReturnCallback(static fn (UserInterface $user): string => '/mnt/' . $user->getDisplayName());
    $this->mountManager->method('ensureProjectTeamFolderReady')->willReturnCallback(function (): bool {
      if ($this->mountThrows) {
        throw new \RuntimeException('mounter unreachable');
      }
      return $this->teamFolderReady;
    });
    $this->mountManager->method('projectTeamFolderMountSource')->willReturnCallback(static fn (UserInterface $user): string => '/mnt/' . $user->getDisplayName() . '/Team');

    $this->componentHelpers = $this->createMock(SodaScsComponentHelpers::class);
    $this->componentHelpers->method('resolveConnectedComponents')->willReturnCallback(fn (): array => [
      'sql' => $this->components[13],
      'triplestore' => $this->components[14],
    ]);

    $this->wisskiActions = $this->createMock(SodaScsWisskiComponentActions::class);
    $this->wisskiActions->method('captureDeployment')->willReturnCallback(function (): SodaScsResult {
      $this->record('capture');
      if ($this->secondCaptureThrows && $this->countSteps('capture') > 1) {
        throw new \RuntimeException('portainer timeout');
      }
      $environment = [
        ['name' => 'DB_USER', 'value' => 'alice'],
        ['name' => 'DB_PASSWORD', 'value' => 'old-db-secret'],
        ['name' => 'TS_TOKEN', 'value' => 'old-token'],
        ['name' => 'NEXTCLOUD_USER_MOUNT_SOURCE', 'value' => $this->mountSourceOfWisski],
      ];
      return $this->deploymentCaptured && ($this->countSteps('capture') < 2 || !$this->hasSecondWisski || $this->secondWisskiSucceeds)
        ? SodaScsResult::success(message: 'recorded', data: ['deployment' => ['env' => $environment]])
        : SodaScsResult::failure(error: 'portainer down', message: 'failed', log: FALSE);
    });
    $this->wisskiActions->method('redeployWithEnvironment')->willReturnCallback(function (SodaScsComponentInterface $wisski, array $updates): SodaScsResult {
      $this->record('redeploy', $wisski, $updates);
      if ($this->redeployThrows) {
        throw new \RuntimeException('portainer timeout');
      }
      return $this->redeployed
        ? SodaScsResult::success(data: [], message: 'redeployed')
        : SodaScsResult::failure(error: 'portainer down', message: 'failed', log: FALSE);
    });
    $this->wisskiActions->method('restoreDeployment')->willReturnCallback(function (): SodaScsResult {
      $this->record('restore');
      return SodaScsResult::success(data: [], message: 'restored');
    });

    $this->databaseCredentials = $this->createMock(SodaScsWisskiDatabaseCredentials::class);
    $this->databaseCredentials->method('replace')->willReturnCallback(function (SodaScsComponentInterface $wisski, string $fromPassword, string $fromUser, string $toPassword, string $toUser): SodaScsResult {
      $this->record('settings', $wisski, $fromPassword, $fromUser, $toPassword, $toUser);
      // The first call changes settings.php; a failure applies to it only.
      return $this->settingsReplaced || $this->countSteps('settings') > 1
        ? SodaScsResult::success(data: ['state' => 'updated'], message: 'ok')
        : SodaScsResult::failure(error: 'mismatch', message: 'failed', log: FALSE);
    });

    $this->tokenSync = $this->createMock(SodaScsWisskiTriplestoreTokenSync::class);
    $this->tokenSync->method('writeToken')->willReturnCallback(function (SodaScsComponentInterface $wisski, SodaScsComponentInterface $triplestore, string $token): SodaScsResult {
      $this->record('token', $wisski, $triplestore, $token);
      return $this->tokenWritten || $token === 'old-token'
        ? SodaScsResult::success(data: ['state' => 'updated'], message: 'ok')
        : SodaScsResult::failure(error: 'exec failed', message: 'failed', log: FALSE);
    });
  }

  /**
   * Service keys of the new owner, one per bundle and type.
   *
   * @var array<string, \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface>
   */
  private array $newKeys = [];

  /**
   * The key of the new owner for a bundle and type, as the lookup returns it.
   */
  private function newKeyOf(string $bundle, string $type): SodaScsServiceKeyInterface {
    return $this->newKeys[$bundle . '/' . $type];
  }

  /**
   * Builds a user double.
   */
  private function user(int $id, string $name): UserInterface&MockObject {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($id);
    $user->method('getDisplayName')->willReturn($name);
    return $user;
  }

  /**
   * Builds a field list double.
   *
   * @param mixed $value
   *   The value of the field.
   * @param array<int, mixed> $items
   *   The items getValue() returns.
   */
  private function field(mixed $value, array $items = []): FieldItemList {
    $field = $this->createMock(FieldItemList::class);
    $field->method('__isset')->willReturn(TRUE);
    $field->method('__get')->with('value')->willReturn($value);
    $field->method('getValue')->willReturn($items);
    return $field;
  }

  /**
   * Builds a reference field double.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   The referenced entities.
   */
  private function references(array $entities): EntityReferenceFieldItemListInterface {
    $references = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $references->method('referencedEntities')->willReturn($entities);
    return $references;
  }

  /**
   * Builds a service key double.
   */
  private function key(int $ownerId, string $bundle, string $type, string $secret): SodaScsServiceKeyInterface&MockObject {
    $key = $this->createMock(SodaScsServiceKeyInterface::class);
    $key->method('getOwnerId')->willReturn($ownerId);
    $key->method('get')->willReturnCallback(fn (string $field): FieldItemList => match ($field) {
      'scsComponentBundle' => $this->field($bundle),
      'type' => $this->field($type),
      'servicePassword' => $this->field($secret),
      default => $this->field(NULL, []),
    });
    return $key;
  }

  /**
   * Builds a component double.
   *
   * @param int $id
   *   The component id.
   * @param string $bundle
   *   The component bundle.
   * @param int $ownerId
   *   The id of the owner.
   * @param int[] $projectIds
   *   The projects the component belongs to.
   * @param string $machineName
   *   The machine name; empty for none.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The component double.
   */
  private function component(int $id, string $bundle, int $ownerId, array $projectIds, string $machineName = ''): SodaScsComponentInterface&MockObject {
    $component = $this->createMock(SodaScsComponentInterface::class);
    $component->method('id')->willReturn($id);
    $component->method('bundle')->willReturn($bundle);
    $component->method('getOwnerId')->willReturn($ownerId);
    $component->method('get')->willReturnCallback(function (string $field) use ($projectIds, $machineName, $id): FieldItemList|EntityReferenceFieldItemListInterface {
      if ($field === 'serviceKey') {
        $references = $this->createMock(EntityReferenceFieldItemListInterface::class);
        $references->method('referencedEntities')->willReturn($this->serviceKeys[$id] ?? []);
        return $references;
      }
      return match ($field) {
        'partOfProjects' => $this->field(NULL, array_map(static fn (int $projectId): array => ['target_id' => $projectId], $projectIds)),
        'machineName' => $this->field($machineName),
        default => $this->field(NULL),
      };
    });
    $component->method('set')->willReturnCallback(function (string $field, mixed $value) use ($component, $id): SodaScsComponentInterface {
      if ($field === 'serviceKey') {
        $this->arguments['component-' . $id . '-keys'][] = $value;
      }
      return $component;
    });
    $component->method('setOwnerId')->willReturnCallback(function (int $ownerId) use ($component, $id): SodaScsComponentInterface {
      $this->record('owner-component-' . $id, $ownerId);
      return $component;
    });
    return $component;
  }

}
