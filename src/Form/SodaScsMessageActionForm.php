<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsMessageInterface;
use Drupal\soda_scs_manager\MessageType\SodaScsMessageTypeManager;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Type-specific actions on an opened message (accept, decline, delete, …).
 */
final class SodaScsMessageActionForm extends FormBase {

  /**
   * The message being acted on (set in buildForm).
   */
  private SodaScsMessageInterface|null $message = NULL;

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SodaScsMessageTypeManager $messageTypeManager,
    MessengerInterface $messenger,
  ) {
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.message_type.manager'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep(): array {
    $this->_serviceIds = [
      'currentUser' => 'current_user',
      'entityTypeManager' => 'entity_type.manager',
      'messageTypeManager' => 'soda_scs_manager.message_type.manager',
      'messenger' => 'messenger',
    ];
    $vars = get_object_vars($this);
    foreach (array_keys($this->_serviceIds) as $key) {
      unset($vars[$key]);
    }
    return array_keys($vars);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, SodaScsMessageInterface|null $soda_scs_message = NULL, bool $redirect_to_inbox = FALSE): array {
    $this->message = $soda_scs_message;
    if ($this->message === NULL) {
      return $form;
    }

    $form_state->set('soda_scs_message_id', $this->message->id());
    $form_state->set('redirect_to_inbox', $redirect_to_inbox);
    $form['#attributes']['class'][] = 'scs-manager--message-actions';

    $actions = $this->messageTypeManager->resolve($this->message)->buildActions($this->message);
    if ($actions === []) {
      return $form;
    }

    $form['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['scs-manager--message-actions-row'],
      ],
    ];

    foreach ($actions as $actionId => $definition) {
      $classes = $definition['classes'] ?? ['button'];
      $element = [
        '#type' => 'submit',
        '#value' => $definition['title'],
        '#name' => 'message_action_' . $actionId,
        '#action_id' => $actionId,
        '#submit' => ['::submitAction'],
        '#limit_validation_errors' => [],
        '#attributes' => [
          'class' => $classes,
        ],
      ];
      if ($actionId === 'delete') {
        $confirm = (string) $this->t('Delete this message from your inbox?');
        $element['#attributes']['onclick'] = 'return confirm(' . json_encode($confirm) . ');';
      }
      $form['actions'][$actionId] = $element;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_message_action_form';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Submission is handled per action.
  }

  /**
   * Dispatch the clicked action to the message type plugin.
   */
  public function submitAction(array &$form, FormStateInterface $form_state): void {
    $message = $this->loadMessage($form_state);
    if ($message === NULL) {
      return;
    }

    $actor = $this->loadCurrentUserEntity();
    if ($actor === NULL) {
      return;
    }

    $trigger = $form_state->getTriggeringElement();
    $action = (string) ($trigger['#action_id'] ?? '');
    if ($action === '') {
      return;
    }

    $result = $this->messageTypeManager->resolve($message)->handleAction($action, $message, $actor);
    if ($result->success) {
      $this->messenger->addStatus($result->message);
    }
    else {
      $this->messenger->addError($result->message);
    }

    $redirectUrl = $result->success ? ($result->data['redirect_url'] ?? NULL) : NULL;
    if ($redirectUrl instanceof Url) {
      $form_state->setRedirectUrl($redirectUrl);
      return;
    }

    $messageStillExists = $this->entityTypeManager->getStorage('soda_scs_message')->load($message->id()) !== NULL;
    if (!$messageStillExists || $form_state->get('redirect_to_inbox')) {
      $form_state->setRedirect('soda_scs_manager.messages');
      return;
    }

    $form_state->setRedirectUrl($message->toUrl());
  }

  /**
   * Load the current user entity.
   */
  private function loadCurrentUserEntity(): UserInterface|null {
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if (!$user instanceof UserInterface) {
      $this->messenger->addError($this->t('Unable to load your user account. Please try again.'));
      return NULL;
    }
    return $user;
  }

  /**
   * Load the message stored on the form state.
   */
  private function loadMessage(FormStateInterface $form_state): SodaScsMessageInterface|null {
    $id = (int) $form_state->get('soda_scs_message_id');
    if ($id <= 0) {
      $this->messenger->addError($this->t('The selected message no longer exists.'));
      return NULL;
    }

    $message = $this->entityTypeManager->getStorage('soda_scs_message')->load($id);
    if (!$message instanceof SodaScsMessageInterface) {
      $this->messenger->addError($this->t('The selected message no longer exists.'));
      return NULL;
    }

    $recipient = $message->getRecipient();
    if ($recipient === NULL || (int) $recipient->id() !== (int) $this->currentUser->id()) {
      $this->messenger->addError($this->t('You are not allowed to act on this message.'));
      return NULL;
    }

    return $message;
  }

}
