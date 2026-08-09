<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Service\NextcloudMountCredentialsStore;
use Drupal\soda_scs_manager\Service\NextcloudMounterClient;
use Drupal\soda_scs_manager\Service\NextcloudMountManager;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Service\NextcloudMountManager
 * @group soda_scs_manager
 */
class NextcloudMountManagerMachineNameTest extends UnitTestCase {

  /**
   * @covers ::machineNameForUser
   * @dataProvider machineNameProvider
   */
  public function testMachineNameForUser(string $accountName, int $uid, string $expected): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getAccountName')->willReturn($accountName);
    $account->method('id')->willReturn($uid);

    $manager = new NextcloudMountManager(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(NextcloudMounterClient::class),
      $this->createMock(NextcloudMountCredentialsStore::class),
      $this->createMock(SodaScsNextcloudHelpers::class),
      $this->createMock(SodaScsServiceHelpers::class),
      $this->createMock(LoggerInterface::class),
    );

    $this->assertSame($expected, $manager->machineNameForUser($account));
  }

  /**
   * @return array<string, array{0: string, 1: int, 2: string}>
   */
  public static function machineNameProvider(): array {
    return [
      'simple' => ['rnsrk', 2, 'rnsrk'],
      'with hyphen' => ['julia-anbr', 5, 'julia-anbr'],
      'uppercase folded' => ['Alice', 9, 'alice'],
      'invalid chars fallback' => ['Alice Müller', 12, 'u12'],
      'leading underscore invalid' => ['_hidden', 3, 'u3'],
    ];
  }

}
