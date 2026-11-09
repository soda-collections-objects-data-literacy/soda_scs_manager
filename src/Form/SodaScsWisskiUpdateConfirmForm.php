<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Access\SodaScsManagerAdminAccess;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Drupal\soda_scs_manager\Service\SodaScsWisskiAdminTasks;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Asks an administrator before updating one WissKI instance.
 *
 * Production instances move to the latest production version; development
 * instances are redeployed with the current development images.
 */
class SodaScsWisskiUpdateConfirmForm extends ConfirmFormBase {

  /**
   * WissKI component to update.
   */
  protected SodaScsComponentInterface $component;

  /**
   * Version or build the update moves to, empty when unknown.
   */
  protected string $updateTarget = '';

  /**
   * Constructs the confirm form.
   */
  public function __construct(
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected LoggerChannelInterface $logger,
    protected SodaScsDrupalHelpers $sodaScsDrupalHelpers,
    protected SodaScsProgressHelper $sodaScsProgressHelper,
    protected SodaScsWisskiAdminTasks $wisskiAdminTasks,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('cache_tags.invalidator'),
      $container->get('logger.channel.soda_scs_manager'),
      $container->get('soda_scs_manager.drupal.helpers'),
      $container->get('soda_scs_manager.progress.helpers'),
      $container->get('soda_scs_manager.wisski_admin_tasks'),
    );
  }

  /**
   * Route access: SCS administrators, and only for a WissKI component.
   */
  public static function access(SodaScsComponentInterface $soda_scs_component, AccountInterface $account): AccessResultInterface {
    $allowed = $account->hasPermission(SodaScsManagerAdminAccess::SCS_MANAGER_ADMIN_PERMISSION)
      && $soda_scs_component->bundle() === 'soda_scs_wisski_component';

    return AccessResult::allowedIf($allowed)
      ->addCacheableDependency($soda_scs_component)
      ->cachePerPermissions();
  }

  /**
   * Confirm-form URL for a WissKI component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The WissKI component.
   * @param string|null $destination
   *   Internal path to return to after confirm or cancel.
   */
  public static function confirmUrl(SodaScsComponentInterface $component, ?string $destination = NULL): Url {
    $options = [];
    if (is_string($destination) && $destination !== '') {
      $options['query']['destination'] = $destination;
    }
    return Url::fromRoute('soda_scs_manager.component.update_wisski', [
      'soda_scs_component' => $component->id(),
    ], $options);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsComponentInterface $soda_scs_component = NULL): array {
    if (!$soda_scs_component instanceof SodaScsComponentInterface) {
      throw new \InvalidArgumentException('WissKI component is required.');
    }
    $this->component = $soda_scs_component;
    $this->updateTarget = $this->wisskiAdminTasks->build($soda_scs_component, $this->currentUser())['availableLabel'];

    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-component--component--form-submit';
    $form['#attached']['library'][] = 'soda_scs_manager/throbberOverlay';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    $destination = $this->getRequest()->query->get('destination');
    if (is_string($destination) && str_starts_with($destination, '/') && !str_starts_with($destination, '//')) {
      try {
        return Url::fromUserInput($destination);
      }
      catch (\InvalidArgumentException) {
        // Fall through to the instance page.
      }
    }

    $stack = $this->component->getPartOfStack();
    return $stack !== NULL ? $stack->toUrl() : $this->component->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    if ($this->updateTarget !== '') {
      return $this->t('Update to @target', ['@target' => $this->updateTarget]);
    }
    return $this->t('Update');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    if ($this->isDevelopmentInstance()) {
      return $this->t('The WissKI stack is redeployed with the current development images. The database is backed up first and rolled back if the database update fails. The instance is offline for a few minutes.');
    }
    return $this->t('The WissKI stack is redeployed with the latest production version. The database is backed up first and rolled back if the database update fails. The instance is offline for a few minutes.');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_wisski_update_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Update @label?', [
      '@label' => $this->component->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $operationUuid = $this->sodaScsProgressHelper->createOperation('drupal_packages_update', 'started');
    $targetVersion = $this->isDevelopmentInstance() ? 'nightly' : 'latest';

    try {
      $result = $this->sodaScsDrupalHelpers->updateDrupalPackages($this->component, (string) $operationUuid, $targetVersion);
    }
    catch (\Throwable $e) {
      $this->logger->error('Update of WissKI component @id failed: @message', [
        '@id'      => $this->component->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Could not update @label.', ['@label' => $this->component->label()]));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    if ($result->success) {
      $this->messenger()->addStatus($result->message);
      $this->cacheTagsInvalidator->invalidateTags([
        'soda_scs_manager:drupal_packages',
        'soda_scs_manager:drupal_packages:' . $this->component->id(),
      ]);
    }
    else {
      $this->logger->error('Update of WissKI component @id failed: @error', [
        '@id'    => $this->component->id(),
        '@error' => $result->error ?? '',
      ]);
      $this->messenger()->addError($this->t('Could not update @label. @message', [
        '@label'   => $this->component->label(),
        '@message' => $result->message,
      ]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Whether the component is a development instance.
   */
  protected function isDevelopmentInstance(): bool {
    return (bool) $this->component->get('developmentInstance')->value;
  }

}
