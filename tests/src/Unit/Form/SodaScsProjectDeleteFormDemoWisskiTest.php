<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\EventSubscriber\RedirectResponseSubscriber;
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\soda_scs_manager\Service\SodaScsProjectApplicationRemover;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * What deleting a project does with its WissKI stacks.
 *
 * The form hands the applications to the application remover (real here, on
 * doubles of the entity storage and of the stack and component actions): the
 * WissKI stacks of the project owner are deleted through the WissKI stack
 * actions, demo or not; any failure stops the deletion before anything else,
 * members included, is removed. The form runs the deletion as a batch; the
 * tests run its operations synchronously (RecordingProjectDeleteForm), so a
 * failure shows in the messenger and in the finish callback instead of in the
 * form state.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Form\SodaScsProjectDeleteForm
 * @group soda_scs_manager
 */
class SodaScsProjectDeleteFormDemoWisskiTest extends UnitTestCase {

  /**
   * Whether the project counts as a demo project.
   */
  private bool $isDemo = TRUE;

  /**
   * The WissKI stacks the project lists, or an exception to throw.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[]|\Throwable
   */
  private array|\Throwable $wisskiStacks = [];

  /**
   * Listed members of the project other than the owner.
   *
   * @var \Drupal\user\UserInterface[]
   */
  private array $members = [];

  /**
   * The WissKI stack actions double.
   */
  private SodaScsStackActionsInterface $wisskiStackActions;

  /**
   * The Jupyter stack actions double.
   */
  private SodaScsStackActionsInterface $jupyterStackActions;

  /**
   * The project helpers double (Team Folder, Keycloak group).
   */
  private SodaScsProjectHelpers $projectHelpers;

  /**
   * The WebProtégé helpers double.
   */
  private SodaScsWebprotegeHelpers $webprotegeHelpers;

  /**
   * The Nextcloud mount manager double.
   */
  private NextcloudProjectMountManager $mountManager;

  /**
   * The project application helpers double.
   */
  private SodaScsProjectApplicationHelpers $applicationHelpers;

  /**
   * The messenger double.
   */
  private MessengerInterface $messenger;

  /**
   * The logger channel double.
   */
  private LoggerChannelInterface $logger;

  /**
   * The lock double.
   */
  private LockBackendInterface $lock;

  /**
   * Whether the lock can be acquired; FALSE when a demo job holds it.
   */
  private bool $isLockFree = TRUE;

  /**
   * Lock calls in order, as "acquire:<name>:<timeout>" or "release:<name>".
   *
   * @var list<string>
   */
  private array $lockCalls = [];

  /**
   * Whether the project is gone once the lock is held.
   */
  private bool $isProjectGone = FALSE;

  /**
   * Ids of the WissKI stacks that were deleted; the storage forgets them.
   *
   * @var list<int>
   */
  private array $removedStackIds = [];

  /**
   * Whether a stack stays in the storage although its deletion succeeded.
   */
  private bool $staysAfterDeletion = FALSE;

  /**
   * The redirect subscriber double.
   */
  private RedirectResponseSubscriber $redirectResponseSubscriber;

  /**
   * Ids the project storage reloaded with loadUnchanged().
   *
   * @var list<int|string>
   */
  private array $reloadedIds = [];

  /**
   * The form under test, set by submit() and buildForm().
   */
  private RecordingProjectDeleteForm $form;

  /**
   * How often the WissKI stacks of the project were looked up.
   */
  private int $wisskiLookupCount = 0;

