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
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Form\SodaScsProjectDeleteForm;
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

/**
 * What deleting a project does with its WissKI stacks.
 *
 * The form hands the applications to the application remover (real here, on
 * doubles of the entity storage and of the stack and component actions): the
 * WissKI stacks of the project owner are deleted through the WissKI stack
 * actions, demo or not; any failure stops the deletion before anything else,
 * members included, is removed.
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
    $this->wisskiLookupCount = 0;
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
    $this->assertTrue($formState->isRebuilding());
    $this->assertFalse($formState->getRedirect());
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

    $this->assertTrue($formState->isRebuilding());
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

    $formState = new FormState();
    $this->submit($formState);

    $this->assertTrue($formState->isRebuilding());
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

    $formState = new FormState();
    $this->submit($formState);

    $this->assertTrue($formState->isRebuilding());
    $this->assertSame(
      ['acquire:soda_scs_manager.demo.project.3:900', 'release:soda_scs_manager.demo.project.3'],
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
    $this->assertSame([], $this->lockCalls);
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

    $formState = new FormState();
    $this->submit($formState);

    $this->assertTrue($formState->isRebuilding());
    $this->assertFalse($formState->getRedirect());
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
      "acquire:$lockName:900",
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
      ->with($this->callback(fn($message): bool => (string) $message === 'The demo project Demo is being set up right now. Delete it when the setup is done.'));
    $this->messenger->expects($this->never())->method('addStatus');

    $formState = new FormState();
    $this->submit($formState);

    $this->assertSame(0, $this->wisskiLookupCount, 'The WissKI stacks are not looked up.');
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
      ['acquire:soda_scs_manager.demo.project.3:900', 'release:soda_scs_manager.demo.project.3'],
      $this->lockCalls,
    );
  }

  /**
   * The lock is released when the deletion throws.
   *
   * @covers ::submitForm
   */
  public function testDemoProjectLockIsReleasedWhenDeleteThrows(): void {
    $this->project->method('delete')->willThrowException(new \RuntimeException('Database is gone.'));

    try {
      $this->submit(new FormState());
      $this->fail('The exception of the deletion must not be swallowed.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Database is gone.', $e->getMessage());
    }

    $this->assertSame(
      ['acquire:soda_scs_manager.demo.project.3:900', 'release:soda_scs_manager.demo.project.3'],
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
    $this->assertNotFalse($formState->getRedirect());
    $this->assertSame(
      ['acquire:soda_scs_manager.demo.project.3:900', 'release:soda_scs_manager.demo.project.3'],
      $this->lockCalls,
    );
  }

  /**
   * A regular project does not touch the demo lock, even if it is held.
   *
   * @covers ::submitForm
   */
  public function testRegularProjectTakesNoLock(): void {
    $this->isDemo = FALSE;
    $this->isLockFree = FALSE;
    $this->project->expects($this->once())->method('delete');

    $formState = new FormState();
    $this->submit($formState);

    $this->assertSame([], $this->lockCalls);
    $this->assertNotFalse($formState->getRedirect());
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
      $ids = array_map(static fn(SodaScsStackInterface $stack) => $stack->id(), $this->wisskiStacks);
      return array_combine($ids, $ids);
    });
    $stackStorage = $this->createMock(EntityStorageInterface::class);
    $stackStorage->method('getQuery')->willReturn($stackQuery);
    $stackStorage->method('loadMultiple')->willReturnCallback(function (): array {
      $stacks = [];
      foreach ($this->wisskiStacks as $stack) {
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

    $remover = new SodaScsProjectApplicationRemover(
      $this->createMock(SodaScsComponentActionsInterface::class),
      $entityTypeManager,
      $this->logger,
      $this->wisskiStackActions,
    );
    $remover->setStringTranslation($this->getStringTranslationStub());
    return $remover;
  }

  /**
   * Submits the delete form for the project.
   */
  private function submit(FormState $formState): void {
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('isDemoProject')->willReturn($this->isDemo);

    // Final class; detachMember() must never be reached in these tests.
    $membershipHelpers = (new \ReflectionClass(SodaScsProjectMembershipHelpers::class))->newInstanceWithoutConstructor();

    $form = new SodaScsProjectDeleteForm(
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
    );
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadUnchanged')->willReturnCallback(fn(): ?SodaScsProjectInterface => $this->isProjectGone ? NULL : $this->project);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('soda_scs_project')->willReturn($storage);
    $form->setEntityTypeManager($entityTypeManager);
    $form->setEntity($this->project);
    $form->setMessenger($this->messenger);
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setLoggerFactory($this->loggerFactoryFor($this->logger));

    $formArray = [];
    $form->submitForm($formArray, $formState);
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
