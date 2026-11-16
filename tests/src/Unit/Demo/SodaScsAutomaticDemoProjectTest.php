<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\soda_scs_manager\Demo\SodaScsAutomaticDemoProject;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\MockObject\MockObject;
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

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $this->createMock(LoggerInterface::class), $this->nextcloudHelpers());
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

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $this->createMock(LoggerInterface::class), $this->nextcloudHelpers());
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

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $this->createMock(LoggerInterface::class), $this->nextcloudHelpers());
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

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $logger, $this->nextcloudHelpers());
    $this->assertFalse($ensurer->ensureFor($this->platformAccount()));
  }

  /**
   * Credentials are ensured before the demo is requested.
   *
   * @covers ::ensureFor
   * @covers ::prepareNextcloudCredentials
   */
  public function testCredentialsAreEnsuredBeforeTheDemoIsRequested(): void {
    $account = $this->platformAccount();
    $calls = [];
    $nextcloudHelpers = $this->nextcloudHelpers(TRUE);
    $nextcloudHelpers->expects($this->once())
      ->method('ensureCredentials')
      ->with($account)
      ->willReturnCallback(function () use (&$calls): array {
        $calls[] = 'ensureCredentials';
        return ['username' => 'u', 'password' => 'p'];
      });
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $this->stubDemoToBeQueued($demoManager);
    $demoManager->expects($this->once())
      ->method('requestDemoProject')
      ->willReturnCallback(function () use (&$calls): SodaScsResult {
        $calls[] = 'requestDemoProject';
        return SodaScsResult::success([], 'queued');
      });
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $logger, $nextcloudHelpers);
    $this->assertTrue($ensurer->ensureFor($account));
    $this->assertSame(['ensureCredentials', 'requestDemoProject'], $calls);
  }

  /**
   * An exception while ensuring credentials is logged; the demo still starts.
   *
   * @covers ::prepareNextcloudCredentials
   */
  public function testCredentialExceptionIsLoggedAndDemoIsStillRequested(): void {
    $nextcloudHelpers = $this->nextcloudHelpers(TRUE);
    $nextcloudHelpers->method('ensureCredentials')->willThrowException(new \RuntimeException('nextcloud down'));
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $this->stubDemoToBeQueued($demoManager);
    $demoManager->expects($this->once())
      ->method('requestDemoProject')
      ->willReturn(SodaScsResult::success([], 'queued'));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Could not prepare the Nextcloud credentials'),
        ['@uid' => '7', '@message' => 'nextcloud down'],
      );

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $logger, $nextcloudHelpers);
    $this->assertTrue($ensurer->ensureFor($this->platformAccount()));
  }

  /**
   * No obtainable credentials are logged; the demo still starts.
   *
   * @covers ::prepareNextcloudCredentials
   */
  public function testMissingCredentialsAreLoggedAndDemoIsStillRequested(): void {
    $nextcloudHelpers = $this->nextcloudHelpers(TRUE);
    $nextcloudHelpers->method('ensureCredentials')->willReturn(NULL);
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $this->stubDemoToBeQueued($demoManager);
    $demoManager->expects($this->once())
      ->method('requestDemoProject')
      ->willReturn(SodaScsResult::success([], 'queued'));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('No Nextcloud credentials'), ['@uid' => '7']);

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $logger, $nextcloudHelpers);
    $this->assertTrue($ensurer->ensureFor($this->platformAccount()));
  }

  /**
   * Without Bearer the Login Flow is the only source, so nothing is ensured.
   *
   * @covers ::prepareNextcloudCredentials
   */
  public function testCredentialsAreSkippedWithoutBearer(): void {
    $nextcloudHelpers = $this->nextcloudHelpers(FALSE);
    $nextcloudHelpers->expects($this->never())->method('ensureCredentials');
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $this->stubDemoToBeQueued($demoManager);
    $demoManager->expects($this->once())
      ->method('requestDemoProject')
      ->willReturn(SodaScsResult::success([], 'queued'));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $logger, $nextcloudHelpers);
    $this->assertTrue($ensurer->ensureFor($this->platformAccount()));
  }

  /**
   * Accounts without the platform role get no credentials either.
   *
   * @covers ::ensureFor
   */
  public function testNoCredentialsForAccountsWithoutThePlatformRole(): void {
    $account = $this->createMock(UserInterface::class);
    $account->method('hasRole')->willReturn(FALSE);
    $nextcloudHelpers = $this->nextcloudHelpers(TRUE);
    $nextcloudHelpers->expects($this->never())->method('ensureCredentials');

    $ensurer = new SodaScsAutomaticDemoProject($this->createMock(SodaScsDemoManager::class), $this->createMock(LoggerInterface::class), $nextcloudHelpers);
    $this->assertFalse($ensurer->ensureFor($account));
  }

  /**
   * A user who already has a demo project costs no Nextcloud call.
   *
   * @covers ::ensureFor
   */
  public function testNoCredentialsForUserWithDemoProject(): void {
    $nextcloudHelpers = $this->nextcloudHelpers(TRUE);
    $nextcloudHelpers->expects($this->never())->method('ensureCredentials');
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('activeTemplate')->willReturn($this->createMock(SodaScsDemoTemplateInterface::class));
    $demoManager->method('findDemoProject')->willReturn($this->createMock(SodaScsProjectInterface::class));
    $demoManager->expects($this->once())
      ->method('requestDemoProject')
      ->willReturn(SodaScsResult::failure(error: 'exists', message: 'exists', log: FALSE));

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $this->createMock(LoggerInterface::class), $nextcloudHelpers);
    $this->assertFalse($ensurer->ensureFor($this->platformAccount()));
  }

  /**
   * Without an active demo version no Nextcloud call is made.
   *
   * @covers ::ensureFor
   */
  public function testNoCredentialsWithoutActiveTemplate(): void {
    $nextcloudHelpers = $this->nextcloudHelpers(TRUE);
    $nextcloudHelpers->expects($this->never())->method('ensureCredentials');
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('activeTemplate')->willReturn(NULL);
    $demoManager->method('findDemoProject')->willReturn(NULL);
    $demoManager->expects($this->once())
      ->method('requestDemoProject')
      ->willReturn(SodaScsResult::failure(error: 'No active demo template.', message: 'none', log: FALSE));

    $ensurer = new SodaScsAutomaticDemoProject($demoManager, $this->createMock(LoggerInterface::class), $nextcloudHelpers);
    $this->assertFalse($ensurer->ensureFor($this->platformAccount()));
  }

  /**
   * Nextcloud helpers with the Bearer switch set.
   *
   * Without an explicit value Bearer is off, so the older tests never call
   * ensureCredentials().
   */
  protected function nextcloudHelpers(bool $isBearerEnabled = FALSE): SodaScsNextcloudHelpers&MockObject {
    $nextcloudHelpers = $this->createMock(SodaScsNextcloudHelpers::class);
    $nextcloudHelpers->method('isBearerEnabled')->willReturn($isBearerEnabled);
    return $nextcloudHelpers;
  }

  /**
   * Makes the manager report an active template and no demo project yet.
   */
  protected function stubDemoToBeQueued(SodaScsDemoManager&MockObject $demoManager): void {
    $demoManager->method('activeTemplate')->willReturn($this->createMock(SodaScsDemoTemplateInterface::class));
    $demoManager->method('findDemoProject')->willReturn(NULL);
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
