<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Demo\SodaScsDemoLabelBuilder;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoQueueRunner;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateBuilder;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectNameHelpers;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\soda_scs_manager\Service\SodaScsProjectProvisioner;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Choosing the demo source project hands it to the service account scs_demo.
 *
 * Issue 3640913. The queue, the runner and the progress storage are doubles.
 */
#[CoversMethod(SodaScsDemoManager::class, 'setSourceProject')]
#[CoversMethod(SodaScsDemoManager::class, 'projectOperationId')]
#[CoversMethod(SodaScsDemoManager::class, 'sourceOwnerNotice')]
#[Group('soda_scs_manager')]
class SodaScsDemoManagerSourceProjectTest extends UnitTestCase {

  private const NOW = 100000;

  /**
   * The editable settings double.
   */
  private Config&MockObject $settings;

  /**
   * The demo queue double.
   */
  private QueueInterface&MockObject $queue;

  /**
   * The queue runner double.
   */
  private SodaScsDemoQueueRunner&MockObject $queueRunner;

  /**
   * The progress helper double.
   */
  private SodaScsProgressHelper&MockObject $progressHelper;

  /**
   * The name helper double.
   */
  private SodaScsProjectNameHelpers&MockObject $projectNameHelpers;

  /**
   * The state double.
   */
  private StateInterface&MockObject $state;

  /**
   * The configured source project id, as the read-only settings answer it.
   */
  private int $configuredSourceId = 0;

