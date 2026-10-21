<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions;
use Drupal\soda_scs_manager\Service\NextcloudMountCredentialsStore;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use GuzzleHttp\Psr7\Response;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers
 * @group soda_scs_manager
 */
class SodaScsNextcloudHelpersTest extends UnitTestCase {

  /**
   * Builds a helper with a fixed OIDC username prefix.
   */
  private function createHelper(
    string $prefix = 'keycloak-',
    ?SodaScsNextcloudServiceActions $nextcloudActions = NULL,
    ?SodaScsProjectHelpers $projectHelpers = NULL,
  ): SodaScsNextcloudHelpers {
    $nextcloudActions ??= $this->createMock(SodaScsNextcloudServiceActions::class);
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

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    return new SodaScsNextcloudHelpers(
      $nextcloudActions,
      $configFactory,
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(MessengerInterface::class),
      $this->createMock(OpenIDConnectSessionInterface::class),
      $this->createMock(SodaScsKeycloakHelpers::class),
      $projectHelpers ?? $this->createMock(SodaScsProjectHelpers::class),
      $loggerFactory,
      $this->createMock(SodaScsContainerHelpers::class),
      $this->createMock(NextcloudMountCredentialsStore::class),
    );
  }

  /**
   * Builds Nextcloud actions whose group request answers an OCS status.
   */
  private function createGroupActions(int $ocsStatus): SodaScsNextcloudServiceActions {
    $actions = $this->createMock(SodaScsNextcloudServiceActions::class);
    $actions->method('buildAddUserToGroupRequest')
      ->with(['groupId' => '10069', 'userId' => 'sub-1'])
      ->willReturn(['success' => TRUE, 'method' => 'POST', 'route' => 'https://drive.test', 'headers' => []]);
    $actions->method('makeRequest')->willReturn([
      'success' => TRUE,
      'data' => [
        'nextcloudResponse' => new Response(200, [], json_encode([
          'ocs' => ['meta' => ['statuscode' => $ocsStatus], 'data' => []],
        ])),
      ],
    ]);
    return $actions;
  }

  /**
   * Builds project helpers that resolve the given SSO UUID.
   */
  private function createProjectHelpers(?string $ssoUuid): SodaScsProjectHelpers {
    $projectHelpers = $this->createMock(SodaScsProjectHelpers::class);
    $projectHelpers->method('getUserSsoUuid')->willReturn($ssoUuid);
    return $projectHelpers;
  }

  /**
   * @covers ::addUserToGroup
   * @dataProvider groupStatusProvider
   */
  public function testAddUserToGroupFollowsOcsStatus(int $ocsStatus, bool $expected): void {
    $helper = $this->createHelper('', $this->createGroupActions($ocsStatus), $this->createProjectHelpers('sub-1'));
    $this->assertSame($expected, $helper->addUserToGroup('10069', $this->createMock(UserInterface::class)));
  }

  /**
   * @return array<string, array{0: int, 1: bool}>
   */
  public static function groupStatusProvider(): array {
    return [
      'added' => [100, TRUE],
      'no Drive account yet' => [103, FALSE],
      'unknown group' => [102, FALSE],
    ];
  }

  /**
   * @covers ::addUserToGroup
   */
  public function testAddUserToGroupSkipsUsersWithoutSso(): void {
    $actions = $this->createMock(SodaScsNextcloudServiceActions::class);
    $actions->expects($this->never())->method('makeRequest');
    $helper = $this->createHelper('', $actions, $this->createProjectHelpers(NULL));
    $this->assertFalse($helper->addUserToGroup('10069', $this->createMock(UserInterface::class)));
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

  /**
   * @covers ::buildProjectFilesUrl
   */
  public function testBuildProjectFilesUrl(): void {
    $this->assertSame(
      'https://drive.dev-scs.sammlungen.io/apps/files/files?dir=/rnsrk%20default%20project',
      SodaScsNextcloudHelpers::buildProjectFilesUrl(
        'https://drive.dev-scs.sammlungen.io/',
        'rnsrk default project',
      ),
    );
  }

}
