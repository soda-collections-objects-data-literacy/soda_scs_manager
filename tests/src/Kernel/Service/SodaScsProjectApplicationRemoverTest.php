<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Service\SodaScsProjectApplicationRemover;
use Drupal\soda_scs_manager\ServiceActions\SodaScsSqlServiceActions;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\UserInterface;

/**
 * What happens to the applications of a project that is about to be deleted.
 *
 * The WissKI stacks and the loose databases and triplestores of the project
 * owner are deleted through the stack and component actions; those of other
 * members are detached from the project and stay. The stack and component
 * actions are doubles: no remote service is reached.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Service\SodaScsProjectApplicationRemover
 * @group soda_scs_manager
 */
class SodaScsProjectApplicationRemoverTest extends SodaScsKernelTestBase {

  /**
   * The component actions double; also stands in for the service the hooks use.
   */
  private SodaScsComponentActionsInterface $componentActions;

  /**
   * The stack actions double.
   */
  private SodaScsStackActionsInterface $stackActions;

  /**
   * The logger channel double.
   */
  private LoggerChannelInterface $logger;

  /**
   * Another member of the project.
   */
  private UserInterface $member;

  /**
   * The project; its owner is the test user.
   */
  private SodaScsProjectInterface $project;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->componentActions = $this->createMock(SodaScsComponentActionsInterface::class);
    // Saving a triplestore component runs a hook that updates it through the
    // component actions service.
    $this->componentActions->method('updateComponent')->willReturn(['success' => TRUE]);
    $this->container->set('soda_scs_manager.component.actions', $this->componentActions);
    // Linking a database to a project grants the members access to it through
    // the SQL service; the double answers that the MariaDB user check failed,
    // so the helper stops there and no database is reached.
    $sqlServiceActions = $this->createMock(SodaScsSqlServiceActions::class);
    $sqlServiceActions->method('existServiceUser')->willReturn(['execStatus' => 1, 'output' => []]);
    $this->container->set('soda_scs_manager.sql_service.actions', $sqlServiceActions);
    $this->stackActions = $this->createMock(SodaScsStackActionsInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->member = $this->createTestUser();
    $this->project = $this->createTestProject(['label' => 'Shared project']);
  }

  /**
   * A project without applications is removed without any call.
   *
   * @covers ::removeApplications
   */
  public function testProjectWithoutApplicationsSucceeds(): void {
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->componentActions->expects($this->never())->method('deleteComponent');

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertTrue($result->success);
  }

  /**
   * The WissKI stack of the project owner is deleted through the stack actions.
   *
   * @covers ::removeApplications
   */
  public function testOwnersWisskiStackIsDeleted(): void {
    $stack = $this->createStack($this->testUser);
    $this->stackActions->expects($this->once())->method('deleteStack')
      ->with($this->callback(fn(SodaScsStackInterface $given): bool => $given->id() === $stack->id()))
      ->willReturn(['success' => TRUE]);

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertTrue($result->success);
  }

  /**
   * Loose databases and triplestores of the owner are deleted, in id order.
   *
   * @covers ::removeApplications
   */
  public function testOwnersLooseComponentsAreDeleted(): void {
    $sql = $this->createComponent('soda_scs_sql_component', $this->testUser);
    $triplestore = $this->createComponent('soda_scs_triplestore_component', $this->testUser);
    $deleted = [];
    $this->componentActions->expects($this->exactly(2))->method('deleteComponent')
      ->willReturnCallback(function (SodaScsComponentInterface $component) use (&$deleted): array {
        $deleted[] = (int) $component->id();
        return ['success' => TRUE];
      });

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertTrue($result->success);
    $this->assertSame([(int) $sql->id(), (int) $triplestore->id()], $deleted);
  }

  /**
   * A database of a stack goes with its stack, not on its own.
   *
   * @covers ::removeApplications
   */
  public function testStackComponentIsNotDeletedSeparately(): void {
    $stack = $this->createStack($this->testUser);
    $this->createComponent('soda_scs_sql_component', $this->testUser, ['partOfStack' => $stack->id()]);
    $this->stackActions->expects($this->once())->method('deleteStack')->willReturn(['success' => TRUE]);
    $this->componentActions->expects($this->never())->method('deleteComponent');

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertTrue($result->success);
  }

