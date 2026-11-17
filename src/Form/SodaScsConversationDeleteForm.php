<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsConversationInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsConversationHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms deleting a conversation and all of its messages.
 */
final class SodaScsConversationDeleteForm extends ConfirmFormBase {

  /**
   * The conversation to delete.
   */
  protected ?SodaScsConversationInterface $conversation = NULL;

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
    return 'soda_scs_manager_conversation_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsConversationInterface $soda_scs_conversation = NULL): array {
    $this->conversation = $soda_scs_conversation;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete the conversation %label?', [
      '%label' => $this->conversation?->label() ?? '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The conversation and every message in it are removed permanently.');
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
    return Url::fromRoute('soda_scs_manager.conversations.team');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->conversation === NULL) {
      return;
    }
    $result = $this->conversationHelpers->deleteConversation($this->conversation, $this->currentUser());
    if ($result->success) {
      $this->messenger()->addStatus($result->message);
    }
    else {
      $this->messenger()->addError($result->message);
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
