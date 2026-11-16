<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Status of a user demo project for badges, panels and polling.
 */
class SodaScsDemoStatusBuilder {

  use StringTranslationTrait;

  public const VARIANT_BADGE = 'badge';
  public const VARIANT_PANEL = 'panel';

  /**
   * Steps per operation ID, read once per request.
   *
   * @var array<string, list<array{created: float, message: string}>>
   */
  protected array $stepCache = [];

  /**
   * Progress messages in the order the provisioner reports them.
   *
   * A message is stored when its step starts, so a later message means the
   * earlier step has finished. The worker stores source strings; jobs from
   * before that stored the translation, which stepSource() still maps back.
   *
   * @var list<string>
   */
  protected const SETUP_STEPS = [
    'Creating missing applications',
    'Waiting for the WissKI installation to finish',
    'Copying files into the Team Folder',
    'Restoring the Jupyter home directory',
    'Importing the ontology',
    'Restoring the WissKI',
    'Setting the WissKI API password',
    'The demo project is ready.',
  ];

  /**
   * Step the job repeats until the WissKI installation has finished.
   */
  protected const WAIT_STEP = 'Waiting for the WissKI installation to finish';

  /**
   * Seconds between two attempts of a waiting job; see the queue worker.
   */
  public const RETRY_DELAY_SECONDS = 60;

  /**
   * Stored failure reason: the WissKI did not finish installing in time.
   */
  public const REASON_TIMEOUT = 'The WissKI installation did not finish within an hour.';

  /**
   * Stored failure reason: the job's template version was deleted.
   */
  public const REASON_TEMPLATE_GONE = 'The demo template no longer exists.';

  /**
   * Stored failure reason: the project's WissKI stack has no WissKI.
   */
  public const REASON_WISSKI_INCOMPLETE = 'The WissKI of this demo is incomplete. Delete it in the project, then reset the demo.';

  /**
   * Constructs the builder.
   */
  public function __construct(
    #[Autowire(service: 'date.formatter')]
    protected DateFormatterInterface $dateFormatter,
    #[Autowire(service: 'soda_scs_manager.demo.manager')]
    protected SodaScsDemoManager $demoManager,
    #[Autowire(service: 'soda_scs_manager.progress.helpers')]
    protected SodaScsProgressHelper $progressHelper,
    #[Autowire(service: 'soda_scs_manager.demo.project_apps')]
    protected SodaScsDemoProjectApps $projectApps,
    #[Autowire(service: 'datetime.time')]
    protected TimeInterface $timeService,
  ) {}