  /**
   * Other bundles of the project are left to the callers.
   *
   * @covers ::removeApplications
   */
  public function testJupyterStackAndWebprotegeComponentAreNotTouched(): void {
    $jupyter = $this->createStack($this->testUser, 'soda_scs_jupyter_stack');
    $webprotege = $this->createComponent('soda_scs_webprotege_component', $this->testUser);
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->componentActions->expects($this->never())->method('deleteComponent');

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertTrue($result->success);
    $this->assertSame([(int) $this->project->id()], $this->projectIds($this->reload($jupyter)));
    $this->assertSame([(int) $this->project->id()], $this->projectIds($this->reload($webprotege)));
  }

  /**
   * Applications of another project are not touched.
   *
   * @covers ::removeApplications
   */
  public function testApplicationsOfAnotherProjectAreNotTouched(): void {
    $other = $this->createTestProject(['label' => 'Other project']);
    $stack = $this->createStack($this->testUser, 'soda_scs_wisski_stack', $other);
    $component = $this->createComponent('soda_scs_sql_component', $this->member, [], $other);
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->componentActions->expects($this->never())->method('deleteComponent');

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertTrue($result->success);
    $this->assertSame([(int) $other->id()], $this->projectIds($this->reload($stack)));
    $this->assertSame([(int) $other->id()], $this->projectIds($this->reload($component)));
  }

  /**
   * A loose component of another member stays and only loses the project.
   *
   * @covers ::removeApplications
   * @covers ::detach
   */
  public function testOtherMembersComponentIsDetachedNotDeleted(): void {
    $component = $this->createComponent('soda_scs_sql_component', $this->member);
    $this->componentActions->expects($this->never())->method('deleteComponent');
    $project = $this->projectHeldByCaller();
    $this->assertContains((int) $component->id(), $this->connectedIds($project), 'Fixture: the project lists the component.');

    $result = $this->remover()->removeApplications($project, TRUE);

    $this->assertTrue($result->success);
    $kept = $this->reload($component);
    $this->assertNotNull($kept, 'The component of the other member still exists.');
    $this->assertSame([], $this->projectIds($kept));
    $this->assertNotContains((int) $component->id(), $this->connectedIds($project), 'The in-memory project forgot the component.');
  }

  /**
   * A detached component stays unlinked when the project object is deleted.
   *
   * Regression: the project delete hook links every component still listed in
   * connectedComponents of the object being deleted back to the project.
   *
   * @covers ::detach
   */
  public function testDetachedComponentStaysWithoutProjectAfterProjectDelete(): void {
    $component = $this->createComponent('soda_scs_sql_component', $this->member);
    $project = $this->projectHeldByCaller();

    $this->remover()->removeApplications($project, TRUE);
    $project->delete();

    $kept = $this->reload($component);
    $this->assertNotNull($kept);
    $this->assertSame([], $this->projectIds($kept));
  }

  /**
   * A detached component stays unlinked when the project object is saved.
   *
   * @covers ::detach
   */
  public function testDetachedComponentStaysWithoutProjectAfterProjectSave(): void {
    $component = $this->createComponent('soda_scs_sql_component', $this->member);
    $project = $this->projectHeldByCaller();

    $this->remover()->removeApplications($project, TRUE);
    $project->save();

    $this->assertSame([], $this->projectIds($this->reload($component)));
  }

  /**
   * A stack of another member and its components are detached together.
   *
   * @covers ::removeApplications
   * @covers ::detach
   */
  public function testOtherMembersStackIsDetachedWithItsComponents(): void {
    $stack = $this->createStack($this->member);
    $database = $this->createComponent('soda_scs_sql_component', $this->member, ['partOfStack' => $stack->id()]);
    $stack->set('includedComponents', [['target_id' => $database->id()]]);
    $stack->save();
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->componentActions->expects($this->never())->method('deleteComponent');
    $project = $this->projectHeldByCaller();

    $result = $this->remover()->removeApplications($project, TRUE);
    $project->delete();

    $this->assertTrue($result->success);
    $this->assertSame([], $this->projectIds($this->reload($stack)));
    $this->assertSame([], $this->projectIds($this->reload($database)));
  }

