<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\soda_scs_manager\WisskiCloudAccountManagerDaemonApiActions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * WissKI cloud create account form.
 */
class UserCreateForm extends FormBase {

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
   * @var \Drupal\soda_scs_manager\WisskiCloudAccountManagerDaemonApiActions
   */
  protected SodaScsUserCreateForm $wisskiCloudAccountManagerDaemonApiActions;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_create';
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
   * @param \Drupal\soda_scs_manager\WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions
   *   The WissKi Cloud account manager daemon API actions service.
   */
  public function __construct(ConfigFactoryInterface $configFactory, EmailValidatorInterface $emailValidator, MessengerInterface $messenger, WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions) {
    $this->configFactory = $configFactory;
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
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
      $container->get('soda_scs_manager.daemon_api.actions'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['person_name'] = [
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
      '#description' => $this->t('WissKI cloud login - NOT your password for your instance - this will be send separately. Only small caps (a-z), underscore (_), minus (-) and 20 letter maximum allowed, i.e. "wisski_user".'),
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
      '#description' => $this->t('WissKI cloud subdomain. Only small caps (a-z), minus (-) and 64 letter maximum allowed, i.e. "my-wisski" will end in "my-wisski.wisski.cloud".'),
      '#pattern' => '[a-z0-9]+([-]{1}[a-z0-9]+)*',
      '#required' => TRUE,
    ];

    $form['terms_conditions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Terms & Conditions'),
      '#description' => $this->t('You have to agree to our <a href="@terms_conditions" target="_blank">terms and conditions</a> to use the WissKI Cloud.', ['@terms_conditions' => '/wisski-cloud-account-manager/terms-and-conditions']),
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
    $dataToCheck['email_provider'] = explode('@', $dataToCheck['email'])[1];
    $dataToCheck['subdomain'] = $form_state->getValue('subdomain');
    $dataToCheck['usernameBlacklist'] = $this->settings->get('usernameBlacklist') ?? '';
    $dataToCheck['email_provider_blacklist'] = $this->settings->get('email_provider_blacklist') ?? '';
    $dataToCheck['subdomainBlacklist'] = $this->settings->get('subdomainBlacklist') ?? '';

    // Check if username is too short.
    if (strlen($dataToCheck['username']) < 2) {
      $form_state->setErrorByName('username', $this->t('The username "@username" is too short, please use at least 2 characters.', ['@username' => $dataToCheck['username']]));
    }

    // Check if username is in blacklist.
    if (in_array($dataToCheck['username'], preg_split('/\r\n|\r|\n/', $dataToCheck['usernameBlacklist']))) {
      $form_state->setErrorByName('username', $this->t('The username "@username" is not allowed.', ['@username' => $dataToCheck['username']]));
    }

    // Check if username only contains lowercase letters, numbers, and underscores
    if (!preg_match('/^[a-z0-9_]+$/', $dataToCheck['username'])) {
      $form_state->setErrorByName('username', $this->t('Username can only contain lowercase letters, numbers, and underscores.'));
    }

    // Check if username is unique
    $userFromUsername = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' =>$dataToCheck['username']]);
    if (!empty($userFromUsername)) {
      $form_state->setErrorByName('username', $this->t('The username is already taken.'));
    }

    // Check if email provider is in blacklist.
    if (in_array($dataToCheck['email_provider'], preg_split('/\r\n|\r|\n/', $dataToCheck['email_provider_blacklist']))) {
      $form_state->setErrorByName('email', $this->t('The email provider  "@provider"is not allowed.', ['@provider' => $dataToCheck['email_provider']]));
    }

    // Check if email is already in use.
    $userFromEmail = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $dataToCheck['email']]);
    if (!empty($userFromEmail)) {
      $form_state->setErrorByName('email', $this->t('The email "@email" is already in use.', ['@email' => $dataToCheck['email']]));
    }

    // Check if subdomain is too short.
    if (strlen($dataToCheck['subdomain']) < 3) {
      $form_state->setErrorByName('subdomain', $this->t('The subdomain "@subdomain" is too short, please use at least 3 characters.', ['@subdomain' => $dataToCheck['subdomain']]));
    }

    // Check if subdomain is in blacklist.
    if (in_array($dataToCheck['subdomain'], preg_split('/\r\n|\r|\n/', $dataToCheck['email_provider_blacklist']))) {
      $form_state->setErrorByName('subdomain', $this->t('The subdomain "@subdomain" is not allowed.', ['@subdomain' => $dataToCheck['subdomain']]));
    }

    // Check if subdomain is already in use.
    if ($this->wisskiCloudAccountManagerDaemonApiActions->checkForRedundantAccountData('subdomain', $dataToCheck['subdomain'])) {
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

      $account["person_name"] = $field['person_name'];
      $account["organisation"] = $field['organisation'];
      $account["email"] = $field['email'];
      $account["username"] = $field['username'];
      $account["password"] = $field['password'];
      $account["subdomain"] = $field['subdomain'];

      $user = $this->wisskiCloudAccountManagerDaemonApiActions->addAccount($account);
      $this->wisskiCloudAccountManagerDaemonApiActions->sendValidationEmail($user['email'], $user['person_name'], $user['validationCode']);

      $this->messenger()
        ->addMessage($this->t('The account data has been successfully saved, please check your email for validation!'));
    }
    catch (\Exception $ex) {
      $this->messenger()
        ->addError($this->t('The account data could not be saved, please try again later or write an email to cloud@wiss-ki.eu.'));
      $this->logger('soda_scs_manager')->error($ex->getMessage());
    }
  }

}
