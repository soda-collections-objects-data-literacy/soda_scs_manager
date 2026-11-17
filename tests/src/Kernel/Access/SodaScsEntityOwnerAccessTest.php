<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Access;

use Drupal\soda_scs_manager\Access\SodaScsComponentAccessControlHandler;
use Drupal\soda_scs_manager\Access\SodaScsStackAccessControlHandler;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;

/**
 * Tests owner-based access for components, stacks, and snapshots.
 *
 * @group soda_scs_manager
 */
class SodaScsEntityOwnerAccessTest extends SodaScsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // hook_user_insert() already created scs_user for the base class's user.
    $role = Role::load('scs_user') ?? Role::create([
      'id' => 'scs_user',
      'label' => 'scs_user',
    ]);
    $role->grantPermission('view soda scs component');
    $role->grantPermission('edit soda scs component');
    $role->grantPermission('delete soda scs component');
    $role->grantPermission('view soda scs stack');
    $role->grantPermission('edit soda scs stack');
    $role->grantPermission('delete soda scs stack');
    $role->grantPermission('create soda scs snapshot');
    $role->grantPermission('view soda scs snapshot');
    $role->save();
  }

  /**
   * Tests that only the component owner may update or delete it.
   */
  public function testComponentOwnerAccess(): void {
    $owner = $this->testUser;
    $otherUser = $this->createTestUser();
    $otherUser->addRole('scs_user');
    $otherUser->save();

    $component = $this->createTestComponent('soda_scs_sql_component', [
      'owner' => $owner->id(),
    ]);

    $this->assertTrue($component->access('update', $owner));
    $this->assertTrue($component->access('delete', $owner));
    $this->assertFalse($component->access('update', $otherUser));
    $this->assertFalse($component->access('delete', $otherUser));
    $this->assertTrue($component->access('view', $otherUser));
  }

  /**
   * Tests that only the stack owner may update or delete it.
   */
  public function testStackOwnerAccess(): void {
    $owner = $this->testUser;
    $otherUser = $this->createTestUser();
    $otherUser->addRole('scs_user');
    $otherUser->save();

    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $owner->id(),
    ]);

    $this->assertTrue($stack->access('update', $owner));
    $this->assertTrue($stack->access('delete', $owner));
    $this->assertFalse($stack->access('update', $otherUser));
    $this->assertFalse($stack->access('delete', $otherUser));
    $this->assertTrue($stack->access('view', $otherUser));
  }

  /**
   * Tests that only the component owner may create snapshots.
   */
  public function testComponentSnapshotAccess(): void {
    $owner = $this->testUser;
    $otherUser = $this->createTestUser();
    $otherUser->addRole('scs_user');
    $otherUser->save();

    $component = $this->createTestComponent('soda_scs_sql_component', [
      'owner' => $owner->id(),
    ]);

    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($owner);
    $this->assertTrue(SodaScsComponentAccessControlHandler::accessSnapshotForm($component)->isAllowed());

    $accountSwitcher->switchTo($otherUser);
    $this->assertFalse(SodaScsComponentAccessControlHandler::accessSnapshotForm($component)->isAllowed());
    $accountSwitcher->switchBack();
  }

  /**
   * Tests that only the stack owner may create snapshots.
   */
  public function testStackSnapshotAccess(): void {
    $owner = $this->testUser;
    $otherUser = $this->createTestUser();
    $otherUser->addRole('scs_user');
    $otherUser->save();

    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $owner->id(),
    ]);

    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($owner);
    $this->assertTrue(SodaScsStackAccessControlHandler::accessSnapshotForm($stack)->isAllowed());

    $accountSwitcher->switchTo($otherUser);
    $this->assertFalse(SodaScsStackAccessControlHandler::accessSnapshotForm($stack)->isAllowed());
    $accountSwitcher->switchBack();
  }

  /**
   * Tests that only the snapshot owner may view, update, or delete it.
   */
  public function testSnapshotOwnerAccess(): void {
    $owner = $this->testUser;
    $otherUser = $this->createTestUser();
    $otherUser->addRole('scs_user');
    $otherUser->save();

    $snapshot = $this->createTestSnapshot([
      'owner' => $owner->id(),
    ]);

    $this->assertTrue($snapshot->access('view', $owner));
    $this->assertTrue($snapshot->access('update', $owner));
    $this->assertTrue($snapshot->access('delete', $owner));
    $this->assertFalse($snapshot->access('view', $otherUser));
    $this->assertFalse($snapshot->access('update', $otherUser));
    $this->assertFalse($snapshot->access('delete', $otherUser));
  }

  /**
   * Tests that SCS Manager admins bypass owner restrictions.
   */
  public function testScsManagerAdminBypass(): void {
    $owner = $this->testUser;
    $admin = $this->createTestUser();
    $adminRole = Role::create([
      'id' => 'scs_manager_admin',
      'label' => 'scs_manager_admin',
    ]);
    $adminRole->grantPermission('soda scs manager admin');
    $adminRole->grantPermission('view soda scs component');
    $adminRole->grantPermission('edit soda scs component');
    $adminRole->grantPermission('delete soda scs component');
    $adminRole->grantPermission('view soda scs stack');
    $adminRole->grantPermission('edit soda scs stack');
    $adminRole->grantPermission('delete soda scs stack');
    $adminRole->grantPermission('view soda scs snapshot');
    $adminRole->save();
    $admin->addRole('scs_manager_admin');
    $admin->save();

    $component = $this->createTestComponent('soda_scs_sql_component', [
      'owner' => $owner->id(),
    ]);
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $owner->id(),
    ]);
    $snapshot = $this->createTestSnapshot([
      'owner' => $owner->id(),
    ]);

    $this->assertTrue($component->access('update', $admin));
    $this->assertTrue($component->access('delete', $admin));
    $this->assertTrue($stack->access('update', $admin));
    $this->assertTrue($stack->access('delete', $admin));
    $this->assertTrue($snapshot->access('view', $admin));
    $this->assertTrue($snapshot->access('delete', $admin));

    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($admin);
    $this->assertTrue(SodaScsComponentAccessControlHandler::accessSnapshotForm($component)->isAllowed());
    $this->assertTrue(SodaScsStackAccessControlHandler::accessSnapshotForm($stack)->isAllowed());
    $accountSwitcher->switchBack();
  }

  /**
   * Tests that soda scs manager admin is the admin permission of all types.
   *
   * Projects, components and service keys named permissions that were not
   * defined, so no role could get their admin access.
   */
  public function testScsManagerAdminIsEntityAdminPermission(): void {
    foreach (['soda_scs_component', 'soda_scs_project', 'soda_scs_service_key', 'soda_scs_wisski_component_ver'] as $entityTypeId) {
      $this->assertSame(
        'soda scs manager admin',
        $this->entityTypeManager->getDefinition($entityTypeId)->getAdminPermission(),
        $entityTypeId,
      );
    }

    $admin = $this->createTestUser();
    $adminRole = Role::create([
      'id' => 'scs_manager_admin',
      'label' => 'scs_manager_admin',
    ]);
    $adminRole->grantPermission('soda scs manager admin');
    $adminRole->save();
    $admin->addRole('scs_manager_admin');
    $admin->save();

    $serviceKey = $this->createTestServiceKey('default', [
      'owner' => $this->testUser->id(),
    ]);
    $this->assertTrue($serviceKey->access('view', $admin));
    $this->assertTrue($serviceKey->access('update', $admin));
    $this->assertTrue($serviceKey->access('delete', $admin));
  }

  /**
   * Tests that only the owner of a service key may view or change it.
   *
   * The scs_user role has the view and delete permissions for service keys;
   * they must not open another user's key.
   */
  public function testServiceKeyOwnerAccess(): void {
    $owner = $this->testUser;
    $otherUser = $this->createTestUser();
    $otherUser->addRole('scs_user');
    $otherUser->save();

    $serviceKey = $this->createTestServiceKey('default', [
      'owner' => $owner->id(),
    ]);

    $this->assertTrue($serviceKey->access('view', $owner));
    $this->assertTrue($serviceKey->access('update', $owner));
    $this->assertTrue($serviceKey->access('delete', $owner));
    $this->assertFalse($serviceKey->access('view', $otherUser));
    $this->assertFalse($serviceKey->access('update', $otherUser));
    $this->assertFalse($serviceKey->access('delete', $otherUser));
  }

}
