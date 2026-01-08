<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for updating Drupal packages of a component.
 */
class SodaScsComponentSetPackageEnvironmentConfirmForm extends ConfirmFormBase {

  /**
   * The SODa SCS Drupal helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers
   */
  protected $sodaScsDrupalHelpers;

  /**
   * The component whose Drupal packages should be updated.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface
   */
  protected $component;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * The SODa SCS Manager service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  /**
   * Constructs a new SodaScsComponentSetPackageEnvironmentConfirmForm.
   *
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers $sodaScsDrupalHelpers
   *   The SODa SCS Drupal helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers $sodaScsServiceHelpers
   *   The SODa SCS service helpers.
   */
  public function __construct(
    CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    LoggerChannelFactoryInterface $logger_factory,
    SodaScsDrupalHelpers $sodaScsDrupalHelpers,
    SodaScsServiceHelpers $sodaScsServiceHelpers,
  ) {
    $this->cacheTagsInvalidator = $cacheTagsInvalidator;
    $this->loggerFactory = $logger_factory;
    $this->sodaScsDrupalHelpers = $sodaScsDrupalHelpers;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('cache_tags.invalidator'),
      $container->get('logger.factory'),
      $container->get('soda_scs_manager.drupal.helpers'),
      $container->get('soda_scs_manager.service.helpers'),
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
    return $this->t('This action will set the package environment for the component @label. Select a version to download the composer.json and composer.lock from <a href="https://github.com/soda-collections-objects-data-literacy/drupal_packages" target="_blank">SCS Drupal/WissKI package environments</a> or "Nightly" to update all packages to latest possible versions. Be aware that downgrades can cause database schema mismatches. This may take some time and can not be undone.', [
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
    $version = $form_state->getValue('version_dropdown', 'latest');

    try {
      $updateResult = $this->sodaScsDrupalHelpers->updateDrupalPackages(
        $this->component,
        $version
      );

      if ($updateResult->success) {
        $this->messenger()->addStatus($updateResult->message);

        $this->cacheTagsInvalidator->invalidateTags([
          'soda_scs_manager:drupal_packages',
          'soda_scs_manager:drupal_packages:' . $this->component->id(),
        ]);
      }
      else {
        $this->messenger()->addError($this->t('Failed to update Drupal packages. @message', [
          '@message' => $updateResult->message,
        ]));

        if (!empty($updateResult->error)) {
          Error::logException(
            $this->loggerFactory->get('soda_scs_manager'),
            new \Exception($updateResult->error),
            (string) $this->t('Failed to update Drupal packages for component @id. @error', [
              '@id' => $this->component->id(),
              '@error' => $updateResult->error,
            ]),
            [],
            LogLevel::ERROR,
          );
        }
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to update Drupal packages. See logs for more details.'));
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        (string) $this->t('Failed to update Drupal packages for component @id. @error', [
          '@id' => $this->component->id(),
          '@error' => $e->getMessage(),
        ]),
        [],
        LogLevel::ERROR,
      );
    }

    // Redirect back to the Installed Drupal packages page for this component.
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
