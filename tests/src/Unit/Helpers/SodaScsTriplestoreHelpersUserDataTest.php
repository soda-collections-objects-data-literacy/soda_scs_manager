<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface;
use Drupal\soda_scs_manager\Service\SodaScsWisskiTriplestoreTokenSync;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The OpenGDB user and token of a triplestore component's owner.
 *
 * Issue 3638810: OpenGDB issues tokens per account. When the account had to
 * be created again, the stored token belongs to the deleted account and is
 * replaced; for an existing account the stored token stays. The OpenGDB and
 * service key calls are doubles.
 *
 * Issue 3638851: a recreated account gets its rights on the owner's other
 * repositories back.
 *
 * Issue 3639291: whenever a new token is issued, the WissKI adapters of the
 * owner get it (syncUser()); an existing token needs no sync.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers
 * @group soda_scs_manager
 */
class SodaScsTriplestoreHelpersUserDataTest extends UnitTestCase {

  /**
   * The OpenGDB helpers double.
   */
  private SodaScsOpenGdbHelpers&MockObject $openGdbHelpers;

  /**
   * The service key actions double.
   */
  private SodaScsServiceKeyActionsInterface&MockObject $serviceKeyActions;

  /**
   * The logger channel double.
   */
  private LoggerChannelInterface&MockObject $logger;

  /**
   * The password service key of the owner.
   */
  private SodaScsServiceKeyInterface&MockObject $passwordKey;

  /**
   * Machine names of the triplestore components the owner has stored.
   *
   * @var string[]
   */
  private array $ownedRepositories = [];

  /**
   * The double that writes tokens into the WissKI adapters.
   */
  private SodaScsWisskiTriplestoreTokenSync&MockObject $tokenSync;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tokenSync = $this->createMock(SodaScsWisskiTriplestoreTokenSync::class);
    $this->openGdbHelpers = $this->createMock(SodaScsOpenGdbHelpers::class);
    $this->serviceKeyActions = $this->createMock(SodaScsServiceKeyActionsInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->passwordKey = $this->createServiceKey('owner-password');
  }

  /**
   * A recreated OpenGDB user gets a new token, saved into the existing key.
   *
   * @covers ::ensureUserDataExists
   */
  public function testRecreatedUserGetsNewTokenSavedIntoExistingKey(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturnCallback($this->userDataAfterCreate());
    $this->openGdbHelpers->expects($this->once())->method('createUser')->with('alice', 'owner-password');
    $this->openGdbHelpers->expects($this->never())->method('updateUser');
    $this->openGdbHelpers->expects($this->once())->method('createUserToken')->with('alice', 'owner-password')->willReturn('new-token');
    $tokenKey = $this->createServiceKey('stale-token');
    $tokenKey->expects($this->once())->method('set')->with('servicePassword', 'new-token');
    $tokenKey->expects($this->once())->method('save');
    $this->useServiceKeys($tokenKey);
    $this->serviceKeyActions->expects($this->never())->method('createServiceKey');
    $this->logger->expects($this->once())->method('notice')
      ->with($this->stringContains('Renewed the triplestore token'), ['@user' => 7]);

    $userData = $this->helpers()->ensureUserDataExists($this->createComponent());

    $this->assertSame('new-token', $userData['token']);
    $this->assertSame($tokenKey, $userData['tokenServiceKey']);
    $this->assertSame('owner-password', $userData['password']);
  }

  /**
   * An existing OpenGDB user keeps the stored token; no token is requested.
   *
   * @covers ::ensureUserDataExists
   */
  public function testExistingUserKeepsStoredToken(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturn(['username' => 'alice']);
    $this->openGdbHelpers->expects($this->never())->method('createUser');
    $this->openGdbHelpers->expects($this->once())->method('updateUser')->with('alice', 'owner-password');
    $this->openGdbHelpers->expects($this->never())->method('createUserToken');
    $tokenKey = $this->createServiceKey('stored-token');
    $tokenKey->expects($this->never())->method('set');
    $tokenKey->expects($this->never())->method('save');
    $this->useServiceKeys($tokenKey);
    $this->serviceKeyActions->expects($this->never())->method('createServiceKey');
    $this->logger->expects($this->never())->method('notice');

    $userData = $this->helpers()->ensureUserDataExists($this->createComponent());

    $this->assertSame('stored-token', $userData['token']);
  }

