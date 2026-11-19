<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Core\Field\FieldItemList;
use Drupal\soda_scs_manager\Demo\SodaScsDemoException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Demo\SodaScsDemoWisskiTransfer;
use Drupal\soda_scs_manager\Demo\SodaScsWisskiInstanceConfigRemapper;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsDatabaseHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWisskiVolumeHelpers;
use Drupal\soda_scs_manager\Service\SodaScsWisskiTriplestoreTokenSync;
use Drupal\soda_scs_manager\Service\SodaScsWisskiVarnishCache;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests the export and import of a demo WissKI.
 *
 * Issue 3639291: the install check counts an interrupted installation as not
 * done; after the container started again the Varnish container is restarted
 * and the import writes the owner's current token into the adapter. Every
 * service is a double.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsDemoWisskiTransfer
 * @group soda_scs_manager
 */
class SodaScsDemoWisskiTransferTest extends UnitTestCase {

  /**
   * Calls of the doubles, in the order they happened.
   *
   * @var string[]
   */
  private array $calls = [];

  /**
   * The container helpers double.
   */
  private SodaScsContainerHelpers&MockObject $containerHelpers;

  /**
   * The project apps double.
   */
  private SodaScsDemoProjectApps&MockObject $projectApps;

  /**
   * The instance config remapper double.
   */
  private SodaScsWisskiInstanceConfigRemapper&MockObject $configRemapper;

  /**
   * The token sync double.
   */
  private SodaScsWisskiTriplestoreTokenSync&MockObject $tokenSync;

  /**
   * The Varnish cache double.
   */
  private SodaScsWisskiVarnishCache&MockObject $varnishCache;

  /**
   * The WissKI component of the stack.
   */
  private SodaScsComponentInterface&MockObject $wisskiComponent;

