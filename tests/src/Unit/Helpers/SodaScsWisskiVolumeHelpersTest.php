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
   * The install state files of the target survive an import of another site.
   *
   * A half-finished install of the source would otherwise stop the target
   * from starting again.
   *
   * @covers ::buildRestoreCommand
   */
  public function testRestoreCommandKeepsInstallStateFilesOfTheTarget(): void {
    $command = $this->volumeHelpers->buildRestoreCommand('wisski-volumes.tar.gz', TRUE);

    $this->assertStringContainsString('/volume-sites/.wisski-install-* /volume-sites/.wisski-packages-version', $command);
    $this->assertStringContainsString('cp -a "$f" /keep-state/', $command);
    $this->assertStringContainsString('rm -f /volume-sites/.wisski-install-* /volume-sites/.wisski-packages-version && cp -a /keep-state/. /volume-sites/', $command);
    $this->assertLessThan(
      strpos($command, 'rm -rf /volume-sites/*'),
      strpos($command, 'cp -a "$f" /keep-state/'),
      'The state files must be saved before the volume is purged.',
    );
    $this->assertGreaterThan(
      strpos($command, 'tar -xzf'),
      strpos($command, 'cp -a /keep-state/. /volume-sites/'),
      'The saved state files must be written back after the archive is extracted.',
    );
    $this->assertLessThan(
      strpos($command, 'cp -a /keep-state/. /volume-sites/'),
      strpos($command, 'rm -f /volume-sites/.wisski-install-*'),
      'State files of the archive are removed before the saved ones are written back.',
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
