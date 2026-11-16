<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoIncompleteAppException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoNotReadyException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectProvisioner;
use Drupal\soda_scs_manager\Demo\SodaScsDemoStatusBuilder;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateBuilder;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Drupal\soda_scs_manager\Plugin\QueueWorker\SodaScsDemoQueueWorker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Tests the progress steps and failure reasons of the demo queue worker.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Plugin\QueueWorker\SodaScsDemoQueueWorker
 * @group soda_scs_manager
 */
class SodaScsDemoQueueWorkerTest extends UnitTestCase {

  private const NOW = 100000;

  private const OPERATION_ID = 'operation-1';

  /**
   * Messages written with createStep(), in order.
   *
   * @var list<string>
   */
  private array $createdSteps = [];

  /**
   * Names of the locks the worker acquired, in order.
   *
   * @var list<string>
   */
  private array $acquiredLocks = [];

  /**
   * Operation statuses written with updateOperation(), in order.
   *
   * @var list<string>
   */
  private array $operationStatuses = [];

  /**
   * The mocked logger.
   */
  private LoggerInterface&MockObject $logger;

  /**
   * The mocked progress helper.
   */
  private SodaScsProgressHelper&MockObject $progressHelper;

  /**
   * The mocked provisioner.
   */
  private SodaScsDemoProjectProvisioner&MockObject $provisioner;

  /**
   * The demo project of the job.
   */
  private SodaScsProjectInterface&MockObject $project;

  /**
   * Steps the progress helper reports as already recorded.
   *
   * @var list<array{message: string}>
   */
  private array $recordedSteps = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->acquiredLocks = [];
    $this->createdSteps = [];
    $this->operationStatuses = [];
    $this->recordedSteps = [];
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->project = $this->createMock(SodaScsProjectInterface::class);
    $this->provisioner = $this->createMock(SodaScsDemoProjectProvisioner::class);

