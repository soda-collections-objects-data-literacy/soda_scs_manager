<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\soda_scs_manager\Batch\SodaScsConversationBroadcastBatch;
use Drupal\soda_scs_manager\Helpers\SodaScsConversationHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets an admin start conversations with one, several or all users.
 *
 * Every recipient gets a conversation of their own. The conversations are
 * created in a batch, so a message to all users does not run in one request.
 */
final class SodaScsConversationStartForm extends FormBase {

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
    return 'soda_scs_manager_conversation_start_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if (!$this->conversationHelpers->isInstalled()) {
      $form['notInstalled'] = [
        '#markup' => $this->t('Conversations are not installed yet. Run drush updb.'),
      ];
      return $form;
    }
    $allCount = count($this->conversationHelpers->resolveRecipients(SodaScsConversationHelpers::RECIPIENTS_ALL, [], NULL, $this->currentUser()));

    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Recipients'),
      '#options' => [
        SodaScsConversationHelpers::RECIPIENTS_USERS => $this->t('Single users'),
        SodaScsConversationHelpers::RECIPIENTS_PROJECT => $this->t('All members of a project'),
        SodaScsConversationHelpers::RECIPIENTS_ALL => $this->t('All users'),
      ],
      '#default_value' => SodaScsConversationHelpers::RECIPIENTS_USERS,
      '#required' => TRUE,
    ];
    $form['users'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Users'),
      '#description' => $this->t('Separate several users with commas.'),
      '#target_type' => 'user',
      '#tags' => TRUE,
      '#selection_settings' => ['include_anonymous' => FALSE],
      '#states' => [
        'visible' => [':input[name="mode"]' => ['value' => SodaScsConversationHelpers::RECIPIENTS_USERS]],
      ],
    ];
    $form['project'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Project'),
      '#target_type' => 'soda_scs_project',
      '#states' => [
        'visible' => [':input[name="mode"]' => ['value' => SodaScsConversationHelpers::RECIPIENTS_PROJECT]],
      ],
    ];
    $form['confirmAll'] = [
      '#type' => 'checkbox',
      '#title' => $this->formatPlural($allCount, 'Send to all users (1 recipient).', 'Send to all users (@count recipients).'),
      '#states' => [
        'visible' => [':input[name="mode"]' => ['value' => SodaScsConversationHelpers::RECIPIENTS_ALL]],
      ],
    ];
    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#description' => $this->t('Plain text; line breaks are kept.'),
      '#rows' => 8,
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $mode = (string) $form_state->getValue('mode');
    if ($mode === SodaScsConversationHelpers::RECIPIENTS_ALL && !$form_state->getValue('confirmAll')) {
      $form_state->setErrorByName('confirmAll', $this->t('Confirm that the message goes to all users.'));
      return;
    }
    if ($mode === SodaScsConversationHelpers::RECIPIENTS_USERS && empty($form_state->getValue('users'))) {
      $form_state->setErrorByName('users', $this->t('Choose at least one user.'));
      return;
    }
    if ($mode === SodaScsConversationHelpers::RECIPIENTS_PROJECT && empty($form_state->getValue('project'))) {
      $form_state->setErrorByName('project', $this->t('Choose a project.'));
      return;
    }
    $recipients = $this->recipients($form_state);
    if ($recipients === []) {
      $form_state->setErrorByName('mode', $this->t('None of the chosen users can receive messages. Blocked users and you yourself are left out.'));
      return;
    }
    $form_state->set('recipients', $recipients);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $recipients = $form_state->get('recipients') ?? $this->recipients($form_state);
    $mode = (string) $form_state->getValue('mode');
    if (count($recipients) > 1) {
      $this->conversationHelpers->logBroadcast($this->currentUser(), $mode, count($recipients));
    }

    $batch = (new BatchBuilder())
      ->setTitle($this->t('Sending the message'))
      ->setInitMessage($this->t('Starting the conversations.'))
      ->setProgressMessage($this->t('@current of @total'))
      ->setFinishCallback([SodaScsConversationBroadcastBatch::class, 'finish']);
    foreach (array_chunk($recipients, SodaScsConversationBroadcastBatch::CHUNK_SIZE) as $chunk) {
      $batch->addOperation([SodaScsConversationBroadcastBatch::class, 'startChunk'], [
        (int) $this->currentUser()->id(),
        $chunk,
        (string) $form_state->getValue('subject'),
        (string) $form_state->getValue('body'),
      ]);
    }
    batch_set($batch->toArray());
    $form_state->setRedirect('soda_scs_manager.conversations.team');
  }

  /**
   * The recipients the form values name.
   *
   * @return list<int>
   *   User ids.
   */
  private function recipients(FormStateInterface $form_state): array {
    $userIds = array_map(
      static fn (array $item): int => (int) ($item['target_id'] ?? 0),
      (array) ($form_state->getValue('users') ?: []),
    );
    $project = $form_state->getValue('project');
    return $this->conversationHelpers->resolveRecipients(
      (string) $form_state->getValue('mode'),
      $userIds,
      $project === NULL || $project === '' ? NULL : (int) $project,
      $this->currentUser(),
    );
  }

}
