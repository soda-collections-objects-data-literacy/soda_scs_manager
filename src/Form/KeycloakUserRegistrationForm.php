<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\EmailValidatorInterface;

/**
 * Provides a form for registering a user through Keycloak.
 */
class KeycloakUserRegistrationForm extends FormBase {
  use StringTranslationTrait;

  /**
   * The database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;
  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The password generator service.
   *
   * @var \Drupal\Core\Password\PasswordGeneratorInterface
   */
  protected $passwordGenerator;

  /**
   * The password hashing service.
   *
   * @var \Drupal\Core\Password\PasswordInterface
   */
  protected $passwordHasher;

  /**
   * The email validator service.
   *
   * @var \Drupal\Component\Utility\EmailValidatorInterface
   */
  protected $emailValidator;

  /**
   * Constructs a KeycloakUserRegistrationForm object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Password\PasswordGeneratorInterface $password_generator
   *   The password generator service.
   * @param \Drupal\Core\Password\PasswordInterface $password_hasher
   *   The password hashing service.
   * @param \Drupal\Component\Utility\EmailValidatorInterface $email_validator
   *   The email validator service.
   */
  public function __construct(
    Connection $connection,
    EntityTypeManagerInterface $entity_type_manager,
    MailManagerInterface $mail_manager,
    MessengerInterface $messenger,
    PasswordGeneratorInterface $password_generator,
    PasswordInterface $password_hasher,
    EmailValidatorInterface $email_validator,
  ) {
    $this->connection = $connection;
    $this->entityTypeManager = $entity_type_manager;
    $this->mailManager = $mail_manager;
    $this->messenger = $messenger;
    $this->passwordGenerator = $password_generator;
    $this->passwordHasher = $password_hasher;
    $this->emailValidator = $email_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.mail'),
      $container->get('messenger'),
      $container->get('password_generator'),
      $container->get('password'),
      $container->get('email.validator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_manager_keycloak_user_registration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'soda_scs_manager/keycloak_registration';

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p><strong>Register for an account. You will receive an email, which confirm your registration. It will be reviewed by an administrator. You will receive another email, when your account is approved or rejected.</strong></p>'),
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
      '#description' => $this->t('Enter a valid email address. This will be used for communication and login.'),
    ];

    // @todo Implement blacklist of usernames (no admin, root, etc.).
    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#required' => TRUE,
      '#description' => $this->t('Enter a username for your account.'),
    ];

    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#required' => TRUE,
      '#description' => $this->t('Enter your first name. Only letters, spaces, hyphens, apostrophes, and periods are allowed.'),
    ];

    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name'),
      '#required' => TRUE,
      '#description' => $this->t('Enter your last name. Only letters, spaces, hyphens, apostrophes, and periods are allowed.'),
    ];

    $form['password'] = [
      '#type' => 'password_confirm',
      '#required' => TRUE,
      '#description' => $this->t('Enter the password you would like to use.'),
    ];

    $form['terms_of_service'] = [
      '#type' => 'checkbox',
      '#description' => $this->t('I agree to the <a href="/terms-of-service" target="_blank">terms of service</a>'),
      '#title' => $this->t('I agree to the terms of service'),
      '#required' => TRUE,
    ];

    $form['privacy_policy'] = [
      '#type' => 'checkbox',
      '#description' => $this->t('I agree to the <a href="/imprint-privacy-policy" target="_blank">privacy policy</a>'),
      '#title' => $this->t('I agree to the privacy policy'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Register'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate email.
    $email = $form_state->getValue('email');
    if (!$this->emailValidator->isValid($email)) {
      $form_state->setErrorByName('email', $this->t('The email address %mail is not valid.', ['%mail' => $email]));
    }

    // Check if email is already registered.
    $query = $this->connection->select('keycloak_user_registration', 'kur')
      ->fields('kur', ['id'])
      ->condition('email', $email)
      ->condition('status', 'pending');

    $result = $query->execute()->fetchField();
    if ($result) {
      $form_state->setErrorByName('email', $this->t('There is already a pending registration for this email address.'));
    }

    // Validate username (only alphanumeric and underscores).
    $username = $form_state->getValue('username');
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
      $form_state->setErrorByName('username', $this->t('Username may only contain alphanumeric characters and underscores.'));
    }

    $blacklist = [
      'admin',
      'root',
      'administrator',
      'editor',
      'anonymous',
      'scs_manager',
      'scs-manager',
      'scs-user',
      'scs_user',
    ];

    if (in_array($username, $blacklist)) {
      $form_state->setErrorByName('username', $this->t('Username may not contain the word.'));
    }

    // Validate first name (no special characters except accented letters).
    $firstName = $form_state->getValue('first_name');
    if (!preg_match('/^[\p{L}\s\-\'\.]+$/u', $firstName)) {
      $form_state->setErrorByName('first_name', $this->t('First name may only contain letters, spaces, hyphens, apostrophes, and periods.'));
    }

    // Validate last name (no special characters except accented letters).
    $lastName = $form_state->getValue('last_name');
    if (!preg_match('/^[\p{L}\s\-\'\.]+$/u', $lastName)) {
      $form_state->setErrorByName('last_name', $this->t('Last name may only contain letters, spaces, hyphens, apostrophes, and periods.'));
    }

    // Check if username is already registered.
    $query = $this->connection->select('keycloak_user_registration', 'kur')
      ->fields('kur', ['id'])
      ->condition('username', $username)
      ->condition('status', 'pending');

    $result = $query->execute()->fetchField();
    if ($result) {
      $form_state->setErrorByName('username', $this->t('This username is already taken.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Get form values.
    $email = $form_state->getValue('email');
    $username = $form_state->getValue('username');
    $first_name = $form_state->getValue('first_name');
    $last_name = $form_state->getValue('last_name');
    $password = $form_state->getValue('password');

    // @todo Make better password security resp. controll if this is secure enough.K
    // We use keycloak for login and it has its own password policy.
    // So we don't need to hash the password here.

    // Save to database.
    $id = $this->connection->insert('keycloak_user_registration')
      ->fields([
        'email' => $email,
        'username' => $username,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'password' => $password,
        'status' => 'pending',
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    // Send email to the admin.
    $site_config = $this->config('system.site');
    $site_mail = $site_config->get('mail');
    $site_name = $site_config->get('name');

    $params = [
      'registration_id' => $id,
      'username' => $username,
      'email' => $email,
      'first_name' => $first_name,
      'last_name' => $last_name,
      'site_name' => $site_name,
    ];

    $this->mailManager->mail(
      'soda_scs_manager',
      'registration_admin_notification',
      $site_mail,
      'en',
      $params,
      $email,
      TRUE
    );

    // Send a confirmation email to the user.
    $params = [
      'username' => $username,
      'email' => $email,
      'site_name' => $site_name,
    ];

    $this->mailManager->mail(
      'soda_scs_manager',
      'registration_user_notification',
      $email,
      'en',
      $params,
      $site_mail,
      TRUE
    );

    // Send notification to site admin about new registration that needs approval.
    $admin_email = $this->config('smtp.settings')->get('smtp_from');
    if (empty($admin_email)) {
      // Fallback to site email if SMTP from is not set.
      $admin_email = $site_mail;
    }

    $admin_params = [
      'registration_id' => $id,
      'username' => $username,
      'email' => $email,
      'first_name' => $first_name,
      'last_name' => $last_name,
      'site_name' => $site_name,
      'approval_url' => Url::fromRoute('soda_scs_manager.user_registration_approvals', [], ['absolute' => TRUE])->toString(),
    ];

    // Display success message.
    $this->messenger->addStatus($this->t('Your registration has been submitted and is pending approval. You will be notified by email when your account is approved.'));

    // Redirect to the home page.
    $form_state->setRedirect('<front>');
  }

}