  /**
   * Owner and member applications are handled each by their own rule.
   *
   * @covers ::removeApplications
   */
  public function testOwnerApplicationsAreDeletedWhileMemberApplicationsAreDetached(): void {
    $ownersStack = $this->createStack($this->testUser);
    $membersStack = $this->createStack($this->member);
    $ownersDatabase = $this->createComponent('soda_scs_sql_component', $this->testUser);
    $membersDatabase = $this->createComponent('soda_scs_triplestore_component', $this->member);
    $this->stackActions->expects($this->once())->method('deleteStack')
      ->with($this->callback(fn(SodaScsStackInterface $given): bool => $given->id() === $ownersStack->id()))
      ->willReturn(['success' => TRUE]);
    $this->componentActions->expects($this->once())->method('deleteComponent')
      ->with($this->callback(fn(SodaScsComponentInterface $given): bool => $given->id() === $ownersDatabase->id()))
      ->willReturn(['success' => TRUE]);

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertTrue($result->success);
    $this->assertSame([], $this->projectIds($this->reload($membersStack)));
    $this->assertSame([], $this->projectIds($this->reload($membersDatabase)));
  }

  /**
   * The first failure stops the run when the caller asks for it.
   *
   * @covers ::removeApplications
   * @covers ::deleteFailure
   */
  public function testStopOnFailureStopsAtTheFirstFailedStack(): void {
    $first = $this->createStack($this->testUser, label: 'First stack');
    $this->createStack($this->testUser, label: 'Second stack');
    $this->createComponent('soda_scs_sql_component', $this->testUser);
    $attempted = [];
    $this->stackActions->method('deleteStack')
      ->willReturnCallback(function (SodaScsStackInterface $stack) use (&$attempted): array {
        $attempted[] = (int) $stack->id();
        return ['success' => FALSE, 'error' => 'Portainer answered 500.'];
      });
    $this->componentActions->expects($this->never())->method('deleteComponent');
    $this->logger->expects($this->once())->method('error')
      ->with($this->stringContains('Could not remove @type @id from project'), $this->callback(
        fn(array $context): bool => (int) $context['@id'] === (int) $first->id()
          && $context['@type'] === 'soda_scs_stack'
          && (int) $context['@project'] === (int) $this->project->id()
          && $context['@message'] === 'Portainer answered 500.'
      ));

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertFalse($result->success);
    $this->assertSame('application_not_removed', $result->error);
    $this->assertSame('Could not delete the application First stack of project Shared project. The project was not deleted.', $result->message);
    $this->assertSame([(int) $first->id()], $attempted, 'The stack after the failed one is not tried.');
  }

  /**
   * A failed stack keeps its entity and its link to the project.
   *
   * @covers ::removeApplications
   */
  public function testFailedStackStaysLinkedToTheProject(): void {
    $stack = $this->createStack($this->testUser);
    $this->stackActions->method('deleteStack')->willReturn(['success' => FALSE, 'error' => 'Portainer answered 500.']);

    $this->remover()->removeApplications($this->freshProject(), TRUE);

    $kept = $this->reload($stack);
    $this->assertNotNull($kept);
    $this->assertSame([(int) $this->project->id()], $this->projectIds($kept));
  }

  /**
   * A failed loose component stops the run and keeps the later ones.
   *
   * @covers ::removeApplications
   */
  public function testStopOnFailureStopsAtTheFirstFailedComponent(): void {
    $first = $this->createComponent('soda_scs_sql_component', $this->testUser, ['label' => 'First database']);
    $this->createComponent('soda_scs_triplestore_component', $this->testUser);
    $attempted = [];
    $this->componentActions->method('deleteComponent')
      ->willReturnCallback(function (SodaScsComponentInterface $component) use (&$attempted): array {
        $attempted[] = (int) $component->id();
        return ['success' => FALSE, 'message' => 'MariaDB refused.'];
      });
    $this->logger->expects($this->once())->method('error')
      ->with($this->anything(), $this->callback(
        fn(array $context): bool => $context['@type'] === 'soda_scs_component' && $context['@message'] === 'MariaDB refused.'
      ));

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('First database', $result->message);
    $this->assertSame([(int) $first->id()], $attempted);
  }

