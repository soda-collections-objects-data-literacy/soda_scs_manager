<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\soda_scs_manager\Demo\SodaScsAutomaticDemoProject;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsAutomaticDemoProject
 * @group soda_scs_manager
 */
class SodaScsAutomaticDemoProjectTest extends UnitTestCase {

  /**
   * Accounts without the platform role are left alone.
   *
   * @covers ::ensureFor
   */
  public function testSkipsAccountsWithoutThePlatformRole(): void {
    $account = $this->createMock(UserInterface::class);
    $account->method('hasRole')->with(SodaScsAutomaticDemoProject::PLATFORM_ROLE)->willReturn(FALSE);

    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->expects($this->never())->method('requestDemoProject');

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $this->createMock(LoggerInterface::class));
    $this->assertFalse($ensurer->ensureFor($account));
  }

  /**
   * An approved platform user gets a demo queued.
   *
   * @covers ::ensureFor
   */
  public function testQueuesDemoForPlatformUser(): void {
    $account = $this->platformAccount();
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->expects($this->once())
      ->method('requestDemoProject')
      ->with($account)
      ->willReturn(SodaScsResult::success([], 'queued'));

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $this->createMock(LoggerInterface::class));
    $this->assertTrue($ensurer->ensureFor($account));
  }

  /**
   * No active demo version is not a sign-in failure.
   *
   * @covers ::ensureFor
   */
  public function testMissingTemplateDoesNotThrow(): void {
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('requestDemoProject')->willReturn(SodaScsResult::failure(
      error: 'No active demo template.',
      message: 'No demo is available at the moment.',
      log: FALSE,
    ));

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $this->createMock(LoggerInterface::class));
    $this->assertFalse($ensurer->ensureFor($this->platformAccount()));
  }

  /**
   * A provisioning error is logged and does not escape sign-in.
   *
   * @covers ::ensureFor
   */
  public function testProvisioningErrorIsLogged(): void {
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('requestDemoProject')->willThrowException(new \RuntimeException('keycloak down'));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $logger);
    $this->assertFalse($ensurer->ensureFor($this->platformAccount()));
  }

  /**
   * A user who already has the platform role.
   */
  protected function platformAccount(): UserInterface {
    $account = $this->createMock(UserInterface::class);
    $account->method('hasRole')->with(SodaScsAutomaticDemoProject::PLATFORM_ROLE)->willReturn(TRUE);
    $account->method('id')->willReturn('7');
    return $account;
  }

}
