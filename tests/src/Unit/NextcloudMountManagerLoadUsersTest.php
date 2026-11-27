<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Service\NextcloudMountCredentialsStore;
use Drupal\soda_scs_manager\Service\NextcloudMounterClient;
use Drupal\soda_scs_manager\Service\NextcloudMountManager;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Which users the Nextcloud mount reconcile looks at.
 *
 * Issue 3638812: the reconcile runs every minute. It only asks for users with
 * a stored app password, skips users whose Keycloak account is gone, and marks
 * and logs such a user once.
 */
#[CoversMethod(NextcloudMountManager::class, 'loadUsersWithCredentials')]
#[Group('soda_scs_manager')]
class NextcloudMountManagerLoadUsersTest extends UnitTestCase {

  /**
   * The credentials store mock.
   */
  private NextcloudMountCredentialsStore&MockObject $credentialsStore;

  /**
   * The entity type manager mock.
   */
  private EntityTypeManagerInterface&MockObject $entityTypeManager;

  /**
   * The logger mock.
   */
  private LoggerInterface&MockObject $logger;

  /**
   * The Nextcloud helpers mock.
   */
  private SodaScsNextcloudHelpers&MockObject $nextcloudHelpers;

  /**
   * The conditions the user query got, as [field, value, operator].
   *
   * @var list<array{0: string, 1: mixed, 2: string|null}>
   */
  private array $conditions = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->credentialsStore = $this->createMock(NextcloudMountCredentialsStore::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->nextcloudHelpers = $this->createMock(SodaScsNextcloudHelpers::class);
  }

  /**
   * Without a stored app password nobody is loaded or asked.
   */
  public function testNoUsersAreLoadedWhenNobodyHasAnAppPassword(): void {
    $this->credentialsStore->method('userIdsWithAppPassword')->willReturn([]);
    $this->entityTypeManager->expects($this->never())->method('getStorage');
    $this->nextcloudHelpers->expects($this->never())->method('getMountCredentials');
    $this->nextcloudHelpers->expects($this->never())->method('isKeycloakUserMissing');

    $this->assertSame([], $this->loadUsers());
  }

  /**
   * The query is limited to active users with a stored app password.
   */
  public function testQueryIsLimitedToUsersWithAppPassword(): void {
    $this->credentialsStore->method('userIdsWithAppPassword')->willReturn([5, 9]);
    $this->useStorage([]);

    $this->loadUsers();

    $this->assertContains(['status', 1, NULL], $this->conditions);
    $this->assertContains(['uid', 0, '>'], $this->conditions);
    $this->assertContains(['uid', [5, 9], 'IN'], $this->conditions);
  }

  /**
   * A user with credentials is returned and Keycloak is not asked.
   */
  public function testUserWithCredentialsIsReturnedWithoutKeycloakCall(): void {
    $user = $this->createUser(5);
    $this->credentialsStore->method('userIdsWithAppPassword')->willReturn([5]);
    $this->credentialsStore->method('isKeycloakUserMarkedMissing')->willReturn(FALSE);
    $this->useStorage([5 => $user]);
    $this->nextcloudHelpers->method('getMountCredentials')->willReturn([
      'username' => 'sub-5',
      'appPassword' => 'secret',
    ]);
    $this->nextcloudHelpers->expects($this->never())->method('isKeycloakUserMissing');
    $this->credentialsStore->expects($this->never())->method('markKeycloakUserMissing');

    $this->assertSame([$user], $this->loadUsers());
  }

  /**
   * A user already marked as missing in Keycloak is skipped without any call.
   */
  public function testUserMarkedKeycloakUserMissingIsSkipped(): void {
    $this->credentialsStore->method('userIdsWithAppPassword')->willReturn([5]);
    $this->credentialsStore->method('isKeycloakUserMarkedMissing')->willReturn(TRUE);
    $this->useStorage([5 => $this->createUser(5)]);
    $this->nextcloudHelpers->expects($this->never())->method('getMountCredentials');
    $this->nextcloudHelpers->expects($this->never())->method('isKeycloakUserMissing');
    $this->credentialsStore->expects($this->never())->method('markKeycloakUserMissing');
    $this->logger->expects($this->never())->method('warning');

    $this->assertSame([], $this->loadUsers());
  }

