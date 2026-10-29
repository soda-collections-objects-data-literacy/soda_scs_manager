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
      '#message' => $status->isBusy() ? $this->latestMessage($project) : '',
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
   * @return array{apps: list<array{current: bool, key: string, label: string, present: bool}>, isBusy: bool, message: string, status: string, statusLabel: string}
   *   JSON-ready status. Apps lists WissKI, Jupyter, SQL, triplestore and
   *   WebProtégé while a job is running, in that order.
   */
  public function data(SodaScsProjectInterface $project): array {
    $status = $this->status($project);
    return [
      'apps'        => $status->isBusy() ? $this->appProgress($project) : [],
      'isBusy'      => $status->isBusy(),
      'message'     => $status->isBusy() ? $this->latestMessage($project) : '',
      'status'      => $status->value,
      'statusLabel' => $this->statusLabel($status),
    ];
  }

  /**
   * Whether the application's entity already exists on the project.
   */
  protected function appIsPresent(SodaScsProjectInterface $project, string $key): bool {
    return match ($key) {
      SodaScsDemoLabelBuilder::APP_DATABASE => $this->projectApps->standaloneComponents($project, SodaScsDemoProjectApps::SQL_COMPONENT_BUNDLE) !== [],
      SodaScsDemoLabelBuilder::APP_JUPYTER => $this->projectApps->jupyterStack($project) !== NULL,
      SodaScsDemoLabelBuilder::APP_TRIPLESTORE => $this->projectApps->standaloneComponents($project, SodaScsDemoProjectApps::TRIPLESTORE_COMPONENT_BUNDLE) !== [],
      SodaScsDemoLabelBuilder::APP_WEBPROTEGE => $this->projectApps->webprotegeComponent($project) !== NULL,
      SodaScsDemoLabelBuilder::APP_WISSKI => $this->projectApps->wisskiStack($project) !== NULL,
      default => FALSE,
    };
  }

  /**
   * Short label of one demo application, matching the product name on cards.
   */
  protected function appLabel(string $key): string {
    return (string) match ($key) {
      SodaScsDemoLabelBuilder::APP_DATABASE => $this->t('SQL Database'),
      SodaScsDemoLabelBuilder::APP_JUPYTER => $this->t('JupyterHub'),
      SodaScsDemoLabelBuilder::APP_TRIPLESTORE => $this->t('Triplestore'),
      SodaScsDemoLabelBuilder::APP_WEBPROTEGE => $this->t('WebProtégé'),
      SodaScsDemoLabelBuilder::APP_WISSKI => $this->t('WissKI'),
      default => $key,
    };
  }

  /**
   * Applications the running demo job will create, with a present flag.
   *
   * Order is WissKI, Jupyter, SQL database, triplestore, WebProtégé. Keys
   * absent from the template manifest are left out.
   *
   * @return list<array{current: bool, key: string, label: string, present: bool}>
   *   Progress rows. current marks the first application that is still missing.
   */
  protected function appProgress(SodaScsProjectInterface $project): array {
    $apps = [];
    $waitingMarked = FALSE;
    foreach ($this->expectedAppKeys($project) as $key) {
      $present = $this->appIsPresent($project, $key);
      $current = !$present && !$waitingMarked;
      if ($current) {
        $waitingMarked = TRUE;
      }
      $apps[] = [
        'current' => $current,
        'key'     => $key,
        'label'   => $this->appLabel($key),
        'present' => $present,
      ];
    }
    return $apps;
  }

  /**
   * Application keys the template contains, in setup order.
   *
   * Uses the project's template, or the active template while the first
   * setup has not stored one yet. Without a manifest, all five are expected.
   *
   * @return list<string>
   *   Subset of the APP_* constants.
   */
  protected function expectedAppKeys(SodaScsProjectInterface $project): array {
    $known = [
      SodaScsDemoLabelBuilder::APP_WISSKI,
      SodaScsDemoLabelBuilder::APP_JUPYTER,
      SodaScsDemoLabelBuilder::APP_DATABASE,
      SodaScsDemoLabelBuilder::APP_TRIPLESTORE,
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
   * Latest progress message of the project's demo job.
   */
  protected function latestMessage(SodaScsProjectInterface $project): string {
    $operationId = $this->demoManager->projectOperationId($project);
    if ($operationId === NULL) {
      return '';
    }
    $steps = $this->progressHelper->findLatestStepsByOperation($operationId, [], 'created', 'DESC', 1);
    return (string) ($steps[0]['message'] ?? $this->t('Waiting to start'));
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

}
