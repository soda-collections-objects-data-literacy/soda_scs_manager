<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\soda_scs_manager\Service\NextcloudMountCredentialsStore;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * The list of users with a stored app password and the Keycloak-missing mark.
 *
 * Issue 3638812: a user whose Keycloak account is gone is marked with the
 * mount status and a timestamp; the mark holds for a day, and a login or a
 * new status removes it.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Service\NextcloudMountCredentialsStore
 * @group soda_scs_manager
 */
class NextcloudMountCredentialsStoreTest extends UnitTestCase {

  /**
   * The request time the store reads.
   */
  private int $requestTime = 1_000_000;

  /**
   * The user data kept in memory, by user id and key.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $stored = [];

  /**
   * A mark set now holds right away.
   *
   * @covers ::markKeycloakUserMissing
   * @covers ::isKeycloakUserMarkedMissing
   */
  public function testMarkedUserIsMarkedMissing(): void {
    $store = $this->buildStore();
    $user = $this->createUser(5);

    $store->markKeycloakUserMissing($user);

    $this->assertTrue($store->isKeycloakUserMarkedMissing($user));
  }

  /**
   * Marking sets the mount status and the time of the mark.
   *
   * @covers ::markKeycloakUserMissing
   */
  public function testMarkSetsMountStatusAndTimestamp(): void {
    $store = $this->buildStore();
    $user = $this->createUser(5);

    $store->markKeycloakUserMissing($user);

    $this->assertSame(NextcloudMountCredentialsStore::STATUS_KEYCLOAK_USER_MISSING, $store->getMountStatus($user));
    $this->assertSame($this->requestTime, $this->stored[5][NextcloudMountCredentialsStore::KEY_KEYCLOAK_USER_MISSING_SINCE]);
  }

  /**
   * The mark holds until a full day has passed.
   *
   * @covers ::isKeycloakUserMarkedMissing
   */
  public function testMarkHoldsJustBeforeTheDayIsOver(): void {
    $store = $this->buildStore();
    $user = $this->createUser(5);
    $store->markKeycloakUserMissing($user);

    $this->requestTime += NextcloudMountCredentialsStore::KEYCLOAK_USER_MISSING_RECHECK_SECONDS - 1;

    $this->assertTrue($store->isKeycloakUserMarkedMissing($user));
  }

  /**
   * After a day the user is checked in Keycloak again.
   *
   * @covers ::isKeycloakUserMarkedMissing
   */
  public function testMarkExpiresAfterOneDay(): void {
    $store = $this->buildStore();
    $user = $this->createUser(5);
    $store->markKeycloakUserMissing($user);

    $this->requestTime += NextcloudMountCredentialsStore::KEYCLOAK_USER_MISSING_RECHECK_SECONDS;

    $this->assertFalse($store->isKeycloakUserMarkedMissing($user));
  }

  /**
   * Marking again after the day restarts the day.
   *
   * @covers ::markKeycloakUserMissing
   * @covers ::isKeycloakUserMarkedMissing
   */
  public function testMarkingAgainAfterOneDayRestartsTheDay(): void {
    $store = $this->buildStore();
    $user = $this->createUser(5);
    $store->markKeycloakUserMissing($user);
    $this->requestTime += NextcloudMountCredentialsStore::KEYCLOAK_USER_MISSING_RECHECK_SECONDS + 10;
    $this->assertFalse($store->isKeycloakUserMarkedMissing($user));

    $store->markKeycloakUserMissing($user);
    $this->requestTime += NextcloudMountCredentialsStore::KEYCLOAK_USER_MISSING_RECHECK_SECONDS - 1;

    $this->assertTrue($store->isKeycloakUserMarkedMissing($user));
  }

  /**
   * A user without any mount status is not marked.
   *
   * @covers ::isKeycloakUserMarkedMissing
   */
  public function testUserWithoutStatusIsNotMarkedMissing(): void {
    $this->assertFalse($this->buildStore()->isKeycloakUserMarkedMissing($this->createUser(5)));
  }

  /**
   * Another status is not the mark, even with a fresh timestamp.
   *
   * @covers ::isKeycloakUserMarkedMissing
   */
  public function testOtherMountStatusIsNotMarkedMissing(): void {
    $store = $this->buildStore();
    $user = $this->createUser(5);
    $store->markKeycloakUserMissing($user);

    $store->setMountStatus($user, 'mounted');

    $this->assertFalse($store->isKeycloakUserMarkedMissing($user));
  }

  /**
   * The status without a timestamp is not a mark: the user is asked again.
   *
   * @covers ::isKeycloakUserMarkedMissing
   */
  public function testStatusWithoutTimestampIsNotMarkedMissing(): void {
    $store = $this->buildStore();
    $user = $this->createUser(5);
    $store->setMountStatus($user, NextcloudMountCredentialsStore::STATUS_KEYCLOAK_USER_MISSING);

    $this->assertFalse($store->isKeycloakUserMarkedMissing($user));
  }

  /**
   * The mark of one user does not touch another user.
   *
   * @covers ::isKeycloakUserMarkedMissing
   */
  public function testMarkBelongsToOneUser(): void {
    $store = $this->buildStore();
    $store->markKeycloakUserMissing($this->createUser(5));

    $this->assertFalse($store->isKeycloakUserMarkedMissing($this->createUser(6)));
  }

