<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectNameHelpers;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\soda_scs_manager\Service\SodaScsProjectProvisioner;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Entry point for demo templates and user demo projects.
 *
 * Requests are cheap and synchronous: they validate, record the new status
 * and queue the long-running work for SodaScsDemoQueueWorker.
 */
class SodaScsDemoManager {

  use StringTranslationTrait;

  public const QUEUE_NAME = 'soda_scs_manager_demo';
  public const OPERATION_BUILD_TEMPLATE = 'build_template';
  public const OPERATION_PROVISION = 'provision';
  public const OPERATION_RESET = 'reset';

  /**
   * Fixed label of the central demo project.
   */
  public const SOURCE_PROJECT_LABEL = 'Demo Projekt';

  /**
   * State key prefixes for the progress operation of a project or template.
   */
  protected const PROJECT_OPERATION_STATE_PREFIX = 'soda_scs_manager.demo_operation.project.';
  protected const TEMPLATE_OPERATION_STATE_PREFIX = 'soda_scs_manager.demo_operation.template.';

  /**
   * Tries "<user> Demo", then "<user> Demo 2" and so on.
   */
  protected const PROJECT_LABEL_ATTEMPTS = 10;

  /**
   * Constructs the manager.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.demo.label_builder')]
    protected SodaScsDemoLabelBuilder $labelBuilder,
    #[Autowire(service: 'soda_scs_manager.progress.helpers')]
    protected SodaScsProgressHelper $progressHelper,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    protected SodaScsProjectHelpers $projectHelpers,
    #[Autowire(service: 'soda_scs_manager.nextcloud_project_mount.manager')]
    protected NextcloudProjectMountManager $projectMountManager,
    #[Autowire(service: 'soda_scs_manager.project_name.helpers')]
    protected SodaScsProjectNameHelpers $projectNameHelpers,
    #[Autowire(service: 'soda_scs_manager.project.provisioner')]
    protected SodaScsProjectProvisioner $projectProvisioner,
    protected QueueFactory $queueFactory,
    #[Autowire(service: 'soda_scs_manager.demo.queue_runner')]
    protected SodaScsDemoQueueRunner $queueRunner,
    protected StateInterface $state,
    #[Autowire(service: 'soda_scs_manager.demo.template_builder')]
    protected SodaScsDemoTemplateBuilder $templateBuilder,
    #[Autowire(service: 'datetime.time')]
    protected TimeInterface $time,
  ) {}

  /**
   * The template new and reset demos use, if it is ready.
   */
  public function activeTemplate(): ?SodaScsDemoTemplateInterface {
    $templateId = (int) $this->configFactory->get('soda_scs_manager.settings')->get('demo.activeTemplateId');
    if ($templateId <= 0) {
      return NULL;
    }
    $template = $this->entityTypeManager->getStorage('soda_scs_demo_template')->load($templateId);
    return $template instanceof SodaScsDemoTemplateInterface && $template->isReady() ? $template : NULL;
  }

  /**
   * Deletes a template and its files unless it is active or in use.
   */
  public function deleteTemplate(SodaScsDemoTemplateInterface $template): SodaScsResult {
    if ($this->isActiveTemplate($template) || $this->templateUsageCount($template) > 0) {
      return SodaScsResult::failure(
        message: (string) $this->t('Version @version is active or used by demo projects and cannot be deleted.', ['@version' => $template->getVersion()]),
        error: 'Template in use.',
        log: FALSE,
      );
    }
    if ($template->getStatus() === SodaScsDemoTemplateInterface::STATUS_BUILDING) {
      return SodaScsResult::failure(
        message: (string) $this->t('Version @version is still being built.', ['@version' => $template->getVersion()]),
        error: 'Template building.',
        log: FALSE,
      );
    }
    $this->templateBuilder->deleteArtifacts($template);
    $this->state->delete(self::TEMPLATE_OPERATION_STATE_PREFIX . $template->id());
    $template->delete();
    return SodaScsResult::success(
      message: (string) $this->t('Version @version has been deleted.', ['@version' => $template->getVersion()]),
      data: [],
    );
  }

