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
use Drupal\soda_scs_manager\Demo\SodaScsDemoServiceAccount;
use Drupal\soda_scs_manager\Demo\SodaScsDemoStatusBuilder;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateBuilder;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Drupal\soda_scs_manager\Plugin\QueueWorker\SodaScsDemoQueueWorker;
use Drupal\soda_scs_manager\Service\SodaScsProjectOwnershipTransfer;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Tests the progress steps and failure reasons of the demo queue worker.
 */
#[CoversMethod(SodaScsDemoQueueWorker::class, 'reportStep')]
#[CoversMethod(SodaScsDemoQueueWorker::class, 'applyTemplate')]
#[CoversMethod(SodaScsDemoQueueWorker::class, 'failProject')]
#[CoversMethod(SodaScsDemoQueueWorker::class, 'recordLastStep')]
#[CoversMethod(SodaScsDemoQueueWorker::class, 'markProjectFailed')]
#[CoversMethod(SodaScsDemoQueueWorker::class, 'processItem')]
#[CoversMethod(SodaScsDemoQueueWorker::class, 'transferSource')]
#[Group('soda_scs_manager')]
class SodaScsDemoQueueWorkerTest extends UnitTestCase {

  private const NOW = 100000;

  private const OPERATION_ID = 'operation-1';

  private const TRANSFER_FAILED_STEP = 'Handing the demo project to scs_demo failed. See the log for details.';

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
   * The mocked ownership transfer.
   */
  private SodaScsProjectOwnershipTransfer&MockObject $ownershipTransfer;

  /**
   * The mocked demo service account.
   */
  private SodaScsDemoServiceAccount&MockObject $serviceAccount;

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
    $this->ownershipTransfer = $this->createMock(SodaScsProjectOwnershipTransfer::class);
    $this->serviceAccount = $this->createMock(SodaScsDemoServiceAccount::class);

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
   */
  public function testReportStepWritesNewMessages(): void {
    $this->provisionerReports(['Creating missing applications', 'Importing the ontology']);

    $this->processProvisionJob();

    $this->assertSame(['Creating missing applications', 'Importing the ontology'], $this->createdSteps);
  }

  /**
   * A job without an operation ID reads and writes no steps.
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
   */
  public function testProjectJobTakesTheProjectLockOfTheDeleteForm(): void {
    $this->processProvisionJob();

    $this->assertSame([SodaScsDemoManager::PROJECT_LOCK_PREFIX . '5'], $this->acquiredLocks);
  }

  /**
   * A source transfer takes the template build lock, not a project lock.
   *
   * The transfer redeploys the WissKI a template build reads from.
   */
  public function testTransferSourceTakesTheTemplateBuildLock(): void {
    $this->serviceAccount->method('ensure')->willReturn(SodaScsResult::success(['account' => $this->createMock(UserInterface::class)], 'ready'));
    $this->ownershipTransfer->method('transfer')->willReturn(SodaScsResult::success(['warnings' => []], 'done'));

    $this->createWorker()->processItem($this->transferJob());

    $this->assertSame(['soda_scs_manager.demo.template_build'], $this->acquiredLocks);
  }

  /**
   * The source project goes to the account that ensure() returned.
   */
  public function testTransferSourceHandsTheProjectToTheServiceAccount(): void {
    $account = $this->createMock(UserInterface::class);
    $this->serviceAccount->expects($this->once())->method('ensure')->willReturn(SodaScsResult::success(['account' => $account], 'ready'));
    $this->ownershipTransfer->expects($this->once())->method('transfer')->with($this->project, $account)
      ->willReturn(SodaScsResult::success(['warnings' => []], 'done'));
    $this->logger->expects($this->never())->method('warning');

    $this->createWorker()->processItem($this->transferJob());

    $this->assertSame(['running', 'completed'], $this->operationStatuses);
    $this->assertSame(['Preparing the account scs_demo', 'Handing the demo project to scs_demo'], $this->createdSteps);
  }

  /**
   * Follow-up warnings of the transfer are logged, the job still completes.
   */
  public function testTransferSourceLogsWarningsAndCompletes(): void {
    $this->project->method('id')->willReturn(5);
    $this->serviceAccount->method('ensure')->willReturn(SodaScsResult::success(['account' => $this->createMock(UserInterface::class)], 'ready'));
    $this->ownershipTransfer->method('transfer')->willReturn(SodaScsResult::success(['warnings' => ['Keycloak groups could not be synced.']], 'done'));
    $this->logger->expects($this->once())->method('warning')->with(
      'Demo source transfer of project @project: @warning',
      ['@project' => 5, '@warning' => 'Keycloak groups could not be synced.'],
    );

    $this->createWorker()->processItem($this->transferJob());

    $this->assertSame(['running', 'completed'], $this->operationStatuses);
  }

  /**
   * Without the service account the project is not touched and the job fails.
   */
  public function testTransferSourceFailsWhenServiceAccountIsNotReady(): void {
    $this->serviceAccount->method('ensure')->willReturn(SodaScsResult::failure(error: 'no password', message: 'Set the password.', log: FALSE));
    $this->ownershipTransfer->expects($this->never())->method('transfer');
    $this->project->method('id')->willReturn(5);
    $this->logger->expects($this->once())->method('error')->with(
      'Handing demo source project @project to scs_demo failed: @reason',
      ['@project' => 5, '@reason' => 'no password'],
    );

    $this->createWorker()->processItem($this->transferJob());

    $this->assertSame(['running', 'failed'], $this->operationStatuses);
    $this->assertSame(['Preparing the account scs_demo', self::TRANSFER_FAILED_STEP], $this->createdSteps);
  }

