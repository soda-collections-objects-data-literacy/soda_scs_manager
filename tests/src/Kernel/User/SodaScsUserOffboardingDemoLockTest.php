<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\User;

use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
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
 * Account delete and the demo job lock of a demo project.
 *
 * The delete forms refuse while the job of an owned demo project holds its
 * project lock; the purge of the account takes that lock around the delete
 * of a demo project, and goes on without it when it is busy. No remote
 * service is reached: every client the purge calls is a double.
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
   * An owned demo project whose lock is busy is listed as in setup.
   */
  public function testDemoProjectsInSetupListsOwnedDemoProjectWithBusyLock(): void {
    $demo = $this->createDemoProject();
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $demo->id();

    $listed = $this->buildService()->demoProjectsInSetup($this->account);

    $this->assertSame([$demo->id()], array_map(static fn ($project) => $project->id(), $listed));
  }

  /**
   * A demo project whose lock is free is not in setup.
   */
  public function testDemoProjectsInSetupIgnoresDemoProjectWithFreeLock(): void {
    $this->createDemoProject();

    $this->assertSame([], $this->buildService()->demoProjectsInSetup($this->account));
  }

  /**
   * A project without demo status is never in setup, whatever its lock says.
   */
  public function testDemoProjectsInSetupIgnoresNonDemoProject(): void {
    $project = $this->createTestProject(['owner' => $this->account->id()]);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $project->id();

    $this->assertSame([], $this->buildService()->demoProjectsInSetup($this->account));
  }

  /**
   * Only the demo projects the user owns count, not those of someone else.
   */
  public function testDemoProjectsInSetupIgnoresDemoProjectOfAnotherOwner(): void {
    $other = $this->createTestUser();
    $foreign = $this->createDemoProject(['owner' => $other->id(), 'members' => [$this->account->id()]]);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $foreign->id();

    $this->assertSame([], $this->buildService()->demoProjectsInSetup($this->account));
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
   * The profile delete form refuses while a demo project is in setup.
   */
  public function testProfileDeleteFormRefusesWhileDemoProjectIsInSetup(): void {
    $demo = $this->createDemoProject(['label' => 'Setup demo']);
    $this->busyLocks[] = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $demo->id();

    $formState = $this->validateProfileDeleteForm();

    $errors = array_map('strval', $formState->getErrors());
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('Setup demo', reset($errors));
    $this->assertStringContainsString('demo setup is still running', reset($errors));
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
    $lock->method('lockMayBeAvailable')->willReturnCallback(
      fn (string $name): bool => !in_array($name, $this->busyLocks, TRUE) && !in_array($name, $this->heldLocks, TRUE)
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