  /**
   * The demo project of a user, if any.
   */
  public function findDemoProject(AccountInterface $account): ?SodaScsProjectInterface {
    $ids = $this->entityTypeManager->getStorage('soda_scs_project')->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner', $account->id())
      ->exists('demoStatus')
      ->sort('id')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $project = $this->entityTypeManager->getStorage('soda_scs_project')->load(reset($ids));
    return $project instanceof SodaScsProjectInterface ? $project : NULL;
  }

  /**
   * Whether a template is the configured active one.
   */
  public function isActiveTemplate(SodaScsDemoTemplateInterface $template): bool {
    return (int) $this->configFactory->get('soda_scs_manager.settings')->get('demo.activeTemplateId') === (int) $template->id();
  }

  /**
   * Whether a project is a user demo project.
   */
  public function isDemoProject(SodaScsProjectInterface $project): bool {
    return $project->hasField('demoStatus') && !$project->get('demoStatus')->isEmpty();
  }

  /**
   * Highest version of all templates, including failed ones.
   */
  public function latestVersion(): ?string {
    $templates = $this->templatesByVersion();
    return $templates === [] ? NULL : reset($templates)->getVersion();
  }

  /**
   * Ready versions after the current one up to the target, newest first.
   *
   * Used to show a demo owner what a reset to the target brings.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface[]
   *   The templates.
   */
  public function newerReadyTemplates(SodaScsDemoTemplateInterface $current, SodaScsDemoTemplateInterface $target): array {
    return array_values(array_filter(
      $this->templatesByVersion(),
      static fn (SodaScsDemoTemplateInterface $template): bool => $template->isReady()
        && SodaScsDemoVersion::compare($template->getVersion(), $current->getVersion()) > 0
        && SodaScsDemoVersion::compare($template->getVersion(), $target->getVersion()) <= 0,
    ));
  }

  /**
   * Progress operation id of the last demo job of a project.
   */
  public function projectOperationId(SodaScsProjectInterface $project): ?string {
    return $this->state->get(self::PROJECT_OPERATION_STATE_PREFIX . $project->id());
  }

  /**
   * Creates the user's demo project and queues filling it.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   On success data['project'] holds the new project.
   */
  public function requestDemoProject(UserInterface $user): SodaScsResult {
    $template = $this->activeTemplate();
    if ($template === NULL) {
      return SodaScsResult::failure(
        message: (string) $this->t('No demo is available at the moment.'),
        error: 'No active demo template.',
        log: FALSE,
      );
    }
    if ($this->findDemoProject($user) !== NULL) {
      return SodaScsResult::failure(
        message: (string) $this->t('You already have a demo project.'),
        error: 'Demo project exists.',
        log: FALSE,
      );
    }

    $result = $this->provisionProject($user);
    if (!$result->success) {
      return $result;
    }
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $result->data['project'];
    $project->set('demoStatus', SodaScsDemoProjectStatus::Provisioning->value);
    $project->set('demoTemplate', $template->id());
    $project->save();
    $this->enqueueProjectJob(self::OPERATION_PROVISION, $project, $template);

    return $this->withRunner(SodaScsResult::success(
      message: (string) $this->t('Your demo project is being set up. This takes a few minutes.'),
      data: ['project' => $project],
    ));
  }

  /**
   * Queues resetting a demo project to the active template.
   */
  public function requestReset(SodaScsProjectInterface $project): SodaScsResult {
    $template = $this->activeTemplate();
    if (!$this->isDemoProject($project) || $template === NULL) {
      return SodaScsResult::failure(
        message: (string) $this->t('This demo project cannot be reset right now.'),
        error: 'Not a demo project or no active template.',
        log: FALSE,
      );
    }
    $status = SodaScsDemoProjectStatus::tryFrom((string) $project->get('demoStatus')->value);
    if ($status?->isBusy()) {
      return SodaScsResult::failure(
        message: (string) $this->t('The demo project is already being prepared.'),
        error: 'Demo project busy.',
        log: FALSE,
      );
    }
    $project->set('demoStatus', SodaScsDemoProjectStatus::Resetting->value);
    $project->save();
    $this->enqueueProjectJob(self::OPERATION_RESET, $project, $template);

    return $this->withRunner(SodaScsResult::success(
      message: (string) $this->t('The demo project is being reset. This takes a few minutes.'),
      data: ['project' => $project],
    ));
  }

