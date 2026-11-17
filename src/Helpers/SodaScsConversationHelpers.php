<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsConversationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsConversationMessageInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Conversations between users and the admin team.
 *
 * Every write checks the entity access of the acting account, so forms and
 * controllers cannot post, edit or delete past it.
 */
final class SodaScsConversationHelpers {

  use StringTranslationTrait;

  /**
   * Longest message text, in characters.
   */
  public const MAX_BODY_LENGTH = 10000;

  /**
   * Recipient mode: the users picked one by one.
   */
  public const RECIPIENTS_USERS = 'users';

  /**
   * Recipient mode: every member of a project, its owner included.
   */
  public const RECIPIENTS_PROJECT = 'project';

  /**
   * Recipient mode: every active user of the SCS Manager.
   */
  public const RECIPIENTS_ALL = 'all';

  /**
   * Permission a recipient must have.
   */
  private const USER_PERMISSION = 'soda scs manager user';

  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    private readonly LoggerChannelInterface $logger,
    #[Autowire(service: 'datetime.time')]
    private readonly TimeInterface $time,
    #[Autowire(service: 'database')]
    private readonly Connection $database,
  ) {}

  /**
   * Number of conversations in the admin list.
   */
  public function countForTeam(): int {
    if (!$this->isInstalled()) {
      return 0;
    }
    return (int) $this->conversationStorage()->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Conversations with something unread for the account.
   *
   * The account's own conversations, plus, for an admin, the conversations
   * whose last message the team has not read.
   */
  public function countUnread(AccountInterface $account): int {
    if (!$account->isAuthenticated() || !$this->isInstalled()) {
      return 0;
    }
    $storage = $this->conversationStorage();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('user', $account->id())
      ->condition('userUnread', 1)
      ->execute();
    if ($account->hasPermission('soda scs manager admin')) {
      $ids += $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('teamUnread', 1)
        ->execute();
    }
    return count($ids);
  }

  /**
   * Deletes a message: its text goes, a placeholder stays.
   */
  public function deleteMessage(SodaScsConversationMessageInterface $message, AccountInterface $actor): SodaScsResult {
    if (!$message->access('delete', $actor)) {
      return SodaScsResult::failure('access_denied', (string) $this->t('You cannot delete this message.'), FALSE);
    }
    $message->markDeleted($this->time->getRequestTime())->save();
    return SodaScsResult::success([], (string) $this->t('The message has been deleted.'));
  }

  /**
   * Deletes the conversations of a user, with all their messages.
   *
   * Messages a deleted admin wrote in other conversations stay; the thread
   * shows them with an unknown sender.
   */
  public function deleteForUser(int $userId): void {
    if (!$this->isInstalled()) {
      return;
    }
    $conversationStorage = $this->conversationStorage();
    $conversationIds = $conversationStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('user', $userId)
      ->execute();
    if ($conversationIds === []) {
      return;
    }
    $messageStorage = $this->messageStorage();
    $messageIds = $messageStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', array_values($conversationIds), 'IN')
      ->execute();
    foreach (array_chunk(array_values($messageIds), 100) as $chunk) {
      $messageStorage->delete($messageStorage->loadMultiple($chunk));
    }
    $conversationStorage->delete($conversationStorage->loadMultiple($conversationIds));
  }

  /**
   * Replaces the text of a message; only its author may.
   */
  public function editMessage(SodaScsConversationMessageInterface $message, string $body, AccountInterface $actor): SodaScsResult {
    if (!$message->access('update', $actor)) {
      return SodaScsResult::failure('access_denied', (string) $this->t('You cannot edit this message.'), FALSE);
    }
    $body = $this->normalizeBody($body);
    if ($body === '') {
      return SodaScsResult::failure('empty_body', (string) $this->t('The message is empty.'), FALSE);
    }
    if ($body !== $message->getBody()) {
      $message->setEditedBody($body, $this->time->getRequestTime())->save();
    }
    return SodaScsResult::success([], (string) $this->t('The message has been changed.'));
  }

  /**
   * Whether update 11042 created the tables.
   *
   * The menu badge, the inbox and the account delete run on every site; until
   * drush updb ran, they act as if there were no conversations.
   */
  public function isInstalled(): bool {
    return $this->database->schema()->tableExists('soda_scs_conversation');
  }

  /**
   * Conversations for the admin list, latest activity first.
   *
   * @return list<\Drupal\soda_scs_manager\Entity\SodaScsConversationInterface>
   *   The conversations.
   */
  public function loadForTeam(int $limit, int $offset = 0): array {
    if (!$this->isInstalled()) {
      return [];
    }
    $ids = $this->conversationStorage()->getQuery()
      ->accessCheck(FALSE)
      ->sort('lastMessageAt', 'DESC')
      ->sort('id', 'DESC')
      ->range($offset, $limit)
      ->execute();
    return $this->loadConversations($ids);
  }

  /**
   * Conversations of a user, latest activity first.
   *
   * @return list<\Drupal\soda_scs_manager\Entity\SodaScsConversationInterface>
   *   The conversations.
   */
  public function loadForUser(AccountInterface $account, int $limit = 50): array {
    if (!$this->isInstalled()) {
      return [];
    }
    $ids = $this->conversationStorage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('user', $account->id())
      ->sort('lastMessageAt', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, $limit)
      ->execute();
    return $this->loadConversations($ids);
  }

  /**
   * Last messages of several conversations, loaded at once.
   *
   * @param list<\Drupal\soda_scs_manager\Entity\SodaScsConversationInterface> $conversations
   *   The conversations.
   *
   * @return array<int, \Drupal\soda_scs_manager\Entity\SodaScsConversationMessageInterface>
   *   Messages keyed by conversation id; a conversation without one is left
   *   out.
   */
  public function loadLastMessages(array $conversations): array {
    $messageIds = [];
    foreach ($conversations as $conversation) {
      $messageId = (int) $conversation->get('lastMessageId')->value;
      if ($messageId > 0) {
        $messageIds[(int) $conversation->id()] = $messageId;
      }
    }
    if ($messageIds === []) {
      return [];
    }
    $loaded = $this->messageStorage()->loadMultiple(array_values($messageIds));
    $messages = [];
    foreach ($messageIds as $conversationId => $messageId) {
      if (isset($loaded[$messageId]) && $loaded[$messageId] instanceof SodaScsConversationMessageInterface) {
        $messages[$conversationId] = $loaded[$messageId];
      }
    }
    return $messages;
  }

  /**
   * Messages of a conversation, oldest first.
   *
   * @return list<\Drupal\soda_scs_manager\Entity\SodaScsConversationMessageInterface>
   *   The messages.
   */
  public function loadMessages(SodaScsConversationInterface $conversation): array {
    $storage = $this->messageStorage();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation->id())
      ->sort('id')
      ->execute();
    $messages = [];
    foreach ($ids === [] ? [] : $storage->loadMultiple($ids) as $message) {
      if ($message instanceof SodaScsConversationMessageInterface) {
        $messages[] = $message;
      }
    }
    return $messages;
  }

  /**
   * Logs the start of a conversation to several users; never its text.
   */
  public function logBroadcast(AccountInterface $sender, string $mode, int $recipientCount): void {
    $this->logger->info('Admin @uid started a conversation with @count users (@mode).', [
      '@count' => $recipientCount,
      '@mode' => $mode,
      '@uid' => $sender->id(),
    ]);
  }

  /**
   * Marks the conversation read for the side of the account.
   */
  public function markRead(SodaScsConversationInterface $conversation, AccountInterface $account): void {
    $isParticipant = $conversation->getParticipantId() === (int) $account->id();
    if ($isParticipant && $conversation->isUnreadForParticipant()) {
      $conversation->markRead(FALSE)->save();
      return;
    }
    if (!$isParticipant && $account->hasPermission('soda scs manager admin') && $conversation->isUnreadForTeam()) {
      $conversation->markRead(TRUE)->save();
    }
  }

  /**
   * Adds a message to a conversation.
   */
  public function postMessage(SodaScsConversationInterface $conversation, AccountInterface $author, string $body): SodaScsResult {
    if (!$conversation->access('reply', $author)) {
      return SodaScsResult::failure('access_denied', (string) $this->t('You cannot reply to this conversation.'), FALSE);
    }
    $body = $this->normalizeBody($body);
    if ($body === '') {
      return SodaScsResult::failure('empty_body', (string) $this->t('The message is empty.'), FALSE);
    }
    $message = $this->messageStorage()->create([
      'conversation' => $conversation->id(),
      'author' => $author->id(),
      'body' => $body,
    ]);
    $message->save();
    return SodaScsResult::success(['message' => $message], (string) $this->t('Your message has been sent.'));
  }

  /**
   * The user ids a new conversation goes to.
   *
   * Only active users with access to the SCS Manager; the sender is left out.
   *
   * @param string $mode
   *   One of the RECIPIENTS_* constants.
   * @param list<int> $userIds
   *   The picked users, for RECIPIENTS_USERS.
   * @param int|null $projectId
   *   The project, for RECIPIENTS_PROJECT.
   * @param \Drupal\Core\Session\AccountInterface $sender
   *   The admin who writes.
   *
   * @return list<int>
   *   User ids, ascending.
   */
  public function resolveRecipients(string $mode, array $userIds, ?int $projectId, AccountInterface $sender): array {
    $candidates = match ($mode) {
      self::RECIPIENTS_USERS => array_map('intval', $userIds),
      self::RECIPIENTS_PROJECT => $this->projectUserIds($projectId),
      self::RECIPIENTS_ALL => NULL,
      default => [],
    };
    if ($candidates === []) {
      return [];
    }

    $roles = array_keys(array_filter(
      $this->entityTypeManager->getStorage('user_role')->loadMultiple(),
      static fn (RoleInterface $role): bool => $role->isAdmin() || $role->hasPermission(self::USER_PERMISSION),
    ));
    if ($roles === []) {
      return [];
    }
    $query = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('uid', [0, (int) $sender->id()], 'NOT IN')
      ->sort('uid');
    // Users never store the implicit authenticated role; when it carries the
    // permission, every active account qualifies.
    if (!in_array(RoleInterface::AUTHENTICATED_ID, $roles, TRUE)) {
      $query->condition('roles', $roles, 'IN');
    }
    if ($candidates !== NULL) {
      $query->condition('uid', array_values(array_unique($candidates)), 'IN');
    }
    return array_values(array_map('intval', $query->execute()));
  }

  /**
   * Starts a conversation with one user and writes its first message.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsConversationInterface|null
   *   The conversation, or NULL when the sender may not start one.
   */
  public function startConversation(AccountInterface $sender, int $recipientId, string $subject, string $body): ?SodaScsConversationInterface {
    $storage = $this->conversationStorage();
    if (!$this->entityTypeManager->getAccessControlHandler('soda_scs_conversation')->createAccess(NULL, $sender)) {
      $this->logStartFailure($sender, $recipientId, 'the sender may not start conversations');
      return NULL;
    }
    $body = $this->normalizeBody($body);
    $subject = trim($subject);
    if ($body === '' || $subject === '') {
      $this->logStartFailure($sender, $recipientId, 'empty subject or text');
      return NULL;
    }
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsConversationInterface $conversation */
    $conversation = $storage->create([
      'label' => mb_substr($subject, 0, 255),
      'user' => $recipientId,
      'startedBy' => $sender->id(),
    ]);
    $conversation->save();
    $this->messageStorage()->create([
      'conversation' => $conversation->id(),
      'author' => $sender->id(),
      'body' => $body,
    ])->save();
    return $conversation;
  }

  /**
   * The conversation storage.
   */
  private function conversationStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('soda_scs_conversation');
  }

  /**
   * Loads conversations in the order of the ids.
   *
   * @param array<int|string, int|string> $ids
   *   Entity ids from a query.
   *
   * @return list<\Drupal\soda_scs_manager\Entity\SodaScsConversationInterface>
   *   The conversations.
   */
  private function loadConversations(array $ids): array {
    if ($ids === []) {
      return [];
    }
    $loaded = $this->conversationStorage()->loadMultiple($ids);
    $conversations = [];
    foreach ($ids as $id) {
      if (isset($loaded[$id]) && $loaded[$id] instanceof SodaScsConversationInterface) {
        $conversations[] = $loaded[$id];
      }
    }
    return $conversations;
  }

  /**
   * Logs a conversation that could not be started; never its text.
   */
  private function logStartFailure(AccountInterface $sender, int $recipientId, string $reason): void {
    $this->logger->warning('Could not start a conversation of admin @sender with user @recipient: @reason.', [
      '@reason' => $reason,
      '@recipient' => $recipientId,
      '@sender' => $sender->id(),
    ]);
  }

  /**
   * The message storage.
   */
  private function messageStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('soda_scs_conversation_message');
  }

  /**
   * Trims the text, unifies line breaks and cuts it to the maximum length.
   */
  private function normalizeBody(string $body): string {
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    return mb_substr($body, 0, self::MAX_BODY_LENGTH);
  }

  /**
   * Owner and members of a project.
   *
   * @return list<int>
   *   User ids; empty when the project does not exist.
   */
  private function projectUserIds(?int $projectId): array {
    if ($projectId === NULL) {
      return [];
    }
    $project = $this->entityTypeManager->getStorage('soda_scs_project')->load($projectId);
    if (!$project instanceof SodaScsProjectInterface) {
      return [];
    }
    $ids = array_map('intval', array_column($project->get('members')->getValue(), 'target_id'));
    $ids[] = (int) $project->getOwnerId();
    return array_values(array_unique($ids));
  }

}