  /**
   * A recreated user without any stored token gets one key, no second request.
   *
   * @covers ::ensureUserDataExists
   */
  public function testRecreatedUserWithoutStoredTokenCreatesTokenKeyOnly(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturnCallback($this->userDataAfterCreate());
    $this->openGdbHelpers->expects($this->once())->method('createUserToken')->willReturn('new-token');
    $createdKey = $this->createServiceKey('new-token');
    $this->serviceKeyActions->method('getServiceKey')->willReturnCallback(
      fn (array $props): ?SodaScsServiceKeyInterface => ($props['type'] ?? '') === 'password' ? $this->passwordKey : NULL
    );
    $this->serviceKeyActions->expects($this->once())->method('createServiceKey')
      ->with($this->callback(static fn (array $props): bool => $props['type'] === 'token' && $props['token'] === 'new-token'))
      ->willReturn($createdKey);
    $this->logger->expects($this->never())->method('notice');

    $userData = $this->helpers()->ensureUserDataExists($this->createComponent());

    $this->assertSame('new-token', $userData['token']);
    $this->assertSame($createdKey, $userData['tokenServiceKey']);
  }

  /**
   * A failing token request for a recreated user reaches the caller.
   *
   * The stored token key is neither changed nor saved then, so the stale
   * token stays as it was and the next call tries again.
   *
   * @covers ::ensureUserDataExists
   */
  public function testFailingTokenRequestForRecreatedUserReachesCallerAndLeavesTokenKeyUntouched(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturnCallback($this->userDataAfterCreate());
    $this->openGdbHelpers->expects($this->once())->method('createUserToken')
      ->willThrowException(new \RuntimeException('OpenGDB answered 500.'));
    $tokenKey = $this->createServiceKey('stale-token');
    $tokenKey->expects($this->never())->method('set');
    $tokenKey->expects($this->never())->method('save');
    $this->useServiceKeys($tokenKey);
    $this->serviceKeyActions->expects($this->never())->method('createServiceKey');
    $this->logger->expects($this->never())->method('notice');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('OpenGDB answered 500.');

    $this->helpers()->ensureUserDataExists($this->createComponent());
  }

  /**
   * A recreated user gets its rights on the owner's stored repositories.
   *
   * @covers ::ensureUserDataExists
   */
  public function testRecreatedUserGetsRightsOnOwnedRepositories(): void {
    $this->ownedRepositories = ['ts-first', 'ts-second'];
    $this->openGdbHelpers->method('getUserDataByName')->willReturnCallback($this->userDataAfterCreate());
    $this->openGdbHelpers->method('createUserToken')->willReturn('new-token');
    $this->openGdbHelpers->expects($this->once())
      ->method('setUserRepositoryGrants')
      ->with('alice', ['ts-first', 'ts-second']);
    $this->useServiceKeys($this->createServiceKey('stale-token'));
    $notices = [];
    $this->logger->method('notice')->willReturnCallback(static function (string $message, array $context) use (&$notices): void {
      $notices[] = [$message, $context];
    });

    $this->helpers()->ensureUserDataExists($this->createComponent());

    $this->assertStringContainsString('Granted the recreated OpenGDB account', $notices[0][0]);
    $this->assertSame(['@repositories' => 'ts-first, ts-second', '@user' => 7], $notices[0][1]);
  }

  /**
   * A recreated user without stored repositories gets no grant request.
   *
   * @covers ::ensureUserDataExists
   */
  public function testRecreatedUserWithoutOwnedRepositoriesSendsNoGrants(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturnCallback($this->userDataAfterCreate());
    $this->openGdbHelpers->method('createUserToken')->willReturn('new-token');
    $this->openGdbHelpers->expects($this->never())->method('setUserRepositoryGrants');
    $this->useServiceKeys($this->createServiceKey('stale-token'));

    $this->helpers()->ensureUserDataExists($this->createComponent());
  }

