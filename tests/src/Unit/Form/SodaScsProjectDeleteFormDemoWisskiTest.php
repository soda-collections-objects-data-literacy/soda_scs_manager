<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Form\SodaScsProjectDeleteForm;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * What deleting a project does with the WissKI stacks of a demo.
 *
 * A demo project takes the WissKI stacks of its demo with it, through the
 * WissKI stack actions; any failure stops the deletion before anything else,
 * members included, is removed. A regular project keeps its WissKI stacks.
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
   * The WissKI stacks the inventory finds, or an exception to throw.
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
    \Drupal::setContainer($container);
  }

  /**
   * Every WissKI stack of a demo project is deleted, in id order.
   *
   * @covers ::deleteDemoWisskiStacks
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
   * @covers ::deleteDemoWisskiStacks
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
   * @covers ::deleteDemoWisskiStacks
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
      ->with($this->stringContains('Could not delete WissKI stack'), $this->callback(
        fn(array $context): bool => $context['@stack'] === 12
          && $context['@project'] === 3
          && $context['@message'] === 'Portainer answered 500.'
      ));
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => str_contains((string) $message, 'The project was not deleted.')));

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
   * @covers ::deleteDemoWisskiStacks
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
   * A failed WissKI lookup stops the project deletion before any deletion.
   *
   * @covers ::deleteDemoWisskiStacks
   */
  public function testFailedWisskiLookupStopsProjectDeletion(): void {
    $this->wisskiStacks = new \RuntimeException('Query failed.');
    $this->wisskiStackActions->expects($this->never())->method('deleteStack');
    $this->expectNothingElseDeleted();
    $this->logger->expects($this->once())->method('error')
      ->with($this->stringContains('Could not look up the WissKI stacks'), $this->callback(
        fn(array $context): bool => $context['@project'] === 3 && $context['@message'] === 'Query failed.'
      ));
    $this->messenger->expects($this->once())->method('addError')
      ->with($this->callback(fn($message): bool => str_contains((string) $message, 'The project was not deleted.')));

    $formState = new FormState();
    $this->submit($formState);

    $this->assertTrue($formState->isRebuilding());
    $this->assertFalse($formState->getRedirect());
  }

  /**
   * A regular project keeps its WissKI stacks and is not even looked up.
   *
   * @covers ::deleteDemoWisskiStacks
   */
  public function testRegularProjectDoesNotDeleteWisskiStacks(): void {
    $this->isDemo = FALSE;
    $this->wisskiStacks = [$this->stack(11)];
    $this->wisskiStackActions->expects($this->never())->method('deleteStack');
    $this->project->expects($this->once())->method('delete');

    $formState = new FormState();
    $this->submit($formState);

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
   * A stack double with an id.
   */
  private function stack(int $id): SodaScsStackInterface {
    $stack = $this->createMock(SodaScsStackInterface::class);
    $stack->method('id')->willReturn($id);
    return $stack;
  }

  /**
   * Submits the delete form for the project.
   */
  private function submit(FormState $formState): void {
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('isDemoProject')->willReturn($this->isDemo);
    $demoProjectApps = $this->createMock(SodaScsDemoProjectApps::class);
    if ($this->wisskiStacks instanceof \Throwable) {
      $demoProjectApps->method('demoWisskiStacks')->willThrowException($this->wisskiStacks);
    }
    else {
      $demoProjectApps->method('demoWisskiStacks')->willReturn($this->wisskiStacks);
    }

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
      $demoProjectApps,
      $this->wisskiStackActions,
    );
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
