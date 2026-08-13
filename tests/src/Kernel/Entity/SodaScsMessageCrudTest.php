<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Entity;

use Drupal\soda_scs_manager\Entity\SodaScsMessage;
use Drupal\soda_scs_manager\Entity\SodaScsMessageInterface;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Tests CRUD for in-app message entities.
 *
 * @group soda_scs_manager
 */
class SodaScsMessageCrudTest extends SodaScsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('soda_scs_message');
  }

  /**
   * Tests creating and reading a message.
   */
  public function testCreateAndReadMessage(): void {
    $recipient = $this->createTestUser();
    $storage = $this->entityTypeManager->getStorage('soda_scs_message');

    $message = $storage->create([
      'type' => SodaScsMessageInterface::TYPE_PROJECT_INVITATION,
      'sender' => $this->testUser->id(),
      'recipient' => $recipient->id(),
      'label' => 'You have been invited to the project Demo',
      'body' => 'Please accept or decline.',
      'isRead' => FALSE,
    ]);
    $message->save();

    $this->assertInstanceOf(SodaScsMessage::class, $message);
    $this->assertNotNull($message->id());
    $this->assertFalse($message->isRead());
    $this->assertSame('You have been invited to the project Demo', $message->getSubject());
    $this->assertSame((int) $this->testUser->id(), (int) $message->getSender()->id());
    $this->assertSame((int) $recipient->id(), (int) $message->getRecipient()->id());

    $storage->resetCache([$message->id()]);
    $loaded = $storage->load($message->id());
    $this->assertInstanceOf(SodaScsMessageInterface::class, $loaded);
    $this->assertSame($message->getSubject(), $loaded->getSubject());
  }

  /**
   * Tests marking a message as read.
   */
  public function testMarkMessageRead(): void {
    $recipient = $this->createTestUser();
    $storage = $this->entityTypeManager->getStorage('soda_scs_message');
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsMessageInterface $message */
    $message = $storage->create([
      'type' => SodaScsMessageInterface::TYPE_PROJECT_INVITATION,
      'sender' => $this->testUser->id(),
      'recipient' => $recipient->id(),
      'label' => 'Test subject',
      'isRead' => FALSE,
    ]);
    $message->save();

    $message->setRead(TRUE);
    $message->save();

    $storage->resetCache([$message->id()]);
    $loaded = $storage->load($message->id());
    $this->assertTrue($loaded->isRead());
  }

}
