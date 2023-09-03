<?php

namespace Drupal\wisski_cloud_account_manager\Form;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * WissKI cloud create account form.
 */
class WisskiCloudAccountManagerCreateForm extends FormBase {




  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The email validator service.
   *
   * @var \Drupal\Component\Utility\EmailValidatorInterface
   */
  private EmailValidatorInterface $emailValidator;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $settings;

  /**
   * The WissKi Cloud account manager daemon API actions service.
   *
   * @var \Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions
   */
  protected WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wisski_cloud_account_manager_create';
  }

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Component\Utility\EmailValidatorInterface $emailValidator
   *   The email validator service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions
   *   The WissKi Cloud account manager daemon API actions service.
   */
  public function __construct(ConfigFactoryInterface $configFactory, EmailValidatorInterface $emailValidator, MessengerInterface $messenger, WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions) {
    $this->configFactory = $configFactory;
    $settings = $configFactory
      ->getEditable('wisski_cloud_account_manager.settings');
    $this->settings = $settings;
    $this->wisskiCloudAccountManagerDaemonApiActions = $wisskiCloudAccountManagerDaemonApiActions;
    $this->emailValidator = $emailValidator;
    $this->messenger = $messenger;
  }

  /**
   * Populate the reachable variables from services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The class container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('email.validator'),
      $container->get('messenger'),
      $container->get('wisski_cloud_account_manager.daemon_api.actions'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['personName'] = [
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
      '#maxlength' => 64,
      '#description' => $this->t('WissKI cloud subdomain. Only small caps (a-z), underscore (_), minus (-) and 64 letter maximum allowed, i.e. "my_wisski" will end in "my_wisski.wisski.cloud".'),
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Check if account data is already in use.
    // @todo Check if username is WissKI Cloud accounts, i.e add direct by admin?.
    $dataToCheck['username'] = $form_state->getValue('username');
    $dataToCheck['email'] = $form_state->getValue('email');
    $dataToCheck['subdomain'] = $form_state->getValue('subdomain');

    $response = $this->wisskiCloudAccountManagerDaemonApiActions->checkAccountData($dataToCheck);

    if (!$response['success']) {
      $this->messenger->addError('Can not communicate with the provision daemon, please try again later or write an email to cloud@wiss-ki.eu.');
    }
    if (strlen($dataToCheck['username']) < 3) {
      $form_state->setErrorByName('username', $this->t('The username "@username" is too short, please use at least 3 characters.', ['@username' => $dataToCheck['username']]));
    }
    if (in_array($dataToCheck['username'], explode(',', $this->settings->get('usernameBlacklist')))) {
      $form_state->setErrorByName('username', $this->t('The username "@username" is not allowed.', ['@username' => $dataToCheck['username']]));
    }
    if ($response['accountData']['accountWithUsername']) {
      $form_state->setErrorByName('username', $this->t('The username "@username" is already in use.', ['@username' => $dataToCheck['username']]));
    }

    if ($response['accountData']['accountWithEmail']) {
      $form_state->setErrorByName('email', $this->t('The email "@email" is already in use.', ['@email' => $dataToCheck['email']]));
    }

    if (strlen($dataToCheck['subdomain']) < 3) {
      $form_state->setErrorByName('subdomain', $this->t('The subdomain "@subdomain" is too short, please use at least 3 characters.', ['@subdomain' => $dataToCheck['subdomain']]));
    }

    if (in_array($dataToCheck['subdomain'], explode(',', $this->settings->get('subdomainBlacklist')))) {
      $form_state->setErrorByName('subdomain', $this->t('The subdomain "@subdomain" is not allowed.', ['@subdomain' => $dataToCheck['subdomain']]));
    }
    if ($response['accountData']['accountWithSubdomain']) {
      $form_state->setErrorByName('subdomain', $this->t('The subdomain "@subdomain" is already in use.', ['@subdomain' => $dataToCheck['subdomain']]));
    }

    // Check if email is in valid form.
    if (!$this->emailValidator->isValid($dataToCheck['email'])) {
      $form_state->setErrorByName('email', $this->t('Email not in valid form, i.e. "name@example.com".'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $field = $form_state->getValues();

      $account["personName"] = $field['personName'];
      $account["organisation"] = $field['organisation'];
      $account["email"] = $field['email'];
      $account["username"] = $field['username'];
      $account["password"] = $field['password'];
      $account["subdomain"] = $field['subdomain'];

      $accountResponse = $this->wisskiCloudAccountManagerDaemonApiActions->addAccount($account);
      $this->wisskiCloudAccountManagerDaemonApiActions->sendValidationEmail($accountResponse['data']['email'], $accountResponse['data']['validationCode']);

      $this->messenger()
        ->addMessage($this->t('The account data has been successfully saved, please check your email for validation!'));
    }
    catch (\Exception $ex) {
      $this->logger('wisski_cloud_account_manager')->error($ex->getMessage());
    }
  }

}