  /**
   * Creates a template version and queues its build.
   *
   * @param string $changelog
   *   What changed compared to the previous version; required.
   * @param string $version
   *   New "major.minor.patch" version, higher than all existing ones.
   *   The same number again replaces a failed build of that version.
   */
  public function requestTemplateBuild(string $changelog, string $version): SodaScsResult {
    $source = $this->sourceProject();
    if ($source === NULL) {
      return SodaScsResult::failure(
        message: (string) $this->t('Choose the demo project first.'),
        error: 'No demo source project.',
        log: FALSE,
      );
    }
    $versionError = $this->validateNewVersion($version);
    if ($versionError !== NULL) {
      return SodaScsResult::failure(message: $versionError, error: 'Invalid demo version.', log: FALSE);
    }
    if (trim($changelog) === '') {
      return SodaScsResult::failure(
        message: (string) $this->t('Describe the changes of this version in the changelog.'),
        error: 'Empty demo changelog.',
        log: FALSE,
      );
    }
    $existing = $this->templateWithVersion($version);
    if ($existing !== NULL && $existing->getStatus() === SodaScsDemoTemplateInterface::STATUS_FAILED) {
      $deleteResult = $this->deleteTemplate($existing);
      if (!$deleteResult->success) {
        return $deleteResult;
      }
    }
    $template = $this->templateBuilder->createPendingTemplate(trim($changelog), $source, $version);
    $operationId = $this->progressHelper->createOperation('demo_' . self::OPERATION_BUILD_TEMPLATE, 'queued');
    if ($operationId !== NULL) {
      $this->state->set(self::TEMPLATE_OPERATION_STATE_PREFIX . $template->id(), $operationId);
    }
    $this->queueFactory->get(self::QUEUE_NAME)->createItem([
      'operation' => self::OPERATION_BUILD_TEMPLATE,
      'operationId' => $operationId,
      'queuedAt' => $this->time->getCurrentTime(),
      'templateId' => (int) $template->id(),
    ]);
    return $this->withRunner(SodaScsResult::success(
      message: (string) $this->t('Version @version is being built in the background.', ['@version' => $template->getVersion()]),
      data: ['template' => $template],
    ));
  }

  /**
   * Makes a ready template the one used for new demos and resets.
   */
  public function setActiveTemplate(SodaScsDemoTemplateInterface $template): SodaScsResult {
    if (!$template->isReady()) {
      return SodaScsResult::failure(
        message: (string) $this->t('Only finished versions can be activated.'),
        error: 'Template not ready.',
        log: FALSE,
      );
    }
    $this->configFactory->getEditable('soda_scs_manager.settings')
      ->set('demo.activeTemplateId', (int) $template->id())
      ->save();
    return SodaScsResult::success(
      message: (string) $this->t('Version @version is now used for demo projects.', ['@version' => $template->getVersion()]),
      data: [],
    );
  }

  /**
   * Makes a project the central demo project and names it "Demo Projekt".
   */
  public function setSourceProject(SodaScsProjectInterface $project): SodaScsResult {
    if ($this->isDemoProject($project)) {
      return SodaScsResult::failure(
        message: (string) $this->t('A user demo project cannot be the source of demos.'),
        error: 'Source is a demo project.',
        log: FALSE,
      );
    }
    $renameResult = $this->renameProject($project, self::SOURCE_PROJECT_LABEL);
    if (!$renameResult->success) {
      return $renameResult;
    }
    $this->configFactory->getEditable('soda_scs_manager.settings')
      ->set('demo.sourceProjectId', (int) $project->id())
      ->save();
    return SodaScsResult::success(
      message: (string) $this->t('@project is now the demo project.', ['@project' => $project->label()]),
      data: [],
    );
  }