  /**
   * Clearing removes the status and the timestamp.
   *
   * @covers ::clearKeycloakUserMissing
   */
  public function testClearRemovesMarkStatusAndTimestamp(): void {
    $store = $this->buildStore();
    $user = $this->createUser(5);
    $store->markKeycloakUserMissing($user);

    $store->clearKeycloakUserMissing($user);

    $this->assertFalse($store->isKeycloakUserMarkedMissing($user));
    $this->assertNull($store->getMountStatus($user));
    $this->assertArrayNotHasKey(NextcloudMountCredentialsStore::KEY_KEYCLOAK_USER_MISSING_SINCE, $this->stored[5] ?? []);
  }

  /**
   * Clearing keeps a status that is not the mark.
   *
   * A login must not wipe "mounted" or another error of a user who was never
   * marked.
   *
   * @covers ::clearKeycloakUserMissing
   */
  public function testClearKeepsOtherMountStatus(): void {
    $store = $this->buildStore();
    $user = $this->createUser(5);
    $store->setMountStatus($user, 'mounted');

    $store->clearKeycloakUserMissing($user);

    $this->assertSame('mounted', $store->getMountStatus($user));
  }

  /**
   * Clearing does not touch the stored app password or another user.
   *
   * The password is put into the user data as it is stored: encrypted.
   *
   * @covers ::clearKeycloakUserMissing
   */
  public function testClearKeepsAppPasswordAndOtherUsers(): void {
    $store = $this->buildStore();
    $user = $this->createUser(5);
    $other = $this->createUser(6);
    $this->stored[5][NextcloudMountCredentialsStore::KEY_APP_PASSWORD] = 'encrypted-secret';
    $store->markKeycloakUserMissing($user);
    $store->markKeycloakUserMissing($other);

    $store->clearKeycloakUserMissing($user);

    $this->assertSame('encrypted-secret', $this->stored[5][NextcloudMountCredentialsStore::KEY_APP_PASSWORD]);
    $this->assertTrue($store->isKeycloakUserMarkedMissing($other));
  }

  /**
   * Clearing a user that was never marked does nothing.
   *
   * @covers ::clearKeycloakUserMissing
   */
  public function testClearOfUnmarkedUserIsHarmless(): void {
    $store = $this->buildStore();
    $user = $this->createUser(5);

    $store->clearKeycloakUserMissing($user);

    $this->assertFalse($store->isKeycloakUserMarkedMissing($user));
    $this->assertNull($store->getMountStatus($user));
  }

  /**
   * The ids are the keys of the stored values, as integers.
   *
   * @covers ::userIdsWithAppPassword
   */
  public function testUserIdsWithAppPasswordAreTheStoredUserIdsAsIntegers(): void {
    $userData = $this->createMock(UserDataInterface::class);
    $userData->expects($this->once())->method('get')
      ->with(NextcloudMountCredentialsStore::USER_DATA_MODULE, NULL, NextcloudMountCredentialsStore::KEY_APP_PASSWORD)
      ->willReturn(['5' => 'encrypted-a', '12' => 'encrypted-b']);

    $this->assertSame([5, 12], (new NextcloudMountCredentialsStore($this->createMock(TimeInterface::class), $userData))->userIdsWithAppPassword());
  }

  /**
   * Nothing stored, or an unexpected value, gives an empty list.
   *
   * @covers ::userIdsWithAppPassword
   * @dataProvider provideNoStoredPasswords
   */
  public function testUserIdsWithAppPasswordIsEmptyWithoutStoredPasswords(mixed $stored): void {
    $userData = $this->createMock(UserDataInterface::class);
    $userData->method('get')->willReturn($stored);

    $this->assertSame([], (new NextcloudMountCredentialsStore($this->createMock(TimeInterface::class), $userData))->userIdsWithAppPassword());
  }

  /**
   * Values user data gives when nobody has a password.
   *
   * @return array<string, array{0: mixed}>
   *   The values.
   */
  public static function provideNoStoredPasswords(): array {
    return [
      'empty array' => [[]],
      'null' => [NULL],
      'string' => ['encrypted'],
    ];
  }

  /**
   * Builds the store on an in-memory user data and the test's request time.
   */
  private function buildStore(): NextcloudMountCredentialsStore {
    $userData = $this->createMock(UserDataInterface::class);
    $userData->method('get')->willReturnCallback(
      fn (string $module, ?int $uid = NULL, ?string $name = NULL): mixed => $this->stored[$uid][$name] ?? NULL
    );
    $userData->method('set')->willReturnCallback(function (string $module, int $uid, string $name, mixed $value): void {
      $this->stored[$uid][$name] = $value;
    });
    $userData->method('delete')->willReturnCallback(function (?string $module = NULL, ?int $uid = NULL, ?string $name = NULL): void {
      unset($this->stored[$uid][$name]);
    });

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn (): int => $this->requestTime);

    return new NextcloudMountCredentialsStore($time, $userData);
  }

  /**
   * Builds a user with the given id.
   */
  private function createUser(int $id): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($id);
    return $user;
  }

}