  /**
   * Without stopOnFailure the others are removed and the first failure returns.
   *
   * @covers ::removeApplications
   */
  public function testContinuesAfterFailureWhenNotStopping(): void {
    $first = $this->createStack($this->testUser, label: 'First stack');
    $second = $this->createStack($this->testUser, label: 'Second stack');
    $database = $this->createComponent('soda_scs_sql_component', $this->testUser);
    $attempted = [];
    $this->stackActions->method('deleteStack')
      ->willReturnCallback(function (SodaScsStackInterface $stack) use (&$attempted, $first): array {
        $attempted[] = (int) $stack->id();
        return $stack->id() === $first->id() ? ['success' => FALSE, 'error' => 'Portainer answered 500.'] : ['success' => TRUE];
      });
    $this->componentActions->expects($this->once())->method('deleteComponent')
      ->with($this->callback(fn(SodaScsComponentInterface $given): bool => $given->id() === $database->id()))
      ->willReturn(['success' => TRUE]);
    $this->logger->expects($this->once())->method('error');

    $result = $this->remover()->removeApplications($this->freshProject(), FALSE);

    $this->assertSame([(int) $first->id(), (int) $second->id()], $attempted);
    $this->assertFalse($result->success);
    $this->assertStringContainsString('First stack', $result->message);
  }

  /**
   * An answer without a success flag counts as a failure.
   *
   * @covers ::removeApplications
   */
  public function testMalformedDeleteAnswerCountsAsFailure(): void {
    $this->createStack($this->testUser);
    $this->stackActions->method('deleteStack')->willReturn([]);
    $this->logger->expects($this->once())->method('error')
      ->with($this->anything(), $this->callback(fn(array $context): bool => $context['@message'] === ''));

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertFalse($result->success);
    $this->assertSame('application_not_removed', $result->error);
  }

  /**
   * An exception of the stack actions is a failure, not a crash.
   *
   * @covers ::removeApplications
   */
  public function testExceptionOfTheStackActionsCountsAsFailure(): void {
    $stack = $this->createStack($this->testUser);
    $this->stackActions->method('deleteStack')->willThrowException(new \RuntimeException('Portainer is down.'));
    $this->logger->expects($this->once())->method('error')
      ->with($this->anything(), $this->callback(fn(array $context): bool => $context['@message'] === 'Portainer is down.'));

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertFalse($result->success);
    $this->assertNotNull($this->reload($stack));
  }

  /**
   * An exception of the component actions is a failure, not a crash.
   *
   * @covers ::removeApplications
   */
  public function testExceptionOfTheComponentActionsCountsAsFailure(): void {
    $component = $this->createComponent('soda_scs_sql_component', $this->testUser);
    $this->componentActions->method('deleteComponent')->willThrowException(new \RuntimeException('MariaDB is down.'));
    $this->logger->expects($this->once())->method('error')
      ->with($this->anything(), $this->callback(fn(array $context): bool => $context['@message'] === 'MariaDB is down.'));

    $result = $this->remover()->removeApplications($this->freshProject(), FALSE);

    $this->assertFalse($result->success);
    $this->assertNotNull($this->reload($component));
  }

  /**
   * A failed detach says so, and the application keeps the project.
   *
   * @covers ::detach
   */
  public function testFailedDetachCountsAsFailureWithItsOwnMessage(): void {
    $component = $this->createComponent('soda_scs_triplestore_component', $this->member, ['label' => 'Member triplestore']);
    // Saving the detached triplestore runs the update hook, which throws now.
    $failingActions = $this->createMock(SodaScsComponentActionsInterface::class);
    $failingActions->method('updateComponent')->willThrowException(new \RuntimeException('OpenGDB is down.'));
    $this->container->set('soda_scs_manager.component.actions', $failingActions);
    $this->componentActions->expects($this->never())->method('deleteComponent');
    $this->logger->expects($this->once())->method('error')
      ->with($this->anything(), $this->callback(fn(array $context): bool => $context['@message'] === 'OpenGDB is down.'));

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('Could not detach the application Member triplestore from project Shared project.', $result->message);
    $this->assertSame([(int) $this->project->id()], $this->projectIds($this->reload($component)));
  }

  /**
   * The user message stays markup, so a label is escaped only once.
   *
   * @covers ::removeApplications
   */
  public function testFailureMessageIsEscapedOnce(): void {
    $this->project->set('label', 'R&D')->save();
    $this->createStack($this->testUser, label: 'Archive & more');
    $this->stackActions->method('deleteStack')->willReturn(['success' => FALSE, 'error' => 'Portainer answered 500.']);

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $message = $result->data['message'] ?? NULL;
    $this->assertInstanceOf(TranslatableMarkup::class, $message);
    $this->assertSame(
      'Could not delete the application Archive &amp; more of project R&amp;D. The project was not deleted.',
      (string) $message,
    );
  }

