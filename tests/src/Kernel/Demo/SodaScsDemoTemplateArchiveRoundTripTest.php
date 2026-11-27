<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Demo;

use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateArchive;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateBuilder;
use Drupal\soda_scs_manager\Demo\SodaScsWisskiUriRewriter;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Exports a demo version and imports it again with the real entity storage.
 *
 * Issue 3640915. Real tar, real temporary directories, real template
 * entities; demo manager, template builder and URI rewriter are doubles.
 */
#[Group('soda_scs_manager')]
final class SodaScsDemoTemplateArchiveRoundTripTest extends SodaScsKernelTestBase {

  /**
   * Temporary base directory of the test.
   */
  private string $baseDirectory;

  /**
   * The demo manager double.
   */
  private SodaScsDemoManager $demoManager;

  /**
   * {@inheritdoc}
   */
  protected function installSchemas(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('soda_scs_manager', ['keycloak_user_registration']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('soda_scs_demo_template');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->baseDirectory = sys_get_temp_dir() . '/scs-roundtrip-' . bin2hex(random_bytes(4));
    mkdir($this->baseDirectory . '/templates', 0775, TRUE);
    $this->demoManager = $this->createMock(SodaScsDemoManager::class);
    $this->demoManager->method('validateNewVersion')->willReturn(NULL);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeTree($this->baseDirectory);
    parent::tearDown();
  }

  /**
   * An exported built version is imported as an imported, ready one.
   */
  public function testExportedVersionImportsAsReadyImportedVersion(): void {
    $built = $this->builtTemplate('2.0.0');
    $archive = $this->archive()->export($built, $this->baseDirectory . '/out');
    $this->assertTrue($archive->success, (string) $archive->error);
    $built->delete();
    $this->removeTree($this->baseDirectory . '/templates/v2.0.0');

    $result = $this->archive()->import($archive->data['archive']);

    $this->assertTrue($result->success, (string) $result->error);
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface $imported */
    $imported = $result->data['template'];
    $this->assertNotNull($imported->id());
    $this->assertSame('2.0.0', $imported->getVersion());
    $this->assertSame(SodaScsDemoTemplateInterface::ORIGIN_IMPORTED, $imported->getOrigin());
    $this->assertTrue($imported->isImported());
    $this->assertTrue($imported->isReady());
    $this->assertTrue($imported->get('sourceProject')->isEmpty(), 'An imported version has no source project.');
    $this->assertSame($this->baseDirectory . '/templates/v2.0.0', $imported->getArtifactDirectory());
    $this->assertSame('Second version.', $imported->getChangelog());
    $this->assertSame('SELECT 1;', file_get_contents($imported->getArtifactDirectory() . '/wisski/wisski-database.sql'));
  }

  /**
   * The saved manifest is the one of the archive, with checksums that match.
   */
  public function testImportedManifestHoldsBasesAndMatchingChecksums(): void {
    $built = $this->builtTemplate('2.0.0');
    $archive = $this->archive()->export($built, $this->baseDirectory . '/out');
    $built->delete();
    $this->removeTree($this->baseDirectory . '/templates/v2.0.0');

    $imported = $this->archive()->import($archive->data['archive'])->data['template'];

    $this->entityTypeManager->getStorage('soda_scs_demo_template')->resetCache([$imported->id()]);
    $manifest = $this->entityTypeManager->getStorage('soda_scs_demo_template')->load($imported->id())->getManifest();
    $this->assertSame(['graph' => 'https://source.example.org/contents/', 'site' => 'https://source.example.org/'], $manifest['apps']['wisski']['uriBases']);
    $this->assertSame([], SodaScsDemoTemplateArchive::damagedFiles($manifest['checksums'], $imported->getArtifactDirectory()));
  }

  /**
   * A second import of the same archive fails on the version check.
   */
  public function testImportingTheSameArchiveTwiceFails(): void {
    $built = $this->builtTemplate('2.0.0');
    $archive = $this->archive()->export($built, $this->baseDirectory . '/out');
    $built->delete();
    $this->removeTree($this->baseDirectory . '/templates/v2.0.0');
    $this->assertTrue($this->archive()->import($archive->data['archive'])->success);

    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('validateNewVersion')->willReturn('Version 2.0.0 already exists.');
    $second = $this->archive($demoManager)->import($archive->data['archive']);

    $this->assertFalse($second->success);
    $this->assertCount(1, $this->entityTypeManager->getStorage('soda_scs_demo_template')->loadMultiple());
    $entries = array_values(array_diff((array) scandir($this->baseDirectory . '/templates'), ['.', '..']));
    $this->assertSame(['v2.0.0'], $entries);
  }

  /**
   * Creates a ready built version with files on disk.
   */
  private function builtTemplate(string $version): SodaScsDemoTemplateInterface {
    $directory = $this->baseDirectory . '/templates/v' . $version;
    mkdir($directory . '/wisski', 0775, TRUE);
    file_put_contents($directory . '/wisski/wisski-database.sql', 'SELECT 1;');
    $manifest = [
      'version' => $version,
      'changelog' => 'Second version.',
      'apps' => [
        'wisski' => [
          'directory' => 'wisski',
          'sourceStackId' => 4,
          'uriBases' => [
            'graph' => 'https://source.example.org/contents/',
            'site' => 'https://source.example.org/',
          ],
        ],
      ],
      'checksums' => ['wisski/wisski-database.sql' => hash('sha256', 'SELECT 1;')],
    ];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface $template */
    $template = $this->entityTypeManager->getStorage('soda_scs_demo_template')->create([
      'artifactDirectory' => $directory,
      'changelog' => 'Second version.',
      'label' => 'Demo v' . $version,
      'status' => SodaScsDemoTemplateInterface::STATUS_READY,
      'version' => $version,
    ]);
    $template->setManifest($manifest)->save();
    return $template;
  }

  /**
   * Builds the archive service on the real entity storage.
   */
  private function archive(?SodaScsDemoManager $demoManager = NULL): SodaScsDemoTemplateArchive {
    $builder = $this->createMock(SodaScsDemoTemplateBuilder::class);
    $builder->method('templatesRoot')->willReturn($this->baseDirectory . '/templates');
    return new SodaScsDemoTemplateArchive(
      $demoManager ?? $this->demoManager,
      $this->entityTypeManager,
      $this->createMock(LoggerInterface::class),
      $builder,
      $this->createMock(SodaScsWisskiUriRewriter::class),
    );
  }

  /**
   * Removes a tree without following links.
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
