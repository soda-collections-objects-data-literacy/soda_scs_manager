<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers
 * @group soda_scs_manager
 */
class SodaScsSnapshotPathTest extends UnitTestCase {

  /**
   * The legacy config value must not hide the real container root.
   *
   * @covers ::convertContainerPathToHostPath
   */
  public function testLegacySnapshotPathMapsToTheHostBind(): void {
    $helper = $this->helper('/srv/backups/scs-manager/snapshots');

    $this->assertSame(
      '/srv/backups/scs-manager/snapshots/_demo-templates/v1.0.0',
      $helper->convertContainerPathToHostPath('/var/scs-manager/snapshots/_demo-templates/v1.0.0'),
    );
  }

  /**
   * No remap when the host path is the container root.
   *
   * @covers ::convertContainerPathToHostPath
   */
  public function testEqualRootsStayUnchanged(): void {
    $helper = $this->helper('/var/scs-manager/snapshots');

    $this->assertSame(
      '/var/scs-manager/snapshots/_demo-templates/v1.0.0',
      $helper->convertContainerPathToHostPath('/var/scs-manager/snapshots/_demo-templates/v1.0.0'),
    );
  }

  /**
   * Helper whose container root is the in-container default.
   */
  protected function helper(string $hostPath): SodaScsSnapshotHelpers {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['snapshotHostPath', $hostPath],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('soda_scs_manager.settings')->willReturn($config);

    $helper = $this->getMockBuilder(SodaScsSnapshotHelpers::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getSnapshotFilesystemRoot'])
      ->getMock();
    $helper->method('getSnapshotFilesystemRoot')->willReturn(SodaScsSnapshotHelpers::DEFAULT_SNAPSHOT_FILESYSTEM_PATH);

    $property = new \ReflectionProperty(SodaScsSnapshotHelpers::class, 'configFactory');
    $property->setValue($helper, $configFactory);
    return $helper;
  }

}
