<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsConversationMessageInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsConversationHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms the delete of a message; a placeholder stays in the thread.
 */
final class SodaScsConversationMessageDeleteForm extends ConfirmFormBase {

  /**
   * The message to delete.
   */
  protected ?SodaScsConversationMessageInterface $message = NULL;

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
    return 'soda_scs_manager_conversation_message_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsConversationMessageInterface $soda_scs_conversation_message = NULL): array {
    $this->message = $soda_scs_conversation_message;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete this message?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The text is removed. The conversation shows "Message deleted" in its place.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    $conversation = $this->message?->getConversation();
    return $conversation !== NULL ? $conversation->toUrl() : Url::fromRoute('soda_scs_manager.messages');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->message === NULL) {
      return;
    }
    $result = $this->conversationHelpers->deleteMessage($this->message, $this->currentUser());
    if ($result->success) {
      $this->messenger()->addStatus($result->message);
    }
    else {
      $this->messenger()->addError($result->message);
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
