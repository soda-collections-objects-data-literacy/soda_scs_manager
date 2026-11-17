<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\User;

use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Service\NextcloudMountCredentialsStore;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\UserInterface;

/**
 * What a login does to the "Keycloak account missing" mark of the mount.
 *
 * Issue 3638812: a login proves the Keycloak account exists again, so
 * hook_user_login() removes the mark and the mount reconcile takes the user
 * back. Keycloak and Nextcloud are doubles.
 *
 * @group soda_scs_manager
 */
class SodaScsUserLoginKeycloakMarkTest extends SodaScsKernelTestBase {

  /**
   * The store under test, from the container.
   */
  private NextcloudMountCredentialsStore $store;

  /**
   * The account that logs in.
   */
  private UserInterface $account;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $keycloakHelpers = $this->createMock(SodaScsKeycloakHelpers::class);
    $keycloakHelpers->method('getKeycloakToken')->willReturn(NULL);
    $keycloakHelpers->method('getKeycloakUser')->willReturn(NULL);
    $this->container->set('soda_scs_manager.keycloak_service.helpers', $keycloakHelpers);
    $this->container->set('soda_scs_manager.nextcloud.helpers', $this->createMock(SodaScsNextcloudHelpers::class));
    $this->store = $this->container->get('soda_scs_manager.nextcloud_mount.credentials');
    $this->account = $this->createTestUser();
  }

  /**
   * {@inheritdoc}
   *
   * Users and their data only.
   */
  protected function installSchemas(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('soda_scs_manager', ['keycloak_user_registration']);
    $this->installEntitySchema('user');
  }

  /**
   * A login removes the mark, so the next reconcile asks Keycloak again.
   */
  public function testLoginClearsKeycloakUserMissingMark(): void {
    $this->store->markKeycloakUserMissing($this->account);
    $this->assertTrue($this->store->isKeycloakUserMarkedMissing($this->account));

    soda_scs_manager_user_login($this->account);

    $this->assertFalse($this->store->isKeycloakUserMarkedMissing($this->account));
    $this->assertNull($this->store->getMountStatus($this->account));
  }

  /**
   * A login leaves another mount status of the user as it is.
   */
  public function testLoginKeepsOtherMountStatus(): void {
    $this->store->setMountStatus($this->account, 'mounted');

    soda_scs_manager_user_login($this->account);

    $this->assertSame('mounted', $this->store->getMountStatus($this->account));
  }

  /**
   * A login of one user does not clear the mark of another user.
   */
  public function testLoginKeepsMarkOfOtherUser(): void {
    $other = $this->createTestUser();
    $this->store->markKeycloakUserMissing($other);
    $this->store->markKeycloakUserMissing($this->account);

    soda_scs_manager_user_login($this->account);

    $this->assertFalse($this->store->isKeycloakUserMarkedMissing($this->account));
    $this->assertTrue($this->store->isKeycloakUserMarkedMissing($other));
  }

}
