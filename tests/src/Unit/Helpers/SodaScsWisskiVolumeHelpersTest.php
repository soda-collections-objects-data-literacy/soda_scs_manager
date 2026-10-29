<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWisskiVolumeHelpers;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsWisskiVolumeHelpers
 * @group soda_scs_manager
 */
class SodaScsWisskiVolumeHelpersTest extends UnitTestCase {

  /**
   * The helpers under test.
   */
  protected SodaScsWisskiVolumeHelpers $volumeHelpers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->volumeHelpers = new SodaScsWisskiVolumeHelpers(
      $this->createMock(SodaScsContainerHelpers::class),
      $this->createMock(SodaScsSnapshotHelpers::class),
    );
  }

  /**
   * @covers ::buildRestoreCommand
   */
  public function testRestoreCommandKeepsTargetSettings(): void {
    $command = $this->volumeHelpers->buildRestoreCommand('wisski-volumes.tar.gz', TRUE);

    $this->assertStringContainsString('/volume-sites/default/settings*.php', $command);
    $this->assertStringContainsString('[ -f /keep/settings.php ] ||', $command, 'A target without settings.php must abort the restore.');
    $this->assertStringContainsString('cp -a /keep/. /volume-sites/default/', $command);
    $this->assertLessThan(
      strpos($command, 'rm -rf /volume-sites/*'),
      strpos($command, 'cp -a "$f" /keep/'),
      'Settings must be saved before the volume is purged.',
    );
    $this->assertGreaterThan(
      strpos($command, 'tar -xzf'),
      strpos($command, 'cp -a /keep/. /volume-sites/default/'),
      'Saved settings must be written back after the archive is extracted.',
    );
  }

  /**
   * @covers ::buildRestoreCommand
   */
  public function testRestoreCommandWithoutKeepingSettingsRestoresSnapshotAsIs(): void {
    $command = $this->volumeHelpers->buildRestoreCommand('snapshot.tar.gz', FALSE);

    $this->assertStringNotContainsString('/keep', $command);
    $this->assertStringContainsString("tar -xzf '/restore/snapshot.tar.gz'", $command);
  }

  /**
   * @covers ::buildRestoreCommand
   */
  public function testRestoreCommandQuotesTheArchiveName(): void {
    $command = $this->volumeHelpers->buildRestoreCommand("evil'; rm -rf /; '.tar.gz", FALSE);

    $this->assertStringContainsString("'/restore/evil'\\''; rm -rf /; '\\''.tar.gz'", $command);
  }

  /**
   * @covers ::privateFilesVolumeName
   * @covers ::sitesVolumeName
   */
  public function testVolumeNames(): void {
    $this->assertSame('wisski-anna-demo--drupal-sites', SodaScsWisskiVolumeHelpers::sitesVolumeName('wisski-anna-demo'));
    $this->assertSame('wisski-anna-demo--drupal-private-files', SodaScsWisskiVolumeHelpers::privateFilesVolumeName('wisski-anna-demo'));
  }

}
