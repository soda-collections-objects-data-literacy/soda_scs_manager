<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\User;

use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\Batch\SodaScsUserOffboardingBatch;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectStatus;
use Drupal\soda_scs_manager\Form\SodaScsProfileDeleteForm;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\Service\NextcloudMountManager;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\soda_scs_manager\Service\SodaScsProjectApplicationRemover;
use Drupal\soda_scs_manager\Service\SodaScsUserOffboarding;
use Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceUserActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbServiceActions;
use Drupal\soda_scs_manager\ServiceActions\SodaScsSqlServiceActions;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\UserInterface;

/**
 * Account delete and the project locks around it.
 *
 * Also the application removal that runs before the account delete in batch
 * requests of its own (issue 3638781).
 *
 * The delete forms refuse while an owned project holds its project lock (a
 * demo job attempt or a project delete); the purge of the account takes that
 * lock around the delete of a demo project, and goes on without it when it is
 * busy. The batch takes the locks of all owned projects through the
 * persistent lock in its first request and holds them until deleteAccount()
 * has run _user_cancel(); a busy project makes deleteAccount() refuse. No
 * remote service is reached: every client the purge calls is a double.
 *
 * @group soda_scs_manager
 */
class SodaScsUserOffboardingDemoLockTest extends SodaScsKernelTestBase {

  /**
   * Names of the locks another process holds right now.
   *
   * @var list<string>
   */
  private array $busyLocks = [];

  /**
   * Names of the locks this test's code holds right now.
   *
   * @var list<string>
   */
  private array $heldLocks = [];

  /**
   * Every lock call in order, as "acquire name timeout" or "release name".
   *
   * @var list<string>
   */
  private array $lockCalls = [];

  /**
   * Names of the persistent locks this test's code holds right now.
   *
   * @var list<string>
   */
  private array $heldPersistentLocks = [];

  /**
   * Every persistent lock call in order, in the format of $lockCalls.
   *
   * @var list<string>
   */
  private array $persistentLockCalls = [];

  /**
   * Messages of the warnings the service logged.
   *
   * @var list<string>
   */
  private array $warnings = [];

  /**
   * Whether the project group delete ran while the project lock was held.
   *
   * Keyed by project id.
   *
   * @var array<int, bool>
   */
  private array $lockHeldDuringDelete = [];

  /**
   * Runs when the purge acquires a lock, as another process would meanwhile.
   */
  private ?\Closure $onAcquire = NULL;

  /**
   * The WebProtégé project ids the purge asked to trash.
   *
   * @var list<string|null>
   */
  private array $trashedWebprotegeIds = [];

  /**
   * The account that gets deleted; not uid 1, which the purge never touches.
   */
  private UserInterface $account;

  /**
   * The stack actions double the purge and the application remover use.
   */
  private SodaScsStackActionsInterface $stackActions;

  /**
   * The component actions double the purge and the application remover use.
   */
  private SodaScsComponentActionsInterface $componentActions;

