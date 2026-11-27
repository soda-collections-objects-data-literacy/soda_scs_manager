<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\externalauth\AuthmapInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudGroupHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Deleting a project group removes its Nextcloud gid group too.
 *
 * Issue 3642180: the Team Folder's Nextcloud group stayed after the project,
 * its Keycloak group, or the account was deleted.
 */
#[CoversMethod(SodaScsProjectHelpers::class, 'deleteProjectGroup')]
#[Group('soda_scs_manager')]
class SodaScsProjectHelpersDeleteProjectGroupTest extends UnitTestCase {

  /**
   * The Keycloak group actions double.
   */
  private SodaScsServiceRequestInterface&MockObject $groupActions;

  /**
   * The Nextcloud group helpers double.
   */
  private SodaScsNextcloudGroupHelpers&MockObject $nextcloudGroupHelpers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->groupActions = $this->createMock(SodaScsServiceRequestInterface::class);
    $this->nextcloudGroupHelpers = $this->createMock(SodaScsNextcloudGroupHelpers::class);
  }

  /**
   * After the Keycloak delete, the gid group is deleted in Nextcloud.
   */
  public function testDeletesNextcloudGroupAfterKeycloakGroup(): void {
    $calls = [];
    $this->groupActions->expects($this->once())->method('buildDeleteRequest')
      ->with(['token' => 'kc-token', 'routeParams' => ['groupId' => 'kc-group-uuid']])
      ->willReturn(['request' => 'delete']);
    $this->groupActions->expects($this->once())->method('makeRequest')->with(['request' => 'delete'])
      ->willReturnCallback(function () use (&$calls): array {
        $calls[] = 'keycloak';
        return ['success' => TRUE];
      });
    $this->nextcloudGroupHelpers->expects($this->once())->method('deleteGroup')->with('10119')
      ->willReturnCallback(function () use (&$calls): bool {
        $calls[] = 'nextcloud';
        return TRUE;
      });

    $result = $this->helpers()->deleteProjectGroup($this->project('kc-group-uuid', '10119'));

    $this->assertTrue($result['success']);
    $this->assertSame(['keycloak', 'nextcloud'], $calls);
  }

  /**
   * A failed Keycloak delete keeps the Nextcloud group.
   *
   * Otherwise the next Drive login would copy the Keycloak group back.
   */
  public function testKeepsNextcloudGroupWhenKeycloakDeleteFails(): void {
    $this->groupActions->method('buildDeleteRequest')->willReturn(['request' => 'delete']);
    $this->groupActions->method('makeRequest')->willReturn(['success' => FALSE, 'error' => 'Keycloak answered 500.']);
    $this->nextcloudGroupHelpers->expects($this->never())->method('deleteGroup');

    $result = $this->helpers()->deleteProjectGroup($this->project('kc-group-uuid', '10119'));

    $this->assertFalse($result['success']);
    $this->assertSame('Keycloak answered 500.', $result['error']);
  }

  /**
   * A project without Keycloak group deletes nothing anywhere.
   */
  public function testDeletesNothingWithoutKeycloakUuid(): void {
    $this->groupActions->expects($this->never())->method('makeRequest');
    $this->nextcloudGroupHelpers->expects($this->never())->method('deleteGroup');

    $result = $this->helpers()->deleteProjectGroup($this->project('', '10119'));

    $this->assertFalse($result['success']);
  }

  /**
   * Builds the helpers with doubles; the Keycloak token is canned.
   */
  private function helpers(): SodaScsProjectHelpers {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('getEditable')->willReturn($this->createMock(Config::class));
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $helpers = $this->getMockBuilder(SodaScsProjectHelpers::class)
      ->setConstructorArgs([
        $configFactory,
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(AuthmapInterface::class),
        $this->createMock(SodaScsServiceHelpers::class),
        $this->createMock(SodaScsServiceRequestInterface::class),
        $this->groupActions,
        $this->createMock(SodaScsServiceRequestInterface::class),
        $this->createMock(SodaScsNextcloudServiceActions::class),
        $loggerFactory,
        $this->createMock(SodaScsProjectApplicationHelpers::class),
        $this->nextcloudGroupHelpers,
      ])
      ->onlyMethods(['getKeycloakToken'])
      ->getMock();
    $helpers->method('getKeycloakToken')->willReturn('kc-token');
    $helpers->setStringTranslation($this->getStringTranslationStub());
    return $helpers;
  }

  /**
   * A project double with the given Keycloak group UUID and gid.
   */
  private function project(string $keycloakUuid, string $groupId): SodaScsProjectInterface {
    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('get')->willReturnMap([
      ['keycloakUuid', $this->field($keycloakUuid)],
      ['groupId', $this->field($groupId)],
    ]);
    $project->method('label')->willReturn('Test project');
    return $project;
  }

  /**
   * A field double whose ->value is the given string.
   */
  private function field(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__isset')->with('value')->willReturn(TRUE);
    $field->method('__get')->with('value')->willReturn($value);
    return $field;
  }

}