  /**
   * An existing user keeps its rights; no grant request is sent.
   *
   * @covers ::ensureUserDataExists
   */
  public function testExistingUserGetsNoGrantRequest(): void {
    $this->ownedRepositories = ['ts-first'];
    $this->openGdbHelpers->method('getUserDataByName')->willReturn(['username' => 'alice']);
    $this->openGdbHelpers->expects($this->never())->method('setUserRepositoryGrants');
    $this->useServiceKeys($this->createServiceKey('stored-token'));

    $this->helpers()->ensureUserDataExists($this->createComponent());
  }

  /**
   * A refused grant request is logged and the new triplestore goes on.
   *
   * The error names the repositories, so an administrator can grant them.
   *
   * @covers ::ensureUserDataExists
   */
  public function testRefusedGrantsAreLoggedAndTokenIsStillRenewed(): void {
    $this->ownedRepositories = ['ts-first'];
    $this->openGdbHelpers->method('getUserDataByName')->willReturnCallback($this->userDataAfterCreate());
    $this->openGdbHelpers->method('setUserRepositoryGrants')->willThrowException(new \RuntimeException('OpenGDB answered 500.'));
    $this->openGdbHelpers->method('createUserToken')->willReturn('new-token');
    $this->useServiceKeys($this->createServiceKey('stale-token'));
    $this->logger->expects($this->once())->method('error')->with(
      $this->stringContains('Could not grant the recreated OpenGDB account'),
      ['@message' => 'OpenGDB answered 500.', '@repositories' => 'ts-first', '@user' => 7],
    );

    $userData = $this->helpers()->ensureUserDataExists($this->createComponent());

    $this->assertSame('new-token', $userData['token']);
  }

  /**
   * A renewed token is synced to the owner's WissKIs after it was stored.
   *
   * @covers ::ensureUserDataExists
   */
  public function testRecreatedUserSyncsWisskiTokensAfterTheTokenIsStored(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturnCallback($this->userDataAfterCreate());
    $this->openGdbHelpers->method('createUserToken')->willReturn('new-token');
    $order = [];
    $tokenKey = $this->createServiceKey('stale-token');
    $tokenKey->method('save')->willReturnCallback(static function () use (&$order): void {
      $order[] = 'save';
    });
    $this->useServiceKeys($tokenKey);
    $this->tokenSync->expects($this->once())->method('syncUser')->with(7)
      ->willReturnCallback(static function () use (&$order): int {
        $order[] = 'sync';
        return 1;
      });

    $this->helpers()->ensureUserDataExists($this->createComponent());

    $this->assertSame(['save', 'sync'], $order);
  }

  /**
   * A first token for an existing account is synced to the owner's WissKIs.
   *
   * @covers ::ensureUserDataExists
   */
  public function testExistingUserWithoutStoredTokenSyncsWisskiTokensAfterTheKeyIsCreated(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturn(['username' => 'alice']);
    $this->openGdbHelpers->expects($this->once())->method('createUserToken')->willReturn('new-token');
    $order = [];
    $this->serviceKeyActions->method('getServiceKey')->willReturnCallback(
      fn (array $props): ?SodaScsServiceKeyInterface => ($props['type'] ?? '') === 'password' ? $this->passwordKey : NULL
    );
    $this->serviceKeyActions->method('createServiceKey')->willReturnCallback(function () use (&$order): SodaScsServiceKeyInterface {
      $order[] = 'create';
      return $this->createServiceKey('new-token');
    });
    $this->tokenSync->expects($this->once())->method('syncUser')->with(7)
      ->willReturnCallback(static function () use (&$order): int {
        $order[] = 'sync';
        return 0;
      });

    $this->helpers()->ensureUserDataExists($this->createComponent());

    $this->assertSame(['create', 'sync'], $order);
  }

