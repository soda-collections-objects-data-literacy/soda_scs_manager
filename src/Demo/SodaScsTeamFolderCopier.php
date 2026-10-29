<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Service\NextcloudMounterClient;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Copies the files of a project Team Folder to disk and back.
 *
 * Works through the project's rclone mount: writes go to Nextcloud via
 * WebDAV, so Nextcloud registers them without a files:scan.
 */
class SodaScsTeamFolderCopier {

  /**
   * Constructs the copier.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.nextcloud_mounter.client')]
    protected NextcloudMounterClient $mounterClient,
    #[Autowire(service: 'soda_scs_manager.nextcloud_project_mount.manager')]
    protected NextcloudProjectMountManager $projectMountManager,
  ) {}

  /**
   * Copies the Team Folder of a project into a local directory.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The source project.
   * @param string $targetDirectory
   *   Local directory; created when missing, must be empty.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   On success data['fileCount'] holds the number of copied files.
   */
  public function exportTeamFolder(SodaScsProjectInterface $project, string $targetDirectory): SodaScsResult {
    $mountPath = $this->mountedPath($project);
    if ($mountPath === NULL) {
      return $this->mountFailure($project);
    }
    try {
      $fileCount = $this->copyTree($mountPath, $targetDirectory);
    }
    catch (\RuntimeException $e) {
      return SodaScsResult::failure(
        message: 'Could not copy the Team Folder.',
        error: $e->getMessage(),
      );
    }
    return SodaScsResult::success(
      message: 'Team Folder exported.',
      data: ['fileCount' => $fileCount],
    );
  }

  /**
   * Replaces the content of a project's Team Folder with a local directory.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The target project.
   * @param string $sourceDirectory
   *   Local directory written by exportTeamFolder().
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   On success data['fileCount'] holds the number of copied files.
   */
  public function importTeamFolder(SodaScsProjectInterface $project, string $sourceDirectory): SodaScsResult {
    $mountPath = $this->mountedPath($project);
    if ($mountPath === NULL) {
      return $this->mountFailure($project);
    }
    try {
      $this->purgeDirectoryContent($mountPath);
      $fileCount = is_dir($sourceDirectory) ? $this->copyTree($sourceDirectory, $mountPath) : 0;
      $this->mounterClient->forgetDirectoryCache((string) $this->projectMountManager->relativeMountPath($project));
    }
    catch (\RuntimeException $e) {
      return SodaScsResult::failure(
        message: 'Could not fill the Team Folder.',
        error: $e->getMessage(),
      );
    }
    return SodaScsResult::success(
      message: 'Team Folder imported.',
      data: ['fileCount' => $fileCount],
    );
  }

  /**
   * Recursively copies a directory tree.
   *
   * @return int
   *   Number of copied files.
   *
   * @throws \RuntimeException
   */
  protected function copyTree(string $sourceDirectory, string $targetDirectory): int {
    $this->ensureDirectory($targetDirectory);
    $fileCount = 0;
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($items as $item) {
      /** @var \SplFileInfo $item */
      $targetPath = $targetDirectory . '/' . substr($item->getPathname(), strlen($sourceDirectory) + 1);
      if ($item->isDir()) {
        $this->ensureDirectory($targetPath);
        continue;
      }
      if (!@copy($item->getPathname(), $targetPath)) {
        throw new \RuntimeException(sprintf('Could not copy %s.', $item->getPathname()));
      }
      $fileCount++;
    }
    return $fileCount;
  }

  /**
   * Creates a directory with its parents unless it exists.
   *
   * @throws \RuntimeException
   */
  protected function ensureDirectory(string $directory): void {
    if (!is_dir($directory) && !@mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new \RuntimeException(sprintf('Could not create directory %s.', $directory));
    }
  }

  /**
   * Builds the failure result for a Team Folder that cannot be mounted.
   */
  protected function mountFailure(SodaScsProjectInterface $project): SodaScsResult {
    return SodaScsResult::failure(
      message: 'The Team Folder is not available.',
      error: sprintf('The Team Folder of project %s could not be mounted.', $project->id()),
    );
  }

  /**
   * Mounts the project's Team Folder and returns its local path.
   */
  protected function mountedPath(SodaScsProjectInterface $project): ?string {
    $relativePath = $this->projectMountManager->relativeMountPath($project);
    if ($relativePath === NULL || !$this->projectMountManager->ensureProjectMounted($project)) {
      return NULL;
    }
    return $this->mounterClient->hostMountPath($relativePath);
  }

  /**
   * Deletes everything inside a directory, but not the directory itself.
   *
   * @throws \RuntimeException
   */
  protected function purgeDirectoryContent(string $directory): void {
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
      /** @var \SplFileInfo $item */
      $isRemoved = $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
      if (!$isRemoved) {
        throw new \RuntimeException(sprintf('Could not delete %s.', $item->getPathname()));
      }
    }
  }

}