  /**
   * The project the project storage returns for the configured id.
   */
  private ?SodaScsProjectInterface $storedSource = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->state = $this->createMock(StateInterface::class);
    $this->configuredSourceId = 0;
    $this->storedSource = NULL;
    $this->settings = $this->createMock(Config::class);
    $this->settings->method('set')->willReturnSelf();
    $this->queue = $this->createMock(QueueInterface::class);
    $this->queueRunner = $this->createMock(SodaScsDemoQueueRunner::class);
    $this->queueRunner->method('start')->willReturn(SodaScsResult::success(data: [], message: 'started'));
    $this->progressHelper = $this->createMock(SodaScsProgressHelper::class);
    $this->projectNameHelpers = $this->createMock(SodaScsProjectNameHelpers::class);
    $this->projectNameHelpers->method('checkLabel')->willReturn(['error' => NULL]);
  }

  /**
   * A source owned by someone else queues the transfer job for the project.
   */
  public function testSourceOfAnotherOwnerQueuesTheTransferJob(): void {
    $this->progressHelper->expects($this->once())->method('createOperation')
      ->with('demo_transfer_source', 'queued')->willReturn('operation-1');
    $this->queue->expects($this->once())->method('createItem')->with([
      'operation' => SodaScsDemoManager::OPERATION_TRANSFER_SOURCE,
      'operationId' => 'operation-1',
      'projectId' => 5,
      'queuedAt' => self::NOW,
    ]);
    $this->settings->expects($this->once())->method('set')->with('demo.sourceProjectId', 5)->willReturnSelf();
    $this->settings->expects($this->once())->method('save');
    $this->queueRunner->expects($this->once())->method('start')->willReturn(SodaScsResult::success(data: [], message: 'started'));
    $this->state->expects($this->once())->method('set')->with('soda_scs_manager.demo_operation.project.5', 'operation-1');

    $result = $this->manager()->setSourceProject($this->project('alice'));

    $this->assertTrue($result->success);
    $this->assertStringContainsString('scs_demo', $result->message);
  }

  /**
   * The operation id of the handover is found again through the project.
   */
  public function testOperationIdOfTheHandoverIsReadableThroughTheProject(): void {
    $stored = [];
    $this->progressHelper->method('createOperation')->willReturn('operation-1');
    $this->state = $this->createMock(StateInterface::class);
    $this->state->method('set')->willReturnCallback(function (string $key, mixed $value) use (&$stored): void {
      $stored[$key] = $value;
    });
    $this->state->method('get')->willReturnCallback(function (string $key) use (&$stored): ?string {
      return $stored[$key] ?? NULL;
    });
    $manager = $this->manager();
    $project = $this->project('alice');

    $this->assertNull($manager->projectOperationId($project));
    $manager->setSourceProject($project);

    $this->assertSame('operation-1', $manager->projectOperationId($project));
  }

  /**
   * Without an operation id nothing is stored, but the job is still queued.
   */
  public function testMissingOperationIdStoresNothingAndStillQueuesTheJob(): void {
    $this->progressHelper->method('createOperation')->willReturn(NULL);
    $this->state->expects($this->never())->method('set');
    $this->queue->expects($this->once())->method('createItem')->with($this->callback(static fn (array $item): bool => $item['operationId'] === NULL));

    $this->assertTrue($this->manager()->setSourceProject($this->project('alice'))->success);
  }

  /**
   * A source that scs_demo owns stores no operation id.
   */
  public function testSourceOfTheServiceAccountStoresNoOperationId(): void {
    $this->state->expects($this->never())->method('set');

    $this->assertTrue($this->manager()->setSourceProject($this->project('scs_demo'))->success);
  }

  /**
   * A refused project stores no operation id.
   */
  public function testRefusedProjectStoresNoOperationId(): void {
    $this->state->expects($this->never())->method('set');

    $this->assertFalse($this->manager()->setSourceProject($this->project('alice', 'ready'))->success);
  }

  /**
   * Without a source there is no notice.
   */
  public function testNoNoticeWithoutSource(): void {
    $this->assertNull($this->manager()->sourceOwnerNotice());
  }

  /**
   * A configured id whose project is gone gives no notice.
   */
  public function testNoNoticeWhenTheSourceProjectIsGone(): void {
    $this->configuredSourceId = 5;
    $this->storedSource = NULL;

    $this->assertNull($this->manager()->sourceOwnerNotice());
  }

  /**
   * A source that scs_demo owns gives no notice.
   */
  public function testNoNoticeWhenTheServiceAccountOwnsTheSource(): void {
    $this->useSource($this->project('scs_demo'));
    $this->progressHelper->expects($this->never())->method('findLatestStepsByOperation');

    $this->assertNull($this->manager()->sourceOwnerNotice());
  }

  /**
   * The notice names the owner and the latest step of the handover.
   */
  public function testNoticeNamesOwnerAndLatestHandoverStep(): void {
    $this->useSource($this->project('alice'));
    $this->state->method('get')->with('soda_scs_manager.demo_operation.project.5')->willReturn('operation-1');
    $this->progressHelper->expects($this->once())->method('findLatestStepsByOperation')
      ->with('operation-1', [], 'created', 'DESC', 1)
      ->willReturn([['message' => 'Handing the demo project to scs_demo failed. See the log for details.']]);

    $notice = $this->manager()->sourceOwnerNotice();

    $this->assertStringContainsString('alice (display)', (string) $notice);
    $this->assertStringContainsString('(Handing the demo project to scs_demo failed. See the log for details.)', (string) $notice);
    $this->assertStringNotContainsString('no handover started', (string) $notice);
  }

  /**
   * Without an operation the notice says that no handover started.
   */
  public function testNoticeSaysNoHandoverStartedWithoutOperation(): void {
    $this->useSource($this->project('alice'));
    $this->state->method('get')->willReturn(NULL);
    $this->progressHelper->expects($this->never())->method('findLatestStepsByOperation');

    $notice = $this->manager()->sourceOwnerNotice();

    $this->assertStringContainsString('alice (display)', (string) $notice);
    $this->assertStringContainsString('(no handover started)', (string) $notice);
  }

  /**
   * An operation without steps yet counts as not started.
   */
  public function testNoticeSaysNoHandoverStartedWhenTheOperationHasNoSteps(): void {
    $this->useSource($this->project('alice'));
    $this->state->method('get')->willReturn('operation-1');
    $this->progressHelper->method('findLatestStepsByOperation')->willReturn([]);

    $this->assertStringContainsString('(no handover started)', (string) $this->manager()->sourceOwnerNotice());
  }

  /**
   * A source without owner still gets a notice, with an empty owner name.
   */
  public function testNoticeForSourceWithoutOwner(): void {
    $this->useSource($this->project(NULL));
    $this->state->method('get')->willReturn(NULL);

    $notice = $this->manager()->sourceOwnerNotice();

    $this->assertNotNull($notice);
    $this->assertStringContainsString('(no handover started)', $notice);
  }

  /**
   * A source that scs_demo owns already queues nothing and starts no runner.
   */
  public function testSourceOfTheServiceAccountQueuesNothing(): void {
    $this->queue->expects($this->never())->method('createItem');
    $this->progressHelper->expects($this->never())->method('createOperation');
    $this->queueRunner = $this->createMock(SodaScsDemoQueueRunner::class);
    $this->queueRunner->expects($this->never())->method('start');
    $this->settings->expects($this->once())->method('save');

    $result = $this->manager()->setSourceProject($this->project('scs_demo'));

    $this->assertTrue($result->success);
    $this->assertStringNotContainsString('background', $result->message);
  }

  /**
   * A project without an owner is handed over as well (the job reports it).
   */
  public function testSourceWithoutOwnerQueuesTheTransferJob(): void {
    $this->progressHelper->method('createOperation')->willReturn('operation-1');
    $this->queue->expects($this->once())->method('createItem');

    $this->assertTrue($this->manager()->setSourceProject($this->project(NULL))->success);
  }

  /**
   * A demo project of a user cannot be the source; nothing changes.
   */
  public function testUserDemoProjectIsRefusedWithoutChanges(): void {
    $this->queue->expects($this->never())->method('createItem');
    $this->settings->expects($this->never())->method('save');

    $result = $this->manager()->setSourceProject($this->project('alice', 'ready'));

    $this->assertFalse($result->success);
  }

  /**
   * A refused name keeps the old source and queues nothing.
   */
  public function testRejectedNameQueuesNothingAndKeepsTheSetting(): void {
    $this->projectNameHelpers = $this->createMock(SodaScsProjectNameHelpers::class);
    $this->projectNameHelpers->method('checkLabel')->willReturn(['error' => 'taken']);
    $this->projectNameHelpers->method('errorMessage')->willReturn(new TranslatableMarkup('The name is taken.', [], [], $this->getStringTranslationStub()));
    $this->queue->expects($this->never())->method('createItem');
    $this->settings->expects($this->never())->method('save');

    $result = $this->manager()->setSourceProject($this->project('alice', NULL, 'Other name'));

    $this->assertFalse($result->success);
    $this->assertSame('The name is taken.', $result->message);
  }

  /**
   * When the runner cannot start, the failure is returned.
   */
  public function testRunnerFailureIsReturned(): void {
    $this->progressHelper->method('createOperation')->willReturn('operation-1');
    $this->queueRunner = $this->createMock(SodaScsDemoQueueRunner::class);
    $this->queueRunner->method('start')->willReturn(SodaScsResult::failure(error: 'no spawn', message: 'Could not start.', log: FALSE));

    $result = $this->manager()->setSourceProject($this->project('alice'));

    $this->assertFalse($result->success);
    $this->assertSame('Could not start.', $result->message);
  }

  /**
   * Makes a project the configured demo source.
   */
  private function useSource(SodaScsProjectInterface $project): void {
    $this->configuredSourceId = (int) $project->id();
    $this->storedSource = $project;
  }

  /**
   * Builds the manager with doubles.
   */
  private function manager(): SodaScsDemoManager {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('getEditable')->with('soda_scs_manager.settings')->willReturn($this->settings);
    $readSettings = $this->createMock(Config::class);
    $readSettings->method('get')->with('demo.sourceProjectId')->willReturn($this->configuredSourceId);
    $configFactory->method('get')->with('soda_scs_manager.settings')->willReturn($readSettings);
    $projectStorage = $this->createMock(EntityStorageInterface::class);
    $projectStorage->method('load')->with($this->configuredSourceId)->willReturn($this->storedSource);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('soda_scs_project')->willReturn($projectStorage);
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->with(SodaScsDemoManager::QUEUE_NAME)->willReturn($this->queue);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(self::NOW);

    $manager = new SodaScsDemoManager(
      $configFactory,
      $entityTypeManager,
      $this->createMock(SodaScsDemoLabelBuilder::class),
      $this->progressHelper,
      $this->createMock(SodaScsProjectHelpers::class),
      $this->createMock(NextcloudProjectMountManager::class),
      $this->projectNameHelpers,
      $this->createMock(SodaScsProjectProvisioner::class),
      $queueFactory,
      $this->queueRunner,
      $this->state,
      $this->createMock(SodaScsDemoTemplateBuilder::class),
      $time,
    );
    $manager->setStringTranslation($this->getStringTranslationStub());
    return $manager;
  }

  /**
   * Builds a project double.
   *
   * @param string|null $ownerName
   *   Account name of the owner; NULL for a project without an owner.
   * @param string|null $demoStatus
   *   The demo status of a user demo project.
   * @param string $label
   *   The current label.
   */
  private function project(?string $ownerName, ?string $demoStatus = NULL, string $label = SodaScsDemoManager::SOURCE_PROJECT_LABEL): SodaScsProjectInterface {
    $owner = NULL;
    if ($ownerName !== NULL) {
      $owner = $this->createMock(UserInterface::class);
      $owner->method('getAccountName')->willReturn($ownerName);
      $owner->method('getDisplayName')->willReturn($ownerName . ' (display)');
    }
    $status = $this->createMock(FieldItemListInterface::class);
    $status->method('isEmpty')->willReturn($demoStatus === NULL);
    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('id')->willReturn(5);
    $project->method('label')->willReturn($label);
    $project->method('hasField')->with('demoStatus')->willReturn(TRUE);
    $project->method('get')->with('demoStatus')->willReturn($status);
    $project->method('getOwner')->willReturn($owner);
    return $project;
  }

}
