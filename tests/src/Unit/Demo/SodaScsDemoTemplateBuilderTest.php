<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\soda_scs_manager\Demo\SodaScsDemoException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateBuilder;
use Drupal\soda_scs_manager\Demo\SodaScsDemoWisskiTransfer;
use Drupal\soda_scs_manager\Demo\SodaScsTeamFolderCopier;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsVolumeArchiveHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Tests the build of a demo template from the source project.
 *
 * Issue 3639291: a source WissKI that is still installing is not exported
 * (the export stops its container), and the Jupyter home export leaves the
 * server secrets, history and caches of the source user out. All services
 * are doubles; the artifacts go to a temporary directory.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateBuilder
 * @group soda_scs_manager
 */
class SodaScsDemoTemplateBuilderTest extends UnitTestCase {

  /**
   * The paths that stay out of the template, as the builder anchors them.
   */
  private const EXPECTED_EXCLUDES = [
    './.cache',
    './.ipython/profile_default/history.sqlite',
    './.jupyter/collaboration_sessions.json',
    './.jupyter/lab/workspaces',
    './.jupyter_ystore.db',
    './.local/share/jupyter/notebook_secret',
    './.local/share/jupyter/runtime',
  ];

  /**
   * Base URIs the WissKI export reports.
   */
  private const URI_BASES = ['graph' => 'https://source.example.org/contents/', 'site' => 'https://source.example.org/'];

  /**
   * The Jupyter helpers double.
   */
  private SodaScsJupyterHelpers&MockObject $jupyterHelpers;

  /**
   * The project apps double.
   */
  private SodaScsDemoProjectApps&MockObject $projectApps;

  /**
   * The WissKI transfer double.
   */
  private SodaScsDemoWisskiTransfer&MockObject $wisskiTransfer;

  /**
   * The Team Folder copier double.
   */
  private SodaScsTeamFolderCopier&MockObject $teamFolderCopier;

  /**
   * The container helpers double behind the volume archive helpers.
   */
  private SodaScsContainerHelpers&MockObject $containerHelpers;

  /**
   * The logger double.
   */
  private LoggerInterface&MockObject $logger;

  /**
   * The template double.
   */
  private SodaScsDemoTemplateInterface&MockObject $template;

  /**
   * The temporary artifact directory of the template.
   */
  private string $directory;

