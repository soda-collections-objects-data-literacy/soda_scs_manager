<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\Demo\SodaScsTeamFolderCopier;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Service\NextcloudMounterClient;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests the copy of a project's Team Folder into and out of a template.
 *
 * Issue 3639291: Jupyter checkpoints are per-user editor state and stay out
 * of the template and out of the demo's Team Folder. The Nextcloud mounter is
 * a double; the folders are temporary local directories.
 */
#[CoversMethod(SodaScsTeamFolderCopier::class, 'exportTeamFolder')]
#[CoversMethod(SodaScsTeamFolderCopier::class, 'copyTree')]
#[CoversMethod(SodaScsTeamFolderCopier::class, 'importTeamFolder')]
#[Group('soda_scs_manager')]
class SodaScsTeamFolderCopierTest extends UnitTestCase {

  /**
   * The mounter client double.
   */
  private NextcloudMounterClient&MockObject $mounterClient;

  /**
   * The project mount manager double.
   */
  private NextcloudProjectMountManager&MockObject $projectMountManager;

  /**
   * Temporary directory that holds the source and target folders.
   */
  private string $workDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->workDirectory = sys_get_temp_dir() . '/scs-team-folder-' . bin2hex(random_bytes(4));
    mkdir($this->workDirectory . '/mount', 0775, TRUE);
    $this->mounterClient = $this->createMock(NextcloudMounterClient::class);
    $this->mounterClient->method('hostMountPath')->willReturn($this->workDirectory . '/mount');
    $this->projectMountManager = $this->createMock(NextcloudProjectMountManager::class);
    $this->projectMountManager->method('relativeMountPath')->willReturn('projects/demo');
    $this->projectMountManager->method('ensureProjectMounted')->willReturn(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->workDirectory);
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * The export leaves checkpoint directories out at every depth.
   */
  public function testExportSkipsIpynbCheckpointsAtAnyDepth(): void {
    $this->writeFiles($this->workDirectory . '/mount', [
      'notebook.ipynb' => '{}',
      '.ipynb_checkpoints/notebook-checkpoint.ipynb' => '{}',
      'data/table.csv' => 'a,b',
      'data/.ipynb_checkpoints/table-checkpoint.ipynb' => '{}',
      'data/deep/.ipynb_checkpoints/x-checkpoint.ipynb' => '{}',
      'data/deep/x.ipynb' => '{}',
    ]);

    $result = $this->copier()->exportTeamFolder($this->project(), $this->workDirectory . '/template');

    $this->assertTrue($result->success);
    $this->assertSame(['data/deep/x.ipynb', 'data/table.csv', 'notebook.ipynb'], $this->listFiles($this->workDirectory . '/template'));
    $this->assertSame(3, $result->data['fileCount']);
    $this->assertDirectoryDoesNotExist($this->workDirectory . '/template/.ipynb_checkpoints');
    $this->assertDirectoryDoesNotExist($this->workDirectory . '/template/data/.ipynb_checkpoints');
  }

  /**
   * Files and directories with other dot names are still copied.
   */
  public function testExportKeepsOtherHiddenFiles(): void {
    $this->writeFiles($this->workDirectory . '/mount', [
      '.hidden/note.txt' => 'x',
      '.ipynb_checkpoints_backup/file.txt' => 'x',
      'checkpoint.ipynb' => '{}',
    ]);

    $result = $this->copier()->exportTeamFolder($this->project(), $this->workDirectory . '/template');

    $this->assertTrue($result->success);
    $this->assertSame(['.hidden/note.txt', '.ipynb_checkpoints_backup/file.txt', 'checkpoint.ipynb'], $this->listFiles($this->workDirectory . '/template'));
  }

  /**
   * A folder that holds only checkpoints exports no file.
   */
  public function testExportOfCheckpointsOnlyFolderCopiesNothing(): void {
    $this->writeFiles($this->workDirectory . '/mount', ['.ipynb_checkpoints/a-checkpoint.ipynb' => '{}']);

    $result = $this->copier()->exportTeamFolder($this->project(), $this->workDirectory . '/template');

    $this->assertTrue($result->success);
    $this->assertSame(0, $result->data['fileCount']);
    $this->assertSame([], $this->listFiles($this->workDirectory . '/template'));
  }

  /**
   * The import does not copy checkpoints of an older template either.
   */
  public function testImportSkipsIpynbCheckpointsOfTheTemplate(): void {
    $this->writeFiles($this->workDirectory . '/mount', ['old.txt' => 'old']);
    $this->writeFiles($this->workDirectory . '/template', [
      'notebook.ipynb' => '{}',
      'sub/.ipynb_checkpoints/n-checkpoint.ipynb' => '{}',
      'sub/n.ipynb' => '{}',
    ]);
    $this->mounterClient->expects($this->once())->method('forgetDirectoryCache')->with('projects/demo');

    $result = $this->copier()->importTeamFolder($this->project(), $this->workDirectory . '/template');

    $this->assertTrue($result->success);
    $this->assertSame(['notebook.ipynb', 'sub/n.ipynb'], $this->listFiles($this->workDirectory . '/mount'));
    $this->assertSame(2, $result->data['fileCount']);
  }

  /**
   * A Team Folder that cannot be mounted fails and copies nothing.
   */
  public function testExportFailsWhenTheTeamFolderIsNotMounted(): void {
    $projectMountManager = $this->createMock(NextcloudProjectMountManager::class);
    $projectMountManager->method('relativeMountPath')->willReturn('projects/demo');
    $projectMountManager->method('ensureProjectMounted')->willReturn(FALSE);
    $this->projectMountManager = $projectMountManager;
    $this->setUpFailureLogging();

    $result = $this->copier()->exportTeamFolder($this->project(), $this->workDirectory . '/template');

    $this->assertFalse($result->success);
    $this->assertSame('The Team Folder is not available.', $result->message);
    $this->assertDirectoryDoesNotExist($this->workDirectory . '/template');
  }

  /**
   * Lets failure results log through \Drupal::logger().
   */
  private function setUpFailureLogging(): void {
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the copier under test.
   */
  private function copier(): SodaScsTeamFolderCopier {
    return new SodaScsTeamFolderCopier($this->mounterClient, $this->projectMountManager);
  }

  /**
   * Builds the project double.
   */
  private function project(): SodaScsProjectInterface {
    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('id')->willReturn('34');
    return $project;
  }

  /**
   * Writes files below a directory; keys are relative paths.
   *
   * @param string $directory
   *   The base directory.
   * @param array<string, string> $files
   *   Content by relative path.
   */
  private function writeFiles(string $directory, array $files): void {
    foreach ($files as $path => $content) {
      $target = $directory . '/' . $path;
      if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, TRUE);
      }
      file_put_contents($target, $content);
    }
  }

  /**
   * Relative paths of all files below a directory, sorted.
   *
   * @return string[]
   *   The paths.
   */
  private function listFiles(string $directory): array {
    $files = [];
    if (!is_dir($directory)) {
      return $files;
    }
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($items as $item) {
      $files[] = substr($item->getPathname(), strlen($directory) + 1);
    }
    sort($files);
    return $files;
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
