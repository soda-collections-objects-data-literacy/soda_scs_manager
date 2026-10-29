<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Access\SodaScsManagerAdminAccess;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Service\SodaScsWisskiVarnishCache;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Asks an administrator before clearing one WissKI instance's Varnish cache.
 */
class SodaScsWisskiClearVarnishCacheConfirmForm extends ConfirmFormBase {

  /**
   * WissKI component whose Varnish container is banned.
   */
  protected SodaScsComponentInterface $component;

  /**
   * Constructs the confirm form.
   */
  public function __construct(
    protected SodaScsWisskiVarnishCache $varnishCache,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('soda_scs_manager.wisski_varnish_cache'),
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
    return Url::fromRoute('soda_scs_manager.component.clear_varnish_cache', [
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
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_wisski_clear_varnish_cache_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Clear the Varnish cache of @label?', [
      '@label' => $this->component->label(),
    ]);
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
    if ($stack !== NULL) {
      return $stack->toUrl();
    }
    return $this->component->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This removes cached pages of this WissKI instance. The next visit loads fresh pages from WissKI. The instance itself stays online.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Clear Varnish cache');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $machineName = (string) ($this->component->get('machineName')->value ?? '');
    $result = $this->varnishCache->clear($machineName);
    if ($result->success) {
      $this->messenger()->addStatus($this->t('Varnish cache of @label cleared.', [
        '@label' => $this->component->label(),
      ]));
    }
    else {
      $this->messenger()->addError($this->t('Could not clear the Varnish cache of @label.', [
        '@label' => $this->component->label(),
      ]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
