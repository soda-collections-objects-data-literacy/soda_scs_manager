<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Core\Field\FieldItemList;
use Drupal\soda_scs_manager\Demo\SodaScsDemoException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Demo\SodaScsDemoWisskiTransfer;
use Drupal\soda_scs_manager\Demo\SodaScsWisskiInstanceConfigRemapper;
use Drupal\soda_scs_manager\Demo\SodaScsWisskiUriRewriter;
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
   * Base URIs of the WissKI the tests import into.
   */
  private const TARGET_BASES = [
    'graph' => 'https://target.example.org/contents/',
    'site' => 'https://target.example.org/',
  ];

  /**
   * Base URIs of the WissKI the template was built from.
   */
  private const SOURCE_BASES = [
    'graph' => 'https://source.example.org/contents/',
    'site' => 'https://source.example.org/',
  ];

  /**
   * Calls of the doubles, in the order they happened.
   *
   * @var string[]
   */
  private array $calls = [];

  /**
   * Temporary directories of the test, removed in tearDown().
   *
   * @var string[]
   */
  private array $directories = [];

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
   * The URI rewriter double; the static helpers stay real.
   */
  private SodaScsWisskiUriRewriter&MockObject $uriRewriter;

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
    $this->uriRewriter = $this->createMock(SodaScsWisskiUriRewriter::class);
    $this->uriRewriter->method('ownBases')->willReturn(SodaScsResult::success(['bases' => self::TARGET_BASES], 'Read.'));
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
   * The export returns the base URIs read from the running WissKI.
   *
   * @covers ::export
   */
  public function testExportReturnsTheBaseUrisOfTheWisski(): void {
    $this->useExportDoubles();

    $bases = $this->transfer(...$this->exportHelpers())
      ->export($this->createMock(SodaScsStackInterface::class), '/tmp/template');

    $this->assertSame(self::TARGET_BASES, $bases);
  }

  /**
   * The bases are read before the container stops (printenv needs it).
   *
   * @covers ::export
   */
  public function testExportReadsBasesBeforeStoppingTheContainer(): void {
    $this->uriRewriter = $this->createMock(SodaScsWisskiUriRewriter::class);
    $this->uriRewriter->method('ownBases')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('bases', SodaScsResult::success(['bases' => self::TARGET_BASES], 'Read.')));
    $this->useExportDoubles();

    $this->transfer(...$this->exportHelpers())
      ->export($this->createMock(SodaScsStackInterface::class), '/tmp/template');

    $this->assertSame(['bases', 'stop', 'start', 'varnish'], $this->calls);
  }

  /**
   * Bases that cannot be read stop the export before the WissKI is touched.
   *
   * @covers ::export
   */
  public function testExportFailsWithoutStoppingWhenBasesCannotBeRead(): void {
    $this->uriRewriter = $this->createMock(SodaScsWisskiUriRewriter::class);
    $this->uriRewriter->method('ownBases')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'no env', 'Could not read the base URIs of the WissKI.'));
    $this->containerHelpers->expects($this->never())->method('stopContainer');
    $this->varnishCache->expects($this->never())->method('restart');

    $this->expectException(SodaScsDemoException::class);
    $this->expectExceptionMessage('Read WissKI base URIs');

    $this->transfer(...$this->exportHelpers())
      ->export($this->createMock(SodaScsStackInterface::class), '/tmp/template');
  }

  /**
   * Without source bases the import neither reads bases nor rewrites.
   *
   * @covers ::import
   */
  public function testImportWithoutSourceBasesDoesNotRewrite(): void {
    $this->uriRewriter = $this->createMock(SodaScsWisskiUriRewriter::class);
    $this->uriRewriter->expects($this->never())->method('ownBases');
    $this->uriRewriter->expects($this->never())->method('rewriteDatabase');
    $this->useImportDoubles();
    $this->varnishCache->method('restart')->willReturn(SodaScsResult::success([], 'Restarted.'));
    $this->tokenSync->method('syncComponents')->willReturn(SodaScsResult::success([], 'ok'));
    $triplestoreHelpers = $this->createMock(SodaScsTriplestoreHelpers::class);
    $triplestoreHelpers->expects($this->once())->method('importTriplestoreRepository')
      ->with('ts-demo', '/tmp/template/wisski-triplestore.nq', 'nq')
      ->willReturn(SodaScsResult::success([], 'Imported.'));

    $this->transfer(NULL, $triplestoreHelpers)->import($this->createMock(SodaScsStackInterface::class), '/tmp/template', 'Demo');
  }

  /**
   * Identical bases leave the file and the database as they are.
   *
   * @covers ::import
   */
  public function testImportWithIdenticalBasesDoesNotRewrite(): void {
    $this->uriRewriter->expects($this->never())->method('rewriteDatabase');
    $this->useImportDoubles();
    $this->varnishCache->method('restart')->willReturn(SodaScsResult::success([], 'Restarted.'));
    $this->tokenSync->method('syncComponents')->willReturn(SodaScsResult::success([], 'ok'));
    $triplestoreHelpers = $this->createMock(SodaScsTriplestoreHelpers::class);
    $triplestoreHelpers->expects($this->once())->method('importTriplestoreRepository')
      ->with('ts-demo', '/tmp/template/wisski-triplestore.nq', 'nq')
      ->willReturn(SodaScsResult::success([], 'Imported.'));

    $this->transfer(NULL, $triplestoreHelpers)
      ->import($this->createMock(SodaScsStackInterface::class), '/tmp/template', 'Demo', self::TARGET_BASES);
  }

  /**
   * Different bases import a rewritten copy; the template file is unchanged.
   *
   * @covers ::import
   * @covers ::importTriplestore
   */
  public function testImportImportsRewrittenCopyAndKeepsTheTemplateFile(): void {
    $directory = $this->temporaryDirectory();
    $original = '<https://source.example.org/contents/a> <https://source.example.org/contents/p> "x" <https://source.example.org/contents/g> .' . "\n";
    file_put_contents($directory . '/wisski-triplestore.nq', $original);
    $this->useImportDoubles();
    $this->varnishCache->method('restart')->willReturn(SodaScsResult::success([], 'Restarted.'));
    $this->tokenSync->method('syncComponents')->willReturn(SodaScsResult::success([], 'ok'));
    $this->uriRewriter->method('rewriteDatabase')->willReturn(SodaScsResult::success(['rows' => 0, 'config' => 0], 'ok'));
    $imported = [];
    $triplestoreHelpers = $this->createMock(SodaScsTriplestoreHelpers::class);
    $triplestoreHelpers->method('importTriplestoreRepository')
      ->willReturnCallback(function (string $repository, string $file) use (&$imported): SodaScsResult {
        $imported = ['file' => $file, 'content' => file_get_contents($file)];
        return SodaScsResult::success([], 'Imported.');
      });

    $this->transfer(NULL, $triplestoreHelpers)
      ->import($this->createMock(SodaScsStackInterface::class), $directory, 'Demo', self::SOURCE_BASES);

    $this->assertNotSame($directory . '/wisski-triplestore.nq', $imported['file']);
    $this->assertSame(
      '<https://target.example.org/contents/a> <https://target.example.org/contents/p> "x" <https://target.example.org/contents/g> .' . "\n",
      $imported['content'],
    );
    $this->assertSame($original, file_get_contents($directory . '/wisski-triplestore.nq'));
    $this->assertFileDoesNotExist($imported['file'], 'The rewritten copy is deleted after the import.');
  }

  /**
   * The rewritten copy is deleted when the triplestore import fails.
   *
   * @covers ::importTriplestore
   */
  public function testImportDeletesTheRewrittenCopyWhenTheTriplestoreImportFails(): void {
    $directory = $this->temporaryDirectory();
    file_put_contents($directory . '/wisski-triplestore.nq', '<https://source.example.org/contents/a> <https://source.example.org/contents/p> "x" .' . "\n");
    $this->useImportDoubles();
    $this->varnishCache->expects($this->never())->method('restart');
    $this->uriRewriter->expects($this->never())->method('rewriteDatabase');
    $copy = NULL;
    $triplestoreHelpers = $this->createMock(SodaScsTriplestoreHelpers::class);
    $triplestoreHelpers->method('importTriplestoreRepository')
      ->willReturnCallback(function (string $repository, string $file) use (&$copy): SodaScsResult {
        $copy = $file;
        return new SodaScsResult(FALSE, NULL, 'HTTP 500', 'Could not import.');
      });

    try {
      $this->transfer(NULL, $triplestoreHelpers)
        ->import($this->createMock(SodaScsStackInterface::class), $directory, 'Demo', self::SOURCE_BASES);
      $this->fail('Expected a SodaScsDemoException.');
    }
    catch (SodaScsDemoException $exception) {
      $this->assertStringContainsString('Import WissKI triplestore', $exception->getMessage());
    }
    $this->assertIsString($copy);
    $this->assertFileDoesNotExist($copy);
    $this->assertContains('start', $this->calls, 'The WissKI is started again.');
  }

  /**
   * A missing template file fails the import, with the WissKI started again.
   *
   * @covers ::importTriplestore
   */
  public function testImportThrowsWhenTheTemplateTriplestoreFileIsMissing(): void {
    $directory = $this->temporaryDirectory();
    $this->useImportDoubles();
    $this->uriRewriter->expects($this->never())->method('rewriteDatabase');
    $triplestoreHelpers = $this->createMock(SodaScsTriplestoreHelpers::class);
    $triplestoreHelpers->expects($this->never())->method('importTriplestoreRepository');

    try {
      $this->transfer(NULL, $triplestoreHelpers)
        ->import($this->createMock(SodaScsStackInterface::class), $directory, 'Demo', self::SOURCE_BASES);
      $this->fail('Expected a SodaScsDemoException.');
    }
    catch (SodaScsDemoException $exception) {
      $this->assertStringContainsString('Import WissKI triplestore: Cannot read', $exception->getMessage());
    }
    $this->assertContains('start', $this->calls);
  }

  /**
   * The database is rewritten after Drush answers and before the config.
   *
   * @covers ::import
   */
  public function testImportRewritesTheDatabaseBeforeTheInstanceConfig(): void {
    $this->useImportDoubles();
    $this->varnishCache->method('restart')->willReturn(SodaScsResult::success([], 'Restarted.'));
    $this->tokenSync->method('syncComponents')->willReturn(SodaScsResult::success([], 'ok'));
    $this->uriRewriter->expects($this->once())->method('rewriteDatabase')
      ->with(
        $this->wisskiComponent,
        [
          'https://source.example.org/' => 'https://target.example.org/',
          'https://source.example.org/contents/' => 'https://target.example.org/contents/',
        ],
      )
      ->willReturnCallback(fn (): SodaScsResult => $this->record(
        'rewrite-db',
        SodaScsResult::success(['rows' => 3, 'config' => 1], 'ok'),
      ));
    $directory = $this->temporaryDirectory();
    file_put_contents($directory . '/wisski-triplestore.nq', "\n");

    $this->transfer()->import($this->createMock(SodaScsStackInterface::class), $directory, 'Demo', self::SOURCE_BASES);

    $this->assertSame(['stop', 'start', 'rewrite-db', 'apply-config'], $this->calls);
  }

  /**
   * A failed database rewrite still restores the target's own configuration.
   *
   * The imported database holds the source's adapter and SSO settings; the
   * failure is reported after the instance config and the token went back.
   *
   * @covers ::import
   */
  public function testImportRestoresTheInstanceConfigAndThrowsWhenTheDatabaseRewriteFails(): void {
    $this->useImportDoubles();
    $this->varnishCache->method('restart')->willReturn(SodaScsResult::success([], 'Restarted.'));
    $this->uriRewriter->method('rewriteDatabase')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('rewrite-db', new SodaScsResult(FALSE, NULL, 'drush failed', 'Could not rewrite the URIs of the WissKI.')));
    $this->tokenSync->expects($this->once())->method('syncComponents')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('token-sync', SodaScsResult::success([], 'ok')));
    $directory = $this->temporaryDirectory();
    file_put_contents($directory . '/wisski-triplestore.nq', "\n");

    try {
      $this->transfer()->import($this->createMock(SodaScsStackInterface::class), $directory, 'Demo', self::SOURCE_BASES);
      $this->fail('Expected a SodaScsDemoException.');
    }
    catch (SodaScsDemoException $exception) {
      $this->assertSame('Rewrite WissKI URIs: Could not rewrite the URIs of the WissKI. drush failed', $exception->getMessage());
    }
    $this->assertSame(['stop', 'start', 'rewrite-db', 'apply-config', 'token-sync'], $this->calls);
  }

  /**
   * When the restore fails as well, both reasons are reported.
   *
   * @covers ::import
   */
  public function testImportReportsBothFailuresWhenTheRewriteAndTheRestoreFail(): void {
    $this->useImportDoubles();
    $this->configRemapper = $this->createMock(SodaScsWisskiInstanceConfigRemapper::class);
    $this->configRemapper->method('captureInstanceConfig')
      ->willReturn(SodaScsResult::success(['configs' => []], 'Captured.'));
    $this->configRemapper->method('applyInstanceConfig')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'drush config:set failed', 'Could not restore.'));
    $this->varnishCache->method('restart')->willReturn(SodaScsResult::success([], 'Restarted.'));
    $this->uriRewriter->method('rewriteDatabase')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'drush failed', 'Could not rewrite the URIs of the WissKI.'));
    $directory = $this->temporaryDirectory();
    file_put_contents($directory . '/wisski-triplestore.nq', "\n");

    try {
      $this->transfer()->import($this->createMock(SodaScsStackInterface::class), $directory, 'Demo', self::SOURCE_BASES);
      $this->fail('Expected a SodaScsDemoException.');
    }
    catch (SodaScsDemoException $exception) {
      $this->assertStringContainsString('Rewrite WissKI URIs: Could not rewrite the URIs of the WissKI. drush failed', $exception->getMessage());
      $this->assertStringContainsString('Restoring the WissKI instance configuration failed as well: Restore WissKI instance configuration: Could not restore. drush config:set failed', $exception->getMessage());
      $this->assertInstanceOf(SodaScsDemoException::class, $exception->getPrevious());
      $this->assertStringStartsWith('Rewrite WissKI URIs', $exception->getPrevious()->getMessage());
    }
  }

  /**
   * A failing restore without an earlier failure is reported as it is.
   *
   * @covers ::import
   */
  public function testImportThrowsTheRestoreFailureWhenNothingElseFailed(): void {
    $this->useImportDoubles();
    $this->configRemapper = $this->createMock(SodaScsWisskiInstanceConfigRemapper::class);
    $this->configRemapper->method('captureInstanceConfig')
      ->willReturn(SodaScsResult::success(['configs' => []], 'Captured.'));
    $this->configRemapper->method('applyInstanceConfig')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'drush config:set failed', 'Could not restore.'));
    $this->varnishCache->method('restart')->willReturn(SodaScsResult::success([], 'Restarted.'));
    $this->tokenSync->expects($this->never())->method('syncComponents');

    $this->expectException(SodaScsDemoException::class);
    $this->expectExceptionMessage('Restore WissKI instance configuration: Could not restore. drush config:set failed');

    $this->transfer()->import($this->createMock(SodaScsStackInterface::class), '/tmp/template', 'Demo');
  }

  /**
   * Unreadable bases of the target stop the import before anything stops.
   *
   * @covers ::import
   */
  public function testImportFailsWithoutStoppingWhenTargetBasesCannotBeRead(): void {
    $this->uriRewriter = $this->createMock(SodaScsWisskiUriRewriter::class);
    $this->uriRewriter->method('ownBases')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'no env', 'Could not read the base URIs of the WissKI.'));
    $this->configRemapper->method('captureInstanceConfig')
      ->willReturn(SodaScsResult::success(['configs' => []], 'Captured.'));
    $this->containerHelpers->expects($this->never())->method('stopContainer');

    $this->expectException(SodaScsDemoException::class);
    $this->expectExceptionMessage('Read WissKI base URIs');

    $this->transfer()->import($this->createMock(SodaScsStackInterface::class), '/tmp/template', 'Demo', self::SOURCE_BASES);
  }

  /**
   * Source bases that are not bases (a tampered manifest) rewrite nothing.
   *
   * @covers ::import
   */
  public function testImportWithInvalidSourceBasesDoesNotRewrite(): void {
    $this->uriRewriter->expects($this->never())->method('rewriteDatabase');
    $this->useImportDoubles();
    $this->varnishCache->method('restart')->willReturn(SodaScsResult::success([], 'Restarted.'));
    $this->tokenSync->method('syncComponents')->willReturn(SodaScsResult::success([], 'ok'));
    $triplestoreHelpers = $this->createMock(SodaScsTriplestoreHelpers::class);
    $triplestoreHelpers->expects($this->once())->method('importTriplestoreRepository')
      ->with('ts-demo', '/tmp/template/wisski-triplestore.nq', 'nq')
      ->willReturn(SodaScsResult::success([], 'Imported.'));

    $this->transfer(NULL, $triplestoreHelpers)->import(
      $this->createMock(SodaScsStackInterface::class),
      '/tmp/template',
      'Demo',
      ['graph' => 'javascript:alert(1)', 'site' => 'not a url'],
    );
  }

  /**
   * Stubs the container and Varnish for a successful export.
   */
  private function useExportDoubles(): void {
    $this->containerHelpers->method('stopContainer')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('stop', SodaScsResult::success([], 'Stopped.')));
    $this->containerHelpers->method('startContainer')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('start', SodaScsResult::success([], 'Started.')));
    $this->varnishCache->method('restart')
      ->willReturnCallback(fn (): SodaScsResult => $this->record('varnish', SodaScsResult::success([], 'Restarted.')));
  }

  /**
   * Helpers whose export methods succeed, as arguments of transfer().
   *
   * @return array{0: \Drupal\soda_scs_manager\Helpers\SodaScsDatabaseHelpers, 1: \Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers, 2: \Drupal\soda_scs_manager\Helpers\SodaScsWisskiVolumeHelpers}
   *   Database, triplestore and volume helper doubles.
   */
  private function exportHelpers(): array {
    $databaseHelpers = $this->createMock(SodaScsDatabaseHelpers::class);
    $databaseHelpers->method('exportDatabaseToFile')->willReturn(SodaScsResult::success([], 'Exported.'));
    $triplestoreHelpers = $this->createMock(SodaScsTriplestoreHelpers::class);
    $triplestoreHelpers->method('exportTriplestoreRepository')->willReturn(SodaScsResult::success([], 'Exported.'));
    $volumeHelpers = $this->createMock(SodaScsWisskiVolumeHelpers::class);
    $volumeHelpers->method('exportVolumes')->willReturn(SodaScsResult::success([], 'Exported.'));
    return [$databaseHelpers, $triplestoreHelpers, $volumeHelpers];
  }

  /**
   * Creates a temporary directory that tearDown() removes.
   */
  private function temporaryDirectory(): string {
    $directory = sys_get_temp_dir() . '/scs-transfer-test-' . bin2hex(random_bytes(4));
    mkdir($directory);
    $this->directories[] = $directory;
    return $directory;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->directories as $directory) {
      array_map('unlink', glob($directory . '/*') ?: []);
      @rmdir($directory);
    }
    parent::tearDown();
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
      $this->uriRewriter,
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
