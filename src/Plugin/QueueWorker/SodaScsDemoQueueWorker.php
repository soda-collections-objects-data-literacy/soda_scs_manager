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
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoNotReadyException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectProvisioner;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectStatus;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateBuilder;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
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
  protected const RETRY_DELAY_SECONDS = 60;

  /**
   * Seconds a job may wait for new applications before it fails.
   */
  protected const MAX_WAIT_SECONDS = 3600;

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
    protected SodaScsProgressHelper $progressHelper,
    protected SodaScsDemoProjectProvisioner $projectProvisioner,
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
      $container->get('soda_scs_manager.progress.helpers'),
      $container->get('soda_scs_manager.demo.project_provisioner'),
      $container->get('soda_scs_manager.demo.template_builder'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $operation = (string) ($data['operation'] ?? '');
    $lockName = $operation === SodaScsDemoManager::OPERATION_BUILD_TEMPLATE
      ? 'soda_scs_manager.demo.template_build'
      : 'soda_scs_manager.demo.project.' . (int) ($data['projectId'] ?? 0);
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT_SECONDS)) {
      throw new DelayedRequeueException(self::RETRY_DELAY_SECONDS);
    }

    $operationId = is_string($data['operationId'] ?? NULL) ? $data['operationId'] : NULL;
    try {
      $this->setOperationStatus($operationId, 'running');
      match ($operation) {
        SodaScsDemoManager::OPERATION_BUILD_TEMPLATE => $this->buildTemplate($data, $operationId),
        SodaScsDemoManager::OPERATION_PROVISION, SodaScsDemoManager::OPERATION_RESET => $this->applyTemplate($data, $operationId),
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
      $this->setOperationStatus($operationId, 'failed');
      return;
    }

    try {
      $this->projectProvisioner->apply($project, $template, $this->stepReporter($operationId));
      $this->setOperationStatus($operationId, 'completed');
    }
    catch (SodaScsDemoNotReadyException $e) {
      if ($this->time->getCurrentTime() - (int) ($data['queuedAt'] ?? 0) < self::MAX_WAIT_SECONDS) {
        $this->setOperationStatus($operationId, 'waiting');
        throw new DelayedRequeueException(self::RETRY_DELAY_SECONDS, $e->getMessage(), 0, $e);
      }
      $this->failProject($project, $operationId, $e);
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
   * Marks a demo project as failed; the user can start a reset to retry.
   */
  protected function failProject(SodaScsProjectInterface $project, ?string $operationId, \Throwable $error): void {
    $this->logger->error('Demo job for project @project failed: @message', [
      '@message' => $error->getMessage(),
      '@project' => $project->id(),
    ]);
    $project->set('demoStatus', SodaScsDemoProjectStatus::Failed->value);
    $project->save();
    $this->reportStep($operationId, (string) new TranslatableMarkup('Setting up the demo failed. Please try to reset it or contact the support.'));
    $this->setOperationStatus($operationId, 'failed');
  }

  /**
   * Adds a progress step; progress problems never stop the job.
   */
  protected function reportStep(?string $operationId, string $message): void {
    if ($operationId === NULL) {
      return;
    }
    try {
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
    return fn (string $message) => $this->reportStep($operationId, $message);
  }

}