  /**
   * A failed transfer fails the job with the reason as its last step.
   */
  public function testTransferSourceFailsWithTheReasonOfTheTransfer(): void {
    $this->serviceAccount->method('ensure')->willReturn(SodaScsResult::success(['account' => $this->createMock(UserInterface::class)], 'ready'));
    $this->ownershipTransfer->method('transfer')->willReturn(SodaScsResult::failure(error: 'x', message: 'The project is locked by another job.', log: FALSE));
    $this->project->method('id')->willReturn(5);
    $this->logger->expects($this->once())->method('error')->with(
      'Handing demo source project @project to scs_demo failed: @reason',
      ['@project' => 5, '@reason' => 'x'],
    );

    $this->createWorker()->processItem($this->transferJob());

    $this->assertSame(['running', 'failed'], $this->operationStatuses);
    $this->assertSame(self::TRANSFER_FAILED_STEP, end($this->createdSteps));
  }

  /**
   * Without an error text the message of the result is the logged reason.
   */
  public function testTransferSourceLogsTheMessageWhenTheResultHasNoError(): void {
    $this->serviceAccount->method('ensure')->willReturn(SodaScsResult::success(['account' => $this->createMock(UserInterface::class)], 'ready'));
    $this->ownershipTransfer->method('transfer')->willReturn(SodaScsResult::failure(error: '', message: 'The project is locked by another job.', log: FALSE));
    $this->project->method('id')->willReturn(5);
    $this->logger->expects($this->once())->method('error')->with(
      'Handing demo source project @project to scs_demo failed: @reason',
      ['@project' => 5, '@reason' => 'The project is locked by another job.'],
    );

    $this->createWorker()->processItem($this->transferJob());

    $this->assertSame(self::TRANSFER_FAILED_STEP, end($this->createdSteps));
  }

  /**
   * An exception from the transfer is logged and fails the job; none escapes.
   */
  public function testTransferSourceFailsWhenTheTransferThrows(): void {
    $this->serviceAccount->method('ensure')->willReturn(SodaScsResult::success(['account' => $this->createMock(UserInterface::class)], 'ready'));
    $this->ownershipTransfer->method('transfer')->willThrowException(new \RuntimeException('portainer timeout'));
    $this->project->method('id')->willReturn(5);
    $this->logger->expects($this->once())->method('error')->with(
      'Handing demo source project @project to scs_demo failed: @reason',
      ['@project' => 5, '@reason' => 'portainer timeout'],
    );

    $this->createWorker()->processItem($this->transferJob());

    $this->assertSame(['running', 'failed'], $this->operationStatuses);
    $this->assertSame([
      'Preparing the account scs_demo',
      'Handing the demo project to scs_demo',
      self::TRANSFER_FAILED_STEP,
    ], $this->createdSteps);
  }

  /**
   * An exception from ensure() is logged and fails the job without a transfer.
   */
  public function testTransferSourceFailsWhenEnsureThrows(): void {
    $this->serviceAccount->method('ensure')->willThrowException(new \RuntimeException('keycloak unreachable'));
    $this->ownershipTransfer->expects($this->never())->method('transfer');
    $this->project->method('id')->willReturn(5);
    $this->logger->expects($this->once())->method('error')->with(
      'Handing demo source project @project to scs_demo failed: @reason',
      ['@project' => 5, '@reason' => 'keycloak unreachable'],
    );

    $this->createWorker()->processItem($this->transferJob());

    $this->assertSame(['running', 'failed'], $this->operationStatuses);
    $this->assertSame(self::TRANSFER_FAILED_STEP, end($this->createdSteps));
  }

  /**
   * A project that no longer exists drops the job without a transfer.
   */
  public function testTransferSourceDropsJobOfDeletedProject(): void {
    $this->project = $this->createMock(SodaScsProjectInterface::class);
    $this->serviceAccount->expects($this->never())->method('ensure');
    $this->ownershipTransfer->expects($this->never())->method('transfer');
    $this->logger->expects($this->once())->method('warning')
      ->with('Demo source transfer dropped: project @project no longer exists.', ['@project' => 5]);

    $this->createWorker(TRUE, FALSE)->processItem($this->transferJob());

    $this->assertSame(['running', 'failed'], $this->operationStatuses);
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
  private function createWorker(bool $templateExists = TRUE, bool $projectExists = TRUE): SodaScsDemoQueueWorker {
    $projectStorage = $this->createMock(EntityStorageInterface::class);
    $projectStorage->method('load')->willReturn($projectExists ? $this->project : NULL);
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
      $this->ownershipTransfer,
      $this->progressHelper,
      $this->provisioner,
      $this->serviceAccount,
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
   * Queue item of a source transfer job.
   *
   * @return array<string, mixed>
   *   The queue item.
   */
  private function transferJob(): array {
    return [
      'operation' => SodaScsDemoManager::OPERATION_TRANSFER_SOURCE,
      'operationId' => self::OPERATION_ID,
      'projectId' => 5,
      'queuedAt' => self::NOW,
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
