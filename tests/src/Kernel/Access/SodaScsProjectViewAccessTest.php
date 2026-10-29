<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;

/**
 * Tests that project pages are limited to owners, members and admins.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\Access\SodaScsProjectAccessControlHandler
 */
class SodaScsProjectViewAccessTest extends SodaScsKernelTestBase {

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
    $adminRole->grantPermission('soda scs manager user');
    $adminRole->grantPermission('administer soda scs project entities');
    $adminRole->save();

    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Outsiders with the generic user permission cannot open a project URL.
   *
   * @covers ::checkAccess
   */
  public function testCanonicalRouteRequiresMembership(): void {
    $owner = $this->testUser;
    $owner->addRole('scs_user');
    $owner->save();

    $member = $this->createTestUser();
    $member->addRole('scs_user');
    $member->save();

    $outsider = $this->createTestUser();
    $outsider->addRole('scs_user');
    $outsider->save();

    $admin = $this->createTestUser();
    $admin->addRole('scs_admin');
    $admin->save();

    $project = $this->createTestProject([
      'owner' => $owner->id(),
      'members' => [$member->id()],
    ]);

    $this->assertTrue($this->canOpenProject($owner, $project->id()));
    $this->assertTrue($this->canOpenProject($member, $project->id()));
    $this->assertTrue($this->canOpenProject($admin, $project->id()));
    $this->assertFalse($this->canOpenProject($outsider, $project->id()));
  }

  /**
   * Whether the account may open the project canonical route.
   */
  private function canOpenProject(AccountInterface $account, int|string $projectId): bool {
    $result = $this->container->get('access_manager')->checkNamedRoute(
      'entity.soda_scs_project.canonical',
      ['soda_scs_project' => $projectId],
      $account,
      TRUE,
    );
    return $result->isAllowed();
  }

}