  /**
   * Statuses the template was set to.
   *
   * @var string[]
   */
  private array $statuses = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = sys_get_temp_dir() . '/scs-template-builder-' . bin2hex(random_bytes(4));
    $this->jupyterHelpers = $this->createMock(SodaScsJupyterHelpers::class);
    $this->projectApps = $this->createMock(SodaScsDemoProjectApps::class);
    $this->wisskiTransfer = $this->createMock(SodaScsDemoWisskiTransfer::class);
    $this->teamFolderCopier = $this->createMock(SodaScsTeamFolderCopier::class);
    $this->containerHelpers = $this->createMock(SodaScsContainerHelpers::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $this->template->method('getArtifactDirectory')->willReturn($this->directory);
    $this->template->method('getVersion')->willReturn('1.0.0');
    $this->template->method('getChangelog')->willReturn('Test.');
    $this->template->method('setStatus')->willReturnCallback(function (string $status): SodaScsDemoTemplateInterface {
      $this->statuses[] = $status;
      return $this->template;
    });
    $this->template->method('setManifest')->willReturnSelf();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->directory);
    parent::tearDown();
  }

  /**
   * A source WissKI in its installation is not exported or stopped.
   *
   * @covers ::build
   */
  public function testBuildThrowsBeforeAnyStopWhenInstallIsNotComplete(): void {
    $this->useSource($this->createStack('Source WissKI'), NULL);
    $this->wisskiTransfer->expects($this->once())->method('isInstallComplete')->willReturn(FALSE);
    $this->wisskiTransfer->expects($this->never())->method('export');
    $this->jupyterHelpers->expects($this->never())->method('stopProjectServerAndWait');
    $this->teamFolderCopier->expects($this->never())->method('exportTeamFolder');
    $this->containerHelpers->expects($this->never())->method('runShortLivedContainer');

    $this->expectException(SodaScsDemoException::class);
    $this->expectExceptionMessage('The WissKI Source WissKI of the source project has not finished its installation.');

    $this->builder()->build($this->template);
  }

  /**
   * The unfinished install marks the template failed and logs the reason.
   *
   * @covers ::build
   */
  public function testBuildMarksTemplateFailedWhenInstallIsNotComplete(): void {
    $this->useSource($this->createStack('Source WissKI'), NULL);
    $this->wisskiTransfer->method('isInstallComplete')->willReturn(FALSE);
    $this->template->expects($this->once())->method('save');
    $this->template->expects($this->never())->method('setManifest');
    $this->logger->expects($this->once())->method('error')->with(
      $this->stringContains('Building demo template'),
      $this->callback(function (array $context): bool {
        $this->assertSame('1.0.0', $context['@version']);
        $this->assertStringContainsString('has not finished its installation', $context['@reason']);
        return TRUE;
      }),
    );

    try {
      $this->builder()->build($this->template);
      $this->fail('Expected a SodaScsDemoException.');
    }
    catch (SodaScsDemoException) {
      $this->assertSame([SodaScsDemoTemplateInterface::STATUS_FAILED], $this->statuses);
    }
  }

  /**
   * A finished install lets the export start.
   *
   * @covers ::build
   */
  public function testBuildExportsWisskiWhenInstallIsComplete(): void {
    $stack = $this->createStack('Source WissKI');
    $this->useSource($stack, NULL);
    $this->wisskiTransfer->method('isInstallComplete')->with($stack)->willReturn(TRUE);
    $this->wisskiTransfer->expects($this->once())->method('export')
      ->with($stack, $this->directory . '/wisski')
      ->willThrowException(new SodaScsDemoException('Export stopped here.'));

    $this->expectException(SodaScsDemoException::class);
    $this->expectExceptionMessage('Export stopped here.');

    $this->builder()->build($this->template);
  }

  /**
   * The base URIs the WissKI export returns land in the manifest.
   *
   * @covers ::build
   */
  public function testBuildStoresTheBaseUrisOfTheWisskiInTheManifest(): void {
    $this->useSuccessfulWisskiExport();
    $manifest = NULL;
    $this->template = $this->recordingTemplate($manifest);

    $this->builder()->build($this->template);

    $this->assertSame(self::URI_BASES, $manifest['apps']['wisski']['uriBases']);
    $this->assertSame('wisski', $manifest['apps']['wisski']['directory']);
    $this->assertSame(21, $manifest['apps']['wisski']['sourceStackId']);
  }

  /**
   * The file manifest.json holds the manifest with the format version.
   *
   * @covers ::build
   */
  public function testBuildWritesManifestFileWithFormatVersionAndBases(): void {
    $this->useSuccessfulWisskiExport();
    $manifest = NULL;
    $this->template = $this->recordingTemplate($manifest);

    $this->builder()->build($this->template);

    $file = json_decode((string) file_get_contents($this->directory . '/manifest.json'), TRUE);
    $this->assertSame(1, $file['formatVersion']);
    $this->assertSame('1.0.0', $file['version']);
    $this->assertSame(self::URI_BASES, $file['apps']['wisski']['uriBases']);
    $this->assertSame($manifest['checksums'], $file['checksums']);
  }

  /**
   * The file manifest.json is not part of its own checksums; the files are.
   *
   * @covers ::build
   */
  public function testBuildKeepsManifestFileOutOfTheChecksums(): void {
    $this->useSuccessfulWisskiExport();
    $manifest = NULL;
    $this->template = $this->recordingTemplate($manifest);

    $this->builder()->build($this->template);

    $this->assertEqualsCanonicalizing(
      ['wisski/wisski-database.sql', 'wisski/wisski-triplestore.nq', 'wisski/wisski-volumes.tar.gz'],
      array_keys($manifest['checksums']),
    );
    $this->assertSame(hash('sha256', 'SELECT 1;'), $manifest['checksums']['wisski/wisski-database.sql']);
  }

  /**
   * A manifest.json that cannot be written fails the build.
   *
   * @covers ::build
   */
  public function testBuildFailsWhenManifestFileCannotBeWritten(): void {
    $stack = $this->createStack('Source WissKI');
    $this->useSource($stack, NULL);
    $this->wisskiTransfer->method('isInstallComplete')->willReturn(TRUE);
    $this->wisskiTransfer->method('export')->willReturnCallback(function (): array {
      // A directory in the way makes file_put_contents() fail.
      $this->writeWisskiFiles($this->directory . '/wisski');
      mkdir($this->directory . '/manifest.json');
      return self::URI_BASES;
    });
    $this->teamFolderCopier->method('exportTeamFolder')->willReturn(SodaScsResult::success(['fileCount' => 0], 'Exported.'));
    $this->template->expects($this->never())->method('setManifest');
    set_error_handler(static fn (): bool => TRUE);

    try {
      $this->builder()->build($this->template);
      $this->fail('Expected a SodaScsDemoException.');
    }
    catch (SodaScsDemoException $exception) {
      $this->assertStringContainsString('Could not write manifest.json', $exception->getMessage());
      $this->assertSame([SodaScsDemoTemplateInterface::STATUS_FAILED], $this->statuses);
    }
    finally {
      restore_error_handler();
    }
  }

  /**
   * Lets the source have a WissKI whose export writes a file and returns bases.
   */
  private function useSuccessfulWisskiExport(): void {
    $stack = $this->createStack('Source WissKI');
    $this->useSource($stack, NULL);
    $this->wisskiTransfer->method('isInstallComplete')->willReturn(TRUE);
    $this->wisskiTransfer->method('export')->willReturnCallback(function (SodaScsStackInterface $stack, string $directory): array {
      $this->writeWisskiFiles($directory);
      return self::URI_BASES;
    });
    $this->teamFolderCopier->method('exportTeamFolder')->willReturn(SodaScsResult::success(['fileCount' => 0], 'Exported.'));
  }

  /**
   * Writes the three files a WissKI export leaves in its directory.
   */
  private function writeWisskiFiles(string $directory): void {
    file_put_contents($directory . '/wisski-database.sql', 'SELECT 1;');
    file_put_contents($directory . '/wisski-triplestore.nq', '');
    file_put_contents($directory . '/wisski-volumes.tar.gz', 'volumes');
  }

  /**
   * A template double that records the manifest it is given.
   *
   * @param array|null $manifest
   *   Receives the manifest.
   */
  private function recordingTemplate(?array &$manifest): SodaScsDemoTemplateInterface&MockObject {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('getArtifactDirectory')->willReturn($this->directory);
    $template->method('getVersion')->willReturn('1.0.0');
    $template->method('getChangelog')->willReturn('Test.');
    $template->method('get')->with('sourceProject')->willReturn($this->template->get('sourceProject'));
    $template->method('setStatus')->willReturnCallback(function (string $status) use ($template): SodaScsDemoTemplateInterface {
      $this->statuses[] = $status;
      return $template;
    });
    $template->method('setManifest')->willReturnCallback(function (array $given) use (&$manifest, $template): SodaScsDemoTemplateInterface {
      $manifest = $given;
      return $template;
    });
    return $template;
  }

  /**
   * The Jupyter home archive excludes secrets, history and caches.
   *
   * @covers ::build
   * @covers ::exportJupyterHome
   */
  public function testBuildExcludesJupyterSecretsAndCachesFromTheHomeExport(): void {
    $this->useSource(NULL, $this->createStack('Jupyter'));
    $this->jupyterHelpers->method('projectServerState')->willReturn('stopped');
    $this->jupyterHelpers->method('stopProjectServerAndWait')
      ->willReturn(SodaScsResult::success([], 'Stopped.'));
    $this->teamFolderCopier->method('exportTeamFolder')
      ->willReturn(SodaScsResult::success(['fileCount' => 0], 'Exported.'));
    $command = NULL;
    $this->containerHelpers->expects($this->once())->method('runShortLivedContainer')
      ->willReturnCallback(function (string $name, array $cmd) use (&$command): SodaScsResult {
        $command = $cmd;
        file_put_contents($this->directory . '/jupyter-home.tar.gz', 'archive');
        return SodaScsResult::success([], 'Archived.');
      });

    $this->builder()->build($this->template);

    $this->assertSame([SodaScsDemoTemplateInterface::STATUS_READY], $this->statuses);
    $this->assertIsArray($command);
    $this->assertSame(['sh', '-c'], array_slice($command, 0, 2));
    foreach (self::EXPECTED_EXCLUDES as $path) {
      $this->assertStringContainsString('--exclude=' . escapeshellarg($path), $command[2]);
    }
    $this->assertStringContainsString('--exclude=' . escapeshellarg('.wisski-demo-api.env'), $command[2]);
    $this->assertStringContainsString('-C /volume .', $command[2]);
  }

  /**
   * A failing archive step keeps the template from being ready.
   *
   * @covers ::build
   * @covers ::exportJupyterHome
   */
  public function testBuildFailsWhenTheJupyterHomeExportFails(): void {
    $this->useSource(NULL, $this->createStack('Jupyter'));
    $this->jupyterHelpers->method('projectServerState')->willReturn('stopped');
    $this->jupyterHelpers->method('stopProjectServerAndWait')
      ->willReturn(SodaScsResult::success([], 'Stopped.'));
    $this->containerHelpers->method('runShortLivedContainer')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'tar failed', 'Short-lived container failed.'));
    $this->teamFolderCopier->expects($this->never())->method('exportTeamFolder');

    try {
      $this->builder()->build($this->template);
      $this->fail('Expected a SodaScsDemoException.');
    }
    catch (SodaScsDemoException $exception) {
      $this->assertStringContainsString('Export Jupyter home', $exception->getMessage());
      $this->assertSame([SodaScsDemoTemplateInterface::STATUS_FAILED], $this->statuses);
    }
  }

  /**
   * Lets the builder find a WissKI stack and a Jupyter stack in the source.
   */
  private function useSource(?SodaScsStackInterface $wisskiStack, ?SodaScsStackInterface $jupyterStack): void {
    $gid = $this->createMock(FieldItemList::class);
    $gid->method('__isset')->willReturn(TRUE);
    $gid->method('__get')->with('value')->willReturn('5');
    $source = $this->createMock(SodaScsProjectInterface::class);
    $source->method('id')->willReturn('34');
    $source->method('get')->with('groupId')->willReturn($gid);
    $sourceField = $this->createMock(FieldItemList::class);
    $sourceField->method('__get')->with('entity')->willReturn($source);
    $this->template->method('get')->with('sourceProject')->willReturn($sourceField);
    $this->projectApps->method('wisskiStack')->willReturn($wisskiStack);
    $this->projectApps->method('jupyterStack')->willReturn($jupyterStack);
    $this->projectApps->method('webprotegeComponent')->willReturn(NULL);
  }

  /**
   * Builds a stack double.
   */
  private function createStack(string $label): SodaScsStackInterface {
    $stack = $this->createMock(SodaScsStackInterface::class);
    $stack->method('label')->willReturn($label);
    $stack->method('id')->willReturn('21');
    return $stack;
  }

  /**
   * Builds the builder with the real volume archive helpers on a double.
   */
  private function builder(): SodaScsDemoTemplateBuilder {
    $snapshotHelpers = $this->createMock(SodaScsSnapshotHelpers::class);
    $snapshotHelpers->method('generateRandomSuffix')->willReturn('abc123');
    $snapshotHelpers->method('convertContainerPathToHostPath')->willReturnArgument(0);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1700000000);
    $builder = new SodaScsDemoTemplateBuilder(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->jupyterHelpers,
      $this->logger,
      $this->projectApps,
      $snapshotHelpers,
      $this->teamFolderCopier,
      $time,
      new SodaScsVolumeArchiveHelpers($this->containerHelpers, $snapshotHelpers),
      $this->createMock(SodaScsWebprotegeHelpers::class),
      $this->wisskiTransfer,
    );
    $builder->setStringTranslation($this->getStringTranslationStub());
    return $builder;
  }

  /**
   * Removes a directory tree, if it exists.
   */
  private function removeDirectory(string $directory): void {
    if (!is_dir($directory)) {
      return;
    }
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
      $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
  }

}