  /**
   * A failed lookup is a failure, so the caller keeps the project.
   *
   * @covers ::removeApplications
   */
  public function testFailedLookupCountsAsFailure(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willThrowException(new \RuntimeException('Query failed.'));
    $this->stackActions->expects($this->never())->method('deleteStack');
    $this->logger->expects($this->once())->method('error')
      ->with($this->stringContains('Could not look up the applications'), $this->callback(fn(array $context): bool => $context['@message'] === 'Query failed.'));
    $remover = new SodaScsProjectApplicationRemover($this->componentActions, $entityTypeManager, $this->logger, $this->stackActions);

    $result = $remover->removeApplications($this->freshProject(), TRUE);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('The project was not deleted.', $result->message);
  }

  /**
   * A component whose stack is gone is handled as a loose one.
   *
   * @covers ::removeApplications
   */
  public function testComponentWithMissingStackIsDeletedAsLoose(): void {
    $component = $this->createComponent('soda_scs_sql_component', $this->testUser, ['partOfStack' => 999999]);
    $this->componentActions->expects($this->once())->method('deleteComponent')
      ->with($this->callback(fn($deleted): bool => (int) $deleted->id() === (int) $component->id()))
      ->willReturn(['success' => TRUE]);

    $result = $this->remover()->removeApplications($this->freshProject(), TRUE);

    $this->assertTrue($result->success);
  }

  /**
   * Builds the remover on the doubles.
   */
  private function remover(): SodaScsProjectApplicationRemover {
    return new SodaScsProjectApplicationRemover(
      $this->componentActions,
      $this->entityTypeManager,
      $this->logger,
      $this->stackActions,
    );
  }

  /**
   * Loads the project as the delete form does, with its connected components.
   */
  private function freshProject(): SodaScsProjectInterface {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project');
    $storage->resetCache([$this->project->id()]);
    $project = $storage->load($this->project->id());
    $this->assertInstanceOf(SodaScsProjectInterface::class, $project);
    return $project;
  }

  /**
   * The project object a caller holds while the hooks load their own copy.
   *
   * The caller (the delete form) keeps its object while saving an application
   * makes the project hooks load the project again; with the entity cache
   * reset they get another instance, so only the caller's own object tells
   * what a later save or delete of it does.
   */
  private function projectHeldByCaller(): SodaScsProjectInterface {
    $project = $this->freshProject();
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$project->id()]);
    return $project;
  }

  /**
   * Creates an application component linked to the project.
   *
   * @param string $bundle
   *   The component bundle.
   * @param \Drupal\user\UserInterface $owner
   *   The owner of the component.
   * @param array<string, mixed> $values
   *   Field values overriding the defaults.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface|null $project
   *   The project to link; the shared test project when NULL.
   */
  private function createComponent(string $bundle, UserInterface $owner, array $values = [], ?SodaScsProjectInterface $project = NULL): SodaScsComponentInterface {
    $values += ['partOfProjects' => [($project ?? $this->project)->id()]];
    return $this->createTestComponent($bundle, ['owner' => $owner->id()] + $values);
  }

  /**
   * Creates a stack linked to the project.
   */
  private function createStack(UserInterface $owner, string $bundle = 'soda_scs_wisski_stack', ?SodaScsProjectInterface $project = NULL, string $label = ''): SodaScsStackInterface {
    $values = [
      'owner' => $owner->id(),
      'partOfProjects' => [($project ?? $this->project)->id()],
    ];
    if ($label !== '') {
      $values['label'] = $label;
    }
    return $this->createTestStack($bundle, $values);
  }

  /**
   * Reloads an application from the database; NULL when it is gone.
   */
  private function reload(SodaScsComponentInterface|SodaScsStackInterface $entity): SodaScsComponentInterface|SodaScsStackInterface|null {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $storage->resetCache([$entity->id()]);
    return $storage->load($entity->id());
  }

  /**
   * The ids of the projects an application lists.
   *
   * @return list<int>
   *   The project ids.
   */
  private function projectIds(SodaScsComponentInterface|SodaScsStackInterface $entity): array {
    return array_map('intval', array_column($entity->get('partOfProjects')->getValue(), 'target_id'));
  }

  /**
   * The ids of the components a project lists.
   *
   * @return list<int>
   *   The component ids.
   */
  private function connectedIds(SodaScsProjectInterface $project): array {
    return array_map('intval', array_column($project->get('connectedComponents')->getValue(), 'target_id'));
  }

}
