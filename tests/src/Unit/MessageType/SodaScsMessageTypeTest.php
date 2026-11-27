<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\MessageType;

use Drupal\soda_scs_manager\Entity\SodaScsMessageInterface;
use Drupal\soda_scs_manager\MessageType\SodaScsGenericMessageType;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SodaScsGenericMessageType.
 */
#[CoversMethod(SodaScsGenericMessageType::class, 'buildSubject')]
#[CoversMethod(SodaScsGenericMessageType::class, 'buildActions')]
#[Group('soda_scs_manager')]
class SodaScsMessageTypeTest extends UnitTestCase {

  /**
   * Tests generic type uses stored subject and delete action.
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
