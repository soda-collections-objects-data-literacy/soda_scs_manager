<?php

namespace Drupal\wisski_cloud_account_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions;
use Drupal\Core\Url;

class WisskiCloudAccountManagerDeleteForm extends ConfirmFormBase {

  /**
   * @var array
   *   The account.
   */
  protected array $account;

  /**
   * @var \Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions
   *   The WissKi Cloud account manager daemon API actions service.
   */
  protected WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wisski_cloud_account_manager_delete_form';
  }

  /**
   * Class constructor.
   *
   * @param \Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions
   *   The WissKi Cloud account manager daemon API actions service.
   */
  public function __construct(WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions) {
    $this->wisskiCloudAccountManagerDaemonApiActions = $wisskiCloudAccountManagerDaemonApiActions;
    $aid = \Drupal::routeMatch()->getParameter('aid');
    $this->account = $this->wisskiCloudAccountManagerDaemonApiActions->getAccounts($aid)[0];
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
    return $this->t('Do you really want to delete your WissKI Cloud Instance?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('wisski_cloud_account_manager.validate')
      ->setRouteParameter('validationCode', $this->account['validation_code']);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will delete your WissKI Cloud instance. This action cannot be undone. Your Drupal user and WissKI Cloud account will remain.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $form['account_table'] = [
      '#type' => 'table',
      '#rows' => [
        [$this->t('Subdomain'), $this->account['subdomain']],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $aid = \Drupal::routeMatch()->getParameter('aid');
    $this->wisskiCloudAccountManagerDaemonApiActions->crudInstance('delete', $aid);
    $form_state->setRedirect('wisski_cloud_account_manager.manage');
  }

}