  /**
   * The project being deleted.
   */
  private SodaScsProjectInterface $project;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->wisskiStackActions = $this->createMock(SodaScsStackActionsInterface::class);
    $this->jupyterStackActions = $this->createMock(SodaScsStackActionsInterface::class);
    $this->projectHelpers = $this->createMock(SodaScsProjectHelpers::class);
    $this->webprotegeHelpers = $this->createMock(SodaScsWebprotegeHelpers::class);
    $this->mountManager = $this->createMock(NextcloudProjectMountManager::class);
    $this->applicationHelpers = $this->createMock(SodaScsProjectApplicationHelpers::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->isLockFree = TRUE;
    $this->lockCalls = [];
    $this->reloadedIds = [];
    $this->redirectResponseSubscriber = $this->createMock(RedirectResponseSubscriber::class);
    $this->wisskiLookupCount = 0;
    $this->removedStackIds = [];
    $this->staysAfterDeletion = FALSE;
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->lock->method('acquire')->willReturnCallback(function (string $name, float $timeout = 30.0): bool {
      $this->lockCalls[] = "acquire:$name:$timeout";
      return $this->isLockFree;
    });
    $this->lock->method('release')->willReturnCallback(function (string $name): void {
      $this->lockCalls[] = "release:$name";
    });

    $this->projectHelpers->method('deleteProjectTeamFolder')->willReturn(SodaScsResult::success([], ''));
    $this->webprotegeHelpers->method('trashOntologyProject')->willReturn(SodaScsResult::success([], ''));
    $this->applicationHelpers->method('loadProjectApplications')->willReturn([]);

    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('id')->willReturn(3);
    $project->method('label')->willReturn('Demo');
    $project->method('getOwnerId')->willReturn(1);
    $project->method('hasField')->willReturnCallback(fn(string $field): bool => $field === 'members' && $this->members !== []);
    $project->method('get')->willReturnCallback(function (): EntityReferenceFieldItemListInterface {
      $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
      $field->method('isEmpty')->willReturn($this->members === []);
      $field->method('referencedEntities')->willReturn($this->members);
      return $field;
    });
    $this->project = $project;

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->logger);
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Every WissKI stack of a demo project is deleted, in id order.
   *
   * @covers ::deleteProject
   * @covers ::submitForm
   */
  public function testDemoProjectDeletesEveryWisskiStack(): void {
    $first = $this->stack(11);
    $second = $this->stack(12);
    $this->wisskiStacks = [$first, $second];
    $deleted = [];
    $this->wisskiStackActions->expects($this->exactly(2))->method('deleteStack')
      ->willReturnCallback(function (SodaScsStackInterface $stack) use (&$deleted): array {
        $deleted[] = $stack;
        return ['success' => TRUE];
      });
    $this->project->expects($this->once())->method('delete');

    $formState = new FormState();
    $this->submit($formState);

    $this->assertSame([$first, $second], $deleted);
    $this->assertNotFalse($formState->getRedirect());
    $this->assertFalse($formState->isRebuilding());
    $this->assertNull($this->form->finishResult, 'The deleted project leaves the dashboard redirect.');
    $this->assertSame(0, $this->form->projectPageRedirectCount);
  }

  /**
   * The WissKI stacks go before the Team Folder, the group and the project.
   *
   * @covers ::submitForm
   */
  public function testDemoProjectDeletesWisskiStacksBeforeTheRest(): void {
    $this->wisskiStacks = [$this->stack(11)];
    $calls = [];
    $this->wisskiStackActions->method('deleteStack')->willReturnCallback(function () use (&$calls): array {
      $calls[] = 'wisski';
      return ['success' => TRUE];
    });
    $this->mountManager->method('ensureProjectUnmounted')->willReturnCallback(function () use (&$calls): void {
      $calls[] = 'unmount';
    });
    $this->project->method('delete')->willReturnCallback(function () use (&$calls): void {
      $calls[] = 'project';
    });

    $this->submit(new FormState());

    $this->assertSame(['wisski', 'unmount', 'project'], $calls);
  }

  /**
   * A demo project without a WissKI stack is deleted as usual.
   *
   * @covers ::deleteProject
   */
  public function testDemoProjectWithoutWisskiStackIsDeleted(): void {
    $this->wisskiStacks = [];
    $this->wisskiStackActions->expects($this->never())->method('deleteStack');
    $this->project->expects($this->once())->method('delete');

    $this->submit(new FormState());
  }

