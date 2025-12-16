<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Utility\Error;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for updating Drupal packages of a component.
 */
class SodaScsDrupalPackagesUpdateConfirmForm extends ConfirmFormBase {

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
   * Constructs a new SodaScsDrupalPackagesUpdateConfirmForm.
   *
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers $sodaScsDrupalHelpers
   *   The SODa SCS Drupal helpers.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   */
  public function __construct(
    SodaScsDrupalHelpers $sodaScsDrupalHelpers,
    LoggerChannelFactoryInterface $logger_factory,
    CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {
    $this->sodaScsDrupalHelpers = $sodaScsDrupalHelpers;
    $this->loggerFactory = $logger_factory;
    $this->cacheTagsInvalidator = $cacheTagsInvalidator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('soda_scs_manager.drupal.helpers'),
      $container->get('logger.factory'),
      $container->get('cache_tags.invalidator'),
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
    return $this->t('Update Drupal packages for component @label?', [
      '@label' => $this->component->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->component->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action will update the Drupal packages inside the container of this component by downloading a composer.json from the configured repository and running composer install. This may take some time and can not be undone.');
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      $updateResult = $this->sodaScsDrupalHelpers->updateDrupalPackages($this->component);

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

}