  /**
   * A recreated user without any stored token is synced once.
   *
   * @covers ::ensureUserDataExists
   */
  public function testRecreatedUserWithoutStoredTokenSyncsWisskiTokensOnce(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturnCallback($this->userDataAfterCreate());
    $this->openGdbHelpers->method('createUserToken')->willReturn('new-token');
    $this->serviceKeyActions->method('getServiceKey')->willReturnCallback(
      fn (array $props): ?SodaScsServiceKeyInterface => ($props['type'] ?? '') === 'password' ? $this->passwordKey : NULL
    );
    $this->serviceKeyActions->method('createServiceKey')->willReturn($this->createServiceKey('new-token'));
    $this->tokenSync->expects($this->once())->method('syncUser')->with(7);

    $this->helpers()->ensureUserDataExists($this->createComponent());
  }

  /**
   * The stored token of an existing account stays: no WissKI is touched.
   *
   * @covers ::ensureUserDataExists
   */
  public function testExistingUserWithStoredTokenSyncsNothing(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturn(['username' => 'alice']);
    $this->useServiceKeys($this->createServiceKey('stored-token'));
    $this->tokenSync->expects($this->never())->method('syncUser');

    $this->helpers()->ensureUserDataExists($this->createComponent());
  }

  /**
   * A failing token request syncs nothing: no new token exists.
   *
   * @covers ::ensureUserDataExists
   */
  public function testFailingTokenRequestSyncsNothing(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturnCallback($this->userDataAfterCreate());
    $this->openGdbHelpers->method('createUserToken')->willThrowException(new \RuntimeException('OpenGDB answered 500.'));
    $this->useServiceKeys($this->createServiceKey('stale-token'));
    $this->tokenSync->expects($this->never())->method('syncUser');

    try {
      $this->helpers()->ensureUserDataExists($this->createComponent());
      $this->fail('Expected the RuntimeException of the token request.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('OpenGDB answered 500.', $exception->getMessage());
    }
  }

  /**
   * Feeds the password key and the given token key to the key lookup.
   */
  private function useServiceKeys(SodaScsServiceKeyInterface $tokenKey): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturnCallback(
      fn (array $props): ?SodaScsServiceKeyInterface => ($props['type'] ?? '') === 'password' ? $this->passwordKey : $tokenKey
    );
  }

  /**
   * OpenGDB answers "no user" until the user was created.
   */
  private function userDataAfterCreate(): \Closure {
    $created = FALSE;
    $this->openGdbHelpers->method('createUser')->willReturnCallback(function () use (&$created): void {
      $created = TRUE;
    });
    return static function () use (&$created): array|false {
      return $created ? ['username' => 'alice'] : FALSE;
    };
  }

  /**
   * Builds the helper under test.
   */
  private function helpers(): SodaScsTriplestoreHelpers {
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->with('soda_scs_manager')->willReturn($this->logger);
    $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);
    $bundleInfo->method('getBundleInfo')->willReturn(['soda_scs_triplestore_component' => ['label' => 'Triplestore']]);

