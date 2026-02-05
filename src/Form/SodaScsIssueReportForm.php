<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
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
  protected MailManagerInterface $mailManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The current user account proxy.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a SodaScsIssueReportForm object.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user account proxy.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    MailManagerInterface $mailManager,
    MessengerInterface $messenger,
    AccountProxyInterface $currentUser,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->mailManager = $mailManager;
    $this->messenger = $messenger;
    $this->currentUser = $currentUser;
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('messenger'),
      $container->get('current_user'),
      $container->get('config.factory')
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

    $form['reporter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Wer ist der oder die Reporter*in'),
      '#description' => $this->t('Name (z. B. Robert)'),
      '#required' => TRUE,
      '#default_value' => $currentUserName,
    ];

    $form['location'] = [
      '#type' => 'url',
      '#title' => $this->t('Wo ist es passiert?'),
      '#description' => $this->t('Route/URL angeben (z. B. https://manager.scs.sammlungen.io/soda-scs-manager/stack/add/soda_scs_wisski_stack)'),
      '#required' => TRUE,
    ];

    $form['steps'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Wobei ist es passiert?'),
      '#description' => $this->t('Genaue Angabe der Input/Click-Chain (Ich habe in das Feld "Label" "Robert`); DROP TABLE users; --" eingeben, "Submit" geklickt)'),
      '#required' => TRUE,
      '#rows' => 5,
    ];

    $form['expected'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Was wurde erwartet?'),
      '#description' => $this->t('Was sollte eigentlich passieren (z. B. Ein neues WissKI)'),
      '#required' => TRUE,
      '#rows' => 3,
    ];

    $form['error'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Was war der Fehler?'),
      '#description' => $this->t('Danach kamen nur noch Whitescreens bzw. Access Denied'),
      '#required' => TRUE,
      '#rows' => 5,
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
    $location = $form_state->getValue('location');
    if (!empty($location) && !filter_var($location, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName('location', $this->t('Please enter a valid URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $reporter = $form_state->getValue('reporter');
    $location = $form_state->getValue('location');
    $steps = $form_state->getValue('steps');
    $expected = $form_state->getValue('expected');
    $error = $form_state->getValue('error');

    // Get site admin email.
    $adminEmail = $this->configFactory->get('soda_scs_manager.settings')->get('administratorEmail');
    if (empty($adminEmail)) {
      // Fallback to site email if administratorEmail is not set.
      $adminEmail = $this->configFactory->get('system.site')->get('mail');
    }

    // Get site name.
    $siteConfig = $this->configFactory->get('system.site');
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
      'de',
      $params,
      $currentUserEmail ?: NULL,
      TRUE
    );

    // Display success message.
    $this->messenger->addStatus($this->t('Your issue report has been submitted successfully. Thank you for your feedback!'));

    // Redirect to the start page.
    $form_state->setRedirect('soda_scs_manager.start_page');
  }

}