  /**
   * The triplestore component of the stack.
   */
  private SodaScsComponentInterface&MockObject $triplestoreComponent;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->calls = [];
    $this->containerHelpers = $this->createMock(SodaScsContainerHelpers::class);
    $this->containerHelpers->method('getComponentContainerName')->willReturn('wisski-demo--drupal');
    $this->projectApps = $this->createMock(SodaScsDemoProjectApps::class);
    $this->configRemapper = $this->createMock(SodaScsWisskiInstanceConfigRemapper::class);
    $this->tokenSync = $this->createMock(SodaScsWisskiTriplestoreTokenSync::class);
    $this->varnishCache = $this->createMock(SodaScsWisskiVarnishCache::class);
    $this->wisskiComponent = $this->component('wisski-demo');
    $this->triplestoreComponent = $this->component('ts-demo');
    $this->projectApps->method('stackComponent')->willReturnCallback(fn (SodaScsStackInterface $stack, string $bundle): ?SodaScsComponentInterface => match ($bundle) {
      SodaScsDemoProjectApps::SQL_COMPONENT_BUNDLE => $this->component('sql-demo'),
      SodaScsDemoProjectApps::TRIPLESTORE_COMPONENT_BUNDLE => $this->triplestoreComponent,
      SodaScsDemoProjectApps::WISSKI_COMPONENT_BUNDLE => $this->wisskiComponent,
      default => NULL,
    });
  }

  /**
   * The check passes only with the complete marker and without the other.
   *
   * @covers ::isInstallComplete
   */
  public function testIsInstallCompleteTestsBothMarkersAsWwwData(): void {
    $this->containerHelpers->expects($this->once())->method('executeDockerExecCommand')
      ->with([
        'cmd' => [
          'test', '-f', '/opt/drupal/web/sites/.wisski-install-complete',
          '-a', '!', '-e', '/opt/drupal/web/sites/.wisski-install-in-progress',
        ],
        'containerName' => 'wisski-demo--drupal',
        'user' => 'www-data',
      ])
      ->willReturn(SodaScsResult::success([], 'ok'));

    $this->assertTrue($this->transfer()->isInstallComplete($this->createMock(SodaScsStackInterface::class)));
  }

  /**
   * A failing test command (marker missing or install in progress) is false.
   *
   * @covers ::isInstallComplete
   */
  public function testIsInstallCompleteIsFalseWhenTheTestFails(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'exit code 1', 'failed'));

    $this->assertFalse($this->transfer()->isInstallComplete($this->createMock(SodaScsStackInterface::class)));
  }

  /**
   * A stack without a WissKI component is not installed.
   *
   * @covers ::isInstallComplete
   */
  public function testIsInstallCompleteIsFalseWithoutWisskiComponent(): void {
    $projectApps = $this->createMock(SodaScsDemoProjectApps::class);
    $projectApps->method('stackComponent')->willReturn(NULL);
    $this->projectApps = $projectApps;
    $this->containerHelpers->expects($this->never())->method('executeDockerExecCommand');

    $this->assertFalse($this->transfer()->isInstallComplete($this->createMock(SodaScsStackInterface::class)));
  }

  /**
   * The import restarts Varnish after the WissKI started, before the config.
   *
   * @covers ::import
   */
  public function testImportRestartsVarnishAfterStartAndBeforeConfigRestore(): void {
    $this->useImportDoubles();
    $this->varnishCache->expects($this->once())->method('restart')->with('wisski-demo')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('varnish', SodaScsResult::success([], 'Restarted.')));
    $this->tokenSync->method('syncComponents')->willReturn(SodaScsResult::success(['state' => 'unchanged'], 'ok'));

    $this->transfer()->import($this->createMock(SodaScsStackInterface::class), '/tmp/template', 'Demo');

    $this->assertSame(['stop', 'start', 'varnish', 'apply-config'], array_slice($this->calls, 0, 4));
  }

  /**
   * The import writes the owner's token into the adapter after the config.
   *
   * @covers ::import
   */
  public function testImportSyncsTriplestoreTokenAfterConfigRestore(): void {
    $this->useImportDoubles();
    $this->varnishCache->method('restart')->willReturn(SodaScsResult::success([], 'Restarted.'));
    $this->tokenSync->expects($this->once())->method('syncComponents')
      ->with($this->wisskiComponent, $this->triplestoreComponent)
      ->willReturnCallback(fn (): SodaScsResult => $this->record('token-sync', SodaScsResult::success(['state' => 'updated'], 'ok')));

    $this->transfer()->import($this->createMock(SodaScsStackInterface::class), '/tmp/template', 'Demo');

    $this->assertSame('apply-config', $this->calls[count($this->calls) - 2]);
    $this->assertSame('token-sync', $this->calls[count($this->calls) - 1]);
  }

  /**
   * A failing token sync fails the import with the step named.
   *
   * @covers ::import
   */
  public function testImportThrowsWhenTokenSyncFails(): void {
    $this->useImportDoubles();
    $this->varnishCache->method('restart')->willReturn(SodaScsResult::success([], 'Restarted.'));
    $this->tokenSync->method('syncComponents')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'drush failed', 'Could not update the triplestore token of the WissKI.'));

    $this->expectException(SodaScsDemoException::class);
    $this->expectExceptionMessage('Update WissKI triplestore token: Could not update the triplestore token of the WissKI. drush failed');

    $this->transfer()->import($this->createMock(SodaScsStackInterface::class), '/tmp/template', 'Demo');
  }

  /**
   * A failing Varnish restart stops the import before the config is applied.
   *
   * @covers ::import
   */
  public function testImportThrowsAndStopsWhenVarnishRestartFails(): void {
    $this->useImportDoubles();
    $this->varnishCache->method('restart')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'Docker answered 500.', 'Could not restart the Varnish container.'));
    $this->configRemapper->expects($this->never())->method('applyInstanceConfig');
    $this->tokenSync->expects($this->never())->method('syncComponents');

    $this->expectException(SodaScsDemoException::class);
    $this->expectExceptionMessage('Restart Varnish: Could not restart the Varnish container. Docker answered 500.');

    $this->transfer()->import($this->createMock(SodaScsStackInterface::class), '/tmp/template', 'Demo');
  }

  /**
   * A WissKI that does not start leaves Varnish and the token alone.
   *
   * @covers ::import
   */
  public function testImportDoesNotRestartVarnishWhenWisskiDoesNotStart(): void {
    $this->useImportDoubles(startSucceeds: FALSE);
    $this->varnishCache->expects($this->never())->method('restart');
    $this->tokenSync->expects($this->never())->method('syncComponents');

    $this->expectException(SodaScsDemoException::class);
    $this->expectExceptionMessage('Start WissKI');

    $this->transfer()->import($this->createMock(SodaScsStackInterface::class), '/tmp/template', 'Demo');
  }

  /**
   * The export restarts Varnish once the WissKI runs again.
   *
   * @covers ::export
   */
  public function testExportRestartsVarnishAfterTheWisskiStarted(): void {
    $this->containerHelpers->method('stopContainer')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('stop', SodaScsResult::success([], 'Stopped.')));
    $this->containerHelpers->method('startContainer')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('start', SodaScsResult::success([], 'Started.')));
    $volumeHelpers = $this->createMock(SodaScsWisskiVolumeHelpers::class);
    $volumeHelpers->method('exportVolumes')->willReturn(SodaScsResult::success([], 'Exported.'));
    $databaseHelpers = $this->createMock(SodaScsDatabaseHelpers::class);
    $databaseHelpers->method('exportDatabaseToFile')->willReturn(SodaScsResult::success([], 'Exported.'));
    $triplestoreHelpers = $this->createMock(SodaScsTriplestoreHelpers::class);
    $triplestoreHelpers->method('exportTriplestoreRepository')->willReturn(SodaScsResult::success([], 'Exported.'));
    $this->varnishCache->expects($this->once())->method('restart')->with('wisski-demo')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('varnish', SodaScsResult::success([], 'Restarted.')));

    $this->transfer($databaseHelpers, $triplestoreHelpers, $volumeHelpers)
      ->export($this->createMock(SodaScsStackInterface::class), '/tmp/template');

    $this->assertSame(['stop', 'start', 'varnish'], $this->calls);
  }

  /**
   * A failing export still starts the WissKI, but leaves Varnish alone.
   *
   * @covers ::export
   */
  public function testExportStartsWisskiButNotVarnishWhenExportFails(): void {
    $this->containerHelpers->method('stopContainer')->willReturn(SodaScsResult::success([], 'Stopped.'));
    $this->containerHelpers->expects($this->once())->method('startContainer')
      ->willReturn(SodaScsResult::success([], 'Started.'));
    $volumeHelpers = $this->createMock(SodaScsWisskiVolumeHelpers::class);
    $volumeHelpers->method('exportVolumes')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'tar failed', 'Short-lived container failed.'));
    $this->varnishCache->expects($this->never())->method('restart');

    $this->expectException(SodaScsDemoException::class);
    $this->expectExceptionMessage('Export WissKI volumes');

    $this->transfer(NULL, NULL, $volumeHelpers)
      ->export($this->createMock(SodaScsStackInterface::class), '/tmp/template');
  }

  /**
   * Stubs every step of an import up to the Varnish restart.
   */
  private function useImportDoubles(bool $startSucceeds = TRUE): void {
    $this->configRemapper->method('captureInstanceConfig')
      ->willReturn(SodaScsResult::success(['configs' => ['system.site' => ['name' => 'Old']]], 'Captured.'));
    $this->configRemapper->method('applyInstanceConfig')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('apply-config', SodaScsResult::success([], 'Applied.')));
    $this->containerHelpers->method('stopContainer')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('stop', SodaScsResult::success([], 'Stopped.')));
    $this->containerHelpers->method('startContainer')
      ->willReturnCallback(fn (): SodaScsResult => $this->record(
        'start',
        $startSucceeds ? SodaScsResult::success([], 'Started.') : new SodaScsResult(FALSE, NULL, 'no such container', 'Start failed.'),
      ));
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(SodaScsResult::success(['output' => 'Successful'], 'ok'));
  }

  /**
   * Builds the transfer; the import helpers succeed unless given.
   */
  private function transfer(
    ?SodaScsDatabaseHelpers $databaseHelpers = NULL,
    ?SodaScsTriplestoreHelpers $triplestoreHelpers = NULL,
    ?SodaScsWisskiVolumeHelpers $volumeHelpers = NULL,
  ): SodaScsDemoWisskiTransfer {
    if ($databaseHelpers === NULL) {
      $databaseHelpers = $this->createMock(SodaScsDatabaseHelpers::class);
      $databaseHelpers->method('importDatabaseFromFile')->willReturn(SodaScsResult::success([], 'Imported.'));
    }
    if ($triplestoreHelpers === NULL) {
      $triplestoreHelpers = $this->createMock(SodaScsTriplestoreHelpers::class);
      $triplestoreHelpers->method('importTriplestoreRepository')->willReturn(SodaScsResult::success([], 'Imported.'));
    }
    if ($volumeHelpers === NULL) {
      $volumeHelpers = $this->createMock(SodaScsWisskiVolumeHelpers::class);
      $volumeHelpers->method('importVolumes')->willReturn(SodaScsResult::success([], 'Imported.'));
    }
    return new SodaScsDemoWisskiTransfer(
      $this->containerHelpers,
      $databaseHelpers,
      $this->projectApps,
      $this->configRemapper,
      $triplestoreHelpers,
      $this->tokenSync,
      $this->varnishCache,
      $volumeHelpers,
    );
  }

  /**
   * Records a call and returns the given result.
   */
  private function record(string $call, SodaScsResult $result): SodaScsResult {
    $this->calls[] = $call;
    return $result;
  }

  /**
   * Builds a component with a machine name.
   */
  private function component(string $machineName): SodaScsComponentInterface&MockObject {
    $field = $this->createMock(FieldItemList::class);
    $field->method('__get')->with('value')->willReturn($machineName);
    $component = $this->createMock(SodaScsComponentInterface::class);
    $component->method('get')->with('machineName')->willReturn($field);
    return $component;
  }

}
