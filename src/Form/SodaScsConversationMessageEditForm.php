<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsConversationMessageInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsConversationHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets the author change the text of a message.
 */
final class SodaScsConversationMessageEditForm extends FormBase {

  public function __construct(
    protected readonly SodaScsConversationHelpers $conversationHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.conversation.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_conversation_message_edit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsConversationMessageInterface $soda_scs_conversation_message = NULL): array {
    $form_state->set('message', $soda_scs_conversation_message);
    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#default_value' => $soda_scs_conversation_message?->getBody() ?? '',
      '#rows' => 5,
      '#required' => TRUE,
      '#maxlength' => SodaScsConversationHelpers::MAX_BODY_LENGTH,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];
    $conversation = $soda_scs_conversation_message?->getConversation();
    if ($conversation !== NULL) {
      $form['actions']['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => $conversation->toUrl(),
        '#attributes' => ['class' => ['button']],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsConversationMessageInterface $message */
    $message = $form_state->get('message');
    $result = $this->conversationHelpers->editMessage($message, (string) $form_state->getValue('body'), $this->currentUser());
    if (!$result->success) {
      $this->messenger()->addError($result->message);
      $form_state->setRebuild();
      return;
    }
    $conversation = $message->getConversation();
    if ($conversation !== NULL) {
      $form_state->setRedirectUrl($conversation->toUrl('canonical', ['fragment' => 'message-' . $message->id()]));
    }
  }

}