    $test = $this;
    $this->progressHelper = $this->createMock(SodaScsProgressHelper::class);
    $this->progressHelper->method('findLatestStepsByOperation')->willReturnCallback(static fn (): array => $test->recordedSteps);
    $this->progressHelper->method('createStep')->willReturnCallback(static function (string $operationId, ?string $message = NULL) use ($test): string {
      $test->createdSteps[] = (string) $message;
      $test->recordedSteps[] = ['message' => (string) $message];
      return 'step-uuid';
    });
    $this->progressHelper->method('updateOperation')->willReturnCallback(static function (string $operationId, array $fields) use ($test): bool {
      $test->operationStatuses[] = $fields['status'];
      return TRUE;
    });
  }

  /**
   * A step the job already recorded is not written a second time.
   *
   * @covers ::reportStep
   */
  public function testReportStepSkipsMessageTheOperationAlreadyHas(): void {
    $this->recordedSteps = [['message' => 'Creating missing applications']];
    $this->provisionerReports(['Creating missing applications', 'Importing the ontology']);

    $this->processProvisionJob();

    $this->assertSame(['Importing the ontology'], $this->createdSteps);
    $this->assertSame(['running', 'completed'], $this->operationStatuses);
  }

  /**
   * Translatable steps are stored as source strings, not translated.
   *
   * The queue runs in the site default language; the status translates the
   * step for each viewer.
   *
   * @covers ::reportStep
   */
  public function testReportStepStoresSourceStringOfMarkup(): void {
    $translator = $this->createMock(TranslationInterface::class);
    $translator->method('translateString')->willReturn('Die Ontologie wird importiert');
    $this->provisioner->method('apply')->willReturnCallback(static function ($project, $template, callable $reportStep) use ($translator): void {
      $reportStep(new TranslatableMarkup('Importing the ontology', [], [], $translator));
    });

    $this->processProvisionJob();

    $this->assertSame(['Importing the ontology'], $this->createdSteps);
  }

  /**
   * A step is written when the operation has none yet.
   *
   * @covers ::reportStep
   */
  public function testReportStepWritesNewMessages(): void {
    $this->provisionerReports(['Creating missing applications', 'Importing the ontology']);

    $this->processProvisionJob();

    $this->assertSame(['Creating missing applications', 'Importing the ontology'], $this->createdSteps);
  }

  /**
   * A job without an operation ID reads and writes no steps.
   *
   * @covers ::reportStep
   */
  public function testReportStepWithoutOperationIdTouchesNoProgress(): void {
    $this->provisionerReports(['Creating missing applications']);
    $this->progressHelper = $this->createMock(SodaScsProgressHelper::class);
    $this->progressHelper->expects($this->never())->method('findLatestStepsByOperation');
    $this->progressHelper->expects($this->never())->method('createStep');
    $this->progressHelper->expects($this->never())->method('updateOperation');

    $this->createWorker()->processItem($this->provisionJob(['operationId' => NULL]));
  }

  /**
   * A progress storage problem is logged and does not stop the job.
   *
   * @covers ::reportStep
   */
  public function testReportStepLogsProgressErrorAndJobCompletes(): void {
    $this->provisionerReports(['Creating missing applications']);
    $this->progressHelper = $this->createMock(SodaScsProgressHelper::class);
    $this->progressHelper->method('findLatestStepsByOperation')->willThrowException(new \RuntimeException('database gone'));
    $this->progressHelper->expects($this->never())->method('createStep');
    $this->progressHelper->expects($this->exactly(2))->method('updateOperation');
    $this->logger->expects($this->once())->method('warning')->with('Could not record demo progress: @message', ['@message' => 'database gone']);

    $this->createWorker()->processItem($this->provisionJob());
  }

  /**
   * A job that waited for an hour fails with the timeout reason.
   *
   * @covers ::applyTemplate
   * @covers ::failProject
   */
  public function testTimeoutFailsWithWaitReason(): void {
    $this->provisioner->method('apply')->willThrowException(new SodaScsDemoNotReadyException('WissKI not ready'));
    $this->expectProjectFailed();

    $this->processProvisionJob(['queuedAt' => self::NOW - 3600]);

    $this->assertSame([SodaScsDemoStatusBuilder::REASON_TIMEOUT], $this->createdSteps);
    $this->assertSame(['running', 'failed'], $this->operationStatuses);
  }

  /**
   * Before the hour is over the job waits and is requeued without a failure.
   *
   * @covers ::applyTemplate
   */
  public function testNotReadyBeforeTimeoutRequeuesAndKeepsProject(): void {
    $this->provisioner->method('apply')->willThrowException(new SodaScsDemoNotReadyException('WissKI not ready'));
    $this->project->expects($this->never())->method('set');
    $this->project->expects($this->never())->method('save');

    try {
      $this->processProvisionJob(['queuedAt' => self::NOW - 3599]);
      $this->fail('The job must be requeued.');
    }
    catch (DelayedRequeueException $e) {
      $this->assertSame(60, $e->getDelay());
    }

    $this->assertSame([], $this->createdSteps);
    $this->assertSame(['running', 'waiting'], $this->operationStatuses);
  }

  /**
   * An incomplete WissKI fails the project at once with its own reason.
   *
   * No wait and no requeue: retrying would not create the missing WissKI.
   *
   * @covers ::applyTemplate
   * @covers ::failProject
   */
  public function testIncompleteAppFailsWithWisskiIncompleteReasonWithoutRequeue(): void {
    $this->provisioner->method('apply')->willThrowException(new SodaScsDemoIncompleteAppException('WissKI stack 7 has no WissKI component.'));
    $this->expectProjectFailed();

    $this->processProvisionJob();

    $this->assertSame([SodaScsDemoStatusBuilder::REASON_WISSKI_INCOMPLETE], $this->createdSteps);
    $this->assertSame(['running', 'failed'], $this->operationStatuses);
  }

  /**
   * Any other error leaves the step it failed at last; its message is logged.
   *
   * @covers ::applyTemplate
   * @covers ::failProject
   */
  public function testFailureNamesTheLastStep(): void {
    $this->provisioner->method('apply')->willReturnCallback(static function ($project, $template, callable $reportStep): void {
      $reportStep('Creating missing applications');
      $reportStep('Importing the ontology');
      throw new \RuntimeException('token abc123 rejected');
    });
    $this->expectProjectFailed();
    $this->logger->expects($this->once())->method('error')->with('Demo job for project @project failed: @message', [
      '@message' => 'token abc123 rejected',
      '@project' => 5,
    ]);

    $this->processProvisionJob();

    $this->assertSame(['Creating missing applications', 'Importing the ontology'], $this->createdSteps);
    $this->assertSame(['running', 'failed'], $this->operationStatuses);
  }

  /**
   * A retry that fails at a step recorded earlier records that step again.
   *
   * Otherwise the wait step would stay last and be shown as the reason.
   *
   * @covers ::recordLastStep
   */
  public function testFailureAtEarlierStepRecordsItAgain(): void {
    $this->recordedSteps = [
      ['message' => 'Creating missing applications'],
      ['message' => 'Waiting for the WissKI installation to finish'],
    ];
    $this->provisioner->method('apply')->willReturnCallback(static function ($project, $template, callable $reportStep): void {
      $reportStep('Creating missing applications');
      throw new \RuntimeException('boom');
    });
    $this->expectProjectFailed();

    $this->processProvisionJob();

    $this->assertSame(['Creating missing applications'], $this->createdSteps);
  }

  /**
   * A deleted template version marks the project failed with its reason.
   *
   * @covers ::applyTemplate
   * @covers ::markProjectFailed
   */
  public function testMissingTemplateMarksProjectFailed(): void {
    $this->expectProjectFailed();
    $this->provisioner->expects($this->never())->method('apply');

    $this->createWorker(FALSE)->processItem($this->provisionJob());

    $this->assertSame([SodaScsDemoStatusBuilder::REASON_TEMPLATE_GONE], $this->createdSteps);
    $this->assertSame(['running', 'failed'], $this->operationStatuses);
  }

  /**
   * An error before any step stores no reason; the status shows the time.
   *
   * @covers ::applyTemplate
   * @covers ::failProject
   */
  public function testFailureWithoutStepStoresNoReason(): void {
    $this->provisioner->method('apply')->willThrowException(new \RuntimeException('boom'));
    $this->expectProjectFailed();

    $this->processProvisionJob();

    $this->assertSame([], $this->createdSteps);
    $this->assertSame(['running', 'failed'], $this->operationStatuses);
  }

  /**
   * The last step of a finished job is not carried into the next job.
   *
   * @covers ::applyTemplate
   */
  public function testLastStepIsResetForTheNextJob(): void {
    $calls = 0;
    $this->provisioner->method('apply')->willReturnCallback(static function ($project, $template, callable $reportStep) use (&$calls): void {
      $calls++;
      if ($calls === 1) {
        $reportStep('Importing the ontology');
      }
      throw new \RuntimeException('boom');
    });
    $worker = $this->createWorker();

    $worker->processItem($this->provisionJob());
    // The next job has a new operation without steps.
    $this->recordedSteps = [];
    $worker->processItem($this->provisionJob(['operationId' => 'operation-2']));

    $this->assertSame(['Importing the ontology'], $this->createdSteps);
  }

  /**
   * A project job takes the lock the project delete form takes.
   *
   * @covers ::processItem
   */
  public function testProjectJobTakesTheProjectLockOfTheDeleteForm(): void {
    $this->processProvisionJob();

    $this->assertSame([SodaScsDemoManager::PROJECT_LOCK_PREFIX . '5'], $this->acquiredLocks);
  }

  /**
   * The project is expected to be marked as failed exactly once.
   */
  private function expectProjectFailed(): void {
    $this->project->method('id')->willReturn(5);
    $this->project->expects($this->once())->method('set')->with('demoStatus', 'failed');
    $this->project->expects($this->once())->method('save');
  }

  /**
   * Worker with mocked collaborators and a project and template that exist.
   */
  private function createWorker(bool $templateExists = TRUE): SodaScsDemoQueueWorker {
    $projectStorage = $this->createMock(EntityStorageInterface::class);
    $projectStorage->method('load')->willReturn($this->project);
    $templateStorage = $this->createMock(EntityStorageInterface::class);
    $templateStorage->method('load')->willReturn($templateExists ? $this->createMock(SodaScsDemoTemplateInterface::class) : NULL);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['soda_scs_project', $projectStorage],
      ['soda_scs_demo_template', $templateStorage],
    ]);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(function (string $name): bool {
      $this->acquiredLocks[] = $name;
      return TRUE;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(self::NOW);

    return new SodaScsDemoQueueWorker(
      [],
      'soda_scs_manager_demo',
      [],
      $this->createMock(ConfigFactoryInterface::class),
      $entityTypeManager,
      $lock,
      $this->logger,
      $this->progressHelper,
      $this->provisioner,
      $this->createMock(SodaScsDemoTemplateBuilder::class),
      $time,
    );
  }

  /**
   * Queue item of a provision job.
   *
   * @param array<string, mixed> $overrides
   *   Values that replace the defaults.
   *
   * @return array<string, mixed>
   *   The queue item.
   */
  private function provisionJob(array $overrides = []): array {
    return $overrides + [
      'operation' => SodaScsDemoManager::OPERATION_PROVISION,
      'operationId' => self::OPERATION_ID,
      'projectId' => 5,
      'queuedAt' => self::NOW,
      'templateId' => 9,
    ];
  }

  /**
   * Runs one provision job on a fresh worker.
   *
   * @param array<string, mixed> $overrides
   *   Values that replace the queue item defaults.
   */
  private function processProvisionJob(array $overrides = []): void {
    $this->createWorker()->processItem($this->provisionJob($overrides));
  }

  /**
   * Lets the provisioner report the given steps and finish.
   *
   * @param list<string> $messages
   *   Step messages in order.
   */
  private function provisionerReports(array $messages): void {
    $this->provisioner->method('apply')->willReturnCallback(static function ($project, $template, callable $reportStep) use ($messages): void {
      foreach ($messages as $message) {
        $reportStep($message);
      }
    });
  }

}