  /**
   * Messages of the errors the service logged.
   *
   * @var list<string>
   */
  private array $errors = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->account = $this->createTestUser();
    $this->stackActions = $this->createMock(SodaScsStackActionsInterface::class);
    $this->componentActions = $this->createMock(SodaScsComponentActionsInterface::class);
    // Linking a database to a project grants the members access through the
    // SQL service; the double stops the helper at the MariaDB user check.
    $sqlServiceActions = $this->createMock(SodaScsSqlServiceActions::class);
    $sqlServiceActions->method('existServiceUser')->willReturn(['execStatus' => 1, 'output' => []]);
    $this->container->set('soda_scs_manager.sql_service.actions', $sqlServiceActions);
  }

  /**
   * An owned demo project whose lock is busy is listed.
   */
  public function testLockedProjectsListsOwnedDemoProjectWithBusyLock(): void {
    $demo = $this->createDemoProject();
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $demo->id();

    $listed = $this->buildService()->lockedProjects($this->account);

    $this->assertSame([$demo->id()], array_map(static fn ($project) => $project->id(), $listed));
  }

  /**
   * A project whose lock is free is not listed.
   */
  public function testLockedProjectsIgnoresDemoProjectWithFreeLock(): void {
    $this->createDemoProject();
    $this->createTestProject(['owner' => $this->account->id()]);

    $this->assertSame([], $this->buildService()->lockedProjects($this->account));
  }

  /**
   * A project without demo status is listed too while its lock is busy.
   *
   * A project delete in another tab holds the lock of an ordinary project.
   */
  public function testLockedProjectsListsNonDemoProjectWithBusyLock(): void {
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $project->id();

    $listed = $this->buildService()->lockedProjects($this->account);

    $this->assertSame([$project->id()], array_map(static fn ($listedProject) => $listedProject->id(), $listed));
  }

  /**
   * Only the busy projects of several owned ones are listed.
   */
  public function testLockedProjectsListsOnlyTheBusyOnesOfSeveralProjects(): void {
    $busy = $this->createTestProject(['owner' => $this->account->id()]);
    $this->createTestProject(['owner' => $this->account->id()]);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $busy->id();

    $listed = $this->buildService()->lockedProjects($this->account);

    $this->assertSame([$busy->id()], array_map(static fn ($project) => $project->id(), $listed));
  }

  /**
   * Only the projects the user owns count, not those of someone else.
   */
  public function testLockedProjectsIgnoresProjectOfAnotherOwner(): void {
    $other = $this->createTestUser();
    $foreign = $this->createTestProject(['owner' => $other->id(), 'members' => [$this->account->id()]]);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $foreign->id();

    $this->assertSame([], $this->buildService()->lockedProjects($this->account));
  }

  /**
   * A project locked by the account delete batch itself counts as locked.
   */
  public function testLockedProjectsListsProjectHeldThroughThePersistentLock(): void {
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $service = $this->buildService();
    $service->lockProjectsForAccountDelete([(int) $project->id()]);

    $listed = $service->lockedProjects($this->account);

    $this->assertSame([$project->id()], array_map(static fn ($listedProject) => $listedProject->id(), $listed));
  }

  /**
   * The batch takes one persistent lock per project, for an hour at most.
   */
  public function testLockProjectsForAccountDeleteTakesPersistentLockPerProject(): void {
    $prefix = SodaScsDemoManager::PROJECT_LOCK_PREFIX;

    $locked = $this->buildService()->lockProjectsForAccountDelete([4, 9]);

    $this->assertSame([4, 9], $locked);
    $this->assertSame(["acquire {$prefix}4 3600", "acquire {$prefix}9 3600"], $this->persistentLockCalls);
    $this->assertSame(["{$prefix}4", "{$prefix}9"], $this->heldPersistentLocks);
    $this->assertSame([], $this->lockCalls, 'The request lock is not used for the batch.');
    $this->assertSame([], $this->warnings);
  }

  /**
   * A project whose lock is busy is left out, warned about, and not released.
   */
  public function testLockProjectsForAccountDeleteLeavesBusyProjectOutAndWarns(): void {
    $prefix = SodaScsDemoManager::PROJECT_LOCK_PREFIX;
    $this->busyLocks[] = $prefix . '9';

    $locked = $this->buildService()->lockProjectsForAccountDelete([4, 9, 12]);

    $this->assertSame([4, 12], $locked);
    $this->assertSame(["{$prefix}4", "{$prefix}12"], $this->heldPersistentLocks);
    $this->assertCount(1, $this->warnings);
    $this->assertStringContainsString('is being set up or deleted right now', $this->warnings[0]);
    $this->assertStringContainsString('the account delete is refused', $this->warnings[0]);
    $this->assertNotContains('release ' . $prefix . '9', $this->persistentLockCalls);
  }

  /**
   * No projects, no lock calls.
   */
  public function testLockProjectsForAccountDeleteWithoutProjectsTakesNoLock(): void {
    $this->assertSame([], $this->buildService()->lockProjectsForAccountDelete([]));
    $this->assertSame([], $this->persistentLockCalls);
  }

  /**
   * Releasing frees the locks of the given projects and no other.
   */
  public function testReleaseProjectLocksReleasesOnlyTheGivenProjects(): void {
    $prefix = SodaScsDemoManager::PROJECT_LOCK_PREFIX;
    $service = $this->buildService();
    $service->lockProjectsForAccountDelete([4, 9, 12]);

    $service->releaseProjectLocks([4, 12]);

    $this->assertSame(["{$prefix}9"], $this->heldPersistentLocks);
    $this->assertSame(["release {$prefix}4", "release {$prefix}12"], array_slice($this->persistentLockCalls, 3));
  }

  /**
   * Releasing an empty list does nothing.
   */
  public function testReleaseProjectLocksWithoutProjectsDoesNothing(): void {
    $this->buildService()->releaseProjectLocks([]);

    $this->assertSame([], $this->persistentLockCalls);
  }

  /**
   * The purge holds the project lock while it deletes a demo project.
   */
  public function testPurgeHoldsDemoProjectLockWhileDeletingAndReleasesIt(): void {
    $demo = $this->createDemoProject();
    $projectId = (int) $demo->id();
    $lockName = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $projectId;

    $this->buildService()->purge($this->account);

    $this->assertSame(["acquire $lockName 900", "release $lockName"], $this->lockCalls);
    $this->assertTrue($this->lockHeldDuringDelete[$projectId], 'The project was torn down while the lock was held.');
    $this->assertSame([], $this->heldLocks);
    $this->assertSame([], $this->warnings);
    $this->assertProjectGone($projectId);
  }

  /**
   * A busy lock is logged, the demo project is deleted anyway, nothing freed.
   */
  public function testPurgeWarnsAndDeletesDemoProjectWhenLockIsBusy(): void {
    $demo = $this->createDemoProject(['label' => 'Busy demo']);
    $projectId = (int) $demo->id();
    $lockName = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $projectId;
    $this->busyLocks[] = $lockName;

    $this->buildService()->purge($this->account);

    $this->assertSame(["acquire $lockName 900"], $this->lockCalls, 'A lock the purge did not get is not released.');
    $this->assertCount(1, $this->warnings);
    $this->assertStringContainsString('while its demo job runs', $this->warnings[0]);
    $this->assertProjectGone($projectId);
  }

  /**
   * A failing delete still releases the lock the purge took.
   */
  public function testPurgeReleasesDemoProjectLockWhenTheDeleteFails(): void {
    $demo = $this->createDemoProject();
    $lockName = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $demo->id();

    $this->buildService(failProjectGroupDelete: TRUE)->purge($this->account);

    $this->assertSame(["acquire $lockName 900", "release $lockName"], $this->lockCalls);
    $this->assertSame([], $this->heldLocks);
  }

  /**
   * A project that is not a demo is deleted without touching any lock.
   */
  public function testPurgeTakesNoLockForNonDemoProject(): void {
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $projectId = (int) $project->id();

    $this->buildService()->purge($this->account);

    $this->assertSame([], $this->lockCalls);
    $this->assertProjectGone($projectId);
  }

  /**
   * Each demo project gets its own lock; the other projects get none.
   */
  public function testPurgeLocksEachDemoProjectSeparately(): void {
    $first = $this->createDemoProject();
    $second = $this->createDemoProject();
    $this->createTestProject(['owner' => $this->account->id()]);
    $prefix = SodaScsDemoManager::PROJECT_LOCK_PREFIX;

    $this->buildService()->purge($this->account);

    $acquired = array_values(array_filter($this->lockCalls, static fn (string $call): bool => str_starts_with($call, 'acquire ')));
    sort($acquired);
    $expected = ["acquire {$prefix}{$first->id()} 900", "acquire {$prefix}{$second->id()} 900"];
    sort($expected);
    $this->assertSame($expected, $acquired);
    $this->assertCount(2, array_filter($this->lockCalls, static fn (string $call): bool => str_starts_with($call, 'release ')));
    $this->assertSame([], $this->heldLocks);
  }

  /**
   * A demo project whose lock the batch holds is deleted without a warning.
   *
   * The batch's persistent lock is busy for the purge's own lock; purge()
   * must not try to take it, nor warn, nor release it.
   */
  public function testPurgeSkipsLockOfDemoProjectHeldByTheBatch(): void {
    $demo = $this->createDemoProject();
    $projectId = (int) $demo->id();
    $service = $this->buildService();
    $this->assertSame([$projectId], $service->lockProjectsForAccountDelete([$projectId]));

    $service->holdProjectLocks([$projectId]);
    $service->purge($this->account);

    $this->assertSame([], $this->lockCalls, 'The purge neither takes nor releases the held lock.');
    $this->assertSame([], $this->warnings);
    $this->assertSame([SodaScsDemoManager::PROJECT_LOCK_PREFIX . $projectId], $this->heldPersistentLocks, 'The batch lock is still held after the purge.');
    $this->assertProjectGone($projectId);
  }

  /**
   * Only the held projects are skipped; other demo projects are still locked.
   */
  public function testPurgeStillLocksDemoProjectsThatAreNotHeld(): void {
    $held = $this->createDemoProject();
    $other = $this->createDemoProject();
    $prefix = SodaScsDemoManager::PROJECT_LOCK_PREFIX;
    $service = $this->buildService();

    $service->holdProjectLocks([(int) $held->id()]);
    $service->purge($this->account);

    $this->assertSame(["acquire {$prefix}{$other->id()} 900", "release {$prefix}{$other->id()}"], $this->lockCalls);
  }

  /**
   * Holding an empty list ends the exemption: the purge locks again.
   */
  public function testPurgeLocksDemoProjectAgainAfterHoldIsCleared(): void {
    $demo = $this->createDemoProject();
    $lockName = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $demo->id();
    $service = $this->buildService();
    $service->holdProjectLocks([(int) $demo->id()]);
    $service->holdProjectLocks([]);

    $service->purge($this->account);

    $this->assertSame(["acquire $lockName 900", "release $lockName"], $this->lockCalls);
  }

  /**
   * The profile delete form refuses while a demo project is locked.
   */
  public function testProfileDeleteFormRefusesWhileDemoProjectIsInSetup(): void {
    $demo = $this->createDemoProject(['label' => 'Setup demo']);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $demo->id();

    $formState = $this->validateProfileDeleteForm();

    $errors = array_map('strval', $formState->getErrors());
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('Setup demo', reset($errors));
    $this->assertStringContainsString('A project is being set up or deleted right now (Setup demo)', reset($errors));
    $this->assertStringContainsString('Delete your profile when that is done.', reset($errors));
  }

  /**
   * The profile delete form refuses while an ordinary project is locked.
   */
  public function testProfileDeleteFormRefusesWhileNonDemoProjectIsLocked(): void {
    $project = $this->createTestProject(['owner' => $this->account->id(), 'label' => 'Deleting project']);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $project->id();

    $messages = $this->profileDeleteFormErrorMessages();

    $this->assertCount(1, $messages);
    $this->assertStringContainsString('Deleting project', $messages[0]);
  }

  /**
   * The profile delete form refuses with several demo projects in setup.
   *
   * Form errors of one element name collapse into the first one, so a single
   * error names every busy project; the idle demo project is not named.
   */
  public function testProfileDeleteFormRefusesWithSeveralDemoProjectsInSetup(): void {
    $busyFirst = $this->createDemoProject(['label' => 'First demo']);
    $busySecond = $this->createDemoProject(['label' => 'Second demo']);
    $this->createDemoProject(['label' => 'Idle demo']);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $busyFirst->id();
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $busySecond->id();

    $messages = $this->profileDeleteFormErrorMessages();

    $this->assertCount(1, $messages);
    $this->assertStringContainsString('First demo', $messages[0]);
    $this->assertStringContainsString('Second demo', $messages[0]);
    $this->assertStringNotContainsString('Idle demo', $messages[0]);
  }

  /**
   * The profile delete form passes when the demo job holds no lock.
   */
  public function testProfileDeleteFormAcceptsWhenDemoLockIsFree(): void {
    $this->createDemoProject();

    $this->assertSame([], $this->validateProfileDeleteForm()->getErrors());
  }

  /**
   * The profile delete form passes for a user without projects.
   */
  public function testProfileDeleteFormAcceptsUserWithoutProjects(): void {
    $this->assertSame([], $this->validateProfileDeleteForm()->getErrors());
  }

  /**
   * The admin cancel form refuses the delete method while a demo is in setup.
   *
   * @dataProvider provideDeletingCancelMethods
   */
  public function testCancelFormRefusesDeletingMethodsWhileDemoProjectIsInSetup(string $method): void {
    $demo = $this->createDemoProject(['label' => 'Setup demo']);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $demo->id();

    $formState = $this->validateCancelForm($method);

    $errors = $formState->getErrors();
    $this->assertSame(['user_cancel_method'], array_keys($errors));
    $this->assertStringContainsString('Setup demo', (string) $errors['user_cancel_method']);
    $this->assertStringContainsString('A project is being set up or deleted right now (Setup demo)', (string) $errors['user_cancel_method']);
    $this->assertStringContainsString('Delete the account when that is done.', (string) $errors['user_cancel_method']);
  }

  /**
   * Cancel methods that delete the account.
   *
   * @return array<string, array{string}>
   *   The method names.
   */
  public static function provideDeletingCancelMethods(): array {
    return [
      'delete account and content' => ['user_cancel_delete'],
      'delete account, reassign content' => ['user_cancel_reassign'],
    ];
  }

  /**
   * Methods that keep the account never look at the demo projects.
   *
   * @dataProvider provideKeepingCancelMethods
   */
  public function testCancelFormAcceptsKeepingMethodsWhileDemoProjectIsInSetup(string $method): void {
    $demo = $this->createDemoProject();
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $demo->id();

    $this->assertSame([], $this->validateCancelForm($method)->getErrors());
  }

  /**
   * Cancel methods that keep the account.
   *
   * @return array<string, array{string}>
   *   The method names.
   */
  public static function provideKeepingCancelMethods(): array {
    return [
      'block account' => ['user_cancel_block'],
      'block account and unpublish content' => ['user_cancel_block_unpublish'],
      'no method submitted' => [''],
    ];
  }

  /**
   * The admin cancel form passes when the demo lock is free.
   */
  public function testCancelFormAcceptsDeleteWhenDemoLockIsFree(): void {
    $this->createDemoProject();

    $this->assertSame([], $this->validateCancelForm('user_cancel_delete')->getErrors());
  }

  /**
   * The bulk cancel form refuses when one of the selected accounts is in setup.
   */
  public function testBulkCancelFormRefusesWhenOneSelectedAccountIsInSetup(): void {
    $idleAccount = $this->createTestUser();
    $demo = $this->createDemoProject(['label' => 'Bulk demo']);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $demo->id();
    $this->container->set('soda_scs_manager.user_offboarding', $this->buildService());

    $form = [];
    $formState = new FormState();
    $formState->setFormObject($this->createMock(FormInterface::class));
    $formState->setValue('user_cancel_method', 'user_cancel_delete');
    $formState->setValue('accounts', [
      $idleAccount->id() => $idleAccount->id(),
      $this->account->id() => $this->account->id(),
    ]);
    soda_scs_manager_user_cancel_validate_demo_setup($form, $formState);

    $errors = $formState->getErrors();
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('Bulk demo', (string) reset($errors));
  }

  /**
   * Both cancel forms get the demo setup check as a validate handler.
   */
  public function testCancelFormAltersRegisterTheDemoSetupValidator(): void {
    $this->container->set('soda_scs_manager.user_offboarding', $this->buildService());
    $callback = 'soda_scs_manager_user_cancel_validate_demo_setup';

    $formState = new FormState();
    $formState->setFormObject($this->createMock(FormInterface::class));
    $single = [];
    soda_scs_manager_form_user_cancel_form_alter($single, $formState, 'user_cancel_form');
    $bulk = [];
    soda_scs_manager_form_user_multiple_cancel_confirm_alter($bulk, $formState, 'user_multiple_cancel_confirm');

    $this->assertContains($callback, $single['#validate']);
    $this->assertContains($callback, $bulk['#validate']);
  }

  /**
   * The bulk cancel form names the demo projects of every selected account.
   */
  public function testBulkCancelFormNamesDemoProjectsOfAllSelectedAccounts(): void {
    $otherAccount = $this->createTestUser();
    $ownDemo = $this->createDemoProject(['label' => 'Own demo']);
    $otherDemo = $this->createDemoProject(['label' => 'Other demo', 'owner' => $otherAccount->id()]);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $ownDemo->id();
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $otherDemo->id();
    $this->container->set('soda_scs_manager.user_offboarding', $this->buildService());

    $form = [];
    $formState = new FormState();
    $formState->setFormObject($this->createMock(FormInterface::class));
    $formState->setValue('user_cancel_method', 'user_cancel_delete');
    $formState->setValue('accounts', [
      $otherAccount->id() => $otherAccount->id(),
      $this->account->id() => $this->account->id(),
    ]);
    soda_scs_manager_user_cancel_validate_demo_setup($form, $formState);

    $errors = array_map('strval', $formState->getErrors());
    $this->assertSame(['user_cancel_method'], array_keys($errors));
    $this->assertStringContainsString('Own demo', $errors['user_cancel_method']);
    $this->assertStringContainsString('Other demo', $errors['user_cancel_method']);
  }

  /**
   * The purge deletes what a demo job saved before the lock was taken.
   *
   * A job attempt in another process can save a new WebProtégé project id
   * after the purge loaded the project and before it got the lock. Under the
   * lock the purge must read the stored project, not its earlier copy.
   */
  public function testPurgeTrashesWebprotegeProjectSavedBeforeTheLock(): void {
    $demo = $this->createDemoProject(['webprotegeProjectId' => 'old-ontology']);
    $projectId = (int) $demo->id();
    $this->onAcquire = function () use ($projectId): void {
      // Another process saves the project: it writes the row and clears the
      // shared persistent cache, while this request's static cache keeps the
      // copy it loaded before.
      \Drupal::database()->update('soda_scs_project_field_data')
        ->fields(['webprotegeProjectId' => 'new-ontology'])
        ->condition('id', $projectId)
        ->execute();
      \Drupal::cache('entity')->delete('values:soda_scs_project:' . $projectId);
    };

    $this->buildService()->purge($this->account);

    $this->assertSame(['new-ontology'], $this->trashedWebprotegeIds);
    $this->assertProjectGone($projectId);
  }

  /**
   * The purge deletes the owner's stack and detaches a member's database.
   */
  public function testPurgeDeletesOwnersStackAndDetachesMembersComponent(): void {
    $member = $this->createTestUser();
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $this->account->id(),
      'partOfProjects' => [$project->id()],
    ]);
    $component = $this->createTestComponent('soda_scs_sql_component', [
      'owner' => $member->id(),
      'partOfProjects' => [$project->id()],
    ]);
    $deletedStacks = [];
    $this->stackActions->method('deleteStack')->willReturnCallback(function (SodaScsStackInterface $given) use (&$deletedStacks): array {
      $deletedStacks[] = (int) $given->id();
      return ['success' => TRUE];
    });
    $this->componentActions->expects($this->never())->method('deleteComponent');

    $this->buildService()->purge($this->account);

    $this->assertContains((int) $stack->id(), $deletedStacks);
    $this->assertProjectGone((int) $project->id());
    $storage = $this->entityTypeManager->getStorage('soda_scs_component');
    $storage->resetCache([$component->id()]);
    $kept = $storage->load($component->id());
    $this->assertNotNull($kept, 'The database of the other member survives the purge.');
    $this->assertTrue($kept->get('partOfProjects')->isEmpty(), 'It lost the deleted project.');
  }

  /**
   * A WissKI stack that cannot be deleted does not stop the rest of the purge.
   */
  public function testPurgeGoesOnWhenWisskiStackCannotBeDeleted(): void {
    $member = $this->createTestUser();
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $first = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $this->account->id(),
      'partOfProjects' => [$project->id()],
    ]);
    $second = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $this->account->id(),
      'partOfProjects' => [$project->id()],
    ]);
    $component = $this->createTestComponent('soda_scs_sql_component', [
      'owner' => $member->id(),
      'partOfProjects' => [$project->id()],
    ]);
    $attempted = [];
    $this->stackActions->method('deleteStack')->willReturnCallback(function (SodaScsStackInterface $given) use (&$attempted, $first): array {
      $attempted[] = (int) $given->id();
      return (int) $given->id() === (int) $first->id() ? ['success' => FALSE, 'error' => 'Portainer answered 500.'] : ['success' => TRUE];
    });

    $this->buildService()->purge($this->account);

    $this->assertSame([(int) $first->id(), (int) $second->id()], array_slice($attempted, 0, 2), 'The stack after the failed one is tried.');
    $this->assertCount(1, array_filter($this->errors, static fn (string $message): bool => str_contains($message, 'Could not remove')));
    $this->assertProjectGone((int) $project->id());
    $storage = $this->entityTypeManager->getStorage('soda_scs_component');
    $storage->resetCache([$component->id()]);
    $this->assertNotNull($storage->load($component->id()));
  }

  /**
   * The ids of the owned projects come in ascending order; others are left.
   */
  public function testOwnedProjectIdsListsOnlyOwnedProjectsInAscendingOrder(): void {
    $first = $this->createTestProject(['owner' => $this->account->id()]);
    $other = $this->createTestUser();
    $this->createTestProject(['owner' => $other->id(), 'members' => [$this->account->id()]]);
    $second = $this->createTestProject(['owner' => $this->account->id()]);

    $this->assertSame([(int) $first->id(), (int) $second->id()], $this->buildService()->ownedProjectIds($this->account));
    $this->assertSame([], $this->buildService()->ownedProjectIds($this->createTestUser()));
  }

  /**
   * The removal of one application takes no lock, not even for a demo project.
   *
   * The batch holds the project lock; the removal only delegates to the
   * application remover and returns its result.
   */
  public function testRemoveNextProjectApplicationDelegatesToRemoverWithoutTakingLock(): void {
    $demo = $this->createDemoProject();
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $demo->id();
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $this->account->id(),
      'partOfProjects' => [$demo->id()],
    ]);
    $this->stackActions->expects($this->once())->method('deleteStack')->willReturn(['success' => TRUE]);

    $result = $this->buildService()->removeNextProjectApplication($demo);

    $this->assertTrue($result->success);
    $this->assertSame('soda_scs_stack:' . $stack->id(), $result->data['key']);
    $this->assertSame($stack->label(), $result->data['application']);
    $this->assertSame([], $this->lockCalls);
    $this->assertSame([], $this->persistentLockCalls);
    $this->assertSame([], $this->warnings);
  }

  /**
   * A stack that cannot be deleted is kept and the failure is returned.
   */
  public function testRemoveNextProjectApplicationKeepsStackWhenDeleteFails(): void {
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $this->account->id(),
      'partOfProjects' => [$project->id()],
    ]);
    $this->stackActions->method('deleteStack')->willReturn([
      'success' => FALSE,
      'error' => 'Portainer answered 500.',
    ]);

    $result = $this->buildService()->removeNextProjectApplication($project);

    $this->assertFalse($result->success);
    $this->assertStackExists((int) $stack->id());
  }

  /**
   * The batch step removes one application per request, project by project.
   */
  public function testBatchStepRemovesOneApplicationPerRequestAcrossProjects(): void {
    $first = $this->createTestProject(['owner' => $this->account->id()]);
    $second = $this->createTestProject(['owner' => $this->account->id()]);
    $stackA = $this->createOwnedStack($first);
    $stackB = $this->createOwnedStack($first);
    $stackC = $this->createOwnedStack($second);
    $deleted = $this->deleteStacksInDouble();
    $this->useOffboardingServices();

    $context = [];
    $finished = [];
    $heldCounts = [];
    for ($request = 0; $request < 5; $request++) {
      SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);
      $finished[] = $context['finished'];
      $heldCounts[] = count($this->heldPersistentLocks);
    }

    $this->assertSame([(int) $stackA->id(), (int) $stackB->id(), (int) $stackC->id()], $deleted->ids);
    $this->assertSame([0, 0, 0.5, 0.5, 1], $finished);
    $this->assertSame([2, 2, 2, 2, 2], $heldCounts, 'Both project locks stay held, also in the request that finishes.');
    $this->assertSame([(int) $first->id(), (int) $second->id()], $context['results']['soda_scs_locked_projects'][(int) $this->account->id()]);
    $this->assertSame([], $this->errors);
    $this->assertSame('Removed ' . $stackC->label() . '.', (string) $context['message']);
  }

  /**
   * A project whose application fails is left and the step moves on.
   */
  public function testBatchStepMovesToNextProjectWhenApplicationFails(): void {
    $first = $this->createTestProject(['owner' => $this->account->id()]);
    $second = $this->createTestProject(['owner' => $this->account->id()]);
    $failing = $this->createOwnedStack($first);
    $other = $this->createOwnedStack($second);
    $attempted = [];
    $this->stackActions->method('deleteStack')->willReturnCallback(function (SodaScsStackInterface $stack) use (&$attempted, $failing): array {
      $attempted[] = (int) $stack->id();
      if ($stack->id() === $failing->id()) {
        return ['success' => FALSE, 'error' => 'Portainer answered 500.'];
      }
      $stack->delete();
      return ['success' => TRUE];
    });
    $this->useOffboardingServices();

    $context = [];
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);
    $this->assertSame(0.5, $context['finished']);
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);
    $this->assertSame(0.5, $context['finished']);
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);
    $this->assertSame(1, $context['finished']);

    $this->assertSame([(int) $failing->id(), (int) $other->id()], $attempted, 'The failing stack is tried once.');
    $this->assertStackExists((int) $failing->id());
    $this->assertStackGone((int) $other->id());
    $this->assertCount(2, $this->heldPersistentLocks, 'A failed application does not release the locks; deleteAccount() does.');
    $this->assertSame([(int) $first->id(), (int) $second->id()], $context['results']['soda_scs_locked_projects'][(int) $this->account->id()]);
  }

  /**
   * A project without applications is done in one request, nothing is called.
   */
  public function testBatchStepFinishesProjectWithoutApplications(): void {
    $this->createTestProject(['owner' => $this->account->id()]);
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->componentActions->expects($this->never())->method('deleteComponent');
    $this->useOffboardingServices();

    $context = [];
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertArrayNotHasKey('message', $context);
  }

  /**
   * An application that is reported as removed but stays is not tried again.
   */
  public function testBatchStepGivesUpOnApplicationThatStaysAfterRemoval(): void {
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $stack = $this->createOwnedStack($project);
    $this->stackActions->expects($this->exactly(2))->method('deleteStack')->willReturn(['success' => TRUE]);
    $this->useOffboardingServices();

    $context = [];
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);
    $this->assertSame(0, $context['finished']);
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertCount(1, array_filter($this->errors, static fn (string $message): bool => str_contains($message, 'is still there after it was removed')));
    $this->assertStackExists((int) $stack->id());
  }

  /**
   * After MAX_STEPS requests the step gives up and leaves the rest.
   */
  public function testBatchStepGivesUpAfterMaxSteps(): void {
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $stack = $this->createOwnedStack($project);
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->useOffboardingServices();

    $lockName = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $project->id();
    $this->heldPersistentLocks[] = $lockName;
    $context = [
      'results' => ['soda_scs_locked_projects' => [(int) $this->account->id() => [(int) $project->id()]]],
      'sandbox' => [
        'projects' => [(int) $project->id()],
        'total' => 1,
        'steps' => SodaScsUserOffboardingBatch::MAX_STEPS,
        'removed' => [],
      ],
    ];
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertCount(1, array_filter($this->errors, static fn (string $message): bool => str_starts_with($message, 'Gave up removing the applications')));
    $this->assertStackExists((int) $stack->id());
    $this->assertSame([$lockName], $this->heldPersistentLocks, 'The step does not release; deleteAccount() does.');
    $this->assertSame([], $this->persistentLockCalls);
    $this->assertSame([(int) $project->id()], $context['results']['soda_scs_locked_projects'][(int) $this->account->id()]);
  }

  /**
   * The step before MAX_STEPS still removes an application.
   */
  public function testBatchStepWorksOnLastAllowedStep(): void {
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $this->createOwnedStack($project);
    $deleted = $this->deleteStacksInDouble();
    $this->useOffboardingServices();

    $context = [
      'sandbox' => [
        'projects' => [(int) $project->id()],
        'total' => 1,
        'steps' => SodaScsUserOffboardingBatch::MAX_STEPS - 1,
        'removed' => [],
      ],
    ];
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);

    $this->assertCount(1, $deleted->ids);
    $this->assertSame([], $this->errors);
  }

  /**
   * The first request locks every owned project; no removal releases them.
   */
  public function testBatchStepLocksOwnedProjectsOnInitAndKeepsThemAfterTheLastRequest(): void {
    $first = $this->createTestProject(['owner' => $this->account->id()]);
    $second = $this->createTestProject(['owner' => $this->account->id()]);
    $prefix = SodaScsDemoManager::PROJECT_LOCK_PREFIX;
    $this->useOffboardingServices();

    $context = [];
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);
    $this->assertSame(0.5, $context['finished']);
    $this->assertSame(["{$prefix}{$first->id()}", "{$prefix}{$second->id()}"], $this->heldPersistentLocks, 'Both are locked in the first request.');

    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);
    $this->assertSame(1, $context['finished']);
    $this->assertSame(["{$prefix}{$first->id()}", "{$prefix}{$second->id()}"], $this->heldPersistentLocks, 'The locks outlive the last removal request.');
    $this->assertSame([
      "acquire {$prefix}{$first->id()} 3600",
      "acquire {$prefix}{$second->id()} 3600",
    ], $this->persistentLockCalls);
    $uid = (int) $this->account->id();
    $this->assertSame([(int) $first->id(), (int) $second->id()], $context['results']['soda_scs_locked_projects'][$uid]);
    $this->assertArrayNotHasKey('soda_scs_busy_projects', $context['results']);
  }

  /**
   * A busy project stops the removal: nothing is removed, taken locks go back.
   *
   * The step cannot lock the busy project, so none of the account's
   * applications may be deleted; deleteAccount() then refuses.
   */
  public function testBatchStepRemovesNothingAndReleasesTakenLocksWhenProjectIsBusy(): void {
    $busy = $this->createTestProject(['owner' => $this->account->id()]);
    $free = $this->createTestProject(['owner' => $this->account->id()]);
    $busyStack = $this->createOwnedStack($busy);
    $freeStack = $this->createOwnedStack($free);
    $prefix = SodaScsDemoManager::PROJECT_LOCK_PREFIX;
    $this->busyLocks[] = $prefix . $busy->id();
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->useOffboardingServices();

    $context = [];
    for ($request = 0; $request < 3; $request++) {
      SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);
    }

    $uid = (int) $this->account->id();
    $this->assertSame(1, $context['finished']);
    $this->assertStackExists((int) $busyStack->id());
    $this->assertStackExists((int) $freeStack->id());
    $this->assertSame([(int) $busy->id()], $context['results']['soda_scs_busy_projects'][$uid]);
    $this->assertSame([], $context['results']['soda_scs_locked_projects'][$uid]);
    $this->assertSame([], $this->heldPersistentLocks, 'The lock taken for the free project is given back.');
    $this->assertContains('release ' . $prefix . $free->id(), $this->persistentLockCalls);
    $this->assertNotContains('release ' . $prefix . $busy->id(), $this->persistentLockCalls, 'A lock the batch did not get is not released.');
    $this->assertCount(1, $this->warnings);
  }

  /**
   * With every project locked elsewhere the step finishes at once.
   */
  public function testBatchStepFinishesWithoutRemovalWhenAllProjectsAreLocked(): void {
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $stack = $this->createOwnedStack($project);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $project->id();
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->useOffboardingServices();

    $context = [];
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertStackExists((int) $stack->id());
    $this->assertSame([(int) $project->id()], $context['results']['soda_scs_busy_projects'][(int) $this->account->id()]);
    $this->assertSame([], array_filter($this->persistentLockCalls, static fn (string $call): bool => str_starts_with($call, 'release ')), 'Nothing was taken, so nothing is released.');
  }

  /**
   * An account without projects takes no lock.
   */
  public function testBatchStepWithoutOwnedProjectsTakesNoLock(): void {
    $this->useOffboardingServices();

    $context = [];
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertSame([], $this->persistentLockCalls);
    $this->assertSame([], $context['results']['soda_scs_locked_projects'][(int) $this->account->id()]);
    $this->assertArrayNotHasKey('soda_scs_busy_projects', $context['results']);
  }

  /**
   * A project that is gone before its turn is skipped.
   */
  public function testBatchStepSkipsProjectThatNoLongerExists(): void {
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->useOffboardingServices();

    $context = ['sandbox' => ['projects' => [987654], 'total' => 1, 'steps' => 0, 'removed' => []]];
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);

    $this->assertSame(1, $context['finished']);
  }

  /**
   * Account 1 and unknown accounts get no removal, whatever they own.
   */
  public function testBatchStepRemovesNothingForAccountOneOrUnknownAccount(): void {
    $project = $this->createTestProject(['owner' => 1]);
    $this->createOwnedStack($project, $this->testUser);
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->useOffboardingServices();

    $contextForAdmin = [];
    SodaScsUserOffboardingBatch::removeApplications(1, $contextForAdmin);
    $contextForUnknown = [];
    SodaScsUserOffboardingBatch::removeApplications(987654, $contextForUnknown);

    $this->assertSame(1, $contextForAdmin['finished']);
    $this->assertSame(1, $contextForUnknown['finished']);
    $this->assertSame([], $this->persistentLockCalls, 'No project is locked for them.');
    $this->assertSame([], $contextForAdmin['results']['soda_scs_locked_projects'][1]);
  }

  /**
   * Applications of a project the account only belongs to are not touched.
   */
  public function testBatchStepLeavesProjectsOfOtherOwners(): void {
    $other = $this->createTestUser();
    $foreign = $this->createTestProject(['owner' => $other->id(), 'members' => [$this->account->id()]]);
    $this->createOwnedStack($foreign, $this->account);
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->useOffboardingServices();

    $context = [];
    SodaScsUserOffboardingBatch::removeApplications((int) $this->account->id(), $context);

    $this->assertSame(1, $context['finished']);
  }

  /**
   * A busy project makes deleteAccount() refuse: the account stays.
   */
  public function testDeleteAccountRefusesAndKeepsAccountWhenProjectWasBusy(): void {
    $project = $this->createTestProject(['owner' => $this->account->id(), 'label' => 'Busy project']);
    $stack = $this->createOwnedStack($project);
    $uid = (int) $this->account->id();
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $project->id();
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->useOffboardingServices();
    \Drupal::messenger()->deleteAll();

    $context = [];
    SodaScsUserOffboardingBatch::removeApplications($uid, $context);
    $persistentCallsBefore = $this->persistentLockCalls;
    SodaScsUserOffboardingBatch::deleteAccount([], $this->account, 'user_cancel_delete', $context);

    $this->assertNotNull($this->entityTypeManager->getStorage('user')->load($uid), 'The account is kept.');
    $this->assertStackExists((int) $stack->id());
    $errors = array_map('strval', \Drupal::messenger()->messagesByType('error'));
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('was not deleted', $errors[0]);
    $this->assertStringContainsString((string) $this->account->getDisplayName(), $errors[0]);
    $this->assertStringContainsString('Busy project', $errors[0]);
    $this->assertCount(1, array_filter($this->warnings, static fn (string $message): bool => str_starts_with($message, 'Account @user was not deleted')));
    $this->assertSame($persistentCallsBefore, $this->persistentLockCalls, 'The refusal takes and releases no lock of its own.');
  }

  /**
   * The refusal names every busy project and keeps the account.
   */
  public function testDeleteAccountRefusalNamesAllBusyProjects(): void {
    $first = $this->createTestProject(['owner' => $this->account->id(), 'label' => 'First busy']);
    $second = $this->createTestProject(['owner' => $this->account->id(), 'label' => 'Second busy']);
    $uid = (int) $this->account->id();
    $this->useOffboardingServices();
    \Drupal::messenger()->deleteAll();

    $context = ['results' => ['soda_scs_busy_projects' => [$uid => [(int) $first->id(), (int) $second->id()]]]];
    SodaScsUserOffboardingBatch::deleteAccount([], $this->account, 'user_cancel_delete', $context);

    $errors = array_map('strval', \Drupal::messenger()->messagesByType('error'));
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('First busy', $errors[0]);
    $this->assertStringContainsString('Second busy', $errors[0]);
    $this->assertNotNull($this->entityTypeManager->getStorage('user')->load($uid));
  }

  /**
   * Only the account's own busy list counts, not that of another account.
   */
  public function testDeleteAccountIgnoresBusyProjectsOfAnotherAccount(): void {
    $this->installSchema('externalauth', ['authmap']);
    $this->useOffboardingServices();
    $uid = (int) $this->account->id();

    $context = [
      'results' => [
        'soda_scs_busy_projects' => [$uid + 100 => [5]],
        'soda_scs_locked_projects' => [$uid => []],
      ],
    ];
    SodaScsUserOffboardingBatch::deleteAccount([], $this->account, 'user_cancel_delete', $context);

    $this->assertNull($this->entityTypeManager->getStorage('user')->load($uid), 'The account is deleted.');
  }

  /**
   * Without a busy project the account is deleted and its locks are released.
   *
   * The purge in hook_user_predelete runs with the locks held: it must not
   * take the demo project's own lock, and it deletes the project.
   */
  public function testDeleteAccountDeletesAccountAndReleasesLocksWhenNoProjectWasBusy(): void {
    $demo = $this->createDemoProject();
    $ordinary = $this->createTestProject(['owner' => $this->account->id()]);
    $uid = (int) $this->account->id();
    $prefix = SodaScsDemoManager::PROJECT_LOCK_PREFIX;
    $this->installSchema('externalauth', ['authmap']);
    $this->useOffboardingServices();

    $context = [];
    SodaScsUserOffboardingBatch::removeApplications($uid, $context);
    $this->assertCount(2, $this->heldPersistentLocks);
    SodaScsUserOffboardingBatch::deleteAccount([], $this->account, 'user_cancel_delete', $context);

    $this->assertNull($this->entityTypeManager->getStorage('user')->load($uid), 'The account is deleted.');
    $this->assertProjectGone((int) $demo->id());
    $this->assertProjectGone((int) $ordinary->id());
    $this->assertSame([], $this->heldPersistentLocks, 'The batch locks are released.');
    $this->assertSame([], $this->lockCalls, 'The purge honored the held lock of the demo project.');
    $this->assertSame([], $this->warnings);
    $this->assertSame([
      "release {$prefix}{$demo->id()}",
      "release {$prefix}{$ordinary->id()}",
    ], array_slice($this->persistentLockCalls, 2));
  }

  /**
   * The locks are released after _user_cancel() ran, not before.
   */
  public function testDeleteAccountReleasesLocksOnlyAfterTheAccountIsDeleted(): void {
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $uid = (int) $this->account->id();
    $lockName = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $project->id();
    $this->useOffboardingServices();
    $heldDuringDelete = NULL;
    $this->onAcquire = NULL;
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getDisplayName')->willReturn('Test account');
    $account->method('getAccountName')->willReturn('test_account');
    $account->method('getEmail')->willReturn('test@example.com');
    $account->method('delete')->willReturnCallback(function () use (&$heldDuringDelete, $lockName): void {
      $heldDuringDelete = in_array($lockName, $this->heldPersistentLocks, TRUE);
    });

    $context = [];
    SodaScsUserOffboardingBatch::removeApplications($uid, $context);
    SodaScsUserOffboardingBatch::deleteAccount([], $account, 'user_cancel_delete', $context);

    $this->assertTrue($heldDuringDelete, 'The project lock is held while the account is deleted.');
    $this->assertSame([], $this->heldPersistentLocks);
  }

  /**
   * The locks are released and the hold is cleared when _user_cancel() throws.
   */
  public function testDeleteAccountReleasesLocksAndClearsHoldWhenUserCancelThrows(): void {
    $demo = $this->createDemoProject();
    $uid = (int) $this->account->id();
    $prefix = SodaScsDemoManager::PROJECT_LOCK_PREFIX;
    $service = $this->buildService();
    $this->container->set('soda_scs_manager.user_offboarding', $service);
    $this->container->set('logger.factory', $this->buildLoggerFactory());
    $failing = $this->createMock(UserInterface::class);
    $failing->method('id')->willReturn($uid);
    $failing->method('delete')->willThrowException(new \RuntimeException('Delete failed.'));

    $context = [];
    SodaScsUserOffboardingBatch::removeApplications($uid, $context);
    $this->assertSame(["{$prefix}{$demo->id()}"], $this->heldPersistentLocks);
    try {
      SodaScsUserOffboardingBatch::deleteAccount([], $failing, 'user_cancel_delete', $context);
      $this->fail('The exception of _user_cancel() must not be swallowed.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Delete failed.', $exception->getMessage());
    }

    $this->assertSame([], $this->heldPersistentLocks, 'The batch locks are released.');
    $this->assertContains("release {$prefix}{$demo->id()}", $this->persistentLockCalls);
    $this->assertNotNull($this->entityTypeManager->getStorage('user')->load($uid), 'The account is still there.');
    // The hold is cleared: the purge takes the demo project's lock again.
    $service->purge($this->account);
    $this->assertSame(["acquire {$prefix}{$demo->id()} 900", "release {$prefix}{$demo->id()}"], $this->lockCalls);
  }

  /**
   * The module's hook_batch_alter() puts the removal before the account delete.
   *
   * The plain _user_cancel operation is replaced by deleteAccount().
   */
  public function testBatchAlterHookInsertsRemovalBeforeAccountDelete(): void {
    $batch = [
      'sets' => [
        [
          'operations' => [['_user_cancel', [[], $this->account, 'user_cancel_delete']]],
          'total' => 1,
          'count' => 1,
        ],
      ],
    ];

    $this->container->get('module_handler')->alter('batch', $batch);

    $this->assertSame([SodaScsUserOffboardingBatch::class, 'removeApplications'], $batch['sets'][0]['operations'][0][0]);
    $this->assertSame([(int) $this->account->id()], $batch['sets'][0]['operations'][0][1]);
    $this->assertSame([SodaScsUserOffboardingBatch::class, 'deleteAccount'], $batch['sets'][0]['operations'][1][0]);
    $this->assertSame([[], $this->account, 'user_cancel_delete'], $batch['sets'][0]['operations'][1][1]);
  }

  /**
   * The profile delete form hands the delete to core's batch; no inline delete.
   *
   * The account must still exist after the submit: the batch deletes it after
   * the removal steps.
   */
  public function testProfileDeleteFormQueuesAccountDeleteBatchInsteadOfDeletingInline(): void {
    $this->container->set('soda_scs_manager.user_offboarding', $this->buildService());
    $this->container->get('current_user')->setAccount($this->account);
    $form = SodaScsProfileDeleteForm::create($this->container);
    $formArray = [];
    $formState = new FormState();

    $form->submitForm($formArray, $formState);

    $this->assertNotNull($this->entityTypeManager->getStorage('user')->load($this->account->id()), 'The account is not deleted inline.');
    // Core's first set only holds the title; the delete is in a later one.
    $operations = array_merge([], ...array_column(batch_get()['sets'] ?? [], 'operations'));
    $this->assertCount(1, $operations);
    $this->assertSame('_user_cancel', $operations[0][0]);
    $this->assertSame((int) $this->account->id(), (int) $operations[0][1][1]->id());
    $this->assertSame('user_cancel_delete', $operations[0][1][2]);
    $this->assertSame('<front>', $formState->getRedirect()->getRouteName());
  }

  /**
   * The profile delete form refuses account 1 and queues no batch.
   */
  public function testProfileDeleteFormRefusesAccountOne(): void {
    $this->container->set('soda_scs_manager.user_offboarding', $this->buildService());
    $this->container->get('current_user')->setAccount($this->testUser);
    $form = SodaScsProfileDeleteForm::create($this->container);
    $formArray = [];
    $formState = new FormState();

    $form->submitForm($formArray, $formState);

    $this->assertSame([], array_merge([], ...array_column(batch_get()['sets'] ?? [], 'operations')));
    $this->assertNotNull($this->entityTypeManager->getStorage('user')->load($this->testUser->id()));
  }

  /**
   * Creates an owned demo project, by default of the account under test.
   *
   * @param array<string, mixed> $values
   *   Field values overriding the defaults.
   */
  private function createDemoProject(array $values = []) {
    $values += [
      'owner' => $this->account->id(),
      'demoStatus' => SodaScsDemoProjectStatus::Provisioning->value,
    ];
    return $this->createTestProject($values);
  }

  /**
   * Creates a WissKI stack in the project, by default of the account.
   */
  private function createOwnedStack($project, ?UserInterface $owner = NULL) {
    return $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => ($owner ?? $this->account)->id(),
      'partOfProjects' => [$project->id()],
    ]);
  }

  /**
   * Lets the stack actions double delete the stack entity like the real one.
   *
   * @return object
   *   Holds the ids of the deleted stacks in "ids", in order.
   */
  private function deleteStacksInDouble(): object {
    $deleted = new \stdClass();
    $deleted->ids = [];
    $this->stackActions->method('deleteStack')->willReturnCallback(function (SodaScsStackInterface $stack) use ($deleted): array {
      $deleted->ids[] = (int) $stack->id();
      $stack->delete();
      return ['success' => TRUE];
    });
    return $deleted;
  }

  /**
   * Asserts that a stack still exists.
   */
  private function assertStackExists(int $stackId): void {
    $storage = $this->entityTypeManager->getStorage('soda_scs_stack');
    $storage->resetCache([$stackId]);
    $this->assertNotNull($storage->load($stackId), "Stack $stackId should still exist.");
  }

  /**
   * Asserts that a stack no longer exists.
   */
  private function assertStackGone(int $stackId): void {
    $storage = $this->entityTypeManager->getStorage('soda_scs_stack');
    $storage->resetCache([$stackId]);
    $this->assertNull($storage->load($stackId), "Stack $stackId should be deleted.");
  }

  /**
   * Puts the service double and the logger the static batch step reads.
   */
  private function useOffboardingServices(): void {
    $this->container->set('soda_scs_manager.user_offboarding', $this->buildService());
    $this->container->set('logger.factory', $this->buildLoggerFactory());
  }

  /**
   * Asserts that a project no longer exists.
   */
  private function assertProjectGone(int $projectId): void {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project');
    $storage->resetCache([$projectId]);
    $this->assertNull($storage->load($projectId), "Project $projectId should be deleted.");
  }

  /**
   * Runs the validation of the profile delete form as the account under test.
   */
  private function validateProfileDeleteForm(): FormState {
    $this->container->set('soda_scs_manager.user_offboarding', $this->buildService());
    $this->container->get('current_user')->setAccount($this->account);

    $form = SodaScsProfileDeleteForm::create($this->container);
    $formArray = [];
    $formState = new FormState();
    $form->validateForm($formArray, $formState);
    return $formState;
  }

  /**
   * The error messages the profile delete form sets.
   *
   * @return list<string>
   *   The messages.
   */
  private function profileDeleteFormErrorMessages(): array {
    return array_values(array_map('strval', $this->validateProfileDeleteForm()->getErrors()));
  }

  /**
   * Runs the cancel form validator of the account under test.
   */
  private function validateCancelForm(string $method): FormState {
    $this->container->set('soda_scs_manager.user_offboarding', $this->buildService());

    $formObject = $this->createMock(ContentEntityFormInterface::class);
    $formObject->method('getEntity')->willReturn($this->account);
    $formState = new FormState();
    $formState->setFormObject($formObject);
    $formState->setValue('user_cancel_method', $method);

    $form = [];
    soda_scs_manager_user_cancel_validate_demo_setup($form, $formState);
    return $formState;
  }

  /**
   * Builds the service with doubles for the lock, the logger and the clients.
   *
   * @param bool $failProjectGroupDelete
   *   Whether the Keycloak project group delete throws.
   */
  private function buildService(bool $failProjectGroupDelete = FALSE): SodaScsUserOffboarding {
    $projectHelpers = $this->createMock(SodaScsProjectHelpers::class);
    $projectHelpers->method('deleteProjectGroup')->willReturnCallback(function ($project) use ($failProjectGroupDelete): void {
      $this->lockHeldDuringDelete[(int) $project->id()] = $this->heldLocks !== [];
      if ($failProjectGroupDelete) {
        throw new \RuntimeException('Keycloak group delete failed.');
      }
    });

    $loggerFactory = $this->buildLoggerFactory();
    return new SodaScsUserOffboarding(
      $this->componentActions,
      $this->container->get('soda_scs_manager.demo.manager'),
      $this->entityTypeManager,
      $this->buildLock(),
      $this->buildPersistentLock(),
      $loggerFactory,
      $this->container->get('soda_scs_manager.project_membership.helpers'),
      $this->createMock(SodaScsNextcloudHelpers::class),
      $this->createMock(NextcloudMountManager::class),
      $this->createMock(NextcloudProjectMountManager::class),
      $this->createMock(SodaScsOpenGdbServiceActions::class),
      $this->buildApplicationRemover($loggerFactory),
      $projectHelpers,
      $this->createMock(SodaScsServiceHelpers::class),
      $this->createMock(SodaScsSqlServiceActions::class),
      $this->stackActions,
      $this->createMock(SodaScsKeycloakServiceUserActions::class),
      $this->buildWebprotegeHelpers(),
    );
  }

  /**
   * The application remover on the real storage and the action doubles.
   */
  private function buildApplicationRemover(LoggerChannelFactoryInterface $loggerFactory): SodaScsProjectApplicationRemover {
    return new SodaScsProjectApplicationRemover(
      $this->componentActions,
      $this->entityTypeManager,
      $loggerFactory->get('soda_scs_manager'),
      $this->stackActions,
    );
  }

  /**
   * A lock backend that records its calls and refuses the busy names.
   */
  private function buildLock(): LockBackendInterface {
    $lock = $this->createMock(LockBackendInterface::class);
    // Both backends share one table, so the persistent locks count here too.
    $lock->method('lockMayBeAvailable')->willReturnCallback(
      fn (string $name): bool => !in_array($name, $this->busyLocks, TRUE)
        && !in_array($name, $this->heldLocks, TRUE)
        && !in_array($name, $this->heldPersistentLocks, TRUE)
    );
    $lock->method('acquire')->willReturnCallback(function (string $name, float $timeout = 30.0): bool {
      $this->lockCalls[] = sprintf('acquire %s %d', $name, $timeout);
      if (in_array($name, $this->busyLocks, TRUE)) {
        return FALSE;
      }
      $this->heldLocks[] = $name;
      if ($this->onAcquire !== NULL) {
        ($this->onAcquire)();
      }
      return TRUE;
    });
    $lock->method('release')->willReturnCallback(function (string $name): void {
      $this->lockCalls[] = 'release ' . $name;
      $this->heldLocks = array_values(array_diff($this->heldLocks, [$name]));
    });
    return $lock;
  }

  /**
   * A persistent lock backend that records its calls, like buildLock().
   */
  private function buildPersistentLock(): LockBackendInterface {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(function (string $name, float $timeout = 30.0): bool {
      $this->persistentLockCalls[] = sprintf('acquire %s %d', $name, $timeout);
      if (in_array($name, $this->busyLocks, TRUE) || in_array($name, $this->heldPersistentLocks, TRUE)) {
        return FALSE;
      }
      $this->heldPersistentLocks[] = $name;
      return TRUE;
    });
    $lock->method('release')->willReturnCallback(function (string $name): void {
      $this->persistentLockCalls[] = 'release ' . $name;
      $this->heldPersistentLocks = array_values(array_diff($this->heldPersistentLocks, [$name]));
    });
    return $lock;
  }

  /**
   * WebProtégé helpers that record which ontology project would be trashed.
   */
  private function buildWebprotegeHelpers(): SodaScsWebprotegeHelpers {
    $helpers = $this->createMock(SodaScsWebprotegeHelpers::class);
    $helpers->method('trashOntologyProject')->willReturnCallback(function ($project): SodaScsResult {
      $this->trashedWebprotegeIds[] = $project->get('webprotegeProjectId')->value;
      return SodaScsResult::success(data: ['removed' => TRUE], message: 'Trashed.');
    });
    return $helpers;
  }

  /**
   * A logger factory whose channel collects the warnings.
   */
  private function buildLoggerFactory(): LoggerChannelFactoryInterface {
    $channel = $this->createMock(LoggerChannelInterface::class);
    $channel->method('warning')->willReturnCallback(function ($message, array $context = []): void {
      $this->warnings[] = (string) $message;
    });
    $channel->method('error')->willReturnCallback(function ($message, array $context = []): void {
      $this->errors[] = (string) $message;
    });
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($channel);
    return $factory;
  }

}
