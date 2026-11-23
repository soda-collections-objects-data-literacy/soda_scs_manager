<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Demo\SodaScsDemoIncompleteAppException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoNotReadyException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectProvisioner;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectStatus;
use Drupal\soda_scs_manager\Demo\SodaScsDemoServiceAccount;
use Drupal\soda_scs_manager\Demo\SodaScsDemoStatusBuilder;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateBuilder;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Drupal\soda_scs_manager\Service\SodaScsProjectOwnershipTransfer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds demo templates and provisions or resets user demo projects.
 *
 * Not run by cron: jobs take many minutes. Creating a version, a demo or a
 * reset spawns "drush queue:run soda_scs_manager_demo" (see
 * docs/technical/demo-project.md).
 * Failed jobs are not retried automatically; the status shows the failure
 * and users or admins start them again from the UI.
 */
#[QueueWorker(
  id: SodaScsDemoManager::QUEUE_NAME,
  title: new TranslatableMarkup('SCS demo projects'),
)]
class SodaScsDemoQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Seconds a job may hold its lock; longer than any import.
   */
  protected const LOCK_TIMEOUT_SECONDS = 7200.0;

  /**
   * Seconds until a job that waits for an installation is tried again.
   */
  protected const RETRY_DELAY_SECONDS = SodaScsDemoStatusBuilder::RETRY_DELAY_SECONDS;

  /**
   * Seconds a job may wait for new applications before it fails.
   */
  protected const MAX_WAIT_SECONDS = 3600;

  /**
   * Source string of the last step the current job reported.
   */
  protected string $lastStep = '';

  /**
   * Constructs the worker.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LockBackendInterface $lock,
    protected LoggerInterface $logger,
    protected SodaScsProjectOwnershipTransfer $ownershipTransfer,
    protected SodaScsProgressHelper $progressHelper,
    protected SodaScsDemoProjectProvisioner $projectProvisioner,
    protected SodaScsDemoServiceAccount $serviceAccount,
    protected SodaScsDemoTemplateBuilder $templateBuilder,
    protected TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('lock'),
      $container->get('logger.channel.soda_scs_manager'),
      $container->get('soda_scs_manager.project_ownership_transfer'),
      $container->get('soda_scs_manager.progress.helpers'),
      $container->get('soda_scs_manager.demo.project_provisioner'),
      $container->get('soda_scs_manager.demo.service_account'),
      $container->get('soda_scs_manager.demo.template_builder'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $operation = (string) ($data['operation'] ?? '');
    // A source transfer redeploys the WissKI a build reads from.
    $templateOperations = [
      SodaScsDemoManager::OPERATION_BUILD_TEMPLATE,
      SodaScsDemoManager::OPERATION_TRANSFER_SOURCE,
    ];
    $lockName = in_array($operation, $templateOperations, TRUE)
      ? 'soda_scs_manager.demo.template_build'
      : SodaScsDemoManager::PROJECT_LOCK_PREFIX . (int) ($data['projectId'] ?? 0);
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT_SECONDS)) {
      throw new DelayedRequeueException(self::RETRY_DELAY_SECONDS);
    }

    $operationId = is_string($data['operationId'] ?? NULL) ? $data['operationId'] : NULL;
    try {
      $this->setOperationStatus($operationId, 'running');
      match ($operation) {
        SodaScsDemoManager::OPERATION_BUILD_TEMPLATE => $this->buildTemplate($data, $operationId),
        SodaScsDemoManager::OPERATION_PROVISION, SodaScsDemoManager::OPERATION_RESET => $this->applyTemplate($data, $operationId),
        SodaScsDemoManager::OPERATION_TRANSFER_SOURCE => $this->transferSource($data, $operationId),
        default => $this->logger->error('Unknown demo queue operation @operation dropped.', ['@operation' => $operation]),
      };
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Provisions or resets a demo project; requeues while apps install.
   *
   * @throws \Drupal\Core\Queue\DelayedRequeueException
   */
  protected function applyTemplate(array $data, ?string $operationId): void {
    $project = $this->entityTypeManager->getStorage('soda_scs_project')->load((int) ($data['projectId'] ?? 0));
    $template = $this->entityTypeManager->getStorage('soda_scs_demo_template')->load((int) ($data['templateId'] ?? 0));
    if (!$project instanceof SodaScsProjectInterface || !$template instanceof SodaScsDemoTemplateInterface) {
      $this->logger->warning('Demo job for project @project dropped: project or template @template no longer exists.', [
        '@project' => $data['projectId'] ?? '-',
        '@template' => $data['templateId'] ?? '-',
      ]);
      if ($project instanceof SodaScsProjectInterface) {
        // Without this the project stays busy and its status polls forever.
        $this->markProjectFailed($project, $operationId, SodaScsDemoStatusBuilder::REASON_TEMPLATE_GONE);
      }
      else {
        $this->setOperationStatus($operationId, 'failed');
      }
      return;
    }

    $this->lastStep = '';
    try {
      $this->projectProvisioner->apply($project, $template, $this->stepReporter($operationId));
      $this->setOperationStatus($operationId, 'completed');
    }
    catch (SodaScsDemoNotReadyException $e) {
      if ($this->time->getCurrentTime() - (int) ($data['queuedAt'] ?? 0) < self::MAX_WAIT_SECONDS) {
        $this->setOperationStatus($operationId, 'waiting');
        throw new DelayedRequeueException(self::RETRY_DELAY_SECONDS, $e->getMessage(), 0, $e);
      }
      $this->failProject($project, $operationId, $e, SodaScsDemoStatusBuilder::REASON_TIMEOUT);
    }
    catch (SodaScsDemoIncompleteAppException $e) {
      $this->failProject($project, $operationId, $e, SodaScsDemoStatusBuilder::REASON_WISSKI_INCOMPLETE);
    }
    catch (\Throwable $e) {
      $this->failProject($project, $operationId, $e);
    }
  }

  /**
   * Builds a template; activates it when no other version is active.
   */
  protected function buildTemplate(array $data, ?string $operationId): void {
    $template = $this->entityTypeManager->getStorage('soda_scs_demo_template')->load((int) ($data['templateId'] ?? 0));
    if (!$template instanceof SodaScsDemoTemplateInterface) {
      $this->setOperationStatus($operationId, 'failed');
      return;
    }

    try {
      $this->templateBuilder->build($template, $this->stepReporter($operationId));
    }
    catch (\Throwable $e) {
      // The builder has logged the cause and marked the template failed.
      $this->reportStep($operationId, $e->getMessage());
      $this->setOperationStatus($operationId, 'failed');
      return;
    }

    $settings = $this->configFactory->getEditable('soda_scs_manager.settings');
    if ((int) $settings->get('demo.activeTemplateId') <= 0) {
      $settings->set('demo.activeTemplateId', (int) $template->id())->save();
    }
    $this->setOperationStatus($operationId, 'completed');
  }

  /**
   * Logs why a demo job failed and marks its project as failed.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The demo project.
   * @param string|null $operationId
   *   The progress operation of the job.
   * @param \Throwable $error
   *   The cause, written to the log only.
   * @param string|null $reason
   *   A SodaScsDemoStatusBuilder::REASON_* source string, or NULL when the
   *   step the job failed at is the reason.
   */
  protected function failProject(SodaScsProjectInterface $project, ?string $operationId, \Throwable $error, ?string $reason = NULL): void {
    $this->logger->error('Demo job for project @project failed: @message', [
      '@message' => $error->getMessage(),
      '@project' => $project->id(),
    ]);
    $this->markProjectFailed($project, $operationId, $reason);
  }

  /**
   * Marks a demo project as failed; the user can start a reset to retry.
   *
   * The status shows the last step as the reason, translated for the viewer,
   * so the stored step is a source string: the given reason, or the step the
   * job failed at.
   */
  protected function markProjectFailed(SodaScsProjectInterface $project, ?string $operationId, ?string $reason): void {
    $project->set('demoStatus', SodaScsDemoProjectStatus::Failed->value);
    $project->save();
    $this->recordLastStep($operationId, $reason ?? $this->lastStep);
    $this->setOperationStatus($operationId, 'failed');
  }

  /**
   * Makes $message the operation's last step, even when it was recorded before.
   *
   * A job that waited records its steps once; when it fails at an earlier
   * step on a later attempt, that step must still come last.
   */
  protected function recordLastStep(?string $operationId, string $message): void {
    if ($operationId === NULL || $message === '') {
      return;
    }
    try {
      $recorded = array_column($this->progressHelper->findLatestStepsByOperation($operationId, [], 'created', 'ASC'), 'message');
      if (end($recorded) !== $message) {
        $this->progressHelper->createStep($operationId, $message);
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not record demo progress: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Adds a progress step; progress problems never stop the job.
   *
   * Stores the source string of translatable messages, so the status shows
   * the step in the viewer's language, not in the site default the queue
   * runs in. A job that waits for an installation runs its steps again on
   * every attempt; a step the job already has is not written a second time.
   */
  protected function reportStep(?string $operationId, TranslatableMarkup|string $message): void {
    $message = $message instanceof TranslatableMarkup ? $message->getUntranslatedString() : $message;
    $this->lastStep = $message;
    if ($operationId === NULL) {
      return;
    }
    try {
      $recorded = array_column($this->progressHelper->findLatestStepsByOperation($operationId), 'message');
      if (in_array($message, $recorded, TRUE)) {
        return;
      }
      $this->progressHelper->createStep($operationId, $message);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not record demo progress: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Updates the progress operation status.
   */
  protected function setOperationStatus(?string $operationId, string $status): void {
    if ($operationId !== NULL) {
      $this->progressHelper->updateOperation($operationId, ['status' => $status]);
    }
  }

  /**
   * Callback that records progress steps of an operation.
   */
  protected function stepReporter(?string $operationId): callable {
    return fn (TranslatableMarkup|string $message) => $this->reportStep($operationId, $message);
  }

  /**
   * Hands the demo source project to the service account scs_demo.
   */
  protected function transferSource(array $data, ?string $operationId): void {
    $project = $this->entityTypeManager->getStorage('soda_scs_project')->load((int) ($data['projectId'] ?? 0));
    if (!$project instanceof SodaScsProjectInterface) {
      $this->logger->warning('Demo source transfer dropped: project @project no longer exists.', ['@project' => $data['projectId'] ?? '-']);
      $this->setOperationStatus($operationId, 'failed');
      return;
    }

    $failed = function (string $reason) use ($operationId, $project): void {
      $this->logger->error('Handing demo source project @project to scs_demo failed: @reason', [
        '@project' => $project->id(),
        '@reason' => $reason,
      ]);
      $this->reportStep($operationId, new TranslatableMarkup('Handing the demo project to scs_demo failed. See the log for details.'));
      $this->setOperationStatus($operationId, 'failed');
    };
    try {
      $this->reportStep($operationId, new TranslatableMarkup('Preparing the account scs_demo'));
      $account = $this->serviceAccount->ensure();
      if (!$account->success) {
        $failed((string) ($account->error ?: $account->message));
        return;
      }

      $this->reportStep($operationId, new TranslatableMarkup('Handing the demo project to scs_demo'));
      $result = $this->ownershipTransfer->transfer($project, $account->data['account']);
      if (!$result->success) {
        $failed((string) ($result->error ?: $result->message));
        return;
      }
    }
    catch (\Throwable $e) {
      $failed($e->getMessage());
      return;
    }
    foreach ($result->data['warnings'] ?? [] as $warning) {
      $this->logger->warning('Demo source transfer of project @project: @warning', [
        '@project' => $project->id(),
        '@warning' => $warning,
      ]);
    }
    $this->setOperationStatus($operationId, 'completed');
  }

}
