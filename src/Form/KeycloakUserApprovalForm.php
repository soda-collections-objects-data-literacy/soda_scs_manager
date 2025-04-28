<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceUserActions;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for approving user registrations.
 */
class KeycloakUserApprovalForm extends FormBase {
  use StringTranslationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The Keycloak service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceUserActions
   */
  protected $keycloakServiceUserActions;

  /**
   * The SODa SCS service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected $serviceHelpers;

  /**
   * Constructs a KeycloakUserApprovalForm object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceUserActions $keycloak_service_user_actions
   *   The Keycloak service actions.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers $service_helpers
   *   The SODa SCS service helpers.
   */
  public function __construct(
    Connection $database,
    MailManagerInterface $mail_manager,
    MessengerInterface $messenger,
    SodaScsKeycloakServiceUserActions $keycloakServiceUserActions,
    SodaScsServiceHelpers $serviceHelpers,
  ) {
    $this->database = $database;
    $this->mailManager = $mail_manager;
    $this->messenger = $messenger;
    $this->keycloakServiceUserActions = $keycloakServiceUserActions;
    $this->serviceHelpers = $serviceHelpers;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('plugin.manager.mail'),
      $container->get('messenger'),
      $container->get('soda_scs_manager.keycloak_service.user.actions'),
      $container->get('soda_scs_manager.service.helpers')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'keycloak_user_approval_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'soda_scs_manager/keycloak_registration';

    // Get all pending registrations.
    $query = $this->database->select('keycloak_user_registration', 'kur')
      ->fields('kur', ['id', 'email', 'username', 'first_name', 'last_name', 'created'])
      ->condition('status', 'pending')
      ->orderBy('created', 'DESC');

    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      $form['no_registrations'] = [
        '#type' => 'markup',
        '#markup' => $this->t('There are no pending registrations at this time.'),
      ];
      return $form;
    }

    $form['registrations'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('ID'),
        $this->t('Email'),
        $this->t('Username'),
        $this->t('Name'),
        $this->t('Date'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No pending registrations.'),
    ];

    foreach ($results as $registration) {
      $created_date = \Drupal::service('date.formatter')->format($registration->created, 'short');

      $form['registrations'][$registration->id]['id'] = [
        '#markup' => $registration->id,
      ];

      $form['registrations'][$registration->id]['email'] = [
        '#markup' => $registration->email,
      ];

      $form['registrations'][$registration->id]['username'] = [
        '#markup' => $registration->username,
      ];

      $form['registrations'][$registration->id]['name'] = [
        '#markup' => $registration->first_name . ' ' . $registration->last_name,
      ];

      $form['registrations'][$registration->id]['created'] = [
        '#markup' => $created_date,
      ];

      $form['registrations'][$registration->id]['operations'] = [
        '#type' => 'operations',
        '#links' => [
          'approve' => [
            'title' => $this->t('Approve'),
            'url' => Url::fromRoute('soda_scs_manager.user_registration_approve', ['id' => $registration->id]),
          ],
          'reject' => [
            'title' => $this->t('Reject'),
            'url' => Url::fromRoute('soda_scs_manager.user_registration_reject', ['id' => $registration->id]),
          ],
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // This form doesn't have a submit handler as actions are handled by separate routes.
  }

  /**
   * Approves a user registration and creates the Keycloak user.
   *
   * @param int $id
   *   The registration ID.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function approveRegistration($id) {
    // Get the registration data.
    $query = $this->database->select('keycloak_user_registration', 'kur')
      ->fields('kur')
      ->condition('id', $id)
      ->condition('status', 'pending');

    $registration = $query->execute()->fetchObject();

    if (!$registration) {
      $this->messenger->addError($this->t('Registration not found or already processed.'));
      return $this->redirect('soda_scs_manager.user_registration_approvals');
    }

    // Get Keycloak settings.
    $keycloakGeneralSettings = $this->serviceHelpers->initKeycloakGeneralSettings();

    // Get token first.
    $tokenRequest = $this->keycloakServiceUserActions->buildTokenRequest([]);
    $tokenResponse = $this->keycloakServiceUserActions->makeRequest($tokenRequest);

    if (!$tokenResponse['success']) {
      $this->messenger->addError($this->t('Failed to authenticate with Keycloak: @error', ['@error' => $tokenResponse['error']]));
      return $this->redirect('soda_scs_manager.user_registration_approvals');
    }

    $token = json_decode($tokenResponse['data']['keycloakResponse']->getBody()->getContents())->access_token;

    $requestParams = [
      'token' => $token,
      'routeParams' => [
        'realm' => $keycloakGeneralSettings['realm'],
      ],
      'body' => [
        'enabled' => TRUE,
        'username' => $registration->username,
        'email' => $registration->email,
        'firstName' => $registration->first_name,
        'lastName' => $registration->last_name,
        'credentials' => [
          [
            'type' => 'password',
            'value' => $registration->password,
            'temporary' => FALSE,
          ],
        ],
        'emailVerified' => TRUE,
      ],
    ];

    $createUserRequest = $this->keycloakServiceUserActions->buildCreateRequest($requestParams);
    $createUserResponse = $this->keycloakServiceUserActions->makeRequest($createUserRequest);

    if (!$createUserResponse['success']) {
      $this->messenger->addError($this->t('Failed to create user in Keycloak: @error', ['@error' => $createUserResponse['error']]));
      return $this->redirect('soda_scs_manager.user_registration_approvals');
    }

    // Update registration status.
    $this->database->update('keycloak_user_registration')
      ->fields([
        'status' => 'approved',
        'updated' => time(),
      ])
      ->condition('id', $id)
      ->execute();

    // Send approval email to the user.
    $site_config = $this->configFactory()->get('system.site');
    $site_name = $site_config->get('name');

    $params = [
      'username' => $registration->username,
      'email' => $registration->email,
      'site_name' => $site_name,
      'login_url' => "https://scs.sammlungen.io/user/login",
    ];

    $this->mailManager->mail(
      'soda_scs_manager',
      'registration_approval',
      $registration->email,
      'en',
      $params,
      NULL,
      TRUE
    );

    $this->messenger->addStatus($this->t('User registration for @username has been approved and their account has been created.', ['@username' => $registration->username]));

    return $this->redirect('soda_scs_manager.user_registration_approvals');
  }

  /**
   * Rejects a user registration.
   *
   * @param int $id
   *   The registration ID.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function rejectRegistration($id) {
    // Get the registration data.
    $query = $this->database->select('keycloak_user_registration', 'kur')
      ->fields('kur', ['email', 'username'])
      ->condition('id', $id)
      ->condition('status', 'pending');

    $registration = $query->execute()->fetchObject();

    if (!$registration) {
      $this->messenger->addError($this->t('Registration not found or already processed.'));
      return $this->redirect('soda_scs_manager.user_registration_approvals');
    }

    // Update registration status.
    $this->database->update('keycloak_user_registration')
      ->fields([
        'status' => 'rejected',
        'updated' => time(),
      ])
      ->condition('id', $id)
      ->execute();

    // Send rejection email to the user.
    $site_config = $this->configFactory()->get('system.site');
    $site_name = $site_config->get('name');

    $params = [
      'username' => $registration->username,
      'email' => $registration->email,
      'site_name' => $site_name,
    ];

    $this->mailManager->mail(
      'soda_scs_manager',
      'registration_rejection',
      $registration->email,
      'en',
      $params,
      NULL,
      TRUE
    );

    $this->messenger->addStatus($this->t('User registration for @username has been rejected.', ['@username' => $registration->username]));

    return $this->redirect('soda_scs_manager.user_registration_approvals');
  }

}
