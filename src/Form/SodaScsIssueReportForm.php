<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for reporting issues.
 */
class SodaScsIssueReportForm extends FormBase {
  use StringTranslationTrait;

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
   * The current user account proxy.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a SodaScsIssueReportForm object.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user account proxy.
   */
  public function __construct(
    MailManagerInterface $mailManager,
    MessengerInterface $messenger,
    AccountProxyInterface $currentUser,
  ) {
    $this->mailManager = $mailManager;
    $this->messenger = $messenger;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('messenger'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_issue_report_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Get current user account for default value.
    $currentUserAccount = $this->currentUser->getAccount();
    $currentUserName = '';
    if ($currentUserAccount && !$currentUserAccount->isAnonymous()) {
      $currentUserName = $currentUserAccount->getDisplayName();
    }

    $form['info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('This report will be used to create issues in the public issue tracker at <a href="https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/issues" target="_blank">Soda SCS Manager GitHub Issue Tracker</a>. All personal data will be removed from the submitted report before publication. <strong>Please do not include sensitive data such as passwords, usernames, or other confidential information in any of the input fields.</strong>'),
    ];

    $form['info_understood'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand the information above.'),
      '#required' => TRUE,
    ];

    $form['reporter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Who is the reporter?'),
      '#description' => $this->t('Name (e.g. Robert)'),
      '#required' => TRUE,
      '#default_value' => $currentUserName,
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title:'),
      '#description' => $this->t('Short description of the issue. (e.g. "Soda SCS Manager: WissKI Stack Add Page: Access Denied")'),
      '#required' => TRUE,
    ];

    $form['location'] = [
      '#type' => 'url',
      '#title' => $this->t('Where did it happen?'),
      '#description' => $this->t('Provide route/URL (e.g. https://manager.scs.sammlungen.io/soda-scs-manager/stack/add/soda_scs_wisski_stack)'),
      '#required' => TRUE,
    ];

    $form['steps'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What happened?'),
      '#description' => $this->t('Exact description of the input/click chain (I entered "Robert`); DROP TABLE users; --" in the "Label" field, clicked "Submit")'),
      '#required' => TRUE,
      '#rows' => 5,
    ];

    $form['expected'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What was expected?'),
      '#description' => $this->t('What should have happened (e.g. A new WissKI)'),
      '#required' => TRUE,
      '#rows' => 3,
    ];

    $form['error'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What was the error?'),
      '#description' => $this->t('If there was an error or irritation, please describe it here. (e.g. "After that, only white screens or Access Denied appeared")'),
      '#required' => TRUE,
      '#rows' => 5,
    ];

    $form['send_copy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send copy to my email'),
      '#default_value' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->getValue('info_understood')) {
      $form_state->setErrorByName('info_understood', $this->t('You must confirm that you understand the information above before submitting.'));
    }

    $location = $form_state->getValue('location');
    if (!empty($location) && !filter_var($location, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName('location', $this->t('Please enter a valid URL.'));
    }

    // Check for Little Bobby Tables SQL injection attempt.
    $bobbyTablesPattern = '/Robert`\); DROP TABLE .+; --/';
    $formValues = [
      'reporter' => $form_state->getValue('reporter'),
      'title' => $form_state->getValue('title'),
      'location' => $form_state->getValue('location'),
      'steps' => $form_state->getValue('steps'),
      'expected' => $form_state->getValue('expected'),
      'error' => $form_state->getValue('error'),
    ];

    foreach ($formValues as $fieldName => $value) {
      if (!empty($value) && preg_match($bobbyTablesPattern, $value)) {
        $form_state->setErrorByName($fieldName, $this->t('Little Bobby Tables has no power here!'));
        break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $reporter = $form_state->getValue('reporter');
    $title = $form_state->getValue('title');
    $location = $form_state->getValue('location');
    $steps = $form_state->getValue('steps');
    $expected = $form_state->getValue('expected');
    $error = $form_state->getValue('error');

    // Get site admin email.
    $adminEmail = $this->config('soda_scs_manager.settings')->get('administratorEmail');
    if (empty($adminEmail)) {
      // Fallback to site email if administratorEmail is not set.
      $adminEmail = $this->config('system.site')->get('mail');
    }

    // Get site name.
    $siteConfig = $this->config('system.site');
    $siteName = $siteConfig->get('name');

    // Get current user info for context.
    $currentUserAccount = $this->currentUser->getAccount();
    $currentUserEmail = '';
    $currentUserId = 0;
    if ($currentUserAccount && !$currentUserAccount->isAnonymous()) {
      $currentUserEmail = $currentUserAccount->getEmail();
      $currentUserId = $currentUserAccount->id();
    }

    // Prepare email parameters.
    $params = [
      'reporter' => $reporter,
      'title' => $title,
      'location' => $location,
      'steps' => $steps,
      'expected' => $expected,
      'error' => $error,
      'site_name' => $siteName,
      'current_user_email' => $currentUserEmail,
      'current_user_id' => $currentUserId,
    ];

    // Send email to site admin.
    $this->mailManager->mail(
      'soda_scs_manager',
      'issue_report',
      $adminEmail,
      'en',
      $params,
      $currentUserEmail ?: NULL,
      TRUE
    );

    // Send copy to the reporter if requested.
    $sendCopy = $form_state->getValue('send_copy');
    if ($sendCopy && !empty($currentUserEmail)) {
      $this->mailManager->mail(
        'soda_scs_manager',
        'issue_report_copy',
        $currentUserEmail,
        'en',
        $params,
        $currentUserEmail,
        TRUE
      );
    }

    // Display success message.
    $this->messenger->addStatus($this->t('Your issue report has been submitted successfully. Thank you for your feedback!'));

    // Redirect to the start page.
    $form_state->setRedirect('soda_scs_manager.start_page');
  }

}