  /**
   * A failed stack deletion stops the project deletion and keeps the rest.
   *
   * @covers ::deleteProject
   */
  public function testFailedWisskiStackDeletionStopsProjectDeletion(): void {
    $first = $this->stack(11);
    $second = $this->stack(12);
    $third = $this->stack(13);
    $this->wisskiStacks = [$first, $second, $third];
    $attempted = [];
    $this->wisskiStackActions->method('deleteStack')
      ->willReturnCallback(function (SodaScsStackInterface $stack) use (&$attempted, $second): array {
        $attempted[] = $stack->id();
        return $stack === $second
          ? ['success' => FALSE, 'error' => 'Portainer answered 500.']
          : ['success' => TRUE];
      });
    $this->expectNothingElseDeleted();
    $this->logger->expects($this->once())->method('error')
      ->with($this->stringContains('Could not remove @type @id from project'), $this->callback(
        fn(array $context): bool => $context['@id'] === 12
          && $context['@type'] === 'soda_scs_stack'
          && $context['@project'] === 3
          && $context['@message'] === 'Portainer answered 500.'
      ));
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => str_contains((string) $message, 'Could not delete the application Stack 12 of project Demo. The project was not deleted.')));

    $formState = new FormState();
    $this->submit($formState);

    $this->assertSame([11, 12], $attempted, 'The stack after the failed one is not tried.');
    $this->assertSame(1, $this->form->projectPageRedirectCount, 'The user lands on the project page.');
    $this->assertInstanceOf(RedirectResponse::class, $this->form->finishResult);
  }

  /**
   * Members stay on the project when its WissKI cannot be deleted.
   *
   * The membership helpers are an uninitialized instance of a final class:
   * detachMember() would throw on it, so this test also fails when a member
   * is detached.
   *
   * @covers ::submitForm
   */
  public function testFailedWisskiStackDeletionKeepsMembers(): void {
    $member = $this->createMock(UserInterface::class);
    $member->method('id')->willReturn(7);
    $member->method('getDisplayName')->willReturn('member');
    $this->members = [$member];
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->expects($this->once())->method('deleteStack')
      ->willReturn(['success' => FALSE, 'error' => 'Portainer answered 500.']);
    $this->expectNothingElseDeleted();

    $formState = new FormState();
    $formState->setValue('remove_members', '1');
    $this->submit($formState);

    $this->assertInstanceOf(RedirectResponse::class, $this->form->finishResult);
  }

  /**
   * Without the confirmation, members refuse the deletion before the WissKI.
   *
   * @covers ::membersMayBeRemoved
   * @covers ::submitForm
   */
  public function testMembersWithoutConfirmationStopBeforeWisskiDeletion(): void {
    $member = $this->createMock(UserInterface::class);
    $member->method('id')->willReturn(7);
    $member->method('getDisplayName')->willReturn('member');
    $this->members = [$member];
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->expects($this->never())->method('deleteStack');
    $this->expectNothingElseDeleted();
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => str_contains((string) $message, 'still has members')));

    $formState = new FormState();
    $this->submit($formState);

    $this->assertTrue($formState->isRebuilding());
    $this->assertFalse($formState->getRedirect());
    $this->assertNull($this->form->batch, 'No batch starts.');
    $this->assertSame([], $this->lockCalls, 'The demo lock is not taken.');
  }

  /**
   * An answer without a success flag counts as a failure.
   *
   * @covers ::deleteProject
   */
  public function testMalformedStackDeletionAnswerStopsProjectDeletion(): void {
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->method('deleteStack')->willReturn([]);
    $this->expectNothingElseDeleted();
    $this->messenger->expects($this->once())->method('addError');

    $this->submit(new FormState());

    $this->assertSame(1, $this->form->projectPageRedirectCount);
  }

  /**
   * A failed lookup of the applications deletes nothing and frees the lock.
   *
   * The user gets the message instead of an error page; the project, its
   * members and the services stay as they were.
   *
   * @covers ::deleteProject
   */
  public function testFailedApplicationLookupDeletesNothing(): void {
    $this->wisskiStacks = new \RuntimeException('Query failed.');
    $this->wisskiStackActions->expects($this->never())->method('deleteStack');
    $this->expectNothingElseDeleted();
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => $message instanceof TranslatableMarkup
        && str_contains((string) $message, 'The project was not deleted.')));

    $this->submit(new FormState());

    $this->assertSame(1, $this->form->projectPageRedirectCount);
    $this->assertSame(
      ['acquire:soda_scs_manager.demo.project.3:3600', 'release:soda_scs_manager.demo.project.3'],
      $this->lockCalls,
    );
  }

  /**
   * A regular project deletes the WissKI stacks of its owner as well.
   *
   * @covers ::deleteProject
   */
  public function testRegularProjectDeletesItsWisskiStacks(): void {
    $this->isDemo = FALSE;
    $stack = $this->stack(11);
    $this->wisskiStacks = [$stack];
    $this->wisskiStackActions->expects($this->once())->method('deleteStack')
      ->with($stack)
      ->willReturn(['success' => TRUE]);
    $this->project->expects($this->once())->method('delete');

    $formState = new FormState();
    $this->submit($formState);

    $this->assertNotFalse($formState->getRedirect());
  }

  /**
   * A regular project whose WissKI stack cannot be deleted stays.
   *
   * @covers ::deleteProject
   */
  public function testRegularProjectStaysWhenWisskiStackCannotBeDeleted(): void {
    $this->isDemo = FALSE;
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->expects($this->once())->method('deleteStack')
      ->willReturn(['success' => FALSE, 'error' => 'Portainer answered 500.']);
    $this->expectNothingElseDeleted();
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => str_contains((string) $message, 'The project was not deleted.')));

    $this->submit(new FormState());

    $this->assertSame(1, $this->form->projectPageRedirectCount);
  }

  /**
   * A demo project is deleted while its lock is held, the lock then goes.
   *
   * @covers ::submitForm
   */
  public function testDemoProjectDeleteTakesAndReleasesItsLock(): void {
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->method('deleteStack')->willReturnCallback(function (): array {
      $this->lockCalls[] = 'wisski';
      return ['success' => TRUE];
    });
    $this->project->method('delete')->willReturnCallback(function (): void {
      $this->lockCalls[] = 'project';
    });
    $lockName = SodaScsDemoManager::PROJECT_LOCK_PREFIX . '3';

    $this->submit(new FormState());

    $this->assertSame([
      "acquire:$lockName:3600",
      'wisski',
      'project',
      "release:$lockName",
    ], $this->lockCalls);
  }

  /**
   * A demo project whose job holds the lock is neither looked up nor deleted.
   *
   * @covers ::submitForm
   */
  public function testDemoProjectHeldByRunningSetupIsNotDeleted(): void {
    $this->isLockFree = FALSE;
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->expects($this->never())->method('deleteStack');
    $this->expectNothingElseDeleted();
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => (string) $message === 'The project Demo is being set up or deleted right now. Try again when that is done.'));
    $this->messenger->expects($this->never())->method('addStatus');

    $formState = new FormState();
    $this->submit($formState);

    $this->assertSame(0, $this->wisskiLookupCount, 'The WissKI stacks are not looked up.');
    $this->assertNull($this->form->batch, 'No batch starts.');
    $this->assertTrue($formState->isRebuilding());
    $this->assertFalse($formState->getRedirect());
    $this->assertCount(1, $this->lockCalls, 'A lock held by another process is not released.');
    $this->assertStringStartsWith('acquire:', $this->lockCalls[0]);
  }

  /**
   * The lock is released when a WissKI stack cannot be deleted.
   *
   * @covers ::submitForm
   */
  public function testDemoProjectLockIsReleasedWhenWisskiDeletionFails(): void {
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->method('deleteStack')->willReturn([
      'success' => FALSE,
      'error' => 'Portainer answered 500.',
    ]);
    $this->expectNothingElseDeleted();

    $this->submit(new FormState());

    $this->assertSame(
      ['acquire:soda_scs_manager.demo.project.3:3600', 'release:soda_scs_manager.demo.project.3'],
      $this->lockCalls,
    );
  }

  /**
   * The lock is released and the user told when the deletion throws.
   *
   * The Batch API ends the batch and runs the finish callback with a failed
   * state then.
   *
   * @covers ::finishDeleteBatch
   */
  public function testDemoProjectLockIsReleasedWhenDeleteThrows(): void {
    $this->project->method('delete')->willThrowException(new \RuntimeException('Database is gone.'));
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => str_contains((string) $message, 'could not be deleted. Some of its applications may already be deleted; try again.')));
    $this->messenger->expects($this->never())->method('addStatus');

    $this->submit(new FormState());

    $this->assertSame(1, $this->form->projectPageRedirectCount);
    $this->assertSame(
      ['acquire:soda_scs_manager.demo.project.3:3600', 'release:soda_scs_manager.demo.project.3'],
      $this->lockCalls,
    );
  }

  /**
   * A demo project that is gone once the lock is held is not deleted again.
   *
   * @covers ::submitForm
   */
  public function testDemoProjectGoneAfterLockIsNotDeletedAgain(): void {
    $this->isProjectGone = TRUE;
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->expects($this->never())->method('deleteStack');
    $this->expectNothingElseDeleted();
    $this->messenger->expects($this->never())->method('addError');

    $formState = new FormState();
    $this->submit($formState);

    $this->assertSame(0, $this->wisskiLookupCount, 'The WissKI stacks are not looked up.');
    $this->assertNull($this->form->batch, 'No batch starts.');
    $this->assertNotFalse($formState->getRedirect());
    $this->assertSame(
      ['acquire:soda_scs_manager.demo.project.3:3600', 'release:soda_scs_manager.demo.project.3'],
      $this->lockCalls,
    );
  }

  /**
   * A regular project is deleted under the project lock as well.
   *
   * @covers ::submitForm
   */
  public function testRegularProjectTakesAndReleasesTheLock(): void {
    $this->isDemo = FALSE;
    $this->project->expects($this->once())->method('delete');
    $lockName = SodaScsDemoManager::PROJECT_LOCK_PREFIX . '3';

    $formState = new FormState();
    $this->submit($formState);

    $this->assertSame(["acquire:$lockName:3600", "release:$lockName"], $this->lockCalls);
    $this->assertNotFalse($formState->getRedirect());
  }

  /**
   * A regular project that is locked (a second delete) is not deleted.
   *
   * @covers ::submitForm
   */
  public function testRegularProjectHeldByAnotherDeleteIsNotDeleted(): void {
    $this->isDemo = FALSE;
    $this->isLockFree = FALSE;
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->expects($this->never())->method('deleteStack');
    $this->expectNothingElseDeleted();
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => (string) $message === 'The project Demo is being set up or deleted right now. Try again when that is done.'));

    $formState = new FormState();
    $this->submit($formState);

    $this->assertNull($this->form->batch, 'No batch starts.');
    $this->assertTrue($formState->isRebuilding());
    $this->assertFalse($formState->getRedirect());
    $this->assertCount(1, $this->lockCalls, 'A lock held by another process is not released.');
  }

  /**
   * A regular project that is gone once the lock is held is not deleted again.
   *
   * @covers ::submitForm
   */
  public function testRegularProjectGoneAfterLockIsNotDeletedAgain(): void {
    $this->isDemo = FALSE;
    $this->isProjectGone = TRUE;
    $this->expectNothingElseDeleted();
    $this->messenger->expects($this->never())->method('addError');
    $this->messenger->expects($this->once())->method('addStatus');

    $formState = new FormState();
    $this->submit($formState);

    $this->assertNull($this->form->batch, 'No batch starts.');
    $this->assertSame([3], $this->reloadedIds);
    $this->assertSame(
      ['acquire:soda_scs_manager.demo.project.3:3600', 'release:soda_scs_manager.demo.project.3'],
      $this->lockCalls,
    );
  }

  /**
   * The lock of a regular project is released when its batch fails.
   *
   * @covers ::finishDeleteBatch
   */
  public function testRegularProjectLockIsReleasedWhenWisskiDeletionFails(): void {
    $this->isDemo = FALSE;
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->method('deleteStack')->willReturn([
      'success' => FALSE,
      'error' => 'Portainer answered 500.',
    ]);
    $this->expectNothingElseDeleted();

    $this->submit(new FormState());

    $this->assertSame(
      ['acquire:soda_scs_manager.demo.project.3:3600', 'release:soda_scs_manager.demo.project.3'],
      $this->lockCalls,
    );
  }

  /**
   * Submitting only hands the batch over; nothing is deleted before it runs.
   *
   * @covers ::submitForm
   */
  public function testSubmitOnlyStartsTheBatch(): void {
    $this->isDemo = FALSE;
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->expects($this->never())->method('deleteStack');
    $this->expectNothingElseDeleted();

    $formState = new FormState();
    $this->submit($formState, FALSE);

    $this->assertSame('soda_scs_manager.dashboard', $formState->getRedirect()->getRouteName());
    $this->assertFalse($formState->isRebuilding());
    $this->assertCount(2, $this->form->batch['operations']);
    $this->assertSame('removeApplicationsStep', $this->form->batch['operations'][0][0][1]);
    $this->assertSame([], $this->form->batch['operations'][0][1]);
    $this->assertSame('deleteProjectStep', $this->form->batch['operations'][1][0][1]);
    $this->assertSame([FALSE], $this->form->batch['operations'][1][1], 'Members are not confirmed.');
    $this->assertFalse($this->form->hasFinished);
  }

  /**
   * The confirmation of the member removal reaches the delete step.
   *
   * @covers ::submitForm
   */
  public function testSubmitPassesTheMemberConfirmationToTheDeleteStep(): void {
    $this->isDemo = FALSE;
    $formState = new FormState();
    $formState->setValue('remove_members', '1');

    $this->submit($formState, FALSE);

    $this->assertSame([TRUE], $this->form->batch['operations'][1][1]);
  }

  /**
   * A demo project keeps its lock after the submit, until the batch ends.
   *
   * @covers ::submitForm
   */
  public function testDemoLockIsHeldUntilTheBatchFinishes(): void {
    $this->submit(new FormState(), FALSE);

    $this->assertSame(['acquire:soda_scs_manager.demo.project.3:3600'], $this->lockCalls);

    $this->form->finishDeleteBatch(TRUE, ['deleted' => TRUE]);

    $this->assertSame(
      ['acquire:soda_scs_manager.demo.project.3:3600', 'release:soda_scs_manager.demo.project.3'],
      $this->lockCalls,
    );
  }

  /**
   * One application is removed per call of the step; the step asks for more.
   *
   * @covers ::removeApplicationsStep
   */
  public function testApplicationsStepRemovesOneApplicationPerCall(): void {
    $first = $this->stack(11);
    $second = $this->stack(12);
    $this->wisskiStacks = [$first, $second];
    $deleted = [];
    $this->wisskiStackActions->method('deleteStack')
      ->willReturnCallback(function (SodaScsStackInterface $stack) use (&$deleted): array {
        $deleted[] = $stack->id();
        return ['success' => TRUE];
      });
    $this->buildForm();

    $context = [];
    $this->form->removeApplicationsStep($context);
    $this->assertSame([11], $deleted);
    $this->assertSame(0.5, $context['finished']);
    $this->assertSame('Removed Stack 11.', (string) $context['message']);

    $this->form->removeApplicationsStep($context);
    $this->assertSame([11, 12], $deleted);
    $this->assertSame(0.5, $context['finished']);

    $this->form->removeApplicationsStep($context);
    $this->assertSame([11, 12], $deleted, 'Nothing is left to delete.');
    $this->assertSame(1, $context['finished']);
    $this->assertArrayNotHasKey('results', $context);
  }

  /**
   * A failed removal ends the step and is passed on as the batch failure.
   *
   * @covers ::removeApplicationsStep
   */
  public function testApplicationsStepReportsFailureAndFinishes(): void {
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->method('deleteStack')->willReturn([
      'success' => FALSE,
      'error' => 'Portainer answered 500.',
    ]);
    $this->buildForm();

    $context = [];
    $this->form->removeApplicationsStep($context);

    $this->assertSame(1, $context['finished']);
    $this->assertStringContainsString('Could not delete the application Stack 11 of project Demo.', (string) $context['results']['failure']);
  }

  /**
   * A stack that is reported as removed but stays does not loop forever.
   *
   * @covers ::removeApplicationsStep
   */
  public function testApplicationsStepGivesUpAfterTooManySteps(): void {
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->expects($this->never())->method('deleteStack');
    $this->logger->expects($this->once())->method('error')
      ->with($this->stringContains('Gave up deleting the applications'), $this->callback(
        fn(array $context): bool => $context['@project'] === 3 && $context['@steps'] === 200
      ));
    $this->buildForm();

    $context = ['sandbox' => ['steps' => 200]];
    $this->form->removeApplicationsStep($context);

    $this->assertSame(1, $context['finished']);
    $this->assertStringContainsString('The project was not deleted.', (string) $context['results']['failure']);
  }

  /**
   * A stack that stays although its deletion succeeded ends the batch.
   *
   * The repeat is only seen after the removal call that returns it, so the
   * stack's deletion is called twice and then the batch stops: not once per
   * step until the step limit.
   *
   * @covers ::removeApplicationsStep
   * @covers ::submitForm
   */
  public function testBatchEndsWhenTheStackStaysAfterItsDeletion(): void {
    $this->isDemo = FALSE;
    $this->wisskiStacks = [$this->stack(11)];
    $this->staysAfterDeletion = TRUE;
    $this->wisskiStackActions->expects($this->exactly(2))->method('deleteStack')->willReturn(['success' => TRUE]);
    $this->expectNothingElseDeleted();
    $this->logger->expects($this->once())->method('error')
      ->with($this->stringContains('is still there after it was removed'), $this->callback(
        fn(array $context): bool => $context['@key'] === 'soda_scs_stack:11' && $context['@project'] === 3
      ));
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => (string) $message === 'Could not delete the application Stack 11 of project Demo. The project was not deleted.'));

    $this->submit(new FormState());

    $this->assertSame(1, $this->form->projectPageRedirectCount);
    $this->assertSame(['acquire:soda_scs_manager.demo.project.3:3600', 'release:soda_scs_manager.demo.project.3'], $this->lockCalls);
  }

  /**
   * Different applications are not mistaken for a repeat.
   *
   * @covers ::removeApplicationsStep
   */
  public function testApplicationsStepAcceptsDifferentApplications(): void {
    $this->wisskiStacks = [$this->stack(11), $this->stack(12)];
    $this->logger->expects($this->never())->method('error');
    $this->wisskiStackActions->expects($this->exactly(2))->method('deleteStack')->willReturn(['success' => TRUE]);
    $this->buildForm();

    $context = [];
    $this->form->removeApplicationsStep($context);
    $this->form->removeApplicationsStep($context);

    $this->assertSame(0.5, $context['finished']);
    $this->assertArrayNotHasKey('results', $context);
    $this->assertSame(['soda_scs_stack:11', 'soda_scs_stack:12'], $context['sandbox']['removed']);
  }

  /**
   * The delete step does nothing after a failed removal.
   *
   * @covers ::deleteProjectStep
   */
  public function testDeleteStepIsSkippedAfterFailure(): void {
    $this->expectNothingElseDeleted();
    $this->buildForm();

    $context = ['results' => ['failure' => 'Stack 11 stays.']];
    $this->form->deleteProjectStep(TRUE, $context);

    $this->assertSame(['failure' => 'Stack 11 stays.'], $context['results']);
  }

  /**
   * The delete step deletes the project and records it.
   *
   * @covers ::deleteProjectStep
   */
  public function testDeleteStepDeletesTheProject(): void {
    $this->project->expects($this->once())->method('delete');
    $this->buildForm();

    $context = [];
    $this->form->deleteProjectStep(FALSE, $context);

    $this->assertTrue($context['results']['deleted']);
    $this->assertSame([3], $this->reloadedIds, 'The project is reloaded for the step.');
  }

  /**
   * A project that is gone when the delete step runs counts as deleted.
   *
   * @covers ::deleteProjectStep
   */
  public function testDeleteStepReportsDeletedWhenTheProjectIsGone(): void {
    $this->isProjectGone = TRUE;
    $this->expectNothingElseDeleted();
    $this->messenger->expects($this->never())->method('addError');
    $this->buildForm();

    $context = [];
    $this->form->deleteProjectStep(FALSE, $context);

    $this->assertTrue($context['results']['deleted']);
    $this->assertSame([3], $this->reloadedIds);
  }

  /**
   * The delete step does not reload the project after a failed removal.
   *
   * @covers ::deleteProjectStep
   */
  public function testDeleteStepDoesNotReloadAfterFailure(): void {
    $this->buildForm();

    $context = ['results' => ['failure' => 'Stack 11 stays.']];
    $this->form->deleteProjectStep(FALSE, $context);

    $this->assertSame([], $this->reloadedIds);
  }

  /**
   * An application that fails in the final pass keeps the project.
   *
   * The final pass of the delete step catches an application added while the
   * batch ran.
   *
   * @covers ::deleteProjectStep
   */
  public function testDeleteStepKeepsProjectWhenTheLateApplicationCannotBeRemoved(): void {
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->method('deleteStack')->willReturn([
      'success' => FALSE,
      'error' => 'Portainer answered 500.',
    ]);
    $this->expectNothingElseDeleted();
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => str_contains((string) $message, 'The project was not deleted.')));
    $this->buildForm();

    $context = [];
    $this->form->deleteProjectStep(FALSE, $context);

    $this->assertFalse($context['results']['deleted']);
  }

  /**
   * Members that are not confirmed keep the project in the delete step.
   *
   * @covers ::deleteProjectStep
   */
  public function testDeleteStepKeepsProjectWhenMembersAreNotConfirmed(): void {
    $member = $this->createMock(UserInterface::class);
    $member->method('id')->willReturn(7);
    $member->method('getDisplayName')->willReturn('member');
    $this->members = [$member];
    $this->expectNothingElseDeleted();
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => str_contains((string) $message, 'still has members')));
    $this->buildForm();

    $context = [];
    $this->form->deleteProjectStep(FALSE, $context);

    $this->assertFalse($context['results']['deleted']);
  }

  /**
   * An aborted batch tells the user and returns to the project page.
   *
   * @covers ::finishDeleteBatch
   */
  public function testFinishAfterAbortedBatchReportsErrorAndReturnsToProject(): void {
    $this->isDemo = FALSE;
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => str_contains((string) $message, 'The project Demo could not be deleted.')));
    $this->buildForm();

    $result = $this->form->finishDeleteBatch(FALSE, []);

    $this->assertInstanceOf(RedirectResponse::class, $result);
    $this->assertSame(1, $this->form->projectPageRedirectCount);
  }

  /**
   * A failure of a step is shown once, without the generic error.
   *
   * @covers ::finishDeleteBatch
   */
  public function testFinishShowsTheStepFailureOnly(): void {
    $this->messenger->expects($this->once())->method('addError')->with('Stack 11 stays.');
    $this->buildForm();

    $result = $this->form->finishDeleteBatch(TRUE, ['failure' => 'Stack 11 stays.']);

    $this->assertInstanceOf(RedirectResponse::class, $result);
  }

  /**
   * A finished batch whose project is gone leaves the dashboard redirect.
   *
   * @covers ::finishDeleteBatch
   */
  public function testFinishAfterDeletionReturnsNothing(): void {
    $this->messenger->expects($this->never())->method('addError');
    $this->buildForm();

    $this->assertNull($this->form->finishDeleteBatch(TRUE, ['deleted' => TRUE]));
    $this->assertSame(0, $this->form->projectPageRedirectCount);
  }

  /**
   * The failure redirect lets the project page win over the link's destination.
   *
   * @covers ::projectPageRedirect
   */
  public function testProjectPageRedirectIgnoresTheDestination(): void {
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('/project/3');
    $this->project->method('toUrl')->willReturn($url);
    $this->redirectResponseSubscriber->expects($this->once())->method('setIgnoreDestination');
    $this->buildForm()->usesRealProjectPageRedirect = TRUE;

    $result = $this->form->finishDeleteBatch(TRUE, ['failure' => 'Stack 11 stays.']);

    $this->assertInstanceOf(RedirectResponse::class, $result);
    $this->assertSame('/project/3', $result->getTargetUrl());
  }

  /**
   * A deleted project leaves the destination of the delete link alone.
   *
   * @covers ::finishDeleteBatch
   */
  public function testDeletedProjectDoesNotIgnoreTheDestination(): void {
    $this->redirectResponseSubscriber->expects($this->never())->method('setIgnoreDestination');
    $this->buildForm()->usesRealProjectPageRedirect = TRUE;

    $this->assertNull($this->form->finishDeleteBatch(TRUE, ['deleted' => TRUE]));
  }

  /**
   * Expects that neither applications nor project are removed.
   */
  private function expectNothingElseDeleted(): void {
    $this->jupyterStackActions->expects($this->never())->method('deleteStack');
    $this->mountManager->expects($this->never())->method('ensureProjectUnmounted');
    $this->projectHelpers->expects($this->never())->method('deleteProjectTeamFolder');
    $this->projectHelpers->expects($this->never())->method('deleteProjectGroup');
    $this->webprotegeHelpers->expects($this->never())->method('trashOntologyProject');
    $this->project->expects($this->never())->method('delete');
  }

  /**
   * A stack double with an id, owned by the project owner.
   */
  private function stack(int $id): SodaScsStackInterface {
    $owner = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $owner->method('__get')->with('target_id')->willReturn(1);
    $stack = $this->createMock(SodaScsStackInterface::class);
    $stack->method('id')->willReturn($id);
    $stack->method('label')->willReturn('Stack ' . $id);
    $stack->method('getEntityTypeId')->willReturn('soda_scs_stack');
    $stack->method('get')->with('owner')->willReturn($owner);
    return $stack;
  }

  /**
   * The application remover on doubles of the storage and the actions.
   */
  private function applicationRemover(): SodaScsProjectApplicationRemover {
    $stackQuery = $this->createMock(QueryInterface::class);
    $stackQuery->method('accessCheck')->willReturnSelf();
    $stackQuery->method('condition')->willReturnSelf();
    $stackQuery->method('sort')->willReturnSelf();
    $stackQuery->method('execute')->willReturnCallback(function (): array {
      $this->wisskiLookupCount++;
      if ($this->wisskiStacks instanceof \Throwable) {
        throw $this->wisskiStacks;
      }
      $ids = array_map(static fn(SodaScsStackInterface $stack) => $stack->id(), $this->remainingStacks());
      return array_combine($ids, $ids);
    });
    $stackStorage = $this->createMock(EntityStorageInterface::class);
    $stackStorage->method('getQuery')->willReturn($stackQuery);
    $stackStorage->method('loadMultiple')->willReturnCallback(function (): array {
      $stacks = [];
      foreach ($this->remainingStacks() as $stack) {
        $stacks[$stack->id()] = $stack;
      }
      return $stacks;
    });

    $componentQuery = $this->createMock(QueryInterface::class);
    $componentQuery->method('accessCheck')->willReturnSelf();
    $componentQuery->method('condition')->willReturnSelf();
    $componentQuery->method('sort')->willReturnSelf();
    $componentQuery->method('execute')->willReturn([]);
    $componentStorage = $this->createMock(EntityStorageInterface::class);
    $componentStorage->method('getQuery')->willReturn($componentQuery);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['soda_scs_stack', $stackStorage],
      ['soda_scs_component', $componentStorage],
    ]);

    // A deleted stack is gone from the storage, as the one-per-request batch
    // relies on; the wrapper hands the call to the configured double.
    $stackActions = $this->createMock(SodaScsStackActionsInterface::class);
    $stackActions->method('deleteStack')->willReturnCallback(function (SodaScsStackInterface $stack): array {
      $result = $this->wisskiStackActions->deleteStack($stack);
      if (!empty($result['success']) && !$this->staysAfterDeletion) {
        $this->removedStackIds[] = $stack->id();
      }
      return $result;
    });
    $remover = new SodaScsProjectApplicationRemover(
      $this->createMock(SodaScsComponentActionsInterface::class),
      $entityTypeManager,
      $this->logger,
      $stackActions,
    );
    $remover->setStringTranslation($this->getStringTranslationStub());
    return $remover;
  }

  /**
   * The WissKI stacks that were not deleted yet.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsStackInterface[]
   *   The stacks in the storage.
   */
  private function remainingStacks(): array {
    return array_values(array_filter(
      $this->wisskiStacks,
      fn(SodaScsStackInterface $stack): bool => !in_array($stack->id(), $this->removedStackIds, TRUE),
    ));
  }

  /**
   * Builds the delete form on the doubles.
   */
  private function buildForm(bool $runsBatch = TRUE): RecordingProjectDeleteForm {
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('isDemoProject')->willReturn($this->isDemo);

    // Final class; detachMember() must never be reached in these tests.
    $membershipHelpers = (new \ReflectionClass(SodaScsProjectMembershipHelpers::class))->newInstanceWithoutConstructor();

    $form = new RecordingProjectDeleteForm(
      $this->createMock(EntityRepositoryInterface::class),
      $this->createMock(EntityTypeBundleInfoInterface::class),
      $this->projectHelpers,
      $this->webprotegeHelpers,
      $this->createMock(TimeInterface::class),
      $this->applicationHelpers,
      $this->mountManager,
      $this->jupyterStackActions,
      $membershipHelpers,
      $demoManager,
      $this->applicationRemover(),
      $this->lock,
      $this->redirectResponseSubscriber,
    );
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadUnchanged')->willReturnCallback(function (mixed $id): ?SodaScsProjectInterface {
      $this->reloadedIds[] = $id;
      return $this->isProjectGone ? NULL : $this->project;
    });
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('soda_scs_project')->willReturn($storage);
    $form->setEntityTypeManager($entityTypeManager);
    $form->setEntity($this->project);
    $form->setMessenger($this->messenger);
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setLoggerFactory($this->loggerFactoryFor($this->logger));
    $form->runsBatch = $runsBatch;
    $this->form = $form;
    return $form;
  }

  /**
   * Submits the delete form for the project.
   *
   * @param \Drupal\Core\Form\FormState $formState
   *   The form state with the submitted values.
   * @param bool $runsBatch
   *   FALSE only records the batch the form hands over.
   */
  private function submit(FormState $formState, bool $runsBatch = TRUE): void {
    $formArray = [];
    $this->buildForm($runsBatch)->submitForm($formArray, $formState);
  }

  /**
   * A logger factory that hands out the given channel.
   */
  private function loggerFactoryFor(LoggerChannelInterface $channel): LoggerChannelFactoryInterface {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($channel);
    return $factory;
  }

}
