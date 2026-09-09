<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Access;

use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Tests project member add/remove permission rules.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers
 */
class SodaScsProjectMembershipAccessTest extends SodaScsKernelTestBase {

  /**
   * The membership helper.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers
   */
  private $membershipHelpers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $userRole = Role::create([
      'id' => 'scs_user',
      'label' => 'scs_user',
    ]);
    $userRole->grantPermission('soda scs manager user');
    $userRole->save();

    $adminRole = Role::create([
      'id' => 'scs_admin',
      'label' => 'scs_admin',
    ]);
    $adminRole->grantPermission('soda scs manager admin');
    $adminRole->save();

    $this->membershipHelpers = $this->container->get('soda_scs_manager.project_membership.helpers');
  }

  /**
   * @covers ::canAddMembers
   * @covers ::canRemoveMember
   * @covers ::isListedMember
   */
  public function testMemberAddAndRemoveRules(): void {
    $owner = $this->testUser;
    $owner->addRole('scs_user');
    $owner->save();

    $member = $this->createTestUser();
    $member->addRole('scs_user');
    $member->save();

    $otherMember = $this->createTestUser();
    $otherMember->addRole('scs_user');
    $otherMember->save();

    $outsider = $this->createTestUser();
    $outsider->addRole('scs_user');
    $outsider->save();

    $admin = $this->createTestUser();
    $admin->addRole('scs_admin');
    $admin->save();

    $project = $this->createTestProject([
      'owner' => $owner->id(),
      'members' => [$member->id(), $otherMember->id()],
    ]);

    $this->assertTrue($this->membershipHelpers->isListedMember($project, (int) $member->id()));
    $this->assertFalse($this->membershipHelpers->isListedMember($project, (int) $owner->id()));

    $this->assertTrue($this->membershipHelpers->canAddMembers($project, $owner));
    $this->assertTrue($this->membershipHelpers->canAddMembers($project, $admin));
    $this->assertFalse($this->membershipHelpers->canAddMembers($project, $member));
    $this->assertFalse($this->membershipHelpers->canAddMembers($project, $outsider));

    $this->assertTrue($this->membershipHelpers->canRemoveMember($project, $admin, $member));
    $this->assertTrue($this->membershipHelpers->canRemoveMember($project, $owner, $member));
    $this->assertTrue($this->membershipHelpers->canRemoveMember($project, $member, $member));
    $this->assertFalse($this->membershipHelpers->canRemoveMember($project, $member, $otherMember));
    $this->assertFalse($this->membershipHelpers->canRemoveMember($project, $outsider, $member));
    $this->assertFalse($this->membershipHelpers->canRemoveMember($project, $admin, $owner));
  }

  /**
   * @covers ::canRemoveMember
   */
  public function testAnonymousCannotChangeMembers(): void {
    $member = $this->createTestUser();
    $project = $this->createTestProject([
      'members' => [$member->id()],
    ]);
    $anonymous = User::getAnonymousUser();
    $this->assertInstanceOf(UserInterface::class, $anonymous);
    $this->assertFalse($this->membershipHelpers->canAddMembers($project, $anonymous));
    $this->assertFalse($this->membershipHelpers->canRemoveMember($project, $anonymous, $member));
  }

}
