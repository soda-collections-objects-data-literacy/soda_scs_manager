<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Access;

use Drupal\soda_scs_manager\Access\SodaScsComponentLinksTasksAccessControlHandler;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;

/**
 * Tests who may open the WebProtégé ontology import form of a component.
 *
 * @group soda_scs_manager
 */
class SodaScsComponentLinksTasksAccessTest extends SodaScsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $role = Role::load('scs_user') ?? Role::create([
      'id' => 'scs_user',
      'label' => 'scs_user',
    ]);
    $role->grantPermission('view soda scs component');
    $role->grantPermission('edit soda scs component');
    $role->save();
  }

  /**
   * Checks the import form access of a component as the given account.
   */
  private function importAccessIsAllowed(string $bundle, $owner, $account): bool {
    $component = $this->createTestComponent($bundle, ['owner' => $owner->id()]);
    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($account);
    try {
      return SodaScsComponentLinksTasksAccessControlHandler::accessImportOntologyForm($component)->isAllowed();
    }
    finally {
      $accountSwitcher->switchBack();
    }
  }

  /**
   * Tests that the owner of a WebProtégé component may import.
   */
  public function testOwnerMayImportIntoWebprotegeComponent(): void {
    $this->assertTrue($this->importAccessIsAllowed('soda_scs_webprotege_component', $this->testUser, $this->testUser));
  }

  /**
   * Tests that another user may not import into a WebProtégé component.
   */
  public function testNonOwnerMayNotImportIntoWebprotegeComponent(): void {
    $otherUser = $this->createTestUser();
    $otherUser->addRole('scs_user');
    $otherUser->save();

    $this->assertFalse($this->importAccessIsAllowed('soda_scs_webprotege_component', $this->testUser, $otherUser));
  }

  /**
   * Tests that an SCS Manager admin may import into any WebProtégé component.
   */
  public function testAdminMayImportIntoWebprotegeComponentOfOthers(): void {
    $admin = $this->createTestUser();
    $adminRole = Role::create(['id' => 'scs_manager_admin', 'label' => 'scs_manager_admin']);
    $adminRole->grantPermission('soda scs manager admin');
    $adminRole->save();
    $admin->addRole('scs_manager_admin');
    $admin->save();

    $this->assertTrue($this->importAccessIsAllowed('soda_scs_webprotege_component', $this->testUser, $admin));
  }

  /**
   * Tests that no one, the owner included, imports into other bundles.
   */
  public function testOwnerMayNotImportIntoOtherBundles(): void {
    foreach (['soda_scs_sql_component', 'soda_scs_nextcloud_component', 'soda_scs_triplestore_component'] as $bundle) {
      $this->assertFalse($this->importAccessIsAllowed($bundle, $this->testUser, $this->testUser), $bundle);
    }
  }

  /**
   * Tests that an admin may not import into other bundles either.
   */
  public function testAdminMayNotImportIntoOtherBundles(): void {
    $admin = $this->createTestUser();
    $adminRole = Role::create(['id' => 'scs_manager_admin', 'label' => 'scs_manager_admin']);
    $adminRole->grantPermission('soda scs manager admin');
    $adminRole->save();
    $admin->addRole('scs_manager_admin');
    $admin->save();

    $this->assertFalse($this->importAccessIsAllowed('soda_scs_sql_component', $this->testUser, $admin));
  }

}
