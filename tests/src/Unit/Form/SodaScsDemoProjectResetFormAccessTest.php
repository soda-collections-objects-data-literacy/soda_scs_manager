<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Form;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Form\SodaScsDemoProjectResetForm;
use Drupal\Tests\UnitTestCase;

/**
 * Who may open the reset form of a demo project.
 *
 * The demo panel's "Reset demo" link asks this check through the route, so
 * these cases are also the rule for the link.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Form\SodaScsDemoProjectResetForm
 * @group soda_scs_manager
 */
class SodaScsDemoProjectResetFormAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * The owner may reset their demo project.
   *
   * @covers ::access
   */
  public function testOwnerIsAllowed(): void {
    $this->assertTrue($this->access(TRUE, 7, 7, FALSE));
  }

  /**
   * An SCS Manager admin may reset another user's demo project.
   *
   * @covers ::access
   */
  public function testAdminWhoIsNotOwnerIsAllowed(): void {
    $this->assertTrue($this->access(TRUE, 7, 9, TRUE));
  }

  /**
   * A viewer who is neither owner nor admin may not reset.
   *
   * @covers ::access
   */
  public function testOtherUserIsDenied(): void {
    $this->assertFalse($this->access(TRUE, 7, 9, FALSE));
  }

  /**
   * Nobody may reset a project that is not a demo project, not even an admin.
   *
   * @covers ::access
   */
  public function testNonDemoProjectIsDeniedForAdmin(): void {
    $this->assertFalse($this->access(FALSE, 7, 9, TRUE));
  }

  /**
   * Whether access() allows the viewer the reset form.
   *
   * @param bool $isDemoProject
   *   Whether the demo manager treats the project as a demo project.
   * @param int $ownerId
   *   Owner of the project.
   * @param int $viewerId
   *   User ID of the viewer.
   * @param bool $hasAdminPermission
   *   Whether the viewer has the SCS Manager admin permission.
   */
  private function access(bool $isDemoProject, int $ownerId, int $viewerId, bool $hasAdminPermission): bool {
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('isDemoProject')->willReturn($isDemoProject);

    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('getOwnerId')->willReturn($ownerId);
    $project->method('getCacheContexts')->willReturn([]);
    $project->method('getCacheTags')->willReturn([]);
    $project->method('getCacheMaxAge')->willReturn(-1);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($viewerId);
    $account->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => $hasAdminPermission && $permission === 'soda scs manager admin');

    return (new SodaScsDemoProjectResetForm($demoManager))->access($account, $project)->isAllowed();
  }

}
