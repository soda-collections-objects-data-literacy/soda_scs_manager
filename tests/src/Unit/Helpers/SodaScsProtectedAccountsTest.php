<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProtectedAccounts;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

/**
 * Which accounts are protected from deletion and offboarding.
 *
 * Issue 3640913.
 */
#[CoversMethod(SodaScsProtectedAccounts::class, 'isProtected')]
#[Group('soda_scs_manager')]
class SodaScsProtectedAccountsTest extends UnitTestCase {

  /**
   * The site administrator is protected whatever its name.
   */
  public function testUidOneIsProtected(): void {
    $this->assertTrue(SodaScsProtectedAccounts::isProtected($this->account(1, 'admin')));
  }

  /**
   * The demo service account is protected by its name.
   */
  public function testDemoServiceAccountIsProtected(): void {
    $this->assertTrue(SodaScsProtectedAccounts::isProtected($this->account(42, 'scs_demo')));
  }

  /**
   * An ordinary account is not protected.
   */
  public function testOrdinaryAccountIsNotProtected(): void {
    $this->assertFalse(SodaScsProtectedAccounts::isProtected($this->account(42, 'alice')));
  }

  /**
   * The name is matched exactly, not as a prefix.
   */
  public function testSimilarNameIsNotProtected(): void {
    $this->assertFalse(SodaScsProtectedAccounts::isProtected($this->account(42, 'scs_demo2')));
    $this->assertFalse(SodaScsProtectedAccounts::isProtected($this->account(42, 'SCS_DEMO')));
  }

  /**
   * The anonymous user (uid 0) is not protected, even with the demo name.
   */
  public function testAnonymousUserIsNotProtected(): void {
    $this->assertFalse(SodaScsProtectedAccounts::isProtected($this->account(0, 'scs_demo')));
    $this->assertFalse(SodaScsProtectedAccounts::isProtected($this->account(0, '')));
  }

  /**
   * Builds an account double.
   */
  private function account(int $id, string $name): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn((string) $id);
    $account->method('getAccountName')->willReturn($name);
    return $account;
  }

}
