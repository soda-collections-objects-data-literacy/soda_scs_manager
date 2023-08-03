<?php

namespace Drupal\wisski_cloud_account_manager\Form;

use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * WissKI cloud create account form.
 */
class WisskiCloudAccountManagerCreateForm extends FormBase {

  /**
   * @var \Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions
   *  The WissKi Cloud account manager daemon API actions service.
   */
  protected WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wisski_cloud_account_manager_create';
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
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['personname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Person name'),
      '#description' => $this->t('Your first and last name.'),
      '#required' => TRUE,
    ];

    $form['organisation'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organisation'),
      '#description' => $this->t('Your organisation, employer, affiliation - if any.'),
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#description' => $this->t('For communication and opt in.'),
      '#required' => TRUE,
    ];

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#maxlength' => 20,
      '#description' => $this->t('WissKI cloud login user. Only small caps (a-z), underscore (_), minus (-) and 20 letter maximum allowed, i.e. "wisski_user".'),
      '#pattern' => '[a-z]+([_-]{1}[a-z]+)*',
      '#required' => TRUE,
    ];

    $form['password'] = [
      '#type' => 'password_confirm',
      '#size' => 32,
      '#description' => $this->t('Your Password'),
      '#required' => TRUE,
    ];

    // @todo Add '.wisski.cloud' text as prefix.
    $form['subdomain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subdomain'),
      '#maxlength' => 12,
      '#description' => $this->t('WissKI cloud subdomain. Only small caps (a-z), underscore (_), minus (-) and 12 letter maximum allowed, i.e. "my_wisski" will end in "my_wisski.wisski.cloud".'),
      '#pattern' => '[a-z]+([_-]{1}[a-z]+)*',
      '#required' => TRUE,
    ];

    $form['termsConditions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Terms & Conditions'),
      '#description' => $this->t('You have to agree to our <a href="@termsConditions" target="_blank">terms and conditions</a> to use the WissKI Cloud.', ['@termsConditions' => '/wisski-cloud-account-manager/terms-and-conditions']),
      '#required' => TRUE,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Check if username is in database.
    // @todo Check if username is WissKI Cloud accounts, i.e add direct by admin?.
    $username = $form_state->getValue('username');
    $conn = Database::getConnection();
    $accountWithUsername = $conn
      ->select('wisski_cloud_accounts', 'wca')
      ->fields('wca', ['username'])
      ->condition('username', $username)
      ->execute()
      ->fetchCol();
    if (!empty($accountWithUsername)) {
      $form_state->setErrorByName('username', $this->t('The username @username is already in use.', ['@username' => $username]));
    }

    // Check if email is in valid form.
    $email = $form_state->getValue('email');
    if (!\Drupal::service('email.validator')->isValid($email)) {
      $form_state->setErrorByName('email', $this->t('Email not in valid form, i.e. "name@example.com".'));
    }

    // Check if email is in database.
    $accountWithEmail = $conn
      ->select('wisski_cloud_accounts', 'wca')
      ->fields('wca', ['email'])
      ->condition('email', $email)
      ->execute()
      ->fetchCol();
    if (!empty($accountWithEmail)) {
      $form_state->setErrorByName('email', $this->t('The email @email is already in use.', ['@email' => $email]));
    }

    // Check if subdomain is in database.
    $subdomain = $form_state->getValue('subdomain');
    $accountWithSubdomain = $conn
      ->select('wisski_cloud_accounts', 'wca')
      ->fields('wca', ['subdomain'])
      ->condition('subdomain', $subdomain)
      ->execute()
      ->fetchCol();
    if (!empty($accountWithSubdomain)) {
      $form_state->setErrorByName('subdomain', $this->t('The subdomain @subdomain is already in use.', ['@subdomain' => $subdomain]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $conn = Database::getConnection();

      $field = $form_state->getValues();

      $account["personname"] = $field['personname'];
      $account["organisation"] = $field['organisation'];
      $account["email"] = $field['email'];
      $account["username"] = $field['username'];
      $account["password"] = $field['password'];
      $account["subdomain"] = $field['subdomain'];

      $daemonResponse = $this->wisskiCloudAccountManagerDaemonApiActions->addAccount($account);
      dpm($daemonResponse, 'Daemon response');

      unset($account["password"]);

      dpm($account);

      /*
      $conn->insert('wisski_cloud_accounts')
      ->fields($account)->execute();
      \Drupal::messenger()
      ->addMessage($this->t('The account data has been succesfully saved'));
       */
    }
    catch (\Exception $ex) {
      \Drupal::logger('wisski_cloud_account_manager')->error($ex->getMessage());
    }
  }

}
