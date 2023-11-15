<?php

namespace Drupal\wisski_cloud_account_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions;
use Drupal\Core\Url;

class WisskiCloudAccountManagerPurgeForm extends ConfirmFormBase {

  /**
   * @var \Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions
   *   The WissKi Cloud account manager daemon API actions service.
   */
  protected WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wisski_cloud_account_manager_purge_form';
  }

  /**
   * Class constructor.
   *
   * @param \Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions
   *   The WissKi Cloud account manager daemon API actions service.
   */
  public function __construct(WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions) {
    $this->wisskiCloudAccountManagerDaemonApiActions = $wisskiCloudAccountManagerDaemonApiActions;
  }

  /**
   * Populate the reachable variables from services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The class container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wisski_cloud_account_manager.daemon_api.actions'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Do you really want to purge your WissKI Cloud account?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('wisski_cloud_account_manager.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will delete your WissKI Cloud account, your Drupal user, your WissKI Cloud instance and all data associated with it. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Purge');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $aid = \Drupal::routeMatch()->getParameter('aid');
    $account = $this->wisskiCloudAccountManagerDaemonApiActions->getAccounts($aid)[0];
    $form['account_table'] = [
      '#type' => 'table',
      '#rows' => [
        [$this->t('Subdomain'), $account['subdomain']],
        [$this->t('Account name'), $account['name'] ?: $this->t('Seems that Drupal user has already been deleted, delete the remaining account data and instance.')],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $aid = \Drupal::routeMatch()->getParameter('aid');
    $this->wisskiCloudAccountManagerDaemonApiActions->purgeAccount($aid);

    $form_state->setRedirect('wisski_cloud_account_manager.manage');
  }

}
