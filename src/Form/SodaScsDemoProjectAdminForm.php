<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoQueueRunner;
use Drupal\soda_scs_manager\Demo\SodaScsDemoVersion;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Central demo project: source selection and template versions.
 */
final class SodaScsDemoProjectAdminForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected DateFormatterInterface $dateFormatter,
    protected SodaScsDemoManager $demoManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SodaScsDemoQueueRunner $queueRunner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
      $container->get('soda_scs_manager.demo.manager'),
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.demo.queue_runner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $source = $this->demoManager->sourceProject();

    $form['source'] = [
      '#type' => 'details',
      '#title' => $this->t('Demo project'),
      '#open' => $source === NULL,
      '#description' => $this->t('Users get a copy of this project. Choosing a project renames it to "@label".', [
        '@label' => SodaScsDemoManager::SOURCE_PROJECT_LABEL,
      ]),
    ];
    $form['source']['sourceProjectId'] = [
      '#type' => 'select',
      '#title' => $this->t('Project'),
      '#options' => $this->sourceProjectOptions(),
      '#empty_option' => $this->t('- Choose a project -'),
      '#default_value' => $source?->id(),
    ];
    $form['source']['saveSource'] = [
      '#type' => 'submit',
      '#value' => $this->t('Use as demo project'),
      '#submit' => ['::submitSource'],
      '#limit_validation_errors' => [['sourceProjectId']],
    ];
    if ($source !== NULL) {
      $form['source']['current'] = [
        '#type' => 'item',
        '#title' => $this->t('Current demo project'),
        '#markup' => $source->toLink()->toString(),
        '#weight' => -10,
      ];
    }

    $form['build'] = [
      '#type' => 'details',
      '#title' => $this->t('New version'),
      '#open' => TRUE,
      '#description' => $this->t('Copies the WissKI, the Jupyter home directory, the Team Folder, the WebProtégé ontology and the standalone databases and triplestores of the demo project. The demo WissKI and the Jupyter server are stopped for a few minutes while they are copied.'),
    ];
    $templates = $this->demoManager->templatesByVersion();
    $latestTemplate = $templates[0] ?? NULL;
    $latestVersion = $latestTemplate?->getVersion();
    $replacesFailedVersion = $latestTemplate !== NULL && $latestTemplate->getStatus() === SodaScsDemoTemplateInterface::STATUS_FAILED;
    $suggestions = SodaScsDemoVersion::suggestions($latestVersion);
    $form['build']['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#required' => TRUE,
      '#size' => 12,
      '#maxlength' => 32,
      '#default_value' => $replacesFailedVersion
        ? $latestVersion
        : ($suggestions[SodaScsDemoVersion::BUMP_MINOR] ?? SodaScsDemoVersion::INITIAL),
      '#description' => $this->t('{Major}.{Minor}.{Patch}, without a leading v.'),
    ];
    $form['build']['changelog'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Changelog'),
      '#required' => TRUE,
      '#rows' => 5,
      '#default_value' => $replacesFailedVersion ? $latestTemplate->getChangelog() : '',
      '#description' => $this->t('What changed compared to the previous version. Demo owners see it before they reset their demo.'),
    ];
    $form['build']['buildTemplate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Build new version'),
      '#validate' => ['::validateBuild'],
      '#submit' => ['::submitBuild'],
      '#disabled' => $source === NULL,
      '#limit_validation_errors' => [['version'], ['changelog']],
    ];

    if ($this->queueRunner->pendingCount() > 0 && !$this->queueRunner->isRunning()) {
      $form['run'] = [
        '#type' => 'submit',
        '#value' => $this->t('Start queued jobs'),
        '#submit' => ['::submitRun'],
        '#limit_validation_errors' => [],
      ];
    }

    $form['versions'] = $this->buildVersionsTable();
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_demo_project_admin_form';
  }

  /**
   * Activates the version of the clicked row.
   */
  public function submitActivate(array &$form, FormStateInterface $form_state): void {
    $template = $this->loadTriggeringTemplate($form_state);
    if ($template !== NULL) {
      $this->showResult($this->demoManager->setActiveTemplate($template));
    }
  }

  /**
   * Queues a new template version.
   */
  public function submitBuild(array &$form, FormStateInterface $form_state): void {
    $this->showResult($this->demoManager->requestTemplateBuild(
      (string) $form_state->getValue('changelog'),
      trim((string) $form_state->getValue('version')),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Starts jobs that are already waiting in the queue.
   */
  public function submitRun(array &$form, FormStateInterface $form_state): void {
    $this->showResult($this->queueRunner->start());
  }

  /**
   * Makes the chosen project the demo project.
   */
  public function submitSource(array &$form, FormStateInterface $form_state): void {
    $project = $this->entityTypeManager->getStorage('soda_scs_project')->load((int) $form_state->getValue('sourceProjectId'));
    if (!$project instanceof SodaScsProjectInterface) {
      $this->messenger()->addError($this->t('Choose a project.'));
      return;
    }
    $this->showResult($this->demoManager->setSourceProject($project));
  }

  /**
   * Checks format and order of the new version.
   */
  public function validateBuild(array &$form, FormStateInterface $form_state): void {
    $versionError = $this->demoManager->validateNewVersion(trim((string) $form_state->getValue('version')));
    if ($versionError !== NULL) {
      $form_state->setErrorByName('version', $versionError);
    }
  }

  /**
   * Table of all template versions with their actions.
   */
  protected function buildVersionsTable(): array {
    $table = [
      '#type' => 'table',
      '#caption' => $this->t('Versions'),
      '#header' => [
        $this->t('Version'),
        $this->t('Status'),
        $this->t('Created'),
        $this->t('Changelog'),
        $this->t('Demo projects'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No version has been built yet.'),
      '#attributes' => [
        'data-demo-versions' => Url::fromRoute('soda_scs_manager.demo_project_versions_status')->toString(),
      ],
      '#attached' => [
        'library' => ['soda_scs_manager/demoVersionStatus'],
      ],
    ];

    foreach ($this->demoManager->templatesByVersion() as $template) {
      $isActive = $this->demoManager->isActiveTemplate($template);
      $usageCount = $this->demoManager->templateUsageCount($template);
      $rowKey = 'template_' . $template->id();

      $table[$rowKey]['version'] = [
        '#markup' => $isActive
          ? $this->t('v@version (active)', ['@version' => $template->getVersion()])
          : $this->t('v@version', ['@version' => $template->getVersion()]),
      ];
      $table[$rowKey]['status'] = [
        '#plain_text' => $this->demoManager->templateStatusText($template),
        '#wrapper_attributes' => [
          'data-demo-version-state' => $template->getStatus(),
          'data-demo-version-status' => (string) $template->id(),
        ],
      ];
      $table[$rowKey]['created'] = ['#markup' => $this->dateFormatter->format((int) $template->get('created')->value, 'short')];
      $table[$rowKey]['changelog'] = ['#markup' => nl2br(Html::escape($template->getChangelog()))];
      $table[$rowKey]['usage'] = ['#markup' => (string) $usageCount];
      $table[$rowKey]['operations'] = ['#type' => 'container'];
      if ($template->isReady() && !$isActive) {
        $table[$rowKey]['operations']['activate'] = [
          '#type' => 'submit',
          '#value' => $this->t('Activate'),
          '#name' => 'activate_' . $template->id(),
          '#templateId' => (int) $template->id(),
          '#submit' => ['::submitActivate'],
          '#limit_validation_errors' => [],
        ];
      }
      if (!$isActive && $usageCount === 0 && $template->getStatus() !== SodaScsDemoTemplateInterface::STATUS_BUILDING) {
        $table[$rowKey]['operations']['delete'] = [
          '#type' => 'link',
          '#title' => $this->t('Delete'),
          '#url' => Url::fromRoute('soda_scs_manager.demo_template_delete', ['soda_scs_demo_template' => $template->id()]),
          '#attributes' => ['class' => ['button', 'button--danger']],
        ];
      }
    }
    return $table;
  }

  /**
   * The template whose row button was clicked.
   */
  protected function loadTriggeringTemplate(FormStateInterface $form_state): ?SodaScsDemoTemplateInterface {
    $templateId = (int) ($form_state->getTriggeringElement()['#templateId'] ?? 0);
    $template = $this->entityTypeManager->getStorage('soda_scs_demo_template')->load($templateId);
    return $template instanceof SodaScsDemoTemplateInterface ? $template : NULL;
  }

  /**
   * Shows the message of a demo manager result.
   */
  protected function showResult(SodaScsResult $result): void {
    if ($result->message === '') {
      return;
    }
    if ($result->success) {
      $this->messenger()->addStatus($result->message);
    }
    else {
      $this->messenger()->addError($result->message);
    }
  }

  /**
   * Projects that can serve as the demo source; user demos are excluded.
   *
   * @return array<int|string, string>
   *   Labels keyed by project id.
   */
  protected function sourceProjectOptions(): array {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->notExists('demoStatus')
      ->sort('label')
      ->execute();
    $options = [];
    foreach ($storage->loadMultiple($ids) as $project) {
      $options[$project->id()] = $this->t('@label (ID @id)', ['@label' => $project->label(), '@id' => $project->id()]);
    }
    return $options;
  }

}
