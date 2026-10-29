<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

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
   * Progress messages in the order the provisioner reports them.
   *
   * A message is stored when its step starts, so a later message means the
   * earlier step has finished. Compared against the source string and its
   * translation, because steps are stored already translated.
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
   * Constructs the builder.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.demo.manager')]
    protected SodaScsDemoManager $demoManager,
    #[Autowire(service: 'soda_scs_manager.progress.helpers')]
    protected SodaScsProgressHelper $progressHelper,
    #[Autowire(service: 'soda_scs_manager.demo.project_apps')]
    protected SodaScsDemoProjectApps $projectApps,
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
   * @return array{apps: list<array{detail: string, key: string, label: string}>, createdCount: int, isBusy: bool, status: string, statusLabel: string}
   *   JSON-ready status. Apps lists only applications that are not ready yet.
   */
  public function data(SodaScsProjectInterface $project): array {
    $status = $this->status($project);
    $isBusy = $status->isBusy();
    return [
      'apps'         => $isBusy ? $this->appProgress($project) : [],
      'createdCount' => $isBusy ? $this->createdCount($project) : 0,
      'isBusy'       => $isBusy,
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
      SodaScsDemoLabelBuilder::APP_JUPYTER => $this->t('Ready to start'),
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
   * Jupyter is ready once its home directory has been restored. WebProtégé
   * is ready once the ontology import has finished. WissKI is ready once it
   * has been started again after the restore.
   */
  protected function appIsReady(string $key, bool $isPresent, int $rank): bool {
    if (!$isPresent) {
      return FALSE;
    }
    return match ($key) {
      SodaScsDemoLabelBuilder::APP_JUPYTER => $this->stepIsFinished('Restoring the Jupyter home directory', $rank),
      SodaScsDemoLabelBuilder::APP_WEBPROTEGE => $this->stepIsFinished('Importing the ontology', $rank),
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
    $messages = $this->stepMessages($project);
    $rank = -1;
    foreach (self::SETUP_STEPS as $index => $source) {
      $translated = (string) $this->t($source);
      if (in_array($source, $messages, TRUE) || in_array($translated, $messages, TRUE)) {
        $rank = $index;
      }
    }
    return $rank;
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
   * Position of a setup step, or a rank nothing has reached.
   */
  protected function stepIndex(string $source): int {
    $index = array_search($source, self::SETUP_STEPS, TRUE);
    return $index === FALSE ? PHP_INT_MAX : $index;
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
    $operationId = $this->demoManager->projectOperationId($project);
    if ($operationId === NULL) {
      return [];
    }
    $messages = [];
    foreach ($this->progressHelper->findLatestStepsByOperation($operationId, [], 'created', 'ASC') as $step) {
      $message = trim((string) ($step['message'] ?? ''));
      if ($message !== '') {
        $messages[] = $message;
      }
    }
    return $messages;
  }

}