  /**
   * Render array of the demo status, or NULL for non-demo projects.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewer; only the owner gets the reset action.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project.
   * @param string $variant
   *   VARIANT_BADGE for dashboard cards, VARIANT_PANEL for the project page.
   */
  public function build(AccountInterface $account, SodaScsProjectInterface $project, string $variant): ?array {
    if (!$this->demoManager->isDemoProject($project)) {
      return NULL;
    }
    $status = $this->status($project);
    $activeTemplate = $this->demoManager->activeTemplate();
    $currentTemplate = $project->get('demoTemplate')->entity;
    $currentVersion = $currentTemplate instanceof SodaScsDemoTemplateInterface ? $currentTemplate->getVersion() : NULL;
    $isOwner = (int) $project->getOwnerId() === (int) $account->id();

    return [
      '#theme' => 'soda_scs_manager__demo_status',
      '#variant' => $variant,
      '#status' => $status->value,
      '#status_label' => $this->statusLabel($status),
      '#is_busy' => $status->isBusy(),
      '#message' => '',
      '#job_line' => $this->jobLine($project),
      '#status_url' => Url::fromRoute('soda_scs_manager.demo_project_status', ['soda_scs_project' => $project->id()])->toString(),
      '#reset_url' => $isOwner && !$status->isBusy() && $activeTemplate !== NULL
        ? Url::fromRoute('soda_scs_manager.demo_project_reset', ['soda_scs_project' => $project->id()])->toString()
        : '',
      '#apps' => $status->isBusy() ? $this->appProgress($project) : [],
      '#current_version' => $currentVersion,
      '#newer_version' => $activeTemplate !== NULL && $currentVersion !== NULL && SodaScsDemoVersion::compare($activeTemplate->getVersion(), $currentVersion) > 0
        ? $activeTemplate->getVersion()
        : NULL,
      '#attached' => $status->isBusy() ? ['library' => ['soda_scs_manager/demoProjectStatus']] : [],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Status data for the polling endpoint.
   *
   * @return array{apps: list<array{detail: string, key: string, label: string}>, createdCount: int, isBusy: bool, jobLine: string, status: string, statusLabel: string}
   *   JSON-ready status. Apps lists only applications that are not ready yet;
   *   jobLine says what the demo job does and since when.
   */
  public function data(SodaScsProjectInterface $project): array {
    $status = $this->status($project);
    $isBusy = $status->isBusy();
    return [
      'apps'         => $isBusy ? $this->appProgress($project) : [],
      'createdCount' => $isBusy ? $this->createdCount($project) : 0,
      'isBusy'       => $isBusy,
      'jobLine'      => $this->jobLine($project),
      'status'       => $status->value,
      'statusLabel'  => $this->statusLabel($status),
    ];
  }

  /**
   * Current step of one application that is not ready yet.
   */
  protected function appDetail(string $key, bool $isPresent): string {
    if (!$isPresent) {
      return (string) $this->t('Being created');
    }
    return (string) match ($key) {
      SodaScsDemoLabelBuilder::APP_WEBPROTEGE => $this->t('Importing the ontology'),
      SodaScsDemoLabelBuilder::APP_WISSKI => $this->t('Started'),
      default => $this->t('Being created'),
    };
  }

  /**
   * Whether the application's entity already exists on the project.
   */
  protected function appIsPresent(SodaScsProjectInterface $project, string $key): bool {
    return match ($key) {
      SodaScsDemoLabelBuilder::APP_JUPYTER => $this->projectApps->jupyterStack($project) !== NULL,
      SodaScsDemoLabelBuilder::APP_WEBPROTEGE => $this->projectApps->webprotegeComponent($project) !== NULL,
      SodaScsDemoLabelBuilder::APP_WISSKI => $this->projectApps->wisskiStack($project) !== NULL,
      default => FALSE,
    };
  }

  /**
   * Whether the line for this application should disappear.
   *
   * Jupyter is ready once its stack exists. The server stays stopped until
   * someone opens it, and that still counts as finished. WebProtégé is ready
   * once its component exists, except while the ontology import is the
   * current step. WissKI is ready once it has been started again after the
   * restore.
   */
  protected function appIsReady(string $key, bool $isPresent, int $rank): bool {
    if (!$isPresent) {
      return FALSE;
    }
    return match ($key) {
      SodaScsDemoLabelBuilder::APP_JUPYTER => TRUE,
      SodaScsDemoLabelBuilder::APP_WEBPROTEGE => !$this->stepIsCurrent('Importing the ontology', $rank),
      SodaScsDemoLabelBuilder::APP_WISSKI => $this->stepIsFinished('Restoring the WissKI', $rank),
      default => FALSE,
    };
  }

  /**
   * Short label of one demo application, matching the product name on cards.
   */
  protected function appLabel(string $key): string {
    return (string) match ($key) {
      SodaScsDemoLabelBuilder::APP_JUPYTER => $this->t('JupyterHub'),
      SodaScsDemoLabelBuilder::APP_WEBPROTEGE => $this->t('WebProtégé'),
      SodaScsDemoLabelBuilder::APP_WISSKI => $this->t('WissKI'),
      default => $key,
    };
  }

  /**
   * Applications that are not ready yet, in setup order.
   *
   * Keys absent from the template manifest are left out. A ready application
   * is omitted so its line disappears.
   *
   * @return list<array{detail: string, key: string, label: string}>
   *   One row per application that is still being set up.
   */
  protected function appProgress(SodaScsProjectInterface $project): array {
    $rank = $this->highestStepRank($project);
    $apps = [];
    foreach ($this->expectedAppKeys($project) as $key) {
      $present = $this->appIsPresent($project, $key);
      if ($this->appIsReady($key, $present, $rank)) {
        continue;
      }
      $apps[] = [
        'detail' => $this->appDetail($key, $present),
        'key'    => $key,
        'label'  => $this->appLabel($key),
      ];
    }
    return $apps;
  }

  /**
   * How many of the expected applications already exist.
   */
  protected function createdCount(SodaScsProjectInterface $project): int {
    $count = 0;
    foreach ($this->expectedAppKeys($project) as $key) {
      if ($this->appIsPresent($project, $key)) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Application keys the template contains, in setup order.
   *
   * Uses the project's template, or the active template while the first
   * setup has not stored one yet. Without a manifest, WissKI, JupyterHub
   * and WebProtégé are expected. Keys for a standalone database or
   * triplestore in an older manifest are ignored.
   *
   * @return list<string>
   *   Subset of the APP_* constants.
   */
  protected function expectedAppKeys(SodaScsProjectInterface $project): array {
    $known = [
      SodaScsDemoLabelBuilder::APP_WISSKI,
      SodaScsDemoLabelBuilder::APP_JUPYTER,
      SodaScsDemoLabelBuilder::APP_WEBPROTEGE,
    ];
    $template = $project->get('demoTemplate')->entity;
    if (!$template instanceof SodaScsDemoTemplateInterface) {
      $template = $this->demoManager->activeTemplate();
    }
    $manifestApps = $template instanceof SodaScsDemoTemplateInterface ? ($template->getManifest()['apps'] ?? NULL) : NULL;
    if (!is_array($manifestApps) || $manifestApps === []) {
      return $known;
    }
    return array_values(array_filter(
      $known,
      static fn (string $key): bool => array_key_exists($key, $manifestApps),
    ));
  }

  /**
   * Index of the furthest setup step already reported, or -1.
   */
  protected function highestStepRank(SodaScsProjectInterface $project): int {
    $rank = -1;
    foreach ($this->stepMessages($project) as $message) {
      $source = $this->stepSource($message);
      if ($source !== NULL) {
        $rank = max($rank, $this->stepIndex($source));
      }
    }
    return $rank;
  }

  /**
   * One translated line on the demo job: its state, step and times.
   *
   * Empty when the project has no job or the job has completed. A failed job
   * shows the reason the worker stored as its last step; details stay in the
   * log.
   */
  protected function jobLine(SodaScsProjectInterface $project): string {
    $operationId = $this->demoManager->projectOperationId($project);
    $operation = $operationId !== NULL ? $this->progressHelper->readOperation($operationId) : NULL;
    if ($operation === NULL) {
      return '';
    }
    $steps = $this->steps($project);
    $lastStep = $steps === [] ? '' : $steps[array_key_last($steps)]['message'];
    $updated = (int) ($operation['updated'] ?? 0);

    return (string) match ((string) ($operation['status'] ?? '')) {
      'queued' => $this->t('Queued since @time', ['@time' => $this->time((int) ($operation['created'] ?? 0))]),
      'running' => $lastStep !== ''
        ? $this->t('Running: @step', ['@step' => $this->stepLabel($lastStep)])
        : $this->t('Running since @time', ['@time' => $this->time($updated)]),
      'waiting' => $this->waitingLine($steps, $updated),
      'failed' => $lastStep !== '' ? $this->failureReason($lastStep) : $this->t('Failed at @time', ['@time' => $this->time($updated)]),
      default => '',
    };
  }

  /**
   * Translated reason of a failed job from its last stored step.
   *
   * The worker stores a REASON_* constant, or leaves the step the job failed
   * at as the last one.
   */
  protected function failureReason(string $lastStep): string {
    return (string) match (TRUE) {
      $lastStep === self::REASON_TIMEOUT => $this->t('The WissKI installation did not finish within an hour.'),
      $lastStep === self::REASON_TEMPLATE_GONE => $this->t('The demo template no longer exists.'),
      $lastStep === self::REASON_WISSKI_INCOMPLETE => $this->t('The WissKI of this demo is incomplete. Delete it in the project, then reset the demo.'),
      $this->stepSource($lastStep) !== NULL => $this->t('Setting up the demo failed at the step: @step', ['@step' => $this->stepLabel($lastStep)]),
      default => $lastStep,
    };
  }

  /**
   * Demo status of a project; unknown values count as failed.
   */
  protected function status(SodaScsProjectInterface $project): SodaScsDemoProjectStatus {
    return SodaScsDemoProjectStatus::tryFrom((string) $project->get('demoStatus')->value) ?? SodaScsDemoProjectStatus::Failed;
  }

  /**
   * Human-readable status.
   */
  protected function statusLabel(SodaScsDemoProjectStatus $status): string {
    return (string) match ($status) {
      SodaScsDemoProjectStatus::Provisioning => $this->t('Demo is being set up'),
      SodaScsDemoProjectStatus::Resetting => $this->t('Demo is being reset'),
      SodaScsDemoProjectStatus::Ready => $this->t('Demo'),
      SodaScsDemoProjectStatus::Failed => $this->t('Demo setup failed'),
    };
  }

  /**
   * Translated label of a stored setup step; unknown text is shown as is.
   */
  protected function stepLabel(string $message): string {
    $source = $this->stepSource($message);
    return $source !== NULL ? $this->stepLabels()[$source] : $message;
  }

  /**
   * Setup steps by source string, translated for the viewer.
   *
   * @return array<string, string>
   *   Translations keyed by the entries of SETUP_STEPS.
   */
  protected function stepLabels(): array {
    return [
      'Creating missing applications' => (string) $this->t('Creating missing applications'),
      'Waiting for the WissKI installation to finish' => (string) $this->t('Waiting for the WissKI installation to finish'),
      'Copying files into the Team Folder' => (string) $this->t('Copying files into the Team Folder'),
      'Restoring the Jupyter home directory' => (string) $this->t('Restoring the Jupyter home directory'),
      'Importing the ontology' => (string) $this->t('Importing the ontology'),
      'Restoring the WissKI' => (string) $this->t('Restoring the WissKI'),
      'Setting the WissKI API password' => (string) $this->t('Setting the WissKI API password'),
      'The demo project is ready.' => (string) $this->t('The demo project is ready.'),
    ];
  }

  /**
   * Source string of a stored setup step, or NULL for other text.
   *
   * Also maps a step stored in the viewer's language by older jobs.
   */
  protected function stepSource(string $message): ?string {
    if (in_array($message, self::SETUP_STEPS, TRUE)) {
      return $message;
    }
    $source = array_search($message, $this->stepLabels(), TRUE);
    return $source === FALSE ? NULL : $source;
  }

  /**
   * Position of a setup step, or a rank nothing has reached.
   */
  protected function stepIndex(string $source): int {
    $index = array_search($source, self::SETUP_STEPS, TRUE);
    return $index === FALSE ? PHP_INT_MAX : $index;
  }

  /**
   * Whether $source is the furthest setup step reported so far.
   */
  protected function stepIsCurrent(string $source, int $rank): bool {
    return $rank === $this->stepIndex($source);
  }

  /**
   * Whether a later step than $source has already been reported.
   */
  protected function stepIsFinished(string $source, int $rank): bool {
    return $rank > $this->stepIndex($source);
  }

  /**
   * Progress messages recorded for the project's demo job.
   *
   * @return list<string>
   *   Messages in chronological order.
   */
  protected function stepMessages(SodaScsProjectInterface $project): array {
    return array_column($this->steps($project), 'message');
  }

  /**
   * Progress steps of the project's demo job, with their times.
   *
   * Read once per request: badge, panel and polling ask several times.
   *
   * @return list<array{created: float, message: string}>
   *   Non-empty steps in chronological order.
   */
  protected function steps(SodaScsProjectInterface $project): array {
    $operationId = $this->demoManager->projectOperationId($project);
    if ($operationId === NULL) {
      return [];
    }
    if (isset($this->stepCache[$operationId])) {
      return $this->stepCache[$operationId];
    }
    $steps = [];
    foreach ($this->progressHelper->findLatestStepsByOperation($operationId, [], 'created', 'ASC') as $step) {
      $message = trim((string) ($step['message'] ?? ''));
      if ($message !== '') {
        $steps[] = ['created' => (float) ($step['created'] ?? 0), 'message' => $message];
      }
    }
    return $this->stepCache[$operationId] = $steps;
  }

  /**
   * Time of day in the viewer's time zone.
   */
  protected function time(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'custom', 'H:i');
  }

  /**
   * When the job first reported that it waits for the WissKI, or NULL.
   *
   * @param list<array{created: float, message: string}> $steps
   *   Steps of the job.
   */
  protected function waitingSince(array $steps): ?int {
    foreach ($steps as $step) {
      if ($this->stepSource($step['message']) === self::WAIT_STEP) {
        return (int) $step['created'];
      }
    }
    return NULL;
  }

  /**
   * Line of a job that waits for the WissKI installation.
   *
   * The next attempt is left out once it is a full retry delay overdue: then
   * the queue runner has stopped and the job waits until someone starts it
   * again.
   *
   * @param list<array{created: float, message: string}> $steps
   *   Steps of the job.
   * @param int $updated
   *   When the job last changed its status.
   */
  protected function waitingLine(array $steps, int $updated): string {
    $since = $this->time($this->waitingSince($steps) ?? $updated);
    $next = $updated + self::RETRY_DELAY_SECONDS;
    if ($next + self::RETRY_DELAY_SECONDS < $this->timeService->getCurrentTime()) {
      return (string) $this->t('Waiting for the WissKI installation since @since', ['@since' => $since]);
    }
    return (string) $this->t('Waiting for the WissKI installation since @since, next attempt at @next', [
      '@next' => $this->time($next),
      '@since' => $since,
    ]);
  }

}