  /**
   * The central demo project, if configured.
   */
  public function sourceProject(): ?SodaScsProjectInterface {
    $projectId = (int) $this->configFactory->get('soda_scs_manager.settings')->get('demo.sourceProjectId');
    if ($projectId <= 0) {
      return NULL;
    }
    $project = $this->entityTypeManager->getStorage('soda_scs_project')->load($projectId);
    return $project instanceof SodaScsProjectInterface ? $project : NULL;
  }

  /**
   * Progress operation id of a template build.
   */
  public function templateOperationId(SodaScsDemoTemplateInterface $template): ?string {
    return $this->state->get(self::TEMPLATE_OPERATION_STATE_PREFIX . $template->id());
  }

  /**
   * Status of a version; building versions include their latest step.
   */
  public function templateStatusText(SodaScsDemoTemplateInterface $template): string {
    $status = match ($template->getStatus()) {
      SodaScsDemoTemplateInterface::STATUS_READY => (string) $this->t('Ready'),
      SodaScsDemoTemplateInterface::STATUS_FAILED => (string) $this->t('Failed'),
      default => (string) $this->t('Building'),
    };
    $operationId = $this->templateOperationId($template);
    if ($template->getStatus() !== SodaScsDemoTemplateInterface::STATUS_BUILDING || $operationId === NULL) {
      return $status;
    }
    $steps = $this->progressHelper->findLatestStepsByOperation($operationId, [], 'created', 'DESC', 1);
    $latestMessage = (string) ($steps[0]['message'] ?? $this->t('Waiting for the job runner'));
    return $status . ': ' . $latestMessage;
  }

  /**
   * All templates, newest version first.
   *
   * Sorted in PHP because the stored strings do not sort numerically.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface[]
   *   The templates.
   */
  public function templatesByVersion(): array {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface[] $templates */
    $templates = array_values($this->entityTypeManager->getStorage('soda_scs_demo_template')->loadMultiple());
    usort(
      $templates,
      static fn (SodaScsDemoTemplateInterface $first, SodaScsDemoTemplateInterface $second): int => SodaScsDemoVersion::compare($second->getVersion(), $first->getVersion()),
    );
    return $templates;
  }

  /**
   * Number of demo projects created from or reset to a template.
   */
  public function templateUsageCount(SodaScsDemoTemplateInterface $template): int {
    return (int) $this->entityTypeManager->getStorage('soda_scs_project')->getQuery()
      ->accessCheck(FALSE)
      ->condition('demoTemplate', $template->id())
      ->count()
      ->execute();
  }

  /**
   * The template stored under a version number, if any.
   */
  public function templateWithVersion(string $version): ?SodaScsDemoTemplateInterface {
    $ids = $this->entityTypeManager->getStorage('soda_scs_demo_template')->getQuery()
      ->accessCheck(FALSE)
      ->condition('version', $version)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $template = $this->entityTypeManager->getStorage('soda_scs_demo_template')->load(reset($ids));
    return $template instanceof SodaScsDemoTemplateInterface ? $template : NULL;
  }

  /**
   * Why a version cannot be used for a new template, or NULL if it can.
   *
   * A failed build of the same number can be replaced. A finished or running
   * version keeps its number.
   */
  public function validateNewVersion(string $version): ?string {
    if (!SodaScsDemoVersion::isValid($version)) {
      return (string) $this->t('Enter the version as major.minor.patch, e.g. @example.', ['@example' => SodaScsDemoVersion::INITIAL]);
    }
    $existing = $this->templateWithVersion($version);
    if ($existing !== NULL) {
      if ($existing->getStatus() === SodaScsDemoTemplateInterface::STATUS_FAILED) {
        return NULL;
      }
      if ($existing->getStatus() === SodaScsDemoTemplateInterface::STATUS_BUILDING) {
        return (string) $this->t('Version @version is still being built.', ['@version' => $version]);
      }
      return (string) $this->t('Version @version already exists.', ['@version' => $version]);
    }
    $latest = $this->latestVersion();
    if ($latest !== NULL && SodaScsDemoVersion::compare($version, $latest) <= 0) {
      return (string) $this->t('The version must be higher than the latest version @latest.', ['@latest' => $latest]);
    }
    return NULL;
  }

