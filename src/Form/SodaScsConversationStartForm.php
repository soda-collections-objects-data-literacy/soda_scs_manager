<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\soda_scs_manager\Batch\SodaScsConversationBroadcastBatch;
use Drupal\soda_scs_manager\Helpers\SodaScsConversationHelpers;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets an admin start conversations with users or project members.
 *
 * Recipients are picked as chips: users (including one "All" chip) or projects.
 * Every recipient gets a conversation of their own. The conversations are
 * created in a batch, so a message to all users does not run in one request.
 */
final class SodaScsConversationStartForm extends FormBase {

  /**
   * Token for every eligible user.
   */
  public const TOKEN_ALL = 'all';

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

    $userOptions = $this->userOptions();
    $projectOptions = $this->projectOptions();
    $tokens = $this->normalizeTokens((string) ($form_state->getValue('recipient_tokens') ?? ''));

    $form['#attributes']['class'][] = 'scs-manager--conversation-start';
    $form['#attached'] = [
      'library' => [
        'soda_scs_manager/conversationStartForm',
      ],
      'drupalSettings' => [
        'sodaScsManager' => [
          'conversationRecipients' => [
            'allLabel' => (string) $this->t('All'),
            'projects' => $projectOptions,
            'users' => $userOptions,
          ],
        ],
      ],
    ];

    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Recipients'),
      '#options' => [
        SodaScsConversationHelpers::RECIPIENTS_USERS => $this->t('User'),
        SodaScsConversationHelpers::RECIPIENTS_PROJECT => $this->t('Project'),
      ],
      '#default_value' => SodaScsConversationHelpers::RECIPIENTS_USERS,
      '#required' => TRUE,
      '#attributes' => ['class' => ['scs-manager--conversation-start__mode']],
    ];

    $form['picker_users'] = [
      '#type' => 'select',
      '#title' => $this->t('User'),
      '#title_display' => 'invisible',
      '#options' => ['' => $this->t('- Select -')] + [self::TOKEN_ALL => $this->t('All')] + $userOptions,
      '#attributes' => [
        'class' => ['scs-manager--conversation-start__picker'],
        'data-scs-recipient-kind' => 'user',
      ],
      '#states' => [
        'visible' => [
          ':input[name="mode"]' => ['value' => SodaScsConversationHelpers::RECIPIENTS_USERS],
        ],
      ],
    ];

    $form['picker_projects'] = [
      '#type' => 'select',
      '#title' => $this->t('Project'),
      '#title_display' => 'invisible',
      '#options' => ['' => $this->t('- Select -')] + $projectOptions,
      '#attributes' => [
        'class' => ['scs-manager--conversation-start__picker'],
        'data-scs-recipient-kind' => 'project',
      ],
      '#states' => [
        'visible' => [
          ':input[name="mode"]' => ['value' => SodaScsConversationHelpers::RECIPIENTS_PROJECT],
        ],
      ],
    ];

    $form['recipient_tokens'] = [
      '#type' => 'hidden',
      '#default_value' => implode(',', $tokens),
      '#attributes' => ['class' => ['scs-manager--conversation-start__tokens']],
    ];

    $form['recipient_chips'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['scs-manager--conversation-start__chips'],
        'data-scs-recipient-chips' => '1',
        'aria-live' => 'polite',
      ],
    ];

    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#maxlength' => 255,
      '#required' => TRUE,
      // Avoid the browser width from size="60"; CSS makes the field full width.
      '#size' => 120,
      '#attributes' => ['class' => ['scs-manager--conversation-start__field']],
    ];

    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#description' => $this->t('Markdown: # headings, **bold**, *italic*, ++underline++, ~~strike~~, https://… or [label](https://…).'),
      '#rows' => 8,
      '#cols' => 120,
      '#required' => TRUE,
      '#maxlength' => SodaScsConversationHelpers::MAX_BODY_LENGTH,
      '#attributes' => ['class' => ['scs-manager--conversation-start__field']],
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
    $tokens = $this->normalizeTokens((string) $form_state->getValue('recipient_tokens'));
    $form_state->setValue('recipient_tokens', implode(',', $tokens));

    if ($tokens === []) {
      $form_state->setErrorByName('recipient_tokens', $this->t('Choose at least one recipient.'));
      return;
    }

    if ($mode === SodaScsConversationHelpers::RECIPIENTS_USERS) {
      foreach ($tokens as $token) {
        if ($token !== self::TOKEN_ALL && !str_starts_with($token, 'u:')) {
          $form_state->setErrorByName('recipient_tokens', $this->t('Choose users or All while User is selected.'));
          return;
        }
      }
    }
    elseif ($mode === SodaScsConversationHelpers::RECIPIENTS_PROJECT) {
      foreach ($tokens as $token) {
        if (!str_starts_with($token, 'p:')) {
          $form_state->setErrorByName('recipient_tokens', $this->t('Choose projects while Project is selected.'));
          return;
        }
      }
    }
    else {
      $form_state->setErrorByName('mode', $this->t('Choose User or Project.'));
      return;
    }

    $recipients = $this->recipients($mode, $tokens);
    if ($recipients === []) {
      $form_state->setErrorByName('recipient_tokens', $this->t('None of the chosen users can receive messages. Blocked users and you yourself are left out.'));
      return;
    }
    $form_state->set('recipients', $recipients);
    $form_state->set('broadcast_mode', in_array(self::TOKEN_ALL, $tokens, TRUE)
      ? SodaScsConversationHelpers::RECIPIENTS_ALL
      : $mode);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $recipients = $form_state->get('recipients') ?? [];
    $mode = (string) ($form_state->get('broadcast_mode') ?? $form_state->getValue('mode'));
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
   * Deduplicates and keeps only known token shapes.
   *
   * @return list<string>
   *   Tokens such as "all", "u:12", "p:3".
   */
  private function normalizeTokens(string $raw): array {
    $tokens = [];
    foreach (preg_split('/\s*,\s*/', trim($raw)) ?: [] as $token) {
      if ($token === '' || isset($tokens[$token])) {
        continue;
      }
      if ($token === self::TOKEN_ALL || preg_match('/^[up]:\d+$/', $token) === 1) {
        $tokens[$token] = $token;
      }
    }
    return array_values($tokens);
  }

  /**
   * Projects an admin can address, keyed by id.
   *
   * @return array<string, string>
   *   Options for the project picker.
   */
  private function projectOptions(): array {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('label')
      ->execute();
    $options = [];
    foreach ($ids === [] ? [] : $storage->loadMultiple($ids) as $project) {
      $options[(string) $project->id()] = $project->label();
    }
    return $options;
  }

  /**
   * The recipients the mode and tokens name.
   *
   * @param string $mode
   *   User or project mode.
   * @param list<string> $tokens
   *   Normalized tokens.
   *
   * @return list<int>
   *   User ids.
   */
  private function recipients(string $mode, array $tokens): array {
    if (in_array(self::TOKEN_ALL, $tokens, TRUE)) {
      return $this->conversationHelpers->resolveRecipients(
        SodaScsConversationHelpers::RECIPIENTS_ALL,
        [],
        [],
        $this->currentUser(),
      );
    }

    $userIds = [];
    $projectIds = [];
    foreach ($tokens as $token) {
      if (str_starts_with($token, 'u:')) {
        $userIds[] = (int) substr($token, 2);
      }
      elseif (str_starts_with($token, 'p:')) {
        $projectIds[] = (int) substr($token, 2);
      }
    }

    return $this->conversationHelpers->resolveRecipients(
      $mode,
      $userIds,
      $projectIds,
      $this->currentUser(),
    );
  }

  /**
   * Active users who can receive messages, without the sender.
   *
   * @return array<string, string>
   *   Options for the user picker.
   */
  private function userOptions(): array {
    $senderId = (int) $this->currentUser()->id();
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('uid', [0, $senderId], 'NOT IN')
      ->sort('name')
      ->execute();
    $options = [];
    foreach ($ids === [] ? [] : $storage->loadMultiple($ids) as $user) {
      if ($user instanceof UserInterface) {
        $options[(string) $user->id()] = $user->getDisplayName();
      }
    }
    return $options;
  }

}