    return new SodaScsTriplestoreHelpers(
      $loggerFactory,
      $this->createMock(SodaScsOpenGdbRequestInterface::class),
      $this->createMock(SodaScsSnapshotHelpers::class),
      $this->serviceKeyActions,
      $this->openGdbHelpers,
      $bundleInfo,
      $this->entityTypeManager(),
      $this->tokenSync,
    );
  }

  /**
   * An entity type manager whose component storage holds owned repositories.
   */
  private function entityTypeManager(): EntityTypeManagerInterface {
    $components = [];
    foreach ($this->ownedRepositories as $index => $machineName) {
      $field = $this->createMock(FieldItemList::class);
      $field->method('__get')->with('value')->willReturn($machineName);
      $component = $this->createMock(SodaScsComponentInterface::class);
      $component->method('get')->with('machineName')->willReturn($field);
      $components[$index + 1] = $component;
    }
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnCallback(function (string $field, $value) use ($query): QueryInterface {
      $expected = ['bundle' => 'soda_scs_triplestore_component', 'owner' => 7];
      $this->assertSame($expected[$field], $value);
      return $query;
    });
    $query->method('execute')->willReturn(array_combine(array_keys($components), array_keys($components)));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($components);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('soda_scs_component')->willReturn($storage);
    return $entityTypeManager;
  }

  /**
   * Any user gets an OpenGDB account and keys, without owning a component.
   *
   * Issue 3640913: the next owner of a project has no triplestore yet.
   *
   * @covers ::ensureUserAccount
   */
  public function testEnsureUserAccountWorksForUserWithoutComponent(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturn(['username' => 'bob']);
    $this->openGdbHelpers->expects($this->once())->method('updateUser')->with('bob', 'owner-password');
    $this->openGdbHelpers->expects($this->once())->method('createUserToken')->with('bob', 'owner-password')->willReturn('bob-token');
    $createdKeys = [];
    $this->serviceKeyActions->method('getServiceKey')->willReturn(NULL);
    $this->serviceKeyActions->method('createServiceKey')->willReturnCallback(function (array $props) use (&$createdKeys): SodaScsServiceKeyInterface {
      $createdKeys[] = $props;
      return $this->createServiceKey($props['type'] === 'token' ? 'bob-token' : 'owner-password');
    });

    $userData = $this->helpers()->ensureUserAccount($this->createUser(9, 'bob'));

    $this->assertSame('bob-token', $userData['token']);
    $this->assertSame('owner-password', $userData['password']);
    $this->assertCount(2, $createdKeys);
    foreach ($createdKeys as $props) {
      $this->assertSame('soda_scs_triplestore_component', $props['bundle']);
      $this->assertSame(9, $props['userId']);
      $this->assertSame('bob', $props['username']);
    }
    $this->assertSame(['password', 'token'], array_column($createdKeys, 'type'));
  }

  /**
   * The keys of the given user are looked up, not those of a component owner.
   *
   * @covers ::ensureUserAccount
   */
  public function testEnsureUserAccountLooksUpKeysOfTheGivenUser(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willReturn(['username' => 'bob']);
    $lookups = [];
    $this->serviceKeyActions->method('getServiceKey')->willReturnCallback(function (array $props) use (&$lookups): SodaScsServiceKeyInterface {
      $lookups[] = [$props['type'], $props['userId']];
      return $this->createServiceKey('secret');
    });
    $this->openGdbHelpers->expects($this->never())->method('createUserToken');

    $this->helpers()->ensureUserAccount($this->createUser(9, 'bob'));

    $this->assertSame([['password', 9], ['token', 9]], $lookups);
  }

  /**
   * A refused account creation reaches the caller; no key is invented.
   *
   * @covers ::ensureUserAccount
   */
  public function testEnsureUserAccountThrowsWhenOpenGdbRefuses(): void {
    $this->openGdbHelpers->method('getUserDataByName')->willThrowException(new \RuntimeException('Failed to get user by name.'));
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->passwordKey);
    $this->serviceKeyActions->expects($this->never())->method('createServiceKey');

    $this->expectException(\RuntimeException::class);

    $this->helpers()->ensureUserAccount($this->createUser(9, 'bob'));
  }

  /**
   * Builds a user double.
   */
  private function createUser(int $id, string $name): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($id);
    $user->method('getDisplayName')->willReturn($name);
    return $user;
  }

  /**
   * Builds the triplestore component of the user alice.
   */
  private function createComponent(): SodaScsComponentInterface {
    $owner = $this->createMock(UserInterface::class);
    $owner->method('getDisplayName')->willReturn('alice');
    $owner->method('id')->willReturn(7);
    $component = $this->createMock(SodaScsComponentInterface::class);
    $component->method('bundle')->willReturn('soda_scs_triplestore_component');
    $component->method('getOwnerId')->willReturn(7);
    $component->method('getOwner')->willReturn($owner);
    return $component;
  }

  /**
   * Builds a service key whose servicePassword field holds the given value.
   */
  private function createServiceKey(string $secret): SodaScsServiceKeyInterface&MockObject {
    $field = $this->createMock(FieldItemList::class);
    $field->method('__get')->with('value')->willReturn($secret);
    $key = $this->createMock(SodaScsServiceKeyInterface::class);
    $key->method('get')->with('servicePassword')->willReturn($field);
    return $key;
  }

}
