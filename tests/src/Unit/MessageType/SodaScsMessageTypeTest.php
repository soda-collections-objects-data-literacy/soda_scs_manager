<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\MessageType;

use Drupal\soda_scs_manager\Entity\SodaScsMessageInterface;
use Drupal\soda_scs_manager\MessageType\SodaScsGenericMessageType;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\MessageType\SodaScsGenericMessageType
 * @group soda_scs_manager
 */
class SodaScsMessageTypeTest extends UnitTestCase {

  /**
   * @covers \Drupal\soda_scs_manager\MessageType\SodaScsGenericMessageType::buildSubject
   * @covers \Drupal\soda_scs_manager\MessageType\SodaScsGenericMessageType::buildActions
   */
  public function testGenericTypeUsesStoredSubjectAndDeleteAction(): void {
    $message = $this->createMock(SodaScsMessageInterface::class);
    $message->method('getSubject')->willReturn('Hello from Alice');

    $type = new SodaScsGenericMessageType();
    $type->setStringTranslation($this->getStringTranslationStub());

    $this->assertSame('generic', $type->getId());
    $this->assertSame('Hello from Alice', $type->buildSubject($message));
    $this->assertArrayHasKey('delete', $type->buildActions($message));
  }

}
