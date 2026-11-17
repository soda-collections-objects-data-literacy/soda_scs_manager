<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsConversationInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsConversationHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reply field at the bottom of a conversation.
 */
final class SodaScsConversationReplyForm extends FormBase {

  public function __construct(
    protected readonly SodaScsConversationHelpers $conversationHelpers,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.conversation.helpers'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_conversation_reply_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsConversationInterface $conversation = NULL): array {
    $form_state->set('conversation_id', $conversation?->id());
    $form['#attributes']['class'][] = 'scs-manager--conversation-reply';
    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Your reply'),
      '#title_display' => 'invisible',
      '#description' => $this->t('Markdown: # headings, **bold**, *italic*, ++underline++, ~~strike~~, https://… or [label](https://…).'),
      '#placeholder' => $this->t('Write a reply'),
      '#rows' => 3,
      '#required' => TRUE,
      '#maxlength' => SodaScsConversationHelpers::MAX_BODY_LENGTH,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $conversation = $this->loadConversation($form_state);
    if ($conversation === NULL) {
      $this->messenger()->addError($this->t('The conversation no longer exists.'));
      return;
    }
    $result = $this->conversationHelpers->postMessage($conversation, $this->currentUser(), (string) $form_state->getValue('body'));
    if (!$result->success) {
      $this->messenger()->addError($result->message);
      $form_state->setRebuild();
      return;
    }
    $form_state->setRedirectUrl($conversation->toUrl('canonical', ['fragment' => 'newest']));
  }

  /**
   * The conversation the form was built for.
   */
  private function loadConversation(FormStateInterface $form_state): ?SodaScsConversationInterface {
    $id = $form_state->get('conversation_id');
    if ($id === NULL) {
      return NULL;
    }
    $conversation = $this->entityTypeManager->getStorage('soda_scs_conversation')->load($id);
    return $conversation instanceof SodaScsConversationInterface ? $conversation : NULL;
  }

}