  /**
   * Queues a provision or reset job and records its progress operation.
   */
  protected function enqueueProjectJob(string $operation, SodaScsProjectInterface $project, SodaScsDemoTemplateInterface $template): void {
    $operationId = $this->progressHelper->createOperation('demo_' . $operation, 'queued');
    if ($operationId !== NULL) {
      $this->state->set(self::PROJECT_OPERATION_STATE_PREFIX . $project->id(), $operationId);
    }
    $this->queueFactory->get(self::QUEUE_NAME)->createItem([
      'operation' => $operation,
      'operationId' => $operationId,
      'projectId' => (int) $project->id(),
      'queuedAt' => $this->time->getCurrentTime(),
      'templateId' => (int) $template->id(),
    ]);
  }

  /**
   * Creates "<user> Demo", falling back to a numbered label when taken.
   */
  protected function provisionProject(UserInterface $user): SodaScsResult {
    $baseLabel = $this->labelBuilder->projectLabel((string) $user->getAccountName());
    $result = NULL;
    for ($attempt = 1; $attempt <= self::PROJECT_LABEL_ATTEMPTS; $attempt++) {
      $label = $attempt === 1 ? $baseLabel : $baseLabel . ' ' . $attempt;
      if ($this->projectNameHelpers->checkLabel($label)['error'] !== NULL) {
        continue;
      }
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
      $project = $this->entityTypeManager->getStorage('soda_scs_project')->create([
        'bundle' => 'soda_scs_project',
        'label' => $label,
        'owner' => $user->id(),
      ]);
      $result = $this->projectProvisioner->provision($project);
      if ($result->success) {
        return $result;
      }
    }
    return $result ?? SodaScsResult::failure(
      message: (string) $this->t('Could not find a free name for your demo project.'),
      error: 'No free demo project label.',
    );
  }

  /**
   * Renames a project including its Keycloak group and Team Folder.
   *
   * Mirrors the project edit form; Nextcloud conflicts keep the old name.
   */
  protected function renameProject(SodaScsProjectInterface $project, string $label): SodaScsResult {
    $previousLabel = (string) $project->label();
    if ($previousLabel === $label) {
      return SodaScsResult::success(message: '', data: []);
    }
    $check = $this->projectNameHelpers->checkLabel($label, $project->id());
    if ($check['error'] !== NULL) {
      return SodaScsResult::failure(
        message: (string) $this->projectNameHelpers->errorMessage($check['error']),
        error: 'Project name rejected: ' . $check['error'],
        log: FALSE,
      );
    }

    $project->set('label', $label);
    $project->save();
    $this->projectHelpers->updateProjectGroupAttributes($project);
    $folderResult = $this->projectHelpers->updateProjectTeamFolderLabel($project);
    if (!$folderResult->success && !empty($folderResult->data['conflict'])) {
      $project->set('label', $previousLabel);
      $project->save();
      $this->projectHelpers->updateProjectGroupAttributes($project);
      return $folderResult;
    }
    if ($folderResult->success && $this->projectMountManager->needsProjectMount($project)) {
      $this->projectMountManager->ensureProjectMounted($project);
    }
    return SodaScsResult::success(message: '', data: []);
  }

  /**
   * Starts the queue runner and keeps the queued result when that worked.
   */
  protected function withRunner(SodaScsResult $queued): SodaScsResult {
    $started = $this->queueRunner->start();
    return $started->success ? $queued : $started;
  }

}
