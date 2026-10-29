<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoVersion;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms resetting a demo project to the active demo version.
 */
final class SodaScsDemoProjectResetForm extends ConfirmFormBase {

  /**
   * The demo project.
   */
  protected ?SodaScsProjectInterface $project = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    protected SodaScsDemoManager $demoManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('soda_scs_manager.demo.manager'));
  }

  /**
   * Only the owner (or an admin) may reset a demo project.
   */
  public function access(AccountInterface $account, ?SodaScsProjectInterface $soda_scs_project = NULL): AccessResultInterface {
    if ($soda_scs_project === NULL || !$this->demoManager->isDemoProject($soda_scs_project)) {
      return AccessResult::forbidden()->addCacheableDependency($soda_scs_project);
    }
    $isOwner = (int) $soda_scs_project->getOwnerId() === (int) $account->id();
    return AccessResult::allowedIf($isOwner || $account->hasPermission('soda scs manager admin'))
      ->addCacheableDependency($soda_scs_project)
      ->cachePerUser();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsProjectInterface $soda_scs_project = NULL): array {
    $this->project = $soda_scs_project;
    $form = parent::buildForm($form, $form_state);

    $activeTemplate = $this->demoManager->activeTemplate();
    $currentTemplate = $this->project?->get('demoTemplate')->entity;
    if ($activeTemplate !== NULL && $currentTemplate instanceof SodaScsDemoTemplateInterface && SodaScsDemoVersion::compare($activeTemplate->getVersion(), $currentTemplate->getVersion()) > 0) {
      $form['newerVersion'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('A newer demo version is available (v@new, your demo has v@current). The reset uses the newer version.', [
          '@current' => $currentTemplate->getVersion(),
          '@new' => $activeTemplate->getVersion(),
        ]),
        '#weight' => -5,
      ];
      $form['changelog'] = $this->buildChangelog($this->demoManager->newerReadyTemplates($currentTemplate, $activeTemplate));
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->project !== NULL
      ? $this->project->toUrl()
      : Url::fromRoute('soda_scs_manager.dashboard');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Reset demo');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('All changes in the WissKI, the databases, the triplestores, the Jupyter home directory, the Team Folder and the WebProtégé ontology of this project are overwritten with the demo data. Applications you added yourself are not changed. This cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_demo_project_reset_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Reset @project?', ['@project' => $this->project?->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->demoManager->requestReset($this->project);
    if ($result->success) {
      $this->messenger()->addStatus($result->message);
    }
    else {
      $this->messenger()->addError($result->message);
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Changelogs of the versions a reset brings, newest first.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface[] $templates
   *   The newer versions.
   */
  protected function buildChangelog(array $templates): array {
    $build = [
      '#type' => 'details',
      '#title' => $this->t('What is new'),
      '#open' => TRUE,
      '#weight' => -4,
    ];
    foreach ($templates as $template) {
      $build['v' . $template->getVersion()] = [
        '#type' => 'item',
        '#title' => $this->t('Version @version', ['@version' => $template->getVersion()]),
        '#markup' => nl2br(Html::escape($template->getChangelog())),
      ];
    }
    return $build;
  }

}
