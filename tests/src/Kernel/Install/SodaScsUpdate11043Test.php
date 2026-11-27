<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Install;

use Drupal\soda_scs_manager\Demo\SodaScsDemoServiceAccount;
use Drupal\soda_scs_manager\Helpers\SodaScsProtectedAccounts;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests soda_scs_manager_update_11043() (the service account scs_demo).
 *
 * Issue 3640913. The update only creates the Drupal account; Keycloak and
 * Nextcloud are reached by drush soda_scs_manager:demo-account, never here.
 */
#[Group('soda_scs_manager')]
final class SodaScsUpdate11043Test extends SodaScsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('soda_scs_manager', 'install');
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV);
    putenv(SodaScsDemoServiceAccount::EMAIL_ENV);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV);
    putenv(SodaScsDemoServiceAccount::EMAIL_ENV);
    parent::tearDown();
  }

  /**
   * The update creates an active account named scs_demo.
   */
  public function testUpdateCreatesTheDemoAccount(): void {
    $this->assertSame([], $this->loadDemoAccounts(), 'Precondition: no account yet.');

    $message = soda_scs_manager_update_11043();

    $accounts = $this->loadDemoAccounts();
    $this->assertCount(1, $accounts);
    $this->assertTrue(reset($accounts)->isActive());
    $this->assertStringContainsString('drush soda_scs_manager:demo-account', (string) $message);
  }

  /**
   * A second run keeps the one account.
   */
  public function testUpdateTwiceKeepsOneAccount(): void {
    soda_scs_manager_update_11043();
    $accountId = array_key_first($this->loadDemoAccounts());

    soda_scs_manager_update_11043();

    $this->assertSame([$accountId], array_keys($this->loadDemoAccounts()));
  }

  /**
   * The mail address of the environment is used for the new account.
   */
  public function testUpdateUsesTheMailAddressOfTheEnvironment(): void {
    putenv(SodaScsDemoServiceAccount::EMAIL_ENV . '=demo@example.com');

    soda_scs_manager_update_11043();

    $this->assertSame('demo@example.com', $this->demoAccount()->getEmail());
  }

  /**
   * The account that was created is the protected one.
   */
  public function testCreatedAccountIsProtected(): void {
    soda_scs_manager_update_11043();

    $account = $this->demoAccount();
    $this->assertTrue(SodaScsProtectedAccounts::isProtected($account));
    $this->assertFalse($account->access('delete', $this->entityTypeManager->getStorage('user')->load(1)));
  }

  /**
   * The one account named scs_demo.
   */
  private function demoAccount(): UserInterface {
    $accounts = $this->loadDemoAccounts();
    $this->assertCount(1, $accounts);
    return reset($accounts);
  }

  /**
   * Loads the accounts named scs_demo.
   *
   * @return \Drupal\user\UserInterface[]
   *   The accounts, keyed by id.
   */
  private function loadDemoAccounts(): array {
    return $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => 'scs_demo']);
  }

}
