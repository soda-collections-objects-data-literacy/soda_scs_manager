<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Helpers;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\soda_scs_manager\Batch\SodaScsConversationBroadcastBatch;
use Drupal\soda_scs_manager\Entity\SodaScsConversationInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsConversationHelpers;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;

/**
 * Conversations between users and the admin team.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsConversationHelpers
 */
class SodaScsConversationHelpersTest extends SodaScsKernelTestBase {

  /**
   * An admin of the SCS Manager.
   */
  private UserInterface $admin;

  /**
   * A second admin.
   */
  private UserInterface $otherAdmin;

  /**
   * A user of the SCS Manager.
   */
  private UserInterface $alice;

  /**
   * Another user of the SCS Manager.
   */
  private UserInterface $bob;

  /**
   * An active user without access to the SCS Manager.
   */
  private UserInterface $mallory;

  /**
   * Helper under test.
   */
  private SodaScsConversationHelpers $helpers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // hook_user_insert() gives every new user the role scs_user (created with
    // the first user, with the permission 'soda scs manager user'), and
    // activates the user. Admins get a second role.
    Role::create(['id' => 'scs_admin', 'label' => 'SCS admin'])
      ->grantPermission('soda scs manager admin')
      ->save();

    $this->admin = $this->createTestUser(['roles' => ['scs_admin']]);
    $this->otherAdmin = $this->createTestUser(['roles' => ['scs_admin']]);
    $this->alice = $this->createTestUser();
    $this->bob = $this->createTestUser();
    $this->mallory = $this->createTestUser();
    $this->mallory->removeRole('scs_user')->save();

