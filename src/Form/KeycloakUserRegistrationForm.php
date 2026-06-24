<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\TimeZoneFormHelper;
use Drupal\Core\Language\LanguageInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\user\Plugin\LanguageNegotiation\LanguageNegotiationUser;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\soda_scs_manager\Validation\InputDisallowedTerms;
use Drupal\soda_scs_manager\Validation\PersonalNameValidator;
use Drupal\soda_scs_manager\Validation\UsernameValidator;

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
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

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
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(
    Connection $connection,
    EntityTypeManagerInterface $entity_type_manager,
    MailManagerInterface $mail_manager,
    MessengerInterface $messenger,
    PasswordGeneratorInterface $password_generator,
    PasswordInterface $password_hasher,
    EmailValidatorInterface $email_validator,
    LanguageManagerInterface $language_manager,
  ) {
    $this->connection = $connection;
    $this->entityTypeManager = $entity_type_manager;
    $this->mailManager = $mail_manager;
    $this->messenger = $messenger;
    $this->passwordGenerator = $password_generator;
    $this->passwordHasher = $password_hasher;
    $this->emailValidator = $email_validator;
    $this->languageManager = $language_manager;
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
      $container->get('email.validator'),
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_manager_keycloak_user_registration_form';
  }

  /**
   * Drops #description so the checkbox label (linked #title) is not shown twice.
   *
   * Runs after hook_form_alter(), so contrib that adds a matching description
   * cannot duplicate the visible text next to the legal checkboxes.
   */
  public static function removeLegalCheckboxDescription(array $element, FormStateInterface $form_state): array {
    unset($element['#description']);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'soda_scs_manager/keycloak_registration';

    $introText = (string) $this->t(
      'Register for an account. You will receive an email confirming your registration. Your registration will be reviewed by an administrator. You will receive another email when your account is approved or rejected.'
    );
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('<p><strong>' . Html::escape($introText) . '</strong></p>'),
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
      '#description' => $this->t('Enter a valid email address. This will be used for communication and login.'),
    ];

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#required' => TRUE,
      '#description' => $this->t(
        'Enter at least @count characters. Only letters, digits, and underscores are allowed.',
        ['@count' => InputDisallowedTerms::USERNAME_MIN_LENGTH],
      ),
    ];

    $nameFieldDescription = $this->t(
      'Enter at least @count letters. Only letters, spaces, hyphens, apostrophes, and periods are allowed. Digits and reserved words are not allowed.',
      ['@count' => InputDisallowedTerms::PERSONAL_NAME_MIN_LETTERS],
    );

    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#required' => TRUE,
      '#description' => $nameFieldDescription,
    ];

    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name'),
      '#required' => TRUE,
      '#description' => $nameFieldDescription,
    ];

    // Same language widget as the user account form (Site language / configurable languages).
    $user_preferred_default = $this->languageManager->getCurrentLanguage()->getId();
    $user_language_added = FALSE;
    if ($this->languageManager instanceof ConfigurableLanguageManagerInterface) {
      $negotiator = $this->languageManager->getNegotiator();
      $user_language_added = $negotiator && $negotiator->isNegotiationMethodEnabled(LanguageNegotiationUser::METHOD_ID, LanguageInterface::TYPE_INTERFACE);
    }

    $form['interface_langcode'] = [
      '#type' => 'language_select',
      '#title' => $this->t('Site language'),
      '#languages' => LanguageInterface::STATE_CONFIGURABLE,
      '#default_value' => $user_preferred_default,
      '#description' => $user_language_added
        ? $this->t("This account's preferred language for emails and site presentation.")
        : $this->t("This account's preferred language for emails."),
      '#parents' => ['interface_langcode'],
      '#required' => TRUE,
    ];

    // Same time zone widget as the user account form (grouped by region).
    $system_date = $this->config('system.date');

    $form['timezone'] = [
      '#type' => 'select',
      '#title' => $this->t('Time zone'),
      '#parents' => ['timezone'],
      '#default_value' => $system_date->get('timezone.default'),
      '#options' => TimeZoneFormHelper::getOptionsListByRegion(FALSE),
      '#description' => $this->t('Select the desired local time and time zone. Dates and times throughout this site will be displayed using this time zone.'),
      '#required' => TRUE,
      '#attributes' => ['class' => ['timezone-detect']],
    ];
    $form['timezone']['#attached']['library'][] = 'core/drupal.timezone';

    $form['password'] = [
      '#type' => 'password_confirm',
      '#required' => TRUE,
      '#description' => $this->t('Enter the password you would like to use.'),
    ];

    $form['terms_of_service'] = [
      '#type' => 'checkbox',
      '#title' => Markup::create($this->t('I agree to the <a href="/terms-of-service" target="_blank">terms of service</a>')),
      '#required' => TRUE,
      '#after_build' => [[static::class, 'removeLegalCheckboxDescription']],
    ];

    $form['privacy_policy'] = [
      '#type' => 'checkbox',
      '#title' => Markup::create($this->t('I agree to the <a href="/privacy-policy" target="_blank">privacy policy</a>')),
      '#required' => TRUE,
      '#after_build' => [[static::class, 'removeLegalCheckboxDescription']],
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

    $username = (string) $form_state->getValue('username');
    $usernameViolation = UsernameValidator::validate($username);
    if ($usernameViolation !== NULL) {
      $form_state->setErrorByName('username', UsernameValidator::violationMessage($usernameViolation));
    }

    foreach (['first_name' => $this->t('First name'), 'last_name' => $this->t('Last name')] as $fieldName => $fieldLabel) {
      $violation = PersonalNameValidator::validate((string) $form_state->getValue($fieldName));
      if ($violation !== NULL) {
        $form_state->setErrorByName($fieldName, PersonalNameValidator::violationMessage($violation, $fieldLabel));
      }
    }

    $langcode = (string) $form_state->getValue('interface_langcode');
    $allowed = array_keys($this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE));
    if ($allowed === []) {
      $allowed = array_keys($this->languageManager->getLanguages());
    }
    if ($langcode === '' || !in_array($langcode, $allowed, TRUE)) {
      $form_state->setErrorByName('interface_langcode', $this->t('Please choose a valid site language.'));
    }

    $timezone = (string) $form_state->getValue('timezone');
    $identifiers = \DateTimeZone::listIdentifiers();
    if ($timezone === '' || !in_array($timezone, $identifiers, TRUE)) {
      $form_state->setErrorByName('timezone', $this->t('Please choose a valid time zone.'));
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
    $interface_langcode = $form_state->getValue('interface_langcode');
    $timezone = $form_state->getValue('timezone');
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
        'interface_langcode' => $interface_langcode,
        'timezone' => $timezone,
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
