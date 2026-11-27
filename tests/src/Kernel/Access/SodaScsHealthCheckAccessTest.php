<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Access;

use Drupal\soda_scs_manager\Controller\SodaScsComponentController;
use Drupal\soda_scs_manager\Controller\SodaScsStackController;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the health endpoints follow the entity's view access.
 *
 * A user without view access gets the "not found" payload, the same as for a
 * missing ID, and no health check runs.
 */
#[Group('soda_scs_manager')]
class SodaScsHealthCheckAccessTest extends SodaScsKernelTestBase {

  /**
   * Tests the component health endpoint without view access.
   */
  public function testComponentHealthWithoutViewAccessLooksLikeNotFound(): void {
    $component = $this->createTestComponent('soda_scs_sql_component', [
      'owner' => $this->testUser->id(),
    ]);
    $this->container->get('current_user')->setAccount($this->createRestrictedUser());

    $controller = SodaScsComponentController::create($this->container);
    $payload = json_decode((string) $controller->componentStatus($component->id())->getContent(), TRUE);

    $this->assertSame('unknown', $payload['status']['status']);
    $this->assertSame('Component not found.', $payload['status']['error']);
  }

  /**
   * Tests the stack health endpoint without view access.
   */
  public function testStackHealthWithoutViewAccessLooksLikeNotFound(): void {
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $this->testUser->id(),
    ]);
    $this->container->get('current_user')->setAccount($this->createRestrictedUser());

    $controller = SodaScsStackController::create($this->container);
    $payload = json_decode((string) $controller->stackStatus($stack->id())->getContent(), TRUE);

    $this->assertSame('unknown', $payload['status']['status']);
    $this->assertSame('Stack not found.', $payload['status']['error']);
  }

  /**
   * Creates a user who may open the manager but not view the entities.
   */
  private function createRestrictedUser() {
    $user = $this->createTestUser();
    $user->addRole('scs_user');
    $user->save();

    // After the user: hook_user_insert() grants scs_user its defaults again.
    $role = Role::load('scs_user');
    $role->grantPermission('soda scs manager user');
    $role->revokePermission('view soda scs component');
    $role->revokePermission('view soda scs stack');
    $role->save();

    return $user;
  }

}
