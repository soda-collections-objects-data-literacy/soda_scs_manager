<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\externalauth\AuthmapInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoServiceAccount;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceUserActions;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The service account scs_demo in Drupal, Keycloak and Nextcloud.
 *
 * Issue 3640913. Keycloak, Nextcloud and the user storage are doubles.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsDemoServiceAccount
 * @group soda_scs_manager
 */
class SodaScsDemoServiceAccountTest extends UnitTestCase {

  private const KEYCLOAK_ID = '4e25a3ff-b955-4731-9c56-9e67a858828d';

  /**
   * The user storage double.
   */
  private EntityStorageInterface&MockObject $userStorage;

  /**
   * The Keycloak helpers double.
   */
  private SodaScsKeycloakHelpers&MockObject $keycloakHelpers;

  /**
   * The Keycloak user actions double.
   */
  private SodaScsKeycloakServiceUserActions&MockObject $keycloakUserActions;

  /**
   * The Nextcloud helpers double.
   */
  private SodaScsNextcloudHelpers&MockObject $nextcloudHelpers;

  /**
   * The project helpers double.
   */
  private SodaScsProjectHelpers&MockObject $projectHelpers;

  /**
   * The authmap double.
   */
  private AuthmapInterface&MockObject $authmap;

  /**
   * The service helpers double.
   */
  private SodaScsServiceHelpers&MockObject $serviceHelpers;

  /**
   * The Drupal account of the test.
   */
  private UserInterface&MockObject $account;

