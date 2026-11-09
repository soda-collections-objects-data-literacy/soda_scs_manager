<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Service\SodaScsWisskiUpdateQueue;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for updating Drupal packages of a component.
 */
class SodaScsComponentSetPackageEnvironmentConfirmForm extends ConfirmFormBase {

  /**
   * The component whose Drupal packages should be updated.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface
   */
  protected $component;

  /**
   * Constructs a new SodaScsComponentSetPackageEnvironmentConfirmForm.
   *
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers $sodaScsServiceHelpers
   *   The SODa SCS service helpers.
   * @param \Drupal\soda_scs_manager\Service\SodaScsWisskiUpdateQueue $wisskiUpdateQueue
   *   Queue that runs WissKI updates in the background.
   */
  public function __construct(
    protected SodaScsServiceHelpers $sodaScsServiceHelpers,
    protected SodaScsWisskiUpdateQueue $wisskiUpdateQueue,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('soda_scs_manager.service.helpers'),
      $container->get('soda_scs_manager.wisski_update_queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_drupal_packages_update_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsComponentInterface $soda_scs_component = NULL) {
    $this->component = $soda_scs_component;

    if (!$this->component) {
      throw new \Exception('Component not found.');
    }

    $form = parent::buildForm($form, $form_state);

    // Add version dropdown from form.
    $form['version_dropdown'] = $this->buildVersionDropdown($this->component);

    // Add throbber overlay class to the submit button.
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-component--component--form-submit';
    }

    // Attach the throbber overlay library.
    $form['#attached']['library'][] = 'soda_scs_manager/throbberOverlay';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Set package environment for component @label?', [
      '@label' => $this->component->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('soda_scs_manager.component.installed_drupal_packages', [
      'soda_scs_component' => $this->component->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action will update the package environment for the component @label by redeploying its WissKI stack with the selected image version. Packages are baked into the wisski-base-image, so the stack is rebuilt and the latest image for that version is pulled. Select a configured production version or "Nightly" on development instances to redeploy with the development image tags. Be aware that downgrades can cause database schema mismatches. This may take some time and can not be undone.', [
      '@label' => $this->component->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Update Drupal packages');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $version = (string) $form_state->getValue('version_dropdown', 'latest');
    // The update outlives a web request, so it runs as a queued job; the
    // component page shows its progress.
    $result = $this->wisskiUpdateQueue->enqueue($this->component, $version);
    if ($result->success) {
      $this->messenger()->addStatus($result->message);
      $form_state->setRedirectUrl($this->component->toUrl());
      return;
    }
    $this->messenger()->addError($result->message);
    $form_state->setRedirectUrl(Url::fromRoute('soda_scs_manager.component.installed_drupal_packages', [
      'soda_scs_component' => $this->component->id(),
    ]));
  }

  /**
   * Build version dropdown for setting package environment.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The render array for the select dropdown.
   */
  public function buildVersionDropdown(SodaScsComponentInterface $component): array {
    try {
      // Get package environments and production version.
      $wisskiInstanceSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();
      $packageEnvironments = explode(',', $wisskiInstanceSettings['packageEnvironments'] ?? '');
      $productionVersion = $wisskiInstanceSettings['productionVersion'] ?? '';

      // Build version options.
      $options = [];

      // Add "latest" option with production version.
      if (!empty($productionVersion)) {
        $options['latest'] = $this->t('Latest production version (@version)', ['@version' => $productionVersion]);
      }

      // Add all package environment versions.
      if (is_array($packageEnvironments)) {
        foreach ($packageEnvironments as $env) {
          $env = trim($env);
          if (!empty($env) && $env !== $productionVersion) {
            $options[$env] = $env;
          }
        }
      }

      // Add nightly option if development instance.
      if ($component->get('developmentInstance')->value) {
        $options['nightly'] = $this->t('Nightly');
      }

      if (empty($options)) {
        // Fallback if no versions found - return empty array.
        return [];
      }

      // Build plain select dropdown.
      return [
        '#type' => 'select',
        '#title' => $this->t('Package environment'),
        '#options' => $options,
        '#default_value' => 'latest',
        '#required' => TRUE,
        '#attributes' => [
          'class' => ['soda-scs-manager--version-dropdown'],
        ],
      ];
    }
    catch (\Exception $e) {
      // If we can't get versions, return empty array.
      return [];
    }
  }

}
