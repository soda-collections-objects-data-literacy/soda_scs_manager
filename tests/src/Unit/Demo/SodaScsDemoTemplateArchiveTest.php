<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateArchive;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateBuilder;
use Drupal\soda_scs_manager\Demo\SodaScsWisskiUriRewriter;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Tests the export and import of demo version archives.
 *
 * Issue 3640915. Real tar and real temporary directories; the demo manager,
 * the template builder and the entity storage are doubles. An import that
 * fails must leave neither the temporary directory nor the version behind.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateArchive
 * @group soda_scs_manager
 */
class SodaScsDemoTemplateArchiveTest extends UnitTestCase {

  private const VERSION = '1.2.0';

  private const URI_BASES = ['graph' => 'https://source.example.org/contents/', 'site' => 'https://source.example.org/'];

  /**
   * Temporary base directory of the test.
   */
  private string $baseDirectory;

  /**
   * The templates root of the site under test.
   */
  private string $templatesRoot;

  /**
   * Where archives are written and read.
   */
  private string $archiveDirectory;

  /**
   * The demo manager double.
   */
  private SodaScsDemoManager&MockObject $demoManager;

  /**
   * The template storage double.
   */
  private EntityStorageInterface&MockObject $storage;

  /**
   * The logger double.
   */
  private LoggerInterface&MockObject $logger;

  /**
   * The URI rewriter double.
   */
  private SodaScsWisskiUriRewriter&MockObject $uriRewriter;