  /**
   * The logger double.
   */
  private LoggerChannelInterface&MockObject $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV);
    putenv(SodaScsDemoServiceAccount::EMAIL_ENV);

    // Failure results log through \Drupal::logger().
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->account = $this->createMock(UserInterface::class);
    $this->account->method('getAccountName')->willReturn('scs_demo');
    // @phpstan-ignore drupal.entityStoragePropertyAssignment (a double the test configures per case, not an injected service)
    $this->userStorage = $this->createMock(EntityStorageInterface::class);
    $this->keycloakHelpers = $this->createMock(SodaScsKeycloakHelpers::class);
    $this->keycloakUserActions = $this->createMock(SodaScsKeycloakServiceUserActions::class);
    $this->nextcloudHelpers = $this->createMock(SodaScsNextcloudHelpers::class);
    $this->projectHelpers = $this->createMock(SodaScsProjectHelpers::class);
    $this->authmap = $this->createMock(AuthmapInterface::class);
    $this->serviceHelpers = $this->createMock(SodaScsServiceHelpers::class);
    $this->serviceHelpers->method('initKeycloakGeneralSettings')->willReturn([
      'openIdConnectClientMachineName' => 'keycloak',
      'realm' => 'scs',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV);
    putenv(SodaScsDemoServiceAccount::EMAIL_ENV);
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * The Drupal account is looked up by its name.
   *
   * @covers ::account
   */
  public function testAccountIsLoadedByName(): void {
    $this->userStorage->expects($this->once())->method('loadByProperties')->with(['name' => 'scs_demo'])->willReturn([4 => $this->account]);

    $this->assertSame($this->account, $this->service()->account());
  }

  /**
   * Without an account the lookup returns NULL.
   *
   * @covers ::account
   */
  public function testAccountIsNullWhenMissing(): void {
    $this->userStorage->method('loadByProperties')->willReturn([]);

    $this->assertNull($this->service()->account());
  }

  /**
   * The update hook path creates the account without calling any service.
   *
   * @covers ::ensureDrupalAccount
   */
  public function testEnsureDrupalAccountCreatesAccountWithoutExternalCalls(): void {
    $this->userStorage->method('loadByProperties')->willReturn([]);
    $this->userStorage->expects($this->once())->method('create')->with([
      'mail' => '',
      'name' => 'scs_demo',
      'pass' => 'generated-password',
      'status' => 1,
    ])->willReturn($this->account);
    $this->account->expects($this->once())->method('save');
    $passwordGenerator = $this->createMock(PasswordGeneratorInterface::class);
    $passwordGenerator->method('generate')->with(32)->willReturn('generated-password');
    $this->keycloakHelpers->expects($this->never())->method('getKeycloakToken');
    $this->nextcloudHelpers->expects($this->never())->method('ensureAccountViaOcc');

    $this->assertSame($this->account, $this->service($passwordGenerator)->ensureDrupalAccount());
  }

  /**
   * The password and e-mail of the environment are used for a new account.
   *
   * @covers ::ensureDrupalAccount
   */
  public function testEnsureDrupalAccountUsesEnvironment(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    putenv(SodaScsDemoServiceAccount::EMAIL_ENV . '=demo@example.com');
    $this->userStorage->method('loadByProperties')->willReturn([]);
    $this->userStorage->expects($this->once())->method('create')->with([
      'mail' => 'demo@example.com',
      'name' => 'scs_demo',
      'pass' => 'env-password',
      'status' => 1,
    ])->willReturn($this->account);

    $this->service()->ensureDrupalAccount();
  }

  /**
   * An existing account is returned as it is, nothing is created.
   *
   * @covers ::ensureDrupalAccount
   */
  public function testEnsureDrupalAccountKeepsExistingAccount(): void {
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->userStorage->expects($this->never())->method('create');
    $this->account->expects($this->never())->method('save');

    $this->assertSame($this->account, $this->service()->ensureDrupalAccount());
  }

  /**
   * Without the password and without a completed account, ensure() fails.
   *
   * @covers ::ensure
   */
  public function testEnsureFailsWithoutPasswordForUnpreparedAccount(): void {
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->projectHelpers->method('getUserSsoUuid')->willReturn(NULL);
    $this->keycloakHelpers->expects($this->never())->method('getKeycloakToken');
    $this->userStorage->expects($this->never())->method('create');

    $result = $this->service()->ensure();

    $this->assertFalse($result->success);
    $this->assertStringContainsString(SodaScsDemoServiceAccount::PASSWORD_ENV, (string) $result->error);
  }

  /**
   * Without the password a completed account is accepted as it is.
   *
   * The job runner may start without the variable.
   *
   * @covers ::ensure
   */
  public function testEnsureAcceptsCompletedAccountWithoutPassword(): void {
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->projectHelpers->method('getUserSsoUuid')->willReturn(self::KEYCLOAK_ID);
    $this->nextcloudHelpers->method('getMountCredentials')->willReturn(['username' => 'x', 'appPassword' => 'y']);
    $this->keycloakHelpers->expects($this->never())->method('getKeycloakToken');
    $this->account->expects($this->never())->method('save');

    $result = $this->service()->ensure();

    $this->assertTrue($result->success);
    $this->assertSame($this->account, $result->data['account']);
  }

  /**
   * Without the password an account without Nextcloud mount is not ready.
   *
   * @covers ::ensure
   */
  public function testEnsureFailsWithoutPasswordWhenMountCredentialsAreMissing(): void {
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->projectHelpers->method('getUserSsoUuid')->willReturn(self::KEYCLOAK_ID);
    $this->nextcloudHelpers->method('getMountCredentials')->willReturn(NULL);

    $this->assertFalse($this->service()->ensure()->success);
  }

  /**
   * A new account is created in Keycloak, linked, and created in Nextcloud.
   *
   * @covers ::ensure
   */
  public function testEnsureCreatesKeycloakUserLinksAndCreatesNextcloudAccount(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    putenv(SodaScsDemoServiceAccount::EMAIL_ENV . '=demo@example.com');
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->account->expects($this->once())->method('setPassword')->with('env-password');
    $this->account->expects($this->once())->method('setEmail')->with('demo@example.com');
    $this->keycloakHelpers->method('getKeycloakToken')->willReturn('token');
    $created = NULL;
    $lookups = 0;
    $this->keycloakUserActions->method('buildGetAllRequest')->willReturn(['request' => 'find']);
    $this->keycloakUserActions->method('buildCreateRequest')->willReturnCallback(static function (array $params) use (&$created): array {
      $created = $params;
      return ['request' => 'create'];
    });
    $this->keycloakUserActions->method('makeRequest')->willReturnCallback(function (array $request) use (&$lookups): array {
      if ($request['request'] === 'create') {
        return ['success' => TRUE, 'data' => []];
      }
      // Not found before the creation, found afterwards.
      $lookups++;
      $users = $lookups === 1 ? [] : [['id' => self::KEYCLOAK_ID, 'username' => 'scs_demo']];
      return ['success' => TRUE, 'data' => ['keycloakResponse' => new Response(200, [], json_encode($users))]];
    });
    $this->keycloakHelpers->expects($this->never())->method('resetKeycloakUserPassword');
    $this->projectHelpers->method('getUserSsoUuid')->willReturn(NULL);
    $this->authmap->expects($this->once())->method('save')->with($this->account, 'openid_connect.keycloak', self::KEYCLOAK_ID);
    $this->nextcloudHelpers->expects($this->once())->method('ensureAccountViaOcc')->with($this->account, 'env-password')->willReturn(TRUE);

    $result = $this->service()->ensure();

    $this->assertTrue($result->success);
    $this->assertSame($this->account, $result->data['account']);
    $this->assertSame('scs', $created['routeParams']['realm']);
    $this->assertSame('token', $created['token']);
    $this->assertSame('scs_demo', $created['body']['username']);
    $this->assertSame('demo@example.com', $created['body']['email']);
    $this->assertSame([['temporary' => FALSE, 'type' => 'password', 'value' => 'env-password']], $created['body']['credentials']);
  }

  /**
   * Without an e-mail address the Keycloak user gets none.
   *
   * @covers ::ensure
   */
  public function testEnsureSendsNoEmailWhenNoneIsConfigured(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->account->expects($this->once())->method('setEmail')->with(NULL);
    $this->keycloakHelpers->method('getKeycloakToken')->willReturn('token');
    $created = NULL;
    $lookups = 0;
    $this->keycloakUserActions->method('buildGetAllRequest')->willReturn(['request' => 'find']);
    $this->keycloakUserActions->method('buildCreateRequest')->willReturnCallback(static function (array $params) use (&$created): array {
      $created = $params;
      return ['request' => 'create'];
    });
    $this->keycloakUserActions->method('makeRequest')->willReturnCallback(function (array $request) use (&$lookups): array {
      if ($request['request'] === 'create') {
        return ['success' => TRUE, 'data' => []];
      }
      $lookups++;
      $users = $lookups === 1 ? [] : [['id' => self::KEYCLOAK_ID, 'username' => 'scs_demo']];
      return ['success' => TRUE, 'data' => ['keycloakResponse' => new Response(200, [], json_encode($users))]];
    });
    $this->nextcloudHelpers->method('ensureAccountViaOcc')->willReturn(TRUE);

    $this->assertTrue($this->service()->ensure()->success);

    $this->assertArrayNotHasKey('email', $created['body']);
    $this->assertArrayNotHasKey('emailVerified', $created['body']);
  }

  /**
   * An existing Keycloak user gets the password again and is not created.
   *
   * @covers ::ensure
   */
  public function testEnsureResetsPasswordOfExistingKeycloakUser(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->keycloakHelpers->method('getKeycloakToken')->willReturn('token');
    $this->keycloakUserActions->method('buildGetAllRequest')->with($this->callback(function (array $params): bool {
      $this->assertSame(['exact' => 'true', 'username' => 'scs_demo'], $params['queryParams']);
      return TRUE;
    }))->willReturn(['request' => 'find']);
    $this->keycloakUserActions->expects($this->never())->method('buildCreateRequest');
    $users = json_encode([
      ['id' => 'someone-else', 'username' => 'scs_demo_other'],
      ['id' => self::KEYCLOAK_ID, 'username' => 'scs_demo'],
    ]);
    $this->keycloakUserActions->method('makeRequest')->willReturn([
      'success' => TRUE,
      'data' => ['keycloakResponse' => new Response(200, [], $users)],
    ]);
    $this->keycloakHelpers->method('updateKeycloakUserProfile')->willReturn(TRUE);
    $this->keycloakHelpers->expects($this->once())->method('resetKeycloakUserPassword')->with(self::KEYCLOAK_ID, 'env-password')->willReturn(TRUE);
    $this->projectHelpers->method('getUserSsoUuid')->willReturn(self::KEYCLOAK_ID);
    $this->authmap->expects($this->never())->method('save');
    $this->nextcloudHelpers->method('ensureAccountViaOcc')->willReturn(TRUE);

    $this->assertTrue($this->service()->ensure()->success);
  }

  /**
   * An existing Keycloak user gets the configured e-mail before the password.
   *
   * @covers ::ensureKeycloakAccount
   */
  public function testEnsureSetsConfiguredEmailOfExistingKeycloakUserBeforeThePassword(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    putenv(SodaScsDemoServiceAccount::EMAIL_ENV . '=demo@example.com');
    $this->stubExistingKeycloakUser();
    $calls = [];
    $this->keycloakHelpers->expects($this->once())->method('updateKeycloakUserProfile')
      ->with(self::KEYCLOAK_ID, ['email' => 'demo@example.com'])
      ->willReturnCallback(static function () use (&$calls): bool {
        $calls[] = 'profile';
        return TRUE;
      });
    $this->keycloakHelpers->method('resetKeycloakUserPassword')->willReturnCallback(static function () use (&$calls): bool {
      $calls[] = 'password';
      return TRUE;
    });
    $this->nextcloudHelpers->method('ensureAccountViaOcc')->willReturn(TRUE);

    $this->assertTrue($this->service()->ensure()->success);
    $this->assertSame(['profile', 'password'], $calls);
  }

  /**
   * Without a configured address the Keycloak user's former address is emptied.
   *
   * @covers ::ensureKeycloakAccount
   */
  public function testEnsureEmptiesEmailOfExistingKeycloakUserWhenNoneIsConfigured(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->stubExistingKeycloakUser();
    $this->keycloakHelpers->expects($this->once())->method('updateKeycloakUserProfile')->with(self::KEYCLOAK_ID, ['email' => ''])->willReturn(TRUE);
    $this->keycloakHelpers->method('resetKeycloakUserPassword')->willReturn(TRUE);
    $this->nextcloudHelpers->method('ensureAccountViaOcc')->willReturn(TRUE);

    $this->assertTrue($this->service()->ensure()->success);
  }

  /**
   * A refused e-mail update fails the run before the password is reset.
   *
   * @covers ::ensureKeycloakAccount
   */
  public function testEnsureFailsWhenKeycloakRefusesTheEmailUpdate(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->stubExistingKeycloakUser();
    $this->keycloakHelpers->method('updateKeycloakUserProfile')->willReturn(FALSE);
    $this->keycloakHelpers->expects($this->never())->method('resetKeycloakUserPassword');
    $this->logger->expects($this->once())->method('error');
    $this->authmap->expects($this->never())->method('save');
    $this->nextcloudHelpers->expects($this->never())->method('ensureAccountViaOcc');

    $result = $this->service()->ensure();

    $this->assertFalse($result->success);
    $this->assertSame('Could not create the Keycloak account of scs_demo. See the log for details.', $result->message);
  }

  /**
   * A Keycloak user the account is not linked to is not taken over.
   *
   * @covers ::ensureKeycloakAccount
   */
  public function testEnsureDoesNotTakeOverKeycloakUserWhenAccountIsNotLinked(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->stubExistingKeycloakUser(NULL);
    $this->keycloakHelpers->expects($this->never())->method('updateKeycloakUserProfile');
    $this->keycloakHelpers->expects($this->never())->method('resetKeycloakUserPassword');
    $this->logger->expects($this->once())->method('error');
    $this->authmap->expects($this->never())->method('save');
    $this->nextcloudHelpers->expects($this->never())->method('ensureAccountViaOcc');

    $result = $this->service()->ensure();

    $this->assertFalse($result->success);
    $this->assertSame('Could not create the Keycloak account of scs_demo. See the log for details.', $result->message);
  }

  /**
   * A Keycloak user linked to the account under another id is not taken either.
   *
   * @covers ::ensureKeycloakAccount
   */
  public function testEnsureDoesNotTakeOverKeycloakUserWhenAccountIsLinkedToAnotherId(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->stubExistingKeycloakUser('another-keycloak-id');
    $this->keycloakHelpers->expects($this->never())->method('updateKeycloakUserProfile');
    $this->keycloakHelpers->expects($this->never())->method('resetKeycloakUserPassword');
    $this->authmap->expects($this->never())->method('save');

    $this->assertFalse($this->service()->ensure()->success);
  }

  /**
   * A password the Keycloak refuses fails the run before Nextcloud.
   *
   * @covers ::ensure
   */
  public function testEnsureFailsWhenKeycloakRefusesPasswordReset(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->keycloakHelpers->method('getKeycloakToken')->willReturn('token');
    $this->keycloakUserActions->method('buildGetAllRequest')->willReturn(['request' => 'find']);
    $this->keycloakUserActions->method('makeRequest')->willReturn([
      'success' => TRUE,
      'data' => [
        'keycloakResponse' => new Response(200, [], json_encode([
          ['id' => self::KEYCLOAK_ID, 'username' => 'scs_demo'],
        ])),
      ],
    ]);
    $this->projectHelpers->method('getUserSsoUuid')->willReturn(self::KEYCLOAK_ID);
    $this->keycloakHelpers->method('updateKeycloakUserProfile')->willReturn(TRUE);
    $this->keycloakHelpers->method('resetKeycloakUserPassword')->willReturn(FALSE);
    $this->authmap->expects($this->never())->method('save');
    $this->nextcloudHelpers->expects($this->never())->method('ensureAccountViaOcc');

    $result = $this->service()->ensure();

    $this->assertFalse($result->success);
    $this->assertSame('Could not create the Keycloak account of scs_demo. See the log for details.', $result->message);
  }

  /**
   * Without a Keycloak token nothing is created or linked.
   *
   * @covers ::ensure
   */
  public function testEnsureFailsWithoutKeycloakToken(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->keycloakHelpers->method('getKeycloakToken')->willReturn(NULL);
    $this->keycloakUserActions->expects($this->never())->method('makeRequest');
    $this->authmap->expects($this->never())->method('save');
    $this->nextcloudHelpers->expects($this->never())->method('ensureAccountViaOcc');

    $this->assertFalse($this->service()->ensure()->success);
  }

  /**
   * A refused Keycloak user creation fails the run and is logged.
   *
   * @covers ::ensure
   */
  public function testEnsureFailsWhenKeycloakRefusesCreation(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->keycloakHelpers->method('getKeycloakToken')->willReturn('token');
    $this->keycloakUserActions->method('buildGetAllRequest')->willReturn(['request' => 'find']);
    $this->keycloakUserActions->method('buildCreateRequest')->willReturn(['request' => 'create']);
    $this->keycloakUserActions->method('makeRequest')->willReturnCallback(static fn (array $request): array => $request['request'] === 'create'
      ? ['success' => FALSE, 'error' => 'HTTP 409']
      : ['success' => TRUE, 'data' => ['keycloakResponse' => new Response(200, [], '[]')]]);
    $this->authmap->expects($this->never())->method('save');
    $this->nextcloudHelpers->expects($this->never())->method('ensureAccountViaOcc');

    $this->assertFalse($this->service()->ensure()->success);
  }

  /**
   * A malformed user list from Keycloak is not taken for an existing user.
   *
   * @covers ::ensure
   */
  public function testEnsureIgnoresMalformedKeycloakUserList(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->keycloakHelpers->method('getKeycloakToken')->willReturn('token');
    $this->keycloakUserActions->method('buildGetAllRequest')->willReturn(['request' => 'find']);
    $this->keycloakUserActions->method('buildCreateRequest')->willReturn(['request' => 'create']);
    $this->keycloakUserActions->method('makeRequest')->willReturnCallback(static fn (array $request): array => $request['request'] === 'create'
      ? ['success' => FALSE, 'error' => 'HTTP 500']
      : ['success' => TRUE, 'data' => ['keycloakResponse' => new Response(200, [], 'not json')]]);
    $this->keycloakHelpers->expects($this->never())->method('resetKeycloakUserPassword');
    $this->authmap->expects($this->never())->method('save');

    $this->assertFalse($this->service()->ensure()->success);
  }

  /**
   * Without an OpenID Connect client the account is not linked.
   *
   * @covers ::ensure
   */
  public function testEnsureFailsWithoutOpenIdConnectClient(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->serviceHelpers = $this->createMock(SodaScsServiceHelpers::class);
    $this->serviceHelpers->method('initKeycloakGeneralSettings')->willReturn(['realm' => 'scs']);
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->keycloakHelpers->method('getKeycloakToken')->willReturn('token');
    $this->keycloakUserActions->method('buildGetAllRequest')->willReturn(['request' => 'find']);
    $this->keycloakUserActions->method('makeRequest')->willReturn([
      'success' => TRUE,
      'data' => [
        'keycloakResponse' => new Response(200, [], json_encode([
          ['id' => self::KEYCLOAK_ID, 'username' => 'scs_demo'],
        ])),
      ],
    ]);
    $this->projectHelpers->method('getUserSsoUuid')->willReturn(self::KEYCLOAK_ID);
    $this->keycloakHelpers->method('updateKeycloakUserProfile')->willReturn(TRUE);
    $this->keycloakHelpers->method('resetKeycloakUserPassword')->willReturn(TRUE);
    $this->authmap->expects($this->never())->method('save');
    $this->nextcloudHelpers->expects($this->never())->method('ensureAccountViaOcc');

    $this->assertFalse($this->service()->ensure()->success);
  }

  /**
   * A failing Nextcloud setup fails the run.
   *
   * @covers ::ensure
   */
  public function testEnsureFailsWhenNextcloudAccountCannotBeCreated(): void {
    putenv(SodaScsDemoServiceAccount::PASSWORD_ENV . '=env-password');
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->keycloakHelpers->method('getKeycloakToken')->willReturn('token');
    $this->keycloakUserActions->method('buildGetAllRequest')->willReturn(['request' => 'find']);
    $this->keycloakUserActions->method('makeRequest')->willReturn([
      'success' => TRUE,
      'data' => [
        'keycloakResponse' => new Response(200, [], json_encode([
          ['id' => self::KEYCLOAK_ID, 'username' => 'scs_demo'],
        ])),
      ],
    ]);
    $this->keycloakHelpers->method('updateKeycloakUserProfile')->willReturn(TRUE);
    $this->keycloakHelpers->method('resetKeycloakUserPassword')->willReturn(TRUE);
    $this->projectHelpers->method('getUserSsoUuid')->willReturn(self::KEYCLOAK_ID);
    $this->nextcloudHelpers->method('ensureAccountViaOcc')->willReturn(FALSE);

    $result = $this->service()->ensure();

    $this->assertFalse($result->success);
    $this->assertStringContainsString('Nextcloud', $result->message);
  }

  /**
   * Lets Keycloak answer with the user scs_demo and sets the linked id.
   *
   * @param string|null $linkedId
   *   The Keycloak id the Drupal account is linked to.
   */
  private function stubExistingKeycloakUser(?string $linkedId = self::KEYCLOAK_ID): void {
    $this->userStorage->method('loadByProperties')->willReturn([4 => $this->account]);
    $this->keycloakHelpers->method('getKeycloakToken')->willReturn('token');
    $this->keycloakUserActions->method('buildGetAllRequest')->willReturn(['request' => 'find']);
    $this->keycloakUserActions->expects($this->never())->method('buildCreateRequest');
    $this->keycloakUserActions->method('makeRequest')->willReturn([
      'success' => TRUE,
      'data' => [
        'keycloakResponse' => new Response(200, [], json_encode([
          ['id' => self::KEYCLOAK_ID, 'username' => 'scs_demo'],
        ])),
      ],
    ]);
    $this->projectHelpers->method('getUserSsoUuid')->willReturn($linkedId);
  }

  /**
   * Builds the service with doubles.
   */
  private function service(?PasswordGeneratorInterface $passwordGenerator = NULL): SodaScsDemoServiceAccount {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('user')->willReturn($this->userStorage);
    return new SodaScsDemoServiceAccount(
      $this->authmap,
      $entityTypeManager,
      $this->keycloakHelpers,
      $this->keycloakUserActions,
      $this->logger,
      $this->nextcloudHelpers,
      $passwordGenerator ?? $this->createMock(PasswordGeneratorInterface::class),
      $this->projectHelpers,
      $this->serviceHelpers,
    );
  }

}
