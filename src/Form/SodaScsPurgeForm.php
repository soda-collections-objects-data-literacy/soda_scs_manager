<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\soda_scs_manager\SodaScsApiActions;
use Drupal\Core\Url;

class SodaScsPurgeForm extends ConfirmFormBase {

  /**
   * @var \Drupal\soda_scs_manager\SodaScsApiActions
   *   The WissKi Cloud account manager daemon API actions service.
   */
  protected SodaScsApiActions $sodaScsApiActions;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_manager_purge_form';
  }

  /**
   * Class constructor.
   *
   * @param \Drupal\soda_scs_manager\WisskiCloudAccountManagerDaemonApiActions $sodaScsApiActions
   *   The WissKi Cloud account manager daemon API actions service.
   */
  public function __construct(SodaScsApiActions $sodaScsApiActions) {
    $this->sodaScsApiActions = $sodaScsApiActions;
  }

  /**
   * Populate the reachable variables from services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The class container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('soda_scs_manager.daemon_api.actions'),
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
    return Url::fromRoute('soda_scs_manager.settings');
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
    $account = $this->sodaScsApiActions->getAccounts($aid)[0];
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
    $this->sodaScsApiActions->purgeAccount($aid);

    $form_state->setRedirect('soda_scs_manager.users');
  }

}
