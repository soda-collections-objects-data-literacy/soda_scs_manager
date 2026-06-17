<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers
 * @group soda_scs_manager
 */
class SodaScsNextcloudHelpersTest extends UnitTestCase {

  /**
   * Builds a helper with a fixed OIDC username prefix.
   */
  private function createHelper(string $prefix = 'keycloak-'): SodaScsNextcloudHelpers {
    $nextcloudActions = $this->createMock(SodaScsNextcloudServiceActions::class);
    $nextcloudActions->method('getOidcUsernamePrefix')->willReturn($prefix);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) {
      return match ($key) {
        'nextcloud.generalSettings.keycloakUsernameAttr' => 'nextcloud_login_name',
        'nextcloud.generalSettings.keycloakAppPasswordAttr' => 'nextcloud_app_password',
        default => NULL,
      };
    });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('soda_scs_manager.settings')->willReturn($config);

    return new SodaScsNextcloudHelpers(
      $nextcloudActions,
      $configFactory,
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(MessengerInterface::class),
      $this->createMock(OpenIDConnectSessionInterface::class),
      $this->createMock(SodaScsKeycloakHelpers::class),
      $this->createMock(SodaScsProjectHelpers::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
    );
  }

  /**
   * @covers ::expectedNextcloudUsernameForKeycloakId
   */
  public function testExpectedNextcloudUsernameForKeycloakId(): void {
    $helper = $this->createHelper();
    $sub = '4e25a3ff-b955-4731-9c56-9e67a858828d';
    $this->assertSame(
      'keycloak-4e25a3ff-b955-4731-9c56-9e67a858828d',
      $helper->expectedNextcloudUsernameForKeycloakId($sub)
    );
  }

  /**
   * @covers ::storedUsernameMatchesKeycloakSub
   */
  public function testStoredUsernameMatchesKeycloakSub(): void {
    $helper = $this->createHelper();
    $sub = '4e25a3ff-b955-4731-9c56-9e67a858828d';

    $this->assertTrue($helper->storedUsernameMatchesKeycloakSub(
      $sub,
      'keycloak-4e25a3ff-b955-4731-9c56-9e67a858828d'
    ));
    $this->assertTrue($helper->storedUsernameMatchesKeycloakSub(
      $sub,
      '4e25a3ff-b955-4731-9c56-9e67a858828d'
    ));
    $this->assertFalse($helper->storedUsernameMatchesKeycloakSub(
      $sub,
      'keycloak-c51612d0-52db-40b4-bc33-5141e8e99d45'
    ));
  }

}
