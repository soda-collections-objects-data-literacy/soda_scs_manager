<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
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
    );
  }

  /**
   * Builds the triplestore component of the user alice.
   */
  private function createComponent(): SodaScsComponentInterface {
    $owner = $this->createMock(UserInterface::class);
    $owner->method('getDisplayName')->willReturn('alice');
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
