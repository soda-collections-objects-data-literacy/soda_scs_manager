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
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SodaScsNextcloudHelpers.
 */
#[CoversMethod(SodaScsNextcloudHelpers::class, 'addUserToGroup')]
#[CoversMethod(SodaScsNextcloudHelpers::class, 'expectedNextcloudUsernameForKeycloakId')]
#[CoversMethod(SodaScsNextcloudHelpers::class, 'storedUsernameMatchesKeycloakSub')]
#[CoversMethod(SodaScsNextcloudHelpers::class, 'buildProjectFilesUrl')]
#[CoversMethod(SodaScsNextcloudHelpers::class, 'isKeycloakUserMissing')]
#[CoversMethod(SodaScsNextcloudHelpers::class, 'canEnsureCredentials')]
#[CoversMethod(SodaScsNextcloudHelpers::class, 'ensureAccountViaOcc')]
#[Group('soda_scs_manager')]
class SodaScsNextcloudHelpersTest extends UnitTestCase {

  /**
   * Builds a helper with a fixed OIDC username prefix.
   */
  private function createHelper(
    string $prefix = 'keycloak-',
    ?SodaScsNextcloudServiceActions $nextcloudActions = NULL,
    ?SodaScsProjectHelpers $projectHelpers = NULL,
    ?SodaScsKeycloakHelpers $keycloakHelpers = NULL,
    ?AccountProxyInterface $currentUser = NULL,
    ?OpenIDConnectSessionInterface $openIdConnectSession = NULL,
    ?SodaScsContainerHelpers $containerHelpers = NULL,
    ?NextcloudMountCredentialsStore $credentialsStore = NULL,
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
      $currentUser ?? $this->createMock(AccountProxyInterface::class),
      $this->createMock(MessengerInterface::class),
      $openIdConnectSession ?? $this->createMock(OpenIDConnectSessionInterface::class),
      $keycloakHelpers ?? $this->createMock(SodaScsKeycloakHelpers::class),
      $projectHelpers ?? $this->createMock(SodaScsProjectHelpers::class),
      $loggerFactory,
      $containerHelpers ?? $this->createMock(SodaScsContainerHelpers::class),
      $credentialsStore ?? $this->createMock(NextcloudMountCredentialsStore::class),
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
   * Tests that addUserToGroup() follows OCS status.
   */
  #[DataProvider('groupStatusProvider')]
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
   * Tests that addUserToGroup() skips users without SSO.
   */
  public function testAddUserToGroupSkipsUsersWithoutSso(): void {
    $actions = $this->createMock(SodaScsNextcloudServiceActions::class);
    $actions->expects($this->never())->method('makeRequest');
    $helper = $this->createHelper('', $actions, $this->createProjectHelpers(NULL));
    $this->assertFalse($helper->addUserToGroup('10069', $this->createMock(UserInterface::class)));
  }

  /**
   * Tests expectedNextcloudUsernameForKeycloakId().
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
   * Tests storedUsernameMatchesKeycloakSub().
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
   * Tests buildProjectFilesUrl().
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

  /**
   * A user whose Keycloak account answers "not found" is missing.
   */
  public function testIsKeycloakUserMissingAsksKeycloakWithTheSsoUuid(): void {
    $keycloakHelpers = $this->createMock(SodaScsKeycloakHelpers::class);
    $keycloakHelpers->expects($this->once())->method('isKeycloakUserMissing')->with('sub-1')->willReturn(TRUE);
    $helper = $this->createHelper('', NULL, $this->createProjectHelpers('sub-1'), $keycloakHelpers);

    $this->assertTrue($helper->isKeycloakUserMissing($this->createMock(UserInterface::class)));
  }

  /**
   * Keycloak that still knows the user means the user is not missing.
   */
  public function testIsKeycloakUserMissingIsFalseWhenKeycloakKnowsTheUser(): void {
    $keycloakHelpers = $this->createMock(SodaScsKeycloakHelpers::class);
    $keycloakHelpers->method('isKeycloakUserMissing')->willReturn(FALSE);
    $helper = $this->createHelper('', NULL, $this->createProjectHelpers('sub-1'), $keycloakHelpers);

    $this->assertFalse($helper->isKeycloakUserMissing($this->createMock(UserInterface::class)));
  }

  /**
   * Without an SSO link nothing is asked and the user is not called missing.
   */
  #[DataProvider('provideNoSsoUuid')]
  public function testIsKeycloakUserMissingIsFalseWithoutSsoLink(?string $ssoUuid): void {
    $keycloakHelpers = $this->createMock(SodaScsKeycloakHelpers::class);
    $keycloakHelpers->expects($this->never())->method('isKeycloakUserMissing');
    $helper = $this->createHelper('', NULL, $this->createProjectHelpers($ssoUuid), $keycloakHelpers);

    $this->assertFalse($helper->isKeycloakUserMissing($this->createMock(UserInterface::class)));
  }

  /**
   * SSO UUIDs that count as no link.
   *
   * @return array<string, array{0: string|null}>
   *   The values.
   */
  public static function provideNoSsoUuid(): array {
    return [
      'null' => [NULL],
      'empty string' => [''],
    ];
  }

  /**
   * Only Flow B with the owner's own token counts; nothing is requested.
   *
   * The owner has no stored credentials (no SSO link), so the result depends
   * on the Bearer setting, the current user and the session token alone.
   */
  #[DataProvider('provideBearerSituations')]
  public function testCanEnsureCredentialsCreatesNothing(bool $useBearer, int $currentUserId, ?string $token, bool $expected): void {
    $nextcloudActions = $this->createMock(SodaScsNextcloudServiceActions::class);
    $nextcloudActions->method('getUseBearerToken')->willReturn($useBearer);
    $nextcloudActions->expects($this->never())->method('makeRequest');

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('id')->willReturn($currentUserId);
    $session = $this->createMock(OpenIDConnectSessionInterface::class);
    $session->method('retrieveAccessToken')->willReturn($token);

    $owner = $this->createMock(UserInterface::class);
    $owner->method('id')->willReturn(5);

    $helper = $this->createHelper(
      nextcloudActions: $nextcloudActions,
      projectHelpers: $this->createProjectHelpers(NULL),
      currentUser: $currentUser,
      openIdConnectSession: $session,
    );

    $this->assertSame($expected, $helper->canEnsureCredentials($owner));
  }

  /**
   * Bearer setting, current user ID, session token, expected result.
   */
  public static function provideBearerSituations(): array {
    return [
      'Bearer disabled' => [FALSE, 5, 'token', FALSE],
      'another user' => [TRUE, 6, 'token', FALSE],
      'no token' => [TRUE, 5, NULL, FALSE],
      'own token' => [TRUE, 5, 'token', TRUE],
    ];
  }

  /**
   * The Nextcloud account of a service account is created with its password.
   *
   * Issue 3640913. The occ exec in the Nextcloud container is a double.
   */
  public function testEnsureAccountViaOccCreatesMissingAccountAndStoresAppPassword(): void {
    $calls = [];
    $containerHelpers = $this->occHelpers($calls, [
      'user:list' => SodaScsResult::success(['output' => '{"other-id":"Other"}'], 'ok'),
      'user:add' => SodaScsResult::success(['output' => 'The account "sub-1" was created successfully'], 'ok'),
      'user:add-app-password' => SodaScsResult::success(['output' => "app password:\n  mount-secret"], 'ok'),
    ]);
    $credentialsStore = $this->createMock(NextcloudMountCredentialsStore::class);
    $user = $this->createMock(UserInterface::class);
    $user->method('getAccountName')->willReturn('scs_demo');
    $credentialsStore->expects($this->once())->method('setAppPassword')->with($user, 'mount-secret');
    $keycloakHelpers = $this->createMock(SodaScsKeycloakHelpers::class);
    $keycloakHelpers->expects($this->once())->method('setKeycloakUserAttributes')
      ->with('sub-1', ['nextcloud_login_name' => ['sub-1']])->willReturn(TRUE);
    $helper = $this->createHelper('', NULL, $this->createProjectHelpers('sub-1'), $keycloakHelpers, NULL, NULL, $containerHelpers, $credentialsStore);

    $this->assertTrue($helper->ensureAccountViaOcc($user, 'login-secret'));

    $this->assertSame(['user:list', 'user:add', 'user:add-app-password'], array_column($calls, 'command'));
    $add = $calls[1]['request'];
    $this->assertSame('www-data', $add['user']);
    $this->assertSame(
      [
        'php',
        '/var/www/html/occ',
        'user:add',
        '--password-from-env',
        '--display-name=scs_demo',
        'sub-1',
        '-n',
        '--no-warnings',
      ],
      $add['cmd'],
    );
    $this->assertSame(['OC_PASS=login-secret'], $add['env']);
    $this->assertStringNotContainsString('login-secret', implode(' ', $add['cmd']));
    $this->assertSame('sub-1', $calls[2]['request']['cmd'][3]);
  }

  /**
   * An account that exists is not created again.
   */
  public function testEnsureAccountViaOccKeepsExistingAccount(): void {
    $calls = [];
    $containerHelpers = $this->occHelpers($calls, [
      'user:list' => SodaScsResult::success(['output' => '{"sub-1":"scs_demo"}'], 'ok'),
      'user:add-app-password' => SodaScsResult::success(['output' => 'app password: mount-secret'], 'ok'),
    ]);
    $keycloakHelpers = $this->createMock(SodaScsKeycloakHelpers::class);
    $keycloakHelpers->method('setKeycloakUserAttributes')->willReturn(TRUE);
    $helper = $this->createHelper('', NULL, $this->createProjectHelpers('sub-1'), $keycloakHelpers, NULL, NULL, $containerHelpers);

    $this->assertTrue($helper->ensureAccountViaOcc($this->createMock(UserInterface::class), 'login-secret'));

    $this->assertSame(['user:list', 'user:add-app-password'], array_column($calls, 'command'));
  }

  /**
   * Stored mount credentials are kept: no second app password is created.
   */
  public function testEnsureAccountViaOccKeepsStoredMountCredentials(): void {
    $calls = [];
    $containerHelpers = $this->occHelpers($calls, [
      'user:list' => SodaScsResult::success(['output' => '{"sub-1":"scs_demo"}'], 'ok'),
    ]);
    $keycloakHelpers = $this->createMock(SodaScsKeycloakHelpers::class);
    $keycloakHelpers->method('getKeycloakUserAttributes')->willReturn(['nextcloud_login_name' => ['sub-1']]);
    $keycloakHelpers->expects($this->never())->method('setKeycloakUserAttributes');
    $credentialsStore = $this->createMock(NextcloudMountCredentialsStore::class);
    $credentialsStore->method('getAppPassword')->willReturn('mount-secret');
    $credentialsStore->expects($this->never())->method('setAppPassword');
    $helper = $this->createHelper('', NULL, $this->createProjectHelpers('sub-1'), $keycloakHelpers, NULL, NULL, $containerHelpers, $credentialsStore);

    $this->assertTrue($helper->ensureAccountViaOcc($this->createMock(UserInterface::class), 'login-secret'));

    $this->assertSame(['user:list'], array_column($calls, 'command'));
  }

  /**
   * The occ user is the Keycloak sub without the OIDC username prefix.
   */
  public function testEnsureAccountViaOccStripsTheOidcPrefixOfTheSub(): void {
    $sub = '4e25a3ff-b955-4731-9c56-9e67a858828d';
    $calls = [];
    $containerHelpers = $this->occHelpers($calls, [
      'user:list' => SodaScsResult::success(['output' => '[]'], 'ok'),
      'user:add' => SodaScsResult::success(['output' => 'ok'], 'ok'),
      'user:add-app-password' => SodaScsResult::success(['output' => 'app password: mount-secret'], 'ok'),
    ]);
    $keycloakHelpers = $this->createMock(SodaScsKeycloakHelpers::class);
    $keycloakHelpers->method('setKeycloakUserAttributes')->willReturn(TRUE);
    $helper = $this->createHelper('keycloak-', NULL, $this->createProjectHelpers('keycloak-' . $sub), $keycloakHelpers, NULL, NULL, $containerHelpers);

    $this->assertTrue($helper->ensureAccountViaOcc($this->createMock(UserInterface::class), 'login-secret'));

    $this->assertContains($sub, $calls[1]['request']['cmd']);
  }

  /**
   * A failing or unreadable user list creates nothing.
   */
  #[DataProvider('unreadableUserListProvider')]
  public function testEnsureAccountViaOccCreatesNothingWhenUserListFails(SodaScsResult $listAnswer): void {
    $calls = [];
    $containerHelpers = $this->occHelpers($calls, ['user:list' => $listAnswer]);
    $credentialsStore = $this->createMock(NextcloudMountCredentialsStore::class);
    $credentialsStore->expects($this->never())->method('setAppPassword');
    $helper = $this->createHelper('', NULL, $this->createProjectHelpers('sub-1'), NULL, NULL, NULL, $containerHelpers, $credentialsStore);

    $this->assertFalse($helper->ensureAccountViaOcc($this->createMock(UserInterface::class), 'login-secret'));

    $this->assertSame(['user:list'], array_column($calls, 'command'));
  }

  /**
   * Answers of the user list that the helper cannot read.
   *
   * @return array<string, array{0: \Drupal\soda_scs_manager\ValueObject\SodaScsResult}>
   *   The data sets, by the way the answer is unusable.
   */
  public static function unreadableUserListProvider(): array {
    return [
      'exec failed' => [new SodaScsResult(FALSE, NULL, 'container is not running', 'failed')],
      'not json' => [SodaScsResult::success(['output' => 'Nextcloud is in maintenance mode'], 'ok')],
      'empty output' => [SodaScsResult::success(['output' => ''], 'ok')],
    ];
  }

  /**
   * A failing account creation stops before any app password exists.
   */
  public function testEnsureAccountViaOccStopsWhenUserAddFails(): void {
    $calls = [];
    $containerHelpers = $this->occHelpers($calls, [
      'user:list' => SodaScsResult::success(['output' => '[]'], 'ok'),
      'user:add' => new SodaScsResult(FALSE, NULL, 'password rejected', 'failed'),
    ]);
    $credentialsStore = $this->createMock(NextcloudMountCredentialsStore::class);
    $credentialsStore->expects($this->never())->method('setAppPassword');
    $helper = $this->createHelper('', NULL, $this->createProjectHelpers('sub-1'), NULL, NULL, NULL, $containerHelpers, $credentialsStore);

    $this->assertFalse($helper->ensureAccountViaOcc($this->createMock(UserInterface::class), 'login-secret'));

    $this->assertSame(['user:list', 'user:add'], array_column($calls, 'command'));
  }

  /**
   * An app password that occ does not print is a failure, nothing is stored.
   */
  public function testEnsureAccountViaOccFailsWhenAppPasswordIsMissing(): void {
    $calls = [];
    $containerHelpers = $this->occHelpers($calls, [
      'user:list' => SodaScsResult::success(['output' => '{"sub-1":"scs_demo"}'], 'ok'),
      'user:add-app-password' => SodaScsResult::success(['output' => 'Something else'], 'ok'),
    ]);
    $credentialsStore = $this->createMock(NextcloudMountCredentialsStore::class);
    $credentialsStore->expects($this->never())->method('setAppPassword');
    $helper = $this->createHelper('', NULL, $this->createProjectHelpers('sub-1'), NULL, NULL, NULL, $containerHelpers, $credentialsStore);

    $this->assertFalse($helper->ensureAccountViaOcc($this->createMock(UserInterface::class), 'login-secret'));
  }

  /**
   * Without a Keycloak link or without a password nothing runs in Nextcloud.
   */
  public function testEnsureAccountViaOccNeedsSsoLinkAndPassword(): void {
    $containerHelpers = $this->createMock(SodaScsContainerHelpers::class);
    $containerHelpers->expects($this->never())->method('executeDockerExecCommand');

    $withoutLink = $this->createHelper('', NULL, $this->createProjectHelpers(NULL), NULL, NULL, NULL, $containerHelpers);
    $this->assertFalse($withoutLink->ensureAccountViaOcc($this->createMock(UserInterface::class), 'login-secret'));

    $withoutPassword = $this->createHelper('', NULL, $this->createProjectHelpers('sub-1'), NULL, NULL, NULL, $containerHelpers);
    $this->assertFalse($withoutPassword->ensureAccountViaOcc($this->createMock(UserInterface::class), ''));
  }

  /**
   * Container helpers that answer occ commands by name and record them.
   *
   * @param array<int, array{command: string, request: array<string, mixed>}> $calls
   *   Filled with the commands that were executed, in order.
   * @param array<string, \Drupal\soda_scs_manager\ValueObject\SodaScsResult> $answers
   *   The answers by occ command; a missing command fails the test.
   */
  private function occHelpers(array &$calls, array $answers): SodaScsContainerHelpers {
    $containerHelpers = $this->createMock(SodaScsContainerHelpers::class);
    $containerHelpers->method('executeDockerExecCommand')->willReturnCallback(function (array $request) use (&$calls, $answers): SodaScsResult {
      $this->assertSame(['php', '/var/www/html/occ'], array_slice($request['cmd'], 0, 2));
      $command = $request['cmd'][2];
      $calls[] = ['command' => $command, 'request' => $request];
      $this->assertArrayHasKey($command, $answers, "Unexpected occ command $command.");
      return $answers[$command];
    });
    return $containerHelpers;
  }

}