  /**
   * Values the storage was asked to create entities with.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $createdValues = [];

  /**
   * The entity the storage creates.
   */
  private SodaScsDemoTemplateInterface&MockObject $createdTemplate;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // SodaScsResult::failure() logs through \Drupal::logger().
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);
    $this->baseDirectory = sys_get_temp_dir() . '/scs-archive-test-' . bin2hex(random_bytes(4));
    $this->templatesRoot = $this->baseDirectory . '/templates';
    $this->archiveDirectory = $this->baseDirectory . '/archives';
    mkdir($this->templatesRoot, 0775, TRUE);
    mkdir($this->archiveDirectory, 0775, TRUE);

    $this->demoManager = $this->createMock(SodaScsDemoManager::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->uriRewriter = $this->createMock(SodaScsWisskiUriRewriter::class);
    $this->createdValues = [];
    $this->createdTemplate = $this->createMock(SodaScsDemoTemplateInterface::class);
    $this->createdTemplate->method('setManifest')->willReturnSelf();
    // @phpstan-ignore drupal.entityStoragePropertyAssignment (a double the test configures per case, not an injected service)
    $this->storage = $this->createMock(EntityStorageInterface::class);
    $this->storage->method('create')->willReturnCallback(function (array $values): SodaScsDemoTemplateInterface {
      $this->createdValues[] = $values;
      return $this->createdTemplate;
    });
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeTree($this->baseDirectory);
    parent::tearDown();
  }

  /**
   * Files that match their checksum are not reported.
   *
   * @covers ::damagedFiles
   */
  public function testDamagedFilesIsEmptyWhenEveryFileMatches(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);

    $this->assertSame([], SodaScsDemoTemplateArchive::damagedFiles($manifest['checksums'], $directory));
  }

  /**
   * Missing, changed, linked and malformed entries are all damaged.
   *
   * @covers ::damagedFiles
   */
  public function testDamagedFilesReportsMissingChangedLinkedAndMalformedEntries(): void {
    $directory = $this->versionDirectory('src');
    $checksums = $this->readManifest($directory)['checksums'];
    file_put_contents($directory . '/wisski/wisski-database.sql', 'changed');
    unlink($directory . '/jupyter-home.tar.gz');
    file_put_contents($directory . '/real.txt', 'real');
    symlink($directory . '/real.txt', $directory . '/link.txt');
    $checksums['link.txt'] = hash('sha256', 'real');
    $checksums['broken'] = ['not a string'];

    $damaged = SodaScsDemoTemplateArchive::damagedFiles($checksums, $directory);

    $this->assertEqualsCanonicalizing(['wisski/wisski-database.sql', 'jupyter-home.tar.gz', 'link.txt', 'broken'], $damaged);
  }

  /**
   * The manifest file gets the format version and readable JSON.
   *
   * @covers ::writeManifestFile
   */
  public function testWriteManifestFileAddsFormatVersionAndKeepsSlashesAndUnicode(): void {
    $directory = $this->baseDirectory . '/manifest';
    mkdir($directory);

    $this->assertTrue(SodaScsDemoTemplateArchive::writeManifestFile($directory, [
      'version' => '1.0.0',
      'changelog' => 'Grüße / Änderung',
    ]));

    $json = (string) file_get_contents($directory . '/manifest.json');
    $this->assertStringContainsString('Grüße / Änderung', $json);
    $this->assertSame(['formatVersion' => 1, 'version' => '1.0.0', 'changelog' => 'Grüße / Änderung'], json_decode($json, TRUE));
  }

  /**
   * A directory that cannot be written is reported, not thrown.
   *
   * @covers ::writeManifestFile
   */
  public function testWriteManifestFileReturnsFalseWhenTheDirectoryIsMissing(): void {
    $this->assertFalse(@SodaScsDemoTemplateArchive::writeManifestFile($this->baseDirectory . '/missing', ['version' => '1.0.0']));
  }

  /**
   * The export writes the archive and a checksum file that matches.
   *
   * @covers ::export
   */
  public function testExportWritesArchiveAndMatchingChecksumFile(): void {
    $template = $this->readyTemplate($this->versionDirectory('src'));

    $result = $this->archive()->export($template, $this->archiveDirectory);

    $archive = $this->archiveDirectory . '/demo-v1.2.0.tar.gz';
    $this->assertTrue($result->success, (string) $result->error);
    $this->assertSame($archive, $result->data['archive']);
    $this->assertFileExists($archive);
    $this->assertSame(hash_file('sha256', $archive), $result->data['checksum']);
    $this->assertSame($result->data['checksum'] . '  demo-v1.2.0.tar.gz' . "\n", file_get_contents($archive . '.sha256'));
    $this->assertFileDoesNotExist($archive . '.partial');
  }

  /**
   * The archive holds v{version}/ with the manifest and every file.
   *
   * @covers ::export
   */
  public function testExportArchiveHoldsTheVersionDirectoryWithManifest(): void {
    $this->archive()->export($this->readyTemplate($this->versionDirectory('src')), $this->archiveDirectory);

    $entries = $this->listArchive($this->archiveDirectory . '/demo-v1.2.0.tar.gz');

    $this->assertEqualsCanonicalizing([
      'v1.2.0/',
      'v1.2.0/jupyter-home.tar.gz',
      'v1.2.0/manifest.json',
      'v1.2.0/wisski/',
      'v1.2.0/wisski/wisski-database.sql',
    ], $entries);
  }

  /**
   * Without an output directory the archive goes to _exports of the root.
   *
   * @covers ::export
   */
  public function testExportWritesToExportsUnderTheTemplatesRootByDefault(): void {
    $result = $this->archive()->export($this->readyTemplate($this->versionDirectory('src')));

    $this->assertTrue($result->success, (string) $result->error);
    $this->assertFileExists($this->templatesRoot . '/_exports/demo-v1.2.0.tar.gz');
    $this->assertFileExists($this->templatesRoot . '/_exports/demo-v1.2.0.tar.gz.sha256');
  }

  /**
   * A version built before manifest.json existed gets the file on export.
   *
   * @covers ::export
   */
  public function testExportWritesMissingManifestFileOfAnOlderVersion(): void {
    $directory = $this->versionDirectory('src');
    $template = $this->readyTemplate($directory);
    unlink($directory . '/manifest.json');

    $result = $this->archive()->export($template, $this->archiveDirectory);

    $this->assertTrue($result->success, (string) $result->error);
    $manifest = json_decode((string) file_get_contents($directory . '/manifest.json'), TRUE);
    $this->assertSame(1, $manifest['formatVersion']);
    $this->assertSame('1.2.0', $manifest['version']);
    $this->assertContains('v1.2.0/manifest.json', $this->listArchive($result->data['archive']));
  }

  /**
   * A version that is not ready is not exported.
   *
   * @covers ::export
   */
  public function testExportRefusesVersionThatIsNotReady(): void {
    $template = $this->readyTemplate($this->versionDirectory('src'), ready: FALSE);

    $result = $this->archive()->export($template, $this->archiveDirectory);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('Version 1.2.0 is not ready.', $result->message);
    $this->assertSame([], glob($this->archiveDirectory . '/*'));
  }

  /**
   * A changed file stops the export; no archive is left behind.
   *
   * @covers ::export
   */
  public function testExportRefusesDamagedFileAndWritesNoArchive(): void {
    $directory = $this->versionDirectory('src');
    file_put_contents($directory . '/wisski/wisski-database.sql', 'tampered');

    $result = $this->archive()->export($this->readyTemplate($directory), $this->archiveDirectory);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('wisski/wisski-database.sql', $result->message);
    $this->assertSame([], glob($this->archiveDirectory . '/*'));
  }

  /**
   * A version directory that is gone stops the export.
   *
   * @covers ::export
   */
  public function testExportRefusesMissingVersionDirectory(): void {
    $directory = $this->versionDirectory('src');
    $template = $this->readyTemplate($directory);
    $this->removeTree($directory);

    $result = $this->archive()->export($template, $this->archiveDirectory);

    $this->assertFalse($result->success);
    $this->assertSame([], glob($this->archiveDirectory . '/*'));
  }

  /**
   * An output directory that cannot be created is reported.
   *
   * @covers ::export
   */
  public function testExportFailsWhenTheOutputDirectoryCannotBeCreated(): void {
    $blocker = $this->baseDirectory . '/blocker';
    file_put_contents($blocker, 'a file');

    $result = $this->archive()->export($this->readyTemplate($this->versionDirectory('src')), $blocker . '/out');

    $this->assertFalse($result->success);
    $this->assertStringContainsString('Could not create', $result->message);
  }

  /**
   * A version built before 4.8 gets the bases of its source WissKI.
   *
   * @covers ::export
   */
  public function testExportAddsTheBasesOfTheSourceWisskiToAnOlderVersion(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    unset($manifest['apps']['wisski']['uriBases']);
    $template = $this->readyTemplate($directory, manifest: $manifest);
    $this->uriRewriter->expects($this->once())->method('templateSourceBases')->with($template)->willReturn(self::URI_BASES);

    $result = $this->archive()->export($template, $this->archiveDirectory);

    $this->assertTrue($result->success, (string) $result->error);
    $this->assertSame(self::URI_BASES, $this->readManifest($directory)['apps']['wisski']['uriBases']);
  }

  /**
   * An older version whose source WissKI is gone is not exported.
   *
   * @covers ::export
   */
  public function testExportRefusesAnOlderVersionWhoseSourceWisskiCannotBeRead(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    unset($manifest['apps']['wisski']['uriBases']);
    $this->uriRewriter->method('templateSourceBases')->willReturn(NULL);

    $result = $this->archive()->export($this->readyTemplate($directory, manifest: $manifest), $this->archiveDirectory);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('cannot be read any more. Build a new version', $result->message);
    $this->assertSame([], glob($this->archiveDirectory . '/*'));
  }

  /**
   * Bases that are in the manifest need no lookup.
   *
   * @covers ::export
   */
  public function testExportDoesNotLookUpBasesThatTheManifestLists(): void {
    $this->uriRewriter->expects($this->never())->method('templateSourceBases');

    $this->assertTrue($this->archive()->export($this->readyTemplate($this->versionDirectory('src')), $this->archiveDirectory)->success);
  }

  /**
   * A version without a WissKI is exported without any lookup.
   *
   * @covers ::export
   */
  public function testExportOfVersionWithoutWisskiNeedsNoBases(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    unset($manifest['apps']['wisski']);
    $this->uriRewriter->expects($this->never())->method('templateSourceBases');

    $this->assertTrue($this->archive()->export($this->readyTemplate($directory, manifest: $manifest), $this->archiveDirectory)->success);
  }

  /**
   * The manifest.json in the archive is rewritten from the entity manifest.
   *
   * @covers ::export
   */
  public function testExportRewritesManifestFileOnEveryExport(): void {
    $directory = $this->versionDirectory('src');
    $template = $this->readyTemplate($directory);
    file_put_contents($directory . '/manifest.json', '{"stale":true}');

    $this->archive()->export($template, $this->archiveDirectory);

    $manifest = $this->readManifest($directory);
    $this->assertSame(1, $manifest['formatVersion']);
    $this->assertArrayNotHasKey('stale', $manifest);
    $this->assertSame('1.2.0', $manifest['version']);
  }

  /**
   * A finished version of that number blocks the import; nothing is changed.
   *
   * @covers ::import
   */
  public function testImportRefusesWhenVersionOfThatNumberExistsAndIsNotFailed(): void {
    $archive = $this->exportedArchive();
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);
    $existing = $this->createMock(SodaScsDemoTemplateInterface::class);
    $existing->method('getStatus')->willReturn(SodaScsDemoTemplateInterface::STATUS_READY);
    $this->demoManager->method('templateWithVersion')->with('1.2.0')->willReturn($existing);
    $this->demoManager->expects($this->never())->method('deleteTemplate');

    $result = $this->archive()->import($archive);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('Version 1.2.0 exists already.', $result->message);
    $this->assertNothingLeftBehind();
  }

  /**
   * A failed build of that number is deleted first, then the import runs.
   *
   * @covers ::import
   */
  public function testImportReplacesFailedBuildOfTheSameVersion(): void {
    $archive = $this->exportedArchive();
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);
    $failed = $this->createMock(SodaScsDemoTemplateInterface::class);
    $failed->method('getStatus')->willReturn(SodaScsDemoTemplateInterface::STATUS_FAILED);
    $this->demoManager->method('templateWithVersion')->willReturn($failed);
    $this->demoManager->expects($this->once())->method('deleteTemplate')->with($failed)
      ->willReturn(SodaScsResult::success([], 'Deleted.'));

    $result = $this->archive()->import($archive);

    $this->assertTrue($result->success, (string) $result->error);
    $this->assertDirectoryExists($this->templatesRoot . '/v1.2.0');
  }

  /**
   * A failed build that cannot be deleted stops the import untouched.
   *
   * @covers ::import
   */
  public function testImportRefusesWhenTheFailedBuildCannotBeDeleted(): void {
    $archive = $this->exportedArchive();
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);
    $failed = $this->createMock(SodaScsDemoTemplateInterface::class);
    $failed->method('getStatus')->willReturn(SodaScsDemoTemplateInterface::STATUS_FAILED);
    $this->demoManager->method('templateWithVersion')->willReturn($failed);
    $this->demoManager->method('deleteTemplate')
      ->willReturn(SodaScsResult::failure(error: 'in use', message: 'Version 1.2.0 is active or used by demo projects and cannot be deleted.', log: FALSE));

    $result = $this->archive()->import($archive);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('The failed build of version 1.2.0 could not be removed: Version 1.2.0 is active', $result->message);
    $this->assertNothingLeftBehind();
  }

  /**
   * A Team Folder directory in the manifest is the one the builder writes.
   *
   * @covers ::readManifest
   */
  public function testImportAcceptsTheTeamFolderDirectoryOfTheBuilder(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    $manifest['teamFolder'] = ['directory' => 'team-folder', 'fileCount' => 0];
    file_put_contents($directory . '/manifest.json', json_encode($manifest));
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);

    $this->assertTrue($this->archive()->import($this->packedArchive($directory))->success);
  }

  /**
   * A Team Folder directory outside the version is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesTeamFolderDirectoryWithParentPath(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    $manifest['teamFolder'] = ['directory' => '../../etc'];
    file_put_contents($directory . '/manifest.json', json_encode($manifest));

    $this->assertImportRefused($this->packedArchive($directory), 'names an invalid Team Folder directory');
  }

  /**
   * Another directory name or a malformed entry is refused too.
   *
   * @covers ::readManifest
   */
  #[DataProvider('invalidTeamFolderEntries')]
  public function testImportRefusesMalformedTeamFolderEntry(mixed $teamFolder): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    $manifest['teamFolder'] = $teamFolder;
    file_put_contents($directory . '/manifest.json', json_encode($manifest));

    $this->assertImportRefused($this->packedArchive($directory), 'names an invalid Team Folder directory');
  }

  /**
   * Team Folder entries that are refused.
   *
   * @return array<string, array{mixed}>
   *   The entries by case.
   */
  public static function invalidTeamFolderEntries(): array {
    return [
      'absolute path' => [['directory' => '/etc']],
      'other name' => [['directory' => 'wisski']],
      'no directory' => [['fileCount' => 3]],
      'string' => ['team-folder'],
      'directory is an array' => [['directory' => ['team-folder']]],
    ];
  }

  /**
   * An archive written by the export imports as a ready, imported version.
   *
   * @covers ::import
   */
  public function testImportCreatesReadyImportedVersionAndMovesTheDirectory(): void {
    $archive = $this->exportedArchive();
    $this->demoManager->method('validateNewVersion')->with('1.2.0')->willReturn(NULL);
    $this->createdTemplate->expects($this->once())->method('save');
    $this->createdTemplate->expects($this->once())->method('setManifest')
      ->with($this->callback(fn (array $manifest): bool => $manifest['version'] === '1.2.0' && $manifest['changelog'] === 'Changes'))
      ->willReturnSelf();

    $result = $this->archive()->import($archive);

    $this->assertTrue($result->success, (string) $result->error);
    $this->assertSame($this->createdTemplate, $result->data['template']);
    $this->assertCount(1, $this->createdValues);
    $values = $this->createdValues[0];
    $this->assertSame($this->templatesRoot . '/v1.2.0', $values['artifactDirectory']);
    $this->assertSame('imported', $values['origin']);
    $this->assertSame('ready', $values['status']);
    $this->assertSame('1.2.0', $values['version']);
    $this->assertSame('Changes', $values['changelog']);
    $this->assertArrayNotHasKey('sourceProject', $values);
    $this->assertFileExists($this->templatesRoot . '/v1.2.0/wisski/wisski-database.sql');
    $this->assertFileExists($this->templatesRoot . '/v1.2.0/manifest.json');
    $this->assertSame(['v1.2.0'], $this->templatesRootEntries());
  }

  /**
   * The imported files are the exported ones.
   *
   * @covers ::import
   */
  public function testImportRestoresTheFileContents(): void {
    $archive = $this->exportedArchive();
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);

    $this->archive()->import($archive);

    $this->assertSame('SELECT 1;', file_get_contents($this->templatesRoot . '/v1.2.0/wisski/wisski-database.sql'));
    $this->assertSame('home', file_get_contents($this->templatesRoot . '/v1.2.0/jupyter-home.tar.gz'));
  }

  /**
   * Without the option the imported version is not activated.
   *
   * @covers ::import
   */
  public function testImportDoesNotActivateByDefault(): void {
    $archive = $this->exportedArchive();
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);
    $this->demoManager->expects($this->never())->method('setActiveTemplate');

    $this->assertTrue($this->archive()->import($archive)->success);
  }

  /**
   * With the option the imported version becomes the active one.
   *
   * @covers ::import
   */
  public function testImportActivatesTheVersionWhenAsked(): void {
    $archive = $this->exportedArchive();
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);
    $this->demoManager->expects($this->once())->method('setActiveTemplate')->with($this->createdTemplate)
      ->willReturn(SodaScsResult::success([], 'Version 1.2.0 is active now.'));

    $result = $this->archive()->import($archive, TRUE);

    $this->assertTrue($result->success);
    $this->assertStringContainsString('Version 1.2.0 imported.', $result->message);
    $this->assertStringContainsString('Version 1.2.0 is active now.', $result->message);
  }

  /**
   * A failed activation is reported; the imported version stays.
   *
   * @covers ::import
   */
  public function testImportReportsFailedActivationAndKeepsTheVersion(): void {
    $archive = $this->exportedArchive();
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);
    $this->demoManager->method('setActiveTemplate')
      ->willReturn(SodaScsResult::failure(error: 'not ready', message: 'Only finished versions can be activated.', log: FALSE));

    $result = $this->archive()->import($archive, TRUE);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('Version 1.2.0 imported.', $result->message);
    $this->assertStringContainsString('Only finished versions can be activated.', $result->message);
    $this->assertDirectoryExists($this->templatesRoot . '/v1.2.0');
  }

  /**
   * Names other than demo-v{x.y.z}.tar.gz are refused before anything is read.
   *
   * @covers ::import
   */
  #[DataProvider('invalidArchiveNames')]
  public function testImportRefusesAnInvalidArchiveName(string $name): void {
    $path = $this->archiveDirectory . '/' . $name;
    file_put_contents($path, 'x');
    $this->demoManager->expects($this->never())->method('validateNewVersion');

    $result = $this->archive()->import($path);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('is not a demo version archive', $result->message);
    $this->assertNothingLeftBehind();
  }

  /**
   * Archive names that are refused.
   *
   * @return array<string, array{string}>
   *   The names by case.
   */
  public static function invalidArchiveNames(): array {
    return [
      'other name' => ['backup.tar.gz'],
      'two-part version' => ['demo-v1.2.tar.gz'],
      'pre-release' => ['demo-v1.2.0-rc1.tar.gz'],
      'zip' => ['demo-v1.2.0.zip'],
      'path in the name' => ['xdemo-v1.2.0.tar.gz.bak'],
    ];
  }

  /**
   * An archive that does not exist is refused.
   *
   * @covers ::import
   */
  public function testImportRefusesMissingArchive(): void {
    $result = $this->archive()->import($this->archiveDirectory . '/demo-v1.2.0.tar.gz');

    $this->assertFalse($result->success);
    $this->assertStringContainsString('is not a demo version archive', $result->message);
  }

  /**
   * A symbolic link to an archive is refused.
   *
   * @covers ::import
   */
  public function testImportRefusesSymbolicLinkAsArchive(): void {
    $archive = $this->exportedArchive();
    $link = $this->archiveDirectory . '/link/demo-v1.2.0.tar.gz';
    mkdir(dirname($link));
    symlink($archive, $link);
    copy($archive . '.sha256', $link . '.sha256');

    $result = $this->archive()->import($link);

    $this->assertFalse($result->success);
    $this->assertNothingLeftBehind();
  }

  /**
   * Without the .sha256 file nothing is unpacked.
   *
   * @covers ::import
   * @covers ::checkArchiveChecksum
   */
  public function testImportRefusesAnArchiveWithoutChecksumFile(): void {
    $archive = $this->exportedArchive();
    unlink($archive . '.sha256');
    $this->demoManager->expects($this->never())->method('validateNewVersion');

    $result = $this->archive()->import($archive);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('is missing or holds no SHA-256 checksum', $result->message);
    $this->assertNothingLeftBehind();
  }

  /**
   * A checksum file without a SHA-256 value is refused.
   *
   * @covers ::checkArchiveChecksum
   */
  public function testImportRefusesMalformedChecksumFile(): void {
    $archive = $this->exportedArchive();
    file_put_contents($archive . '.sha256', "not-a-hash  demo-v1.2.0.tar.gz\n");

    $result = $this->archive()->import($archive);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('is missing or holds no SHA-256 checksum', $result->message);
    $this->assertNothingLeftBehind();
  }

  /**
   * An archive that differs from its checksum is refused.
   *
   * @covers ::checkArchiveChecksum
   */
  public function testImportRefusesChangedArchive(): void {
    $archive = $this->exportedArchive();
    file_put_contents($archive, 'tampered', FILE_APPEND);
    $this->demoManager->expects($this->never())->method('validateNewVersion');

    $result = $this->archive()->import($archive);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('does not match', $result->message);
    $this->assertNothingLeftBehind();
  }

  /**
   * A version that is not higher than the latest is refused before unpacking.
   *
   * @covers ::import
   */
  public function testImportRefusesVersionThatIsNotHigher(): void {
    $archive = $this->exportedArchive();
    $this->demoManager->method('validateNewVersion')->with('1.2.0')
      ->willReturn('The version must be higher than the latest version 1.5.0.');

    $result = $this->archive()->import($archive);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('Version 1.2.0 cannot be imported: The version must be higher than the latest version 1.5.0.', $result->message);
    $this->assertNothingLeftBehind();
  }

  /**
   * A version directory that exists already is not overwritten.
   *
   * @covers ::import
   */
  public function testImportRefusesWhenTheTargetDirectoryExists(): void {
    $archive = $this->exportedArchive();
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);
    mkdir($this->templatesRoot . '/v1.2.0');
    file_put_contents($this->templatesRoot . '/v1.2.0/keep.txt', 'keep');

    $result = $this->archive()->import($archive);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('exists already', $result->message);
    $this->assertSame('keep', file_get_contents($this->templatesRoot . '/v1.2.0/keep.txt'));
    $this->assertSame(['v1.2.0'], $this->templatesRootEntries());
  }

  /**
   * A symbolic link inside the archive is refused before unpacking.
   *
   * @covers ::checkEntries
   */
  public function testImportRefusesSymbolicLinkEntry(): void {
    $directory = $this->versionDirectory('src');
    symlink('/etc/passwd', $directory . '/passwd-link');

    $this->assertImportRefused($this->packedArchive($directory), 'is not a plain file or directory');
  }

  /**
   * A hard link inside the archive is refused as well.
   *
   * @covers ::checkEntries
   */
  public function testImportRefusesHardLinkEntry(): void {
    $directory = $this->versionDirectory('src');
    link($directory . '/jupyter-home.tar.gz', $directory . '/hard-link');

    $this->assertImportRefused($this->packedArchive($directory), 'is not a plain file or directory');
  }

  /**
   * An entry with "../" is refused.
   *
   * @covers ::checkEntries
   */
  public function testImportRefusesParentDirectoryEntry(): void {
    $directory = $this->versionDirectory('src');
    file_put_contents($directory . '/evil', 'evil');

    $archive = $this->packedArchive($directory, ['--transform', 's,^v1.2.0/evil$,v1.2.0/../evil,']);

    $this->assertImportRefused($archive, 'is outside v1.2.0/');
    $this->assertFileDoesNotExist($this->templatesRoot . '/evil');
  }

  /**
   * An absolute entry is refused.
   *
   * @covers ::checkEntries
   */
  public function testImportRefusesAnAbsoluteEntry(): void {
    $directory = $this->versionDirectory('src');
    file_put_contents($directory . '/evil', 'evil');

    $archive = $this->packedArchive($directory, ['-P', '--transform', 's,^v1.2.0/evil$,/scs-archive-test-evil,']);

    $this->assertImportRefused($archive, 'is outside v1.2.0/');
    $this->assertFileDoesNotExist('/scs-archive-test-evil');
  }

  /**
   * An entry beside the version directory is refused.
   *
   * @covers ::checkEntries
   */
  public function testImportRefusesAnEntryOutsideTheVersionDirectory(): void {
    $directory = $this->versionDirectory('src');
    file_put_contents($directory . '/evil', 'evil');

    $archive = $this->packedArchive($directory, ['--transform', 's,^v1.2.0/evil$,other/evil,']);

    $this->assertImportRefused($archive, 'is outside v1.2.0/');
  }

  /**
   * An archive that holds another version directory than its name is refused.
   *
   * @covers ::checkEntries
   */
  public function testImportRefusesDirectoryThatDoesNotMatchTheArchiveName(): void {
    $archive = $this->packedArchive($this->versionDirectory('src', '9.9.9'), archiveVersion: '1.2.0');

    $this->assertImportRefused($archive, 'is outside v1.2.0/');
  }

  /**
   * A file that is no archive but matches its checksum is refused.
   *
   * @covers ::checkEntries
   */
  public function testImportRefusesFileThatIsNoTarArchive(): void {
    $archive = $this->archiveDirectory . '/demo-v1.2.0.tar.gz';
    file_put_contents($archive, 'this is not gzip');
    file_put_contents($archive . '.sha256', hash_file('sha256', $archive) . "  demo-v1.2.0.tar.gz\n");

    $this->assertImportRefused($archive, 'Could not read the entries');
  }

  /**
   * An archive without manifest.json is refused after unpacking, cleanly.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesAnArchiveWithoutManifestFile(): void {
    $directory = $this->versionDirectory('src');
    unlink($directory . '/manifest.json');

    $this->assertImportRefused($this->packedArchive($directory), 'has no readable manifest.json');
  }

  /**
   * A manifest.json that is no JSON object is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesAnUnreadableManifestFile(): void {
    $directory = $this->versionDirectory('src');
    file_put_contents($directory . '/manifest.json', '{broken');

    $this->assertImportRefused($this->packedArchive($directory), 'has no readable manifest.json');
  }

  /**
   * A manifest of another format is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesAnotherFormatVersion(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    $manifest['formatVersion'] = 2;
    file_put_contents($directory . '/manifest.json', json_encode($manifest));

    $this->assertImportRefused($this->packedArchive($directory), 'has format 2; this site reads format 1');
  }

  /**
   * A manifest without a format version is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesManifestWithoutFormatVersion(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    unset($manifest['formatVersion']);
    file_put_contents($directory . '/manifest.json', json_encode($manifest));

    $this->assertImportRefused($this->packedArchive($directory), 'has format NULL');
  }

  /**
   * A manifest that names another version than the archive is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesManifestThatNamesAnotherVersion(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    $manifest['version'] = '1.3.0';
    file_put_contents($directory . '/manifest.json', json_encode($manifest));

    $this->assertImportRefused($this->packedArchive($directory), "names version '1.3.0', the archive 1.2.0");
  }

  /**
   * A manifest without checksums verifies nothing and is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesManifestWithoutChecksums(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    $manifest['checksums'] = [];
    file_put_contents($directory . '/manifest.json', json_encode($manifest));

    $this->assertImportRefused($this->packedArchive($directory), 'lists no checksums');
  }

  /**
   * A file that differs from the manifest checksum is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesDamagedFile(): void {
    $directory = $this->versionDirectory('src');
    file_put_contents($directory . '/wisski/wisski-database.sql', 'DROP DATABASE x;');

    $this->assertImportRefused($this->packedArchive($directory), 'missing or damaged: wisski/wisski-database.sql');
  }

  /**
   * A listed file that is missing from the archive is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesMissingListedFile(): void {
    $directory = $this->versionDirectory('src');
    unlink($directory . '/jupyter-home.tar.gz');

    $this->assertImportRefused($this->packedArchive($directory), 'missing or damaged: jupyter-home.tar.gz');
  }

  /**
   * A file the manifest does not list is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesAnUnlistedFile(): void {
    $directory = $this->versionDirectory('src');
    file_put_contents($directory . '/wisski/extra.sh', 'rm -rf /');

    $this->assertImportRefused($this->packedArchive($directory), 'files the manifest does not list: wisski/extra.sh');
  }

  /**
   * An app entry that points at a file the archive lacks is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesAnAppEntryThatPointsAtMissingFile(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    $manifest['apps']['jupyter']['file'] = 'other-home.tar.gz';
    file_put_contents($directory . '/manifest.json', json_encode($manifest));

    $this->assertImportRefused($this->packedArchive($directory), "names 'other-home.tar.gz' for jupyter");
  }

  /**
   * An app entry with a parent path is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesAnAppEntryWithParentPath(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    $manifest['apps']['wisski']['directory'] = '../wisski';
    file_put_contents($directory . '/manifest.json', json_encode($manifest));

    $this->assertImportRefused($this->packedArchive($directory), "names '../wisski' for wisski");
  }

  /**
   * An app entry that is no object is refused.
   *
   * @covers ::readManifest
   */
  public function testImportRefusesAnAppEntryThatIsNoObject(): void {
    $directory = $this->versionDirectory('src');
    $manifest = $this->readManifest($directory);
    $manifest['apps']['wisski'] = 'wisski';
    file_put_contents($directory . '/manifest.json', json_encode($manifest));

    $this->assertImportRefused($this->packedArchive($directory), 'has an invalid entry for wisski');
  }

  /**
   * A failing entity save removes the moved directory and the work directory.
   *
   * @covers ::import
   */
  public function testImportRemovesTheDirectoryWhenTheEntityCannotBeSaved(): void {
    $archive = $this->exportedArchive();
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);
    $this->createdTemplate->method('save')->willThrowException(new \RuntimeException('Database is gone.'));
    $this->logger->expects($this->once())->method('error');
    $this->demoManager->expects($this->never())->method('setActiveTemplate');

    $result = $this->archive()->import($archive, TRUE);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('The import failed: Database is gone.', $result->message);
    $this->assertSame([], $this->templatesRootEntries());
  }

  /**
   * An import that fails logs nothing as success.
   *
   * @covers ::import
   */
  public function testImportOfRefusedArchiveLogsNoNotice(): void {
    $directory = $this->versionDirectory('src');
    unlink($directory . '/manifest.json');
    $archive = $this->packedArchive($directory);
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);
    $this->logger->expects($this->never())->method('notice');

    $this->assertFalse($this->archive()->import($archive)->success);
  }

  /**
   * Asserts that an import fails with a reason and leaves nothing behind.
   */
  private function assertImportRefused(string $archive, string $reason): void {
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);
    $this->storage->expects($this->never())->method('save');
    $this->createdTemplate->expects($this->never())->method('save');

    $result = $this->archive()->import($archive);

    $this->assertFalse($result->success, 'The import must be refused.');
    $this->assertStringContainsString($reason, $result->message);
    $this->assertNothingLeftBehind();
  }

  /**
   * Asserts that the templates root holds neither a version nor a work dir.
   */
  private function assertNothingLeftBehind(): void {
    $this->assertSame([], $this->templatesRootEntries());
    $this->assertSame([], $this->createdValues, 'No entity was created.');
  }

  /**
   * Entries of the templates root.
   *
   * @return string[]
   *   The names.
   */
  private function templatesRootEntries(): array {
    return array_values(array_diff((array) scandir($this->templatesRoot), ['.', '..']));
  }

  /**
   * Builds the archive service on the doubles.
   */
  private function archive(): SodaScsDemoTemplateArchive {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('soda_scs_demo_template')->willReturn($this->storage);
    $builder = $this->createMock(SodaScsDemoTemplateBuilder::class);
    $builder->method('templatesRoot')->willReturn($this->templatesRoot);
    return new SodaScsDemoTemplateArchive($this->demoManager, $entityTypeManager, $this->logger, $builder, $this->uriRewriter);
  }

  /**
   * Creates a version directory with a manifest, the way the builder does.
   *
   * @param string $name
   *   Name of the directory that holds the version directory.
   * @param string $version
   *   The version.
   *
   * @return string
   *   The version directory.
   */
  private function versionDirectory(string $name, string $version = self::VERSION): string {
    $directory = $this->baseDirectory . '/' . $name . '/v' . $version;
    mkdir($directory . '/wisski', 0775, TRUE);
    file_put_contents($directory . '/wisski/wisski-database.sql', 'SELECT 1;');
    file_put_contents($directory . '/jupyter-home.tar.gz', 'home');
    $manifest = [
      'version' => $version,
      'changelog' => 'Changes',
      'apps' => [
        'wisski' => ['directory' => 'wisski', 'sourceStackId' => 4, 'uriBases' => self::URI_BASES],
        'jupyter' => ['file' => 'jupyter-home.tar.gz'],
      ],
      'checksums' => [
        'jupyter-home.tar.gz' => hash('sha256', 'home'),
        'wisski/wisski-database.sql' => hash('sha256', 'SELECT 1;'),
      ],
    ];
    SodaScsDemoTemplateArchive::writeManifestFile($directory, $manifest);
    return $directory;
  }

  /**
   * Reads the manifest.json of a version directory.
   *
   * @return array<string, mixed>
   *   The manifest.
   */
  private function readManifest(string $directory): array {
    return json_decode((string) file_get_contents($directory . '/manifest.json'), TRUE);
  }

  /**
   * A ready template double for a version directory.
   */
  private function readyTemplate(string $directory, bool $ready = TRUE, ?array $manifest = NULL): SodaScsDemoTemplateInterface&MockObject {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('isReady')->willReturn($ready);
    $template->method('getVersion')->willReturn(self::VERSION);
    $template->method('getArtifactDirectory')->willReturn($directory);
    $template->method('getManifest')->willReturn($manifest ?? $this->readManifest($directory));
    return $template;
  }

  /**
   * Exports a valid version with the service and returns the archive path.
   */
  private function exportedArchive(): string {
    $result = $this->archive()->export($this->readyTemplate($this->versionDirectory('src')), $this->archiveDirectory);
    $this->assertTrue($result->success, (string) $result->error);
    return $result->data['archive'];
  }

  /**
   * Packs a directory with tar, the way a hostile archive could be made.
   *
   * @param string $directory
   *   The version directory.
   * @param string[] $extraArguments
   *   Extra tar arguments, e.g. a --transform.
   * @param string|null $archiveVersion
   *   Version in the archive name; default the version of the directory.
   *
   * @return string
   *   The archive; its .sha256 file is written.
   */
  private function packedArchive(string $directory, array $extraArguments = [], ?string $archiveVersion = NULL): string {
    $name = 'demo-v' . ($archiveVersion ?? substr(basename($directory), 1)) . '.tar.gz';
    $archive = $this->archiveDirectory . '/' . $name;
    $process = new Process([
      'tar',
      ...$extraArguments,
      '-czf',
      $archive,
      '-C',
      dirname($directory),
      basename($directory),
    ]);
    $process->mustRun();
    file_put_contents($archive . '.sha256', hash_file('sha256', $archive) . '  ' . $name . "\n");
    return $archive;
  }

  /**
   * Lists the entries of an archive.
   *
   * @return string[]
   *   The entry names.
   */
  private function listArchive(string $archive): array {
    $process = new Process(['tar', '-tzf', $archive]);
    $process->mustRun();
    return array_values(array_filter(explode("\n", $process->getOutput())));
  }

  /**
   * Removes a tree, links included, without following them.
   */
  private function removeTree(string $path): void {
    if (is_link($path) || is_file($path)) {
      @unlink($path);
      return;
    }
    if (!is_dir($path)) {
      return;
    }
    foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
      $this->removeTree($path . '/' . $entry);
    }
    @rmdir($path);
  }

}