  /**
   * A user without credentials whose Keycloak account is gone is marked once.
   */
  public function testUserWithoutCredentialsAndWithoutKeycloakAccountIsMarkedAndWarnedOnce(): void {
    $user = $this->createUser(5);
    $this->credentialsStore->method('userIdsWithAppPassword')->willReturn([5]);
    $this->credentialsStore->method('isKeycloakUserMarkedMissing')->willReturn(FALSE);
    $this->useStorage([5 => $user]);
    $this->nextcloudHelpers->method('getMountCredentials')->willReturn(NULL);
    $this->nextcloudHelpers->expects($this->once())->method('isKeycloakUserMissing')->with($user)->willReturn(TRUE);
    $this->credentialsStore->expects($this->once())->method('markKeycloakUserMissing')->with($user);
    $this->logger->expects($this->once())->method('warning')
      ->with($this->stringContains('Keycloak has no account for user'), ['@uid' => 5]);

    $this->assertSame([], $this->loadUsers());
  }

  /**
   * Without credentials but with a Keycloak account: skipped, not marked.
   *
   * The credentials may be missing for another reason (an outage while the
   * login name is read), and the next run tries again.
   */
  public function testUserWithoutCredentialsButWithKeycloakAccountIsSkippedNotMarked(): void {
    $this->credentialsStore->method('userIdsWithAppPassword')->willReturn([5]);
    $this->credentialsStore->method('isKeycloakUserMarkedMissing')->willReturn(FALSE);
    $this->useStorage([5 => $this->createUser(5)]);
    $this->nextcloudHelpers->method('getMountCredentials')->willReturn(NULL);
    $this->nextcloudHelpers->method('isKeycloakUserMissing')->willReturn(FALSE);
    $this->credentialsStore->expects($this->never())->method('markKeycloakUserMissing');
    $this->logger->expects($this->never())->method('warning');

    $this->assertSame([], $this->loadUsers());
  }

  /**
   * Each user is judged on its own: a missing one does not hide the others.
   */
  public function testMissingUserDoesNotHideOtherUsers(): void {
    $gone = $this->createUser(5);
    $fine = $this->createUser(6);
    $this->credentialsStore->method('userIdsWithAppPassword')->willReturn([5, 6]);
    $this->credentialsStore->method('isKeycloakUserMarkedMissing')->willReturn(FALSE);
    $this->useStorage([5 => $gone, 6 => $fine]);
    $this->nextcloudHelpers->method('getMountCredentials')->willReturnCallback(
      static fn (UserInterface $user): ?array => (int) $user->id() === 6
        ? ['username' => 'sub-6', 'appPassword' => 'secret']
        : NULL
    );
    $this->nextcloudHelpers->method('isKeycloakUserMissing')->willReturn(TRUE);
    $this->credentialsStore->expects($this->once())->method('markKeycloakUserMissing')->with($gone);

    $this->assertSame([$fine], $this->loadUsers());
  }

  /**
   * Calls the protected method through a subclass.
   *
   * @return list<\Drupal\user\UserInterface>
   *   The users.
   */
  private function loadUsers(): array {
    $manager = new class(
      $this->entityTypeManager,
      $this->createMock(NextcloudMounterClient::class),
      $this->credentialsStore,
      $this->nextcloudHelpers,
      $this->createMock(SodaScsServiceHelpers::class),
      $this->logger,
    ) extends NextcloudMountManager {

      /**
       * Exposes loadUsersWithCredentials().
       */
      public function users(): array {
        return $this->loadUsersWithCredentials();
      }

    };
    return $manager->users();
  }

  /**
   * Lets the user storage answer the query with the given users.
   *
   * @param array<int, \Drupal\user\UserInterface> $users
   *   The users by id.
   */
  private function useStorage(array $users): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnCallback(function (string $field, mixed $value = NULL, ?string $operator = NULL) use ($query): QueryInterface {
      $this->conditions[] = [$field, $value, $operator];
      return $query;
    });
    $query->method('execute')->willReturn(array_keys($users));

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($users);
    $this->entityTypeManager->method('getStorage')->with('user')->willReturn($storage);
  }

  /**
   * Builds a user.
   */
  private function createUser(int $id): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($id);
    return $user;
  }

}
