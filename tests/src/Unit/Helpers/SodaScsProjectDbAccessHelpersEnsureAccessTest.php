<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectDbAccessHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * MariaDB access of one user on the databases of a project.
 *
 * Issue 3640913: the next owner of a project is not a member yet, but needs
 * the MariaDB account the WissKI settings.php will use. The MariaDB calls are
 * doubles.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsProjectDbAccessHelpers
 * @group soda_scs_manager
 */
class SodaScsProjectDbAccessHelpersEnsureAccessTest extends UnitTestCase {

  /**
   * The SQL service actions double.
   */
  private SodaScsServiceActionsInterface&MockObject $sqlActions;

  /**
   * The service key actions double.
   */
  private SodaScsServiceKeyActionsInterface&MockObject $serviceKeyActions;

  /**
   * The Keycloak helpers double.
   */
  private SodaScsKeycloakHelpers&MockObject $keycloakHelpers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sqlActions = $this->createMock(SodaScsServiceActionsInterface::class);
    $this->serviceKeyActions = $this->createMock(SodaScsServiceKeyActionsInterface::class);
    $this->keycloakHelpers = $this->createMock(SodaScsKeycloakHelpers::class);
  }

  /**
   * A project without SQL databases needs no MariaDB call.
   *
   * @covers ::ensureProjectDbAccess
   */
  public function testProjectWithoutDatabasesTouchesNothing(): void {
    $this->sqlActions->expects($this->never())->method('existServiceUser');
    $this->sqlActions->expects($this->never())->method('grantServiceRights');

    $this->assertTrue($this->helpers()->ensureProjectDbAccess($this->user(), $this->project([])));
  }

  /**
   * A missing account is created with its service key password and granted.
   *
   * @covers ::ensureProjectDbAccess
   */
  public function testMissingAccountIsCreatedAndGrantedOnEveryDatabase(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->passwordKey('db-secret'));
    $this->sqlActions->method('existServiceUser')->with('bob')->willReturn(['execStatus' => 0, 'result' => '0']);
    $this->sqlActions->expects($this->once())->method('createServiceUser')->with('bob', 'db-secret')->willReturn(['execStatus' => 0]);
    $granted = [];
    $this->sqlActions->method('grantServiceRights')->willReturnCallback(static function (string $user, string $database, array $rights) use (&$granted): array {
      $granted[] = [$user, $database, $rights];
      return ['execStatus' => 0];
    });

    $this->assertTrue($this->helpers()->ensureProjectDbAccess($this->user(), $this->project(['db-one', 'db-two'])));

    $this->assertSame([
      ['bob', 'db-one', ['ALL PRIVILEGES']],
      ['bob', 'db-two', ['ALL PRIVILEGES']],
    ], $granted);
  }

  /**
   * An existing account gets its password synced and is not created again.
   *
   * @covers ::ensureProjectDbAccess
   */
  public function testExistingAccountIsKeptAndItsPasswordSynced(): void {
    $key = $this->passwordKey('db-secret');
    $this->serviceKeyActions->method('getServiceKey')->willReturn($key);
    $this->sqlActions->method('existServiceUser')->willReturn(['execStatus' => 0, 'result' => '1']);
    $this->sqlActions->expects($this->never())->method('createServiceUser');
    $this->sqlActions->expects($this->once())->method('renewUserPassword')->with($key)->willReturn(['execStatus' => 0]);
    $this->sqlActions->method('grantServiceRights')->willReturn(['execStatus' => 0]);

    $this->assertTrue($this->helpers()->ensureProjectDbAccess($this->user(), $this->project(['db-one'])));
  }

  /**
   * A failing account check is a failure and nothing is granted.
   *
   * @covers ::ensureProjectDbAccess
   */
  public function testFailedAccountCheckFailsWithoutGrants(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->passwordKey('db-secret'));
    $this->sqlActions->method('existServiceUser')->willReturn(['execStatus' => 1, 'output' => ['ERROR 2002']]);
    $this->sqlActions->expects($this->never())->method('createServiceUser');
    $this->sqlActions->expects($this->never())->method('grantServiceRights');

    $this->assertFalse($this->helpers()->ensureProjectDbAccess($this->user(), $this->project(['db-one'])));
  }

  /**
   * A failing account creation is a failure and nothing is granted.
   *
   * @covers ::ensureProjectDbAccess
   */
  public function testFailedAccountCreationFailsWithoutGrants(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->passwordKey('db-secret'));
    $this->sqlActions->method('existServiceUser')->willReturn(['execStatus' => 0, 'result' => '0']);
    $this->sqlActions->method('createServiceUser')->willReturn(['execStatus' => 1, 'output' => ['ERROR 1396']]);
    $this->sqlActions->expects($this->never())->method('grantServiceRights');

    $this->assertFalse($this->helpers()->ensureProjectDbAccess($this->user(), $this->project(['db-one'])));
  }

  /**
   * Builds the helper under test.
   */
  private function helpers(): SodaScsProjectDbAccessHelpers {
    $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);
    $bundleInfo->method('getBundleInfo')->willReturn(['soda_scs_sql_component' => ['label' => 'SQL']]);
    return new SodaScsProjectDbAccessHelpers(
      $this->createMock(EntityTypeManagerInterface::class),
      $bundleInfo,
      $this->createMock(SodaScsProjectHelpers::class),
      $this->sqlActions,
      $this->serviceKeyActions,
      $this->keycloakHelpers,
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds a service key whose password field holds the given value.
   */
  private function passwordKey(string $password): SodaScsServiceKeyInterface {
    $field = $this->createMock(FieldItemList::class);
    $field->method('__get')->with('value')->willReturn($password);
    $key = $this->createMock(SodaScsServiceKeyInterface::class);
    $key->method('get')->with('servicePassword')->willReturn($field);
    return $key;
  }

  /**
   * Builds a project with SQL components of the given database names.
   *
   * @param string[] $databases
   *   Machine names of the SQL components.
   */
  private function project(array $databases): SodaScsProjectInterface {
    $components = [];
    foreach ($databases as $database) {
      $field = $this->createMock(FieldItemList::class);
      $field->method('__get')->with('value')->willReturn($database);
      $component = $this->createMock(SodaScsComponentInterface::class);
      $component->method('bundle')->willReturn('soda_scs_sql_component');
      $component->method('get')->with('machineName')->willReturn($field);
      $components[] = $component;
    }
    $other = $this->createMock(SodaScsComponentInterface::class);
    $other->method('bundle')->willReturn('soda_scs_wisski_component');
    $components[] = $other;
    $connected = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $connected->method('referencedEntities')->willReturn($components);
    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('get')->with('connectedComponents')->willReturn($connected);
    return $project;
  }

  /**
   * Builds the user bob.
   */
  private function user(): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(9);
    $user->method('getDisplayName')->willReturn('bob');
    return $user;
  }

}