    $this->helpers = $this->container->get('soda_scs_manager.conversation.helpers');
  }

  /**
   * {@inheritdoc}
   *
   * Users, projects and messages only: no nodes, files or applications.
   */
  protected function installSchemas(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('soda_scs_manager', ['keycloak_user_registration']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('soda_scs_project');
    $this->installEntitySchema('soda_scs_project_membership');
    $this->installEntitySchema('soda_scs_conversation');
    $this->installEntitySchema('soda_scs_conversation_message');
    $this->installEntitySchema('soda_scs_message');
  }

  /**
   * An admin starts a conversation: user unread, team read, first message.
   *
   * @covers ::startConversation
   */
  public function testStartCreatesConversationAndFirstMessage(): void {
    $conversation = $this->helpers->startConversation($this->admin, (int) $this->alice->id(), '  Maintenance  ', "Hello\r\nworld ");

    $this->assertInstanceOf(SodaScsConversationInterface::class, $conversation);
    $this->assertSame('Maintenance', $conversation->label());
    $this->assertSame((int) $this->alice->id(), $conversation->getParticipantId());
    $this->assertSame((int) $this->admin->id(), (int) $conversation->get('startedBy')->target_id);

    $conversation = $this->reload($conversation);
    $messages = $this->helpers->loadMessages($conversation);
    $this->assertCount(1, $messages);
    $this->assertSame("Hello\nworld", $messages[0]->getBody());
    $this->assertSame((int) $this->admin->id(), $messages[0]->getAuthorId());
    $this->assertSame((int) $messages[0]->id(), (int) $conversation->get('lastMessageId')->value);
    $this->assertSame((int) $this->admin->id(), (int) $conversation->get('lastMessageAuthor')->value);
    $this->assertSame($messages[0]->getCreatedTime(), $conversation->getLastMessageTime());
    $this->assertTrue($conversation->isUnreadForParticipant());
    $this->assertFalse($conversation->isUnreadForTeam());
    $lastMessages = $this->helpers->loadLastMessages([$conversation]);
    $this->assertSame([(int) $conversation->id()], array_keys($lastMessages));
    $this->assertSame($messages[0]->id(), $lastMessages[(int) $conversation->id()]->id());
  }

  /**
   * The last messages are loaded in bulk, keyed by conversation id.
   *
   * @covers ::loadLastMessages
   */
  public function testLoadLastMessagesReturnsLastMessagePerConversation(): void {
    $first = $this->startWithAlice();
    $second = $this->helpers->startConversation($this->admin, (int) $this->bob->id(), 'Second', 'Opening');
    $reply = $this->helpers->postMessage($first, $this->alice, 'Newest')->data['message'];

    $lastMessages = $this->helpers->loadLastMessages([$this->reload($first), $this->reload($second)]);

    $this->assertEqualsCanonicalizing([(int) $first->id(), (int) $second->id()], array_keys($lastMessages));
    $this->assertSame($reply->id(), $lastMessages[(int) $first->id()]->id());
    $this->assertSame('Newest', $lastMessages[(int) $first->id()]->getBody());
    $this->assertSame('Opening', $lastMessages[(int) $second->id()]->getBody());
  }

  /**
   * A conversation without a last message is left out of the result.
   *
   * @covers ::loadLastMessages
   */
  public function testLoadLastMessagesLeavesOutConversationWithoutMessages(): void {
    $withMessage = $this->startWithAlice();
    $empty = $this->entityTypeManager->getStorage('soda_scs_conversation')->create([
      'label' => 'Empty',
      'user' => $this->bob->id(),
      'startedBy' => $this->admin->id(),
    ]);
    $empty->save();

    $lastMessages = $this->helpers->loadLastMessages([$empty, $withMessage]);

    $this->assertSame([(int) $withMessage->id()], array_keys($lastMessages));
    $this->assertSame([], $this->helpers->loadLastMessages([$empty]));
    $this->assertSame([], $this->helpers->loadLastMessages([]));
  }

  /**
   * A last message id pointing to a deleted message is left out.
   *
   * @covers ::loadLastMessages
   */
  public function testLoadLastMessagesLeavesOutMissingMessage(): void {
    $conversation = $this->startWithAlice();
    $messageStorage = $this->entityTypeManager->getStorage('soda_scs_conversation_message');
    $messageStorage->delete($messageStorage->loadMultiple());

    $this->assertSame([], $this->helpers->loadLastMessages([$this->reload($conversation)]));
  }

  /**
   * The schema is installed in the kernel test.
   *
   * @covers ::isInstalled
   */
  public function testIsInstalledIsTrueWithSchema(): void {
    $this->assertTrue($this->helpers->isInstalled());
  }

  /**
   * Only an admin may start a conversation.
   *
   * @covers ::startConversation
   */
  public function testStartIsDeniedForNonAdmin(): void {
    $this->assertNull($this->helpers->startConversation($this->alice, (int) $this->bob->id(), 'Hi', 'Text'));
    $this->assertSame(0, $this->helpers->countForTeam());
  }

  /**
   * A refused start by a non-admin is logged as a warning, without the text.
   *
   * @covers ::startConversation
   */
  public function testStartByNonAdminLogsWarning(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'Could not start a conversation of admin @sender with user @recipient: @reason.',
        [
          '@reason' => 'the sender may not start conversations',
          '@recipient' => (int) $this->bob->id(),
          '@sender' => $this->alice->id(),
        ],
      );

    $this->assertNull($this->helpersWithLogger($logger)->startConversation($this->alice, (int) $this->bob->id(), 'Hi', 'Secret text'));
  }

  /**
   * A refused start for an empty subject or text is logged as a warning.
   *
   * @covers ::startConversation
   */
  public function testStartWithEmptyTextLogsWarning(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'Could not start a conversation of admin @sender with user @recipient: @reason.',
        [
          '@reason' => 'empty subject or text',
          '@recipient' => (int) $this->alice->id(),
          '@sender' => $this->admin->id(),
        ],
      );

    $this->assertNull($this->helpersWithLogger($logger)->startConversation($this->admin, (int) $this->alice->id(), 'Subject', "  \n"));
  }

  /**
   * A successful start logs no warning.
   *
   * @covers ::startConversation
   */
  public function testSuccessfulStartLogsNoWarning(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('warning');

    $this->assertNotNull($this->helpersWithLogger($logger)->startConversation($this->admin, (int) $this->alice->id(), 'Subject', 'Text'));
  }

  /**
   * A blank subject or body starts nothing.
   *
   * @covers ::startConversation
   */
  public function testStartWithEmptySubjectOrBodyCreatesNothing(): void {
    $this->assertNull($this->helpers->startConversation($this->admin, (int) $this->alice->id(), '   ', 'Text'));
    $this->assertNull($this->helpers->startConversation($this->admin, (int) $this->alice->id(), 'Subject', " \n "));
    $this->assertSame(0, $this->helpers->countForTeam());
    $this->assertCount(0, $this->entityTypeManager->getStorage('soda_scs_conversation_message')->loadMultiple());
  }

  /**
   * A reply of the user flags the team and clears the user's flag.
   *
   * @covers ::postMessage
   */
  public function testReplyByUserSetsTeamUnreadAndClearsUserUnread(): void {
    $conversation = $this->startWithAlice();
    $this->assertTrue($conversation->isUnreadForParticipant());

    $result = $this->helpers->postMessage($conversation, $this->alice, 'Thanks');
    $this->assertTrue($result->success);

    $conversation = $this->reload($conversation);
    $this->assertFalse($conversation->isUnreadForParticipant());
    $this->assertTrue($conversation->isUnreadForTeam());
    $this->assertSame((int) $this->alice->id(), (int) $conversation->get('lastMessageAuthor')->value);
    $this->assertCount(2, $this->helpers->loadMessages($conversation));
  }

  /**
   * A reply of an admin flags the user and clears the team's flag.
   *
   * @covers ::postMessage
   */
  public function testReplyByAdminSetsUserUnreadAndClearsTeamUnread(): void {
    $conversation = $this->startWithAlice();
    $this->helpers->postMessage($conversation, $this->alice, 'Thanks');
    $conversation = $this->reload($conversation);
    $this->assertTrue($conversation->isUnreadForTeam());

    $result = $this->helpers->postMessage($conversation, $this->otherAdmin, 'You are welcome');
    $this->assertTrue($result->success);

    $conversation = $this->reload($conversation);
    $this->assertTrue($conversation->isUnreadForParticipant());
    $this->assertFalse($conversation->isUnreadForTeam());
  }

  /**
   * A foreign user cannot reply; nothing is written.
   *
   * @covers ::postMessage
   */
  public function testReplyByForeignUserIsDeniedAndWritesNothing(): void {
    $conversation = $this->startWithAlice();

    $result = $this->helpers->postMessage($conversation, $this->bob, 'Let me in');

    $this->assertFalse($result->success);
    $this->assertCount(1, $this->helpers->loadMessages($conversation));
    $this->assertFalse($this->reload($conversation)->isUnreadForTeam());
  }

  /**
   * An empty reply is refused.
   *
   * @covers ::postMessage
   */
  public function testReplyWithEmptyBodyIsRefused(): void {
    $conversation = $this->startWithAlice();

    $result = $this->helpers->postMessage($conversation, $this->alice, "  \r\n ");

    $this->assertFalse($result->success);
    $this->assertCount(1, $this->helpers->loadMessages($conversation));
  }

  /**
   * Text longer than the maximum is cut.
   *
   * @covers ::postMessage
   */
  public function testReplyIsCutToMaximumLength(): void {
    $conversation = $this->startWithAlice();

    $result = $this->helpers->postMessage($conversation, $this->alice, str_repeat('ä', SodaScsConversationHelpers::MAX_BODY_LENGTH + 50));

    $this->assertTrue($result->success);
    $this->assertSame(SodaScsConversationHelpers::MAX_BODY_LENGTH, mb_strlen($result->data['message']->getBody()));
  }

  /**
   * Markup in a message is stored as typed, not interpreted.
   *
   * @covers ::postMessage
   */
  public function testMessageTextIsStoredAsPlainText(): void {
    $conversation = $this->startWithAlice();
    $text = '<script>alert("x")</script> <b>bold</b> & more';

    $result = $this->helpers->postMessage($conversation, $this->alice, $text);

    $this->assertTrue($result->success);
    $storage = $this->entityTypeManager->getStorage('soda_scs_conversation_message');
    $storage->resetCache();
    $this->assertSame($text, $storage->load($result->data['message']->id())->getBody());
  }

  /**
   * Reading clears the flag of the reading side only.
   *
   * @covers ::markRead
   */
  public function testMarkReadClearsOnlyTheFlagOfTheReadingSide(): void {
    $conversation = $this->startWithAlice();
    $this->helpers->postMessage($conversation, $this->admin, 'Second');
    $conversation = $this->reload($conversation);
    $conversation->set('teamUnread', TRUE)->save();
    $this->assertTrue($conversation->isUnreadForParticipant());
    $this->assertTrue($conversation->isUnreadForTeam());

    $this->helpers->markRead($conversation, $this->alice);
    $conversation = $this->reload($conversation);
    $this->assertFalse($conversation->isUnreadForParticipant());
    $this->assertTrue($conversation->isUnreadForTeam());

    $this->helpers->markRead($conversation, $this->admin);
    $conversation = $this->reload($conversation);
    $this->assertFalse($conversation->isUnreadForTeam());
  }

  /**
   * A foreign user's visit does not clear anything.
   *
   * @covers ::markRead
   */
  public function testMarkReadByForeignUserChangesNothing(): void {
    $conversation = $this->startWithAlice();
    $this->helpers->postMessage($conversation, $this->alice, 'Reply');
    $conversation = $this->reload($conversation);
    $conversation->set('userUnread', TRUE)->save();

    $this->helpers->markRead($conversation, $this->bob);

    $conversation = $this->reload($conversation);
    $this->assertTrue($conversation->isUnreadForParticipant());
    $this->assertTrue($conversation->isUnreadForTeam());
  }

  /**
   * The user counts own unread conversations; the admin also the team's.
   *
   * @covers ::countUnread
   */
  public function testCountUnreadForUserAndAdmin(): void {
    $this->assertSame(0, $this->helpers->countUnread($this->alice));

    $forAlice = $this->startWithAlice();
    $forBob = $this->helpers->startConversation($this->admin, (int) $this->bob->id(), 'Hi Bob', 'Text');
    $this->assertSame(1, $this->helpers->countUnread($this->alice));
    $this->assertSame(1, $this->helpers->countUnread($this->bob));
    $this->assertSame(0, $this->helpers->countUnread($this->admin));
    $this->assertSame(0, $this->helpers->countUnread($this->mallory));

    $this->helpers->postMessage($forAlice, $this->alice, 'Reply A');
    $this->helpers->postMessage($forBob, $this->bob, 'Reply B');
    $this->assertSame(0, $this->helpers->countUnread($this->alice));
    $this->assertSame(0, $this->helpers->countUnread($this->bob));
    $this->assertSame(2, $this->helpers->countUnread($this->admin));
    $this->assertSame(2, $this->helpers->countUnread($this->otherAdmin));

    $this->helpers->markRead($this->reload($forAlice), $this->admin);
    $this->assertSame(1, $this->helpers->countUnread($this->admin));
  }

  /**
   * An anonymous visitor has nothing unread.
   *
   * @covers ::countUnread
   */
  public function testCountUnreadForAnonymousIsZero(): void {
    $this->startWithAlice();
    $this->assertSame(0, $this->helpers->countUnread(new AnonymousUserSession()));
  }

  /**
   * The menu badge adds the unread conversations.
   *
   * @covers \Drupal\soda_scs_manager\Helpers\SodaScsMessageHelpers::countAttentionItems
   */
  public function testCountAttentionItemsIncludesConversations(): void {
    $messageHelpers = $this->container->get('soda_scs_manager.message.helpers');
    $this->assertSame(0, $messageHelpers->countAttentionItems($this->alice));

    $conversation = $this->startWithAlice();
    $this->assertSame(1, $messageHelpers->countAttentionItems($this->alice));
    $this->assertSame(0, $messageHelpers->countAttentionItems($this->admin));

    $this->helpers->postMessage($conversation, $this->alice, 'Reply');
    $this->assertSame(0, $messageHelpers->countAttentionItems($this->alice));
    $this->assertSame(1, $messageHelpers->countAttentionItems($this->admin));
  }

  /**
   * Only the author edits; the edit is marked.
   *
   * @covers ::editMessage
   */
  public function testEditByAuthorChangesBodyAndMarksEdited(): void {
    $conversation = $this->startWithAlice();
    $message = $this->helpers->postMessage($conversation, $this->alice, 'Original')->data['message'];
    $this->assertFalse($message->isEdited());

    $result = $this->helpers->editMessage($message, ' Changed ', $this->alice);

    $this->assertTrue($result->success);
    $message = $this->reloadMessage($message);
    $this->assertSame('Changed', $message->getBody());
    $this->assertTrue($message->isEdited());
    $this->assertGreaterThan(0, (int) $message->get('editedAt')->value);
  }

  /**
   * Editing with the same text does not mark the message edited.
   *
   * @covers ::editMessage
   */
  public function testEditWithSameTextDoesNotMarkEdited(): void {
    $conversation = $this->startWithAlice();
    $message = $this->helpers->postMessage($conversation, $this->alice, 'Same')->data['message'];

    $this->assertTrue($this->helpers->editMessage($message, 'Same', $this->alice)->success);

    $this->assertFalse($this->reloadMessage($message)->isEdited());
  }

  /**
   * An admin may not edit the message of someone else.
   *
   * @covers ::editMessage
   */
  public function testEditByAdminWhoIsNotTheAuthorIsDenied(): void {
    $conversation = $this->startWithAlice();
    $message = $this->helpers->postMessage($conversation, $this->alice, 'Original')->data['message'];

    $result = $this->helpers->editMessage($message, 'Tampered', $this->admin);

    $this->assertFalse($result->success);
    $message = $this->reloadMessage($message);
    $this->assertSame('Original', $message->getBody());
    $this->assertFalse($message->isEdited());
  }

  /**
   * A foreign user may not edit.
   *
   * @covers ::editMessage
   */
  public function testEditByForeignUserIsDenied(): void {
    $conversation = $this->startWithAlice();
    $message = $this->helpers->postMessage($conversation, $this->alice, 'Original')->data['message'];

    $this->assertFalse($this->helpers->editMessage($message, 'Tampered', $this->bob)->success);

    $this->assertSame('Original', $this->reloadMessage($message)->getBody());
  }

  /**
   * An edit to an empty text is refused and keeps the old text.
   *
   * @covers ::editMessage
   */
  public function testEditToEmptyBodyIsRefused(): void {
    $conversation = $this->startWithAlice();
    $message = $this->helpers->postMessage($conversation, $this->alice, 'Original')->data['message'];

    $this->assertFalse($this->helpers->editMessage($message, "  \n", $this->alice)->success);

    $this->assertSame('Original', $this->reloadMessage($message)->getBody());
  }

  /**
   * A deleted message cannot be edited.
   *
   * @covers ::editMessage
   */
  public function testEditAfterDeleteIsDenied(): void {
    $conversation = $this->startWithAlice();
    $message = $this->helpers->postMessage($conversation, $this->alice, 'Original')->data['message'];
    $this->assertTrue($this->helpers->deleteMessage($message, $this->alice)->success);

    $result = $this->helpers->editMessage($this->reloadMessage($message), 'Revived', $this->alice);

    $this->assertFalse($result->success);
    $this->assertSame('', $this->reloadMessage($message)->getBody());
  }

  /**
   * The author deletes softly: the row stays, the text goes.
   *
   * @covers ::deleteMessage
   */
  public function testDeleteByAuthorEmptiesBodyAndKeepsRow(): void {
    $conversation = $this->startWithAlice();
    $message = $this->helpers->postMessage($conversation, $this->alice, 'Secret')->data['message'];

    $result = $this->helpers->deleteMessage($message, $this->alice);

    $this->assertTrue($result->success);
    $message = $this->reloadMessage($message);
    $this->assertNotNull($message);
    $this->assertSame('', $message->getBody());
    $this->assertTrue($message->isDeleted());
    $this->assertGreaterThan(0, (int) $message->get('deletedAt')->value);
    $this->assertCount(2, $this->helpers->loadMessages($conversation));
  }

  /**
   * An admin moderates the message of a user.
   *
   * @covers ::deleteMessage
   */
  public function testDeleteByAdminEmptiesBody(): void {
    $conversation = $this->startWithAlice();
    $message = $this->helpers->postMessage($conversation, $this->alice, 'Rude text')->data['message'];

    $result = $this->helpers->deleteMessage($message, $this->otherAdmin);

    $this->assertTrue($result->success);
    $message = $this->reloadMessage($message);
    $this->assertSame('', $message->getBody());
    $this->assertTrue($message->isDeleted());
  }

  /**
   * Another user and a participant who is not the author cannot delete.
   *
   * @covers ::deleteMessage
   */
  public function testDeleteByOtherUserIsDenied(): void {
    $conversation = $this->startWithAlice();
    $fromAlice = $this->helpers->postMessage($conversation, $this->alice, 'Mine')->data['message'];
    $fromAdmin = $this->helpers->postMessage($conversation, $this->admin, 'Team text')->data['message'];

    $this->assertFalse($this->helpers->deleteMessage($fromAlice, $this->bob)->success);
    $this->assertFalse($this->helpers->deleteMessage($fromAdmin, $this->alice)->success);

    $this->assertSame('Mine', $this->reloadMessage($fromAlice)->getBody());
    $this->assertSame('Team text', $this->reloadMessage($fromAdmin)->getBody());
    $this->assertFalse($this->reloadMessage($fromAlice)->isDeleted());
  }

  /**
   * A deleted message cannot be deleted again, even by an admin.
   *
   * @covers ::deleteMessage
   */
  public function testDeleteTwiceIsDenied(): void {
    $conversation = $this->startWithAlice();
    $message = $this->helpers->postMessage($conversation, $this->alice, 'Text')->data['message'];
    $this->assertTrue($this->helpers->deleteMessage($message, $this->alice)->success);

    $this->assertFalse($this->helpers->deleteMessage($this->reloadMessage($message), $this->admin)->success);
  }

  /**
   * View and reply: the participant and admins; nobody else.
   */
  public function testConversationAccess(): void {
    $conversation = $this->startWithAlice();

    foreach (['view', 'reply'] as $operation) {
      $this->assertTrue($conversation->access($operation, $this->alice), "alice $operation");
      $this->assertTrue($conversation->access($operation, $this->admin), "admin $operation");
      $this->assertFalse($conversation->access($operation, $this->bob), "bob $operation");
      $this->assertFalse($conversation->access($operation, $this->mallory), "mallory $operation");
    }
    $this->assertFalse($conversation->access('delete', $this->alice));
    $this->assertFalse($conversation->access('update', $this->alice));
    $this->assertFalse($conversation->access('view', new AnonymousUserSession()));
  }

  /**
   * Starting a conversation is for admins.
   */
  public function testConversationCreateAccess(): void {
    $handler = $this->entityTypeManager->getAccessControlHandler('soda_scs_conversation');

    $this->assertTrue($handler->createAccess(NULL, $this->admin));
    $this->assertFalse($handler->createAccess(NULL, $this->alice));
  }

  /**
   * Message access: view follows the conversation, edit only the author.
   */
  public function testMessageAccess(): void {
    $conversation = $this->startWithAlice();
    $message = $this->helpers->postMessage($conversation, $this->alice, 'Text')->data['message'];

    $this->assertTrue($message->access('view', $this->alice));
    $this->assertTrue($message->access('view', $this->admin));
    $this->assertFalse($message->access('view', $this->bob));

    $this->assertTrue($message->access('update', $this->alice));
    $this->assertFalse($message->access('update', $this->admin));
    $this->assertFalse($message->access('update', $this->bob));

    $this->assertTrue($message->access('delete', $this->alice));
    $this->assertTrue($message->access('delete', $this->admin));
    $this->assertFalse($message->access('delete', $this->bob));
  }

  /**
   * Deleting a user removes their conversations with all messages only.
   *
   * @covers ::deleteForUser
   */
  public function testDeleteForUserRemovesOwnConversationsAndTheirMessages(): void {
    $aliceConversation = $this->startWithAlice();
    $this->helpers->postMessage($aliceConversation, $this->alice, 'Reply');
    $bobConversation = $this->helpers->startConversation($this->admin, (int) $this->bob->id(), 'Hi Bob', 'Text');

    $this->helpers->deleteForUser((int) $this->alice->id());

    $this->assertNull($this->reload($aliceConversation));
    $messageStorage = $this->entityTypeManager->getStorage('soda_scs_conversation_message');
    $messageStorage->resetCache();
    $this->assertCount(1, $messageStorage->loadMultiple());
    $this->assertNotNull($this->reload($bobConversation));
    $this->assertCount(1, $this->helpers->loadMessages($bobConversation));
    $this->assertCount(0, $this->helpers->loadForUser($this->alice));
  }

  /**
   * A deleted admin leaves the messages they wrote in other conversations.
   *
   * The account is not deleted here: the user delete hook also runs the
   * offboarding against external services. A message whose author account is
   * gone looks the same to the helper.
   *
   * @covers ::deleteForUser
   */
  public function testDeleteForUserKeepsMessagesOfDeletedAdmin(): void {
    $aliceConversation = $this->startWithAlice();
    $goneAdminId = 99999;
    $this->entityTypeManager->getStorage('soda_scs_conversation_message')->create([
      'conversation' => $aliceConversation->id(),
      'author' => $goneAdminId,
      'body' => 'From a deleted admin',
    ])->save();
    $bobConversation = $this->helpers->startConversation($this->admin, (int) $this->bob->id(), 'Hi Bob', 'Text');

    $this->helpers->deleteForUser($goneAdminId);

    $this->assertNotNull($this->reload($aliceConversation));
    $this->assertNotNull($this->reload($bobConversation));
    $messages = $this->helpers->loadMessages($this->reload($aliceConversation));
    $this->assertCount(2, $messages);
    $this->assertSame('From a deleted admin', $messages[1]->getBody());
    $this->assertNull($messages[1]->getAuthor());
    $this->assertCount(1, $this->helpers->loadMessages($this->reload($bobConversation)));
  }

  /**
   * Deleting a user without conversations is a no-op.
   *
   * @covers ::deleteForUser
   */
  public function testDeleteForUserWithoutConversationsDeletesNothing(): void {
    $this->startWithAlice();

    $this->helpers->deleteForUser((int) $this->bob->id());

    $this->assertSame(1, $this->helpers->countForTeam());
    $this->assertCount(1, $this->entityTypeManager->getStorage('soda_scs_conversation_message')->loadMultiple());
  }

  /**
   * The lists are ordered by the latest activity and scoped.
   *
   * @covers ::loadForUser
   * @covers ::loadForTeam
   * @covers ::countForTeam
   */
  public function testListsAreOrderedByLastActivityAndScoped(): void {
    $first = $this->startWithAlice();
    $second = $this->helpers->startConversation($this->admin, (int) $this->alice->id(), 'Second', 'Text');
    $forBob = $this->helpers->startConversation($this->admin, (int) $this->bob->id(), 'Bob', 'Text');
    // Equal timestamps fall back to the id; make the first one the newest.
    $this->helpers->postMessage($first, $this->alice, 'Bump');
    $this->entityTypeManager->getStorage('soda_scs_conversation')->resetCache();
    $first = $this->reload($first);
    $first->set('lastMessageAt', $first->getLastMessageTime() + 100)->save();

    $aliceIds = array_map(static fn ($conversation) => $conversation->id(), $this->helpers->loadForUser($this->alice));
    $this->assertSame([$first->id(), $second->id()], $aliceIds);

    $teamIds = array_map(static fn ($conversation) => $conversation->id(), $this->helpers->loadForTeam(10));
    $this->assertSame([$first->id(), $forBob->id(), $second->id()], $teamIds);
    $this->assertSame(3, $this->helpers->countForTeam());
    $this->assertCount(1, $this->helpers->loadForTeam(1));
    $this->assertSame([$second->id()], array_map(static fn ($conversation) => $conversation->id(), $this->helpers->loadForTeam(1, 2)));
  }

  /**
   * Users mode: only the picked, active users with access, not the sender.
   *
   * @covers ::resolveRecipients
   */
  public function testResolveRecipientsForPickedUsersDropsBlockedUnauthorizedAndSender(): void {
    $blocked = $this->createBlockedUser();
    $picked = [
      (int) $this->bob->id(),
      (int) $this->alice->id(),
      (int) $this->alice->id(),
      (int) $blocked->id(),
      (int) $this->mallory->id(),
      (int) $this->admin->id(),
    ];

    $recipients = $this->helpers->resolveRecipients(SodaScsConversationHelpers::RECIPIENTS_USERS, $picked, NULL, $this->admin);

    $this->assertSame([(int) $this->alice->id(), (int) $this->bob->id()], $recipients);
  }

  /**
   * Users mode with an empty pick resolves to nobody, not to everybody.
   *
   * @covers ::resolveRecipients
   */
  public function testResolveRecipientsForEmptyPickIsEmpty(): void {
    $this->assertSame([], $this->helpers->resolveRecipients(SodaScsConversationHelpers::RECIPIENTS_USERS, [], NULL, $this->admin));
    $this->assertSame([], $this->helpers->resolveRecipients('unknown', [(int) $this->alice->id()], NULL, $this->admin));
  }

  /**
   * Project mode: owner and members, active, with access, not the sender.
   *
   * @covers ::resolveRecipients
   */
  public function testResolveRecipientsForProjectCoversOwnerAndMembers(): void {
    // Saving a project looks up its components.
    $this->installEntitySchema('soda_scs_component');
    $blocked = $this->createBlockedUser();
    $outsider = $this->createTestUser();
    $project = $this->createTestProject([
      'owner' => $this->alice->id(),
      'members' => [
        ['target_id' => $this->bob->id()],
        ['target_id' => $blocked->id()],
        ['target_id' => $this->mallory->id()],
        ['target_id' => $this->admin->id()],
      ],
    ]);

    $recipients = $this->helpers->resolveRecipients(SodaScsConversationHelpers::RECIPIENTS_PROJECT, [], (int) $project->id(), $this->admin);

    $this->assertSame([(int) $this->alice->id(), (int) $this->bob->id()], $recipients);
    $this->assertNotContains((int) $outsider->id(), $recipients);
  }

  /**
   * Project mode with a missing project or none resolves to nobody.
   *
   * @covers ::resolveRecipients
   */
  public function testResolveRecipientsForMissingProjectIsEmpty(): void {
    $this->assertSame([], $this->helpers->resolveRecipients(SodaScsConversationHelpers::RECIPIENTS_PROJECT, [], NULL, $this->admin));
    $this->assertSame([], $this->helpers->resolveRecipients(SodaScsConversationHelpers::RECIPIENTS_PROJECT, [], 99999, $this->admin));
  }

  /**
   * All mode: every active user with access, the other admin included.
   *
   * @covers ::resolveRecipients
   */
  public function testResolveRecipientsForAllExcludesBlockedUnauthorizedAndSender(): void {
    $this->createBlockedUser();

    $recipients = $this->helpers->resolveRecipients(SodaScsConversationHelpers::RECIPIENTS_ALL, [], NULL, $this->admin);

    // The first user of the test (uid 1) is active and has the role too.
    $this->assertSame([
      (int) $this->testUser->id(),
      (int) $this->otherAdmin->id(),
      (int) $this->alice->id(),
      (int) $this->bob->id(),
    ], $recipients);
  }

  /**
   * The authenticated role's permission makes every active user a recipient.
   *
   * Users never store the implicit authenticated role, so a user without
   * extra roles is found only when the roles condition is dropped.
   *
   * @covers ::resolveRecipients
   */
  public function testResolveRecipientsWhenAuthenticatedRoleHasThePermission(): void {
    $blocked = $this->createBlockedUser();
    $withoutRoles = $this->createTestUser();
    foreach (['scs_user', 'scs_admin'] as $roleId) {
      Role::load($roleId)->revokePermission('soda scs manager user')->save();
    }
    $withoutRoles->removeRole('scs_user')->save();
    $this->mallory->removeRole('scs_user')->save();
    Role::load('authenticated')->grantPermission('soda scs manager user')->save();

    $all = $this->helpers->resolveRecipients(SodaScsConversationHelpers::RECIPIENTS_ALL, [], NULL, $this->admin);

    $this->assertContains((int) $withoutRoles->id(), $all);
    $this->assertContains((int) $this->mallory->id(), $all);
    $this->assertNotContains((int) $blocked->id(), $all);
    $this->assertNotContains((int) $this->admin->id(), $all);

    $picked = $this->helpers->resolveRecipients(
      SodaScsConversationHelpers::RECIPIENTS_USERS,
      [(int) $withoutRoles->id(), (int) $blocked->id(), (int) $this->admin->id()],
      NULL,
      $this->admin,
    );
    $this->assertSame([(int) $withoutRoles->id()], $picked);
  }

  /**
   * Without any role carrying the permission nobody is a recipient.
   *
   * @covers ::resolveRecipients
   */
  public function testResolveRecipientsIsEmptyWhenNoRoleHasThePermission(): void {
    foreach (['scs_admin', 'scs_user'] as $roleId) {
      Role::load($roleId)->delete();
    }

    $this->assertSame([], $this->helpers->resolveRecipients(SodaScsConversationHelpers::RECIPIENTS_ALL, [], NULL, $this->admin));
  }

  /**
   * The batch step counts started and failed conversations.
   *
   * @covers \Drupal\soda_scs_manager\Batch\SodaScsConversationBroadcastBatch::startChunk
   */
  public function testBatchChunkCountsStartedConversations(): void {
    $context = [];

    SodaScsConversationBroadcastBatch::startChunk(
      (int) $this->admin->id(),
      [(int) $this->alice->id(), (int) $this->bob->id()],
      'Notice',
      'Text',
      $context,
    );

    $this->assertSame(2, $context['results']['started']);
    $this->assertArrayNotHasKey('failed', $context['results']);
    $this->assertSame(2, $this->helpers->countForTeam());
    $this->assertSame(1, $this->helpers->countUnread($this->alice));
  }

  /**
   * A chunk adds to the counters of earlier chunks.
   *
   * @covers \Drupal\soda_scs_manager\Batch\SodaScsConversationBroadcastBatch::startChunk
   */
  public function testBatchChunkAddsToExistingResults(): void {
    $context = ['results' => ['started' => 5]];

    SodaScsConversationBroadcastBatch::startChunk((int) $this->admin->id(), [(int) $this->alice->id()], 'Notice', 'Text', $context);

    $this->assertSame(6, $context['results']['started']);
  }

  /**
   * A sender without admin rights starts nothing; each attempt counts failed.
   *
   * @covers \Drupal\soda_scs_manager\Batch\SodaScsConversationBroadcastBatch::startChunk
   */
  public function testBatchChunkCountsFailuresForNonAdminSender(): void {
    $context = [];

    SodaScsConversationBroadcastBatch::startChunk((int) $this->alice->id(), [(int) $this->bob->id()], 'Notice', 'Text', $context);

    $this->assertSame(1, $context['results']['failed']);
    $this->assertArrayNotHasKey('started', $context['results']);
    $this->assertSame(0, $this->helpers->countForTeam());
  }

  /**
   * An unknown sender starts nothing; every chunk recipient counts failed.
   *
   * @covers \Drupal\soda_scs_manager\Batch\SodaScsConversationBroadcastBatch::startChunk
   */
  public function testBatchChunkWithUnknownSenderCountsAllRecipientsFailed(): void {
    $context = [];

    SodaScsConversationBroadcastBatch::startChunk(99999, [(int) $this->alice->id(), (int) $this->bob->id()], 'Notice', 'Text', $context);

    $this->assertSame(2, $context['results']['failed']);
    $this->assertArrayNotHasKey('started', $context['results']);
    $this->assertSame(0, $this->helpers->countForTeam());
  }

  /**
   * Failures of an unknown sender add to the counters of earlier chunks.
   *
   * @covers \Drupal\soda_scs_manager\Batch\SodaScsConversationBroadcastBatch::startChunk
   */
  public function testBatchChunkWithUnknownSenderAddsToExistingFailures(): void {
    $context = ['results' => ['started' => 3, 'failed' => 1]];

    SodaScsConversationBroadcastBatch::startChunk(99999, [(int) $this->alice->id()], 'Notice', 'Text', $context);

    $this->assertSame(3, $context['results']['started']);
    $this->assertSame(2, $context['results']['failed']);
  }

  /**
   * Starts a conversation of the admin with alice; first message "Hello".
   */
  private function startWithAlice(): SodaScsConversationInterface {
    $conversation = $this->helpers->startConversation($this->admin, (int) $this->alice->id(), 'Subject', 'Hello');
    $this->assertInstanceOf(SodaScsConversationInterface::class, $conversation);
    return $this->reload($conversation);
  }

  /**
   * A helper wired with the real services but the given logger.
   */
  private function helpersWithLogger(LoggerChannelInterface $logger): SodaScsConversationHelpers {
    return new SodaScsConversationHelpers(
      $this->entityTypeManager,
      $logger,
      $this->container->get('datetime.time'),
      $this->container->get('database'),
    );
  }

  /**
   * Creates a user with access to the SCS Manager who is blocked.
   */
  private function createBlockedUser(): UserInterface {
    $user = $this->createTestUser();
    $user->block()->save();
    return $user;
  }

  /**
   * Loads a conversation fresh from the database.
   */
  private function reload(SodaScsConversationInterface $conversation): ?SodaScsConversationInterface {
    $storage = $this->entityTypeManager->getStorage('soda_scs_conversation');
    $storage->resetCache([$conversation->id()]);
    return $storage->load($conversation->id());
  }

  /**
   * Loads a message fresh from the database.
   */
  private function reloadMessage($message) {
    $storage = $this->entityTypeManager->getStorage('soda_scs_conversation_message');
    $storage->resetCache([$message->id()]);
    return $storage->load($message->id());
  }

}
