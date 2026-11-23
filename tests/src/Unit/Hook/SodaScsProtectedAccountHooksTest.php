<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Hook;

use Drupal\soda_scs_manager\Hook\SodaScsProtectedAccountHooks;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * Cancelling or deleting a protected account throws before anything changes.
 *
 * Issue 3640913.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Hook\SodaScsProtectedAccountHooks
 * @group soda_scs_manager
 */
class SodaScsProtectedAccountHooksTest extends UnitTestCase {

  /**
   * Block, reassign and delete methods all stop for the demo account.
   *
   * @covers ::userCancel
   */
  public function testCancelThrowsForDemoAccountWithEveryMethod(): void {
    foreach (['user_cancel_block', 'user_cancel_block_unpublish', 'user_cancel_reassign', 'user_cancel_delete'] as $method) {
      try {
        (new SodaScsProtectedAccountHooks())->userCancel([], $this->account(42, 'scs_demo'), $method);
        $this->fail("Method $method must be refused.");
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString('scs_demo', $e->getMessage());
      }
    }
  }

  /**
   * Cancelling uid 1 throws.
   *
   * @covers ::userCancel
   */
  public function testCancelThrowsForUidOne(): void {
    $this->expectException(\LogicException::class);

    (new SodaScsProtectedAccountHooks())->userCancel([], $this->account(1, 'admin'), 'user_cancel_block');
  }

  /**
   * An ordinary account is cancelled as before.
   *
   * @covers ::userCancel
   */
  public function testCancelLetsOrdinaryAccountPass(): void {
    (new SodaScsProtectedAccountHooks())->userCancel([], $this->account(42, 'alice'), 'user_cancel_block');

    $this->addToAssertionCount(1);
  }

  /**
   * Deleting the demo account throws.
   *
   * @covers ::userPredelete
   */
  public function testPredeleteThrowsForDemoAccount(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('scs_demo');

    (new SodaScsProtectedAccountHooks())->userPredelete($this->account(42, 'scs_demo'));
  }

  /**
   * Deleting uid 1 throws.
   *
   * @covers ::userPredelete
   */
  public function testPredeleteThrowsForUidOne(): void {
    $this->expectException(\LogicException::class);

    (new SodaScsProtectedAccountHooks())->userPredelete($this->account(1, 'admin'));
  }

  /**
   * An ordinary account is deleted as before.
   *
   * @covers ::userPredelete
   */
  public function testPredeleteLetsOrdinaryAccountPass(): void {
    (new SodaScsProtectedAccountHooks())->userPredelete($this->account(42, 'alice'));

    $this->addToAssertionCount(1);
  }

  /**
   * Builds a user double.
   */
  private function account(int $id, string $name): UserInterface {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn((string) $id);
    $account->method('getAccountName')->willReturn($name);
    return $account;
  }

}
