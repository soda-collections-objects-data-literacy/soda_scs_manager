<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Exports a demo version as one archive and imports it on another instance.
 *
 * The archive "demo-v{version}.tar.gz" holds the version directory
 * "v{version}/" with manifest.json; "demo-v{version}.tar.gz.sha256" sits
 * next to it. An import checks the archive checksum, refuses unsafe entries
 * (absolute paths, "..", links, devices), unpacks into a temporary directory
 * under the templates root, verifies the manifest checksums and only then
 * moves the directory into place and creates the version as ready.
 */
class SodaScsDemoTemplateArchive {

  /**
   * Format of manifest.json; an import refuses other formats.
   */
  public const FORMAT_VERSION = 1;

  /**
   * File name of the manifest in a version directory.
   */
  public const MANIFEST_FILE = 'manifest.json';

  /**
   * Default output directory of exports, under the templates root.
   */
  public const EXPORTS_DIRECTORY = '_exports';

  /**
   * Archive names: demo-v{major.minor.patch}.tar.gz.
   */
  public const ARCHIVE_NAME_PATTERN = '/^demo-v(\d+\.\d+\.\d+)\.tar\.gz$/';

  /**
   * Suffix of the checksum file next to an archive.
   */
  public const CHECKSUM_SUFFIX = '.sha256';

  /**
   * Seconds tar may take for an archive of several hundred MB.
   */
  protected const TAR_TIMEOUT_SECONDS = 3600;

  /**
   * Prefix of the temporary import directories under the templates root.
   */
  protected const IMPORT_DIRECTORY_PREFIX = '_import-';

  /**
   * Constructs the archive service.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.demo.manager')]
    protected SodaScsDemoManager $demoManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
    #[Autowire(service: 'soda_scs_manager.demo.template_builder')]
    protected SodaScsDemoTemplateBuilder $templateBuilder,
    #[Autowire(service: 'soda_scs_manager.demo.wisski_uri_rewriter')]
    protected SodaScsWisskiUriRewriter $uriRewriter,
  ) {}

  /**
   * Relative paths whose file is missing or differs from its checksum.
   *
   * @param array<string, string> $checksums
   *   Relative path => SHA-256, as in the manifest.
   * @param string $directory
   *   The version directory.
   *
   * @return string[]
   *   The damaged paths; empty when every file matches.
   */
  public static function damagedFiles(array $checksums, string $directory): array {
    $damaged = [];
    foreach ($checksums as $relativePath => $expected) {
      $path = $directory . '/' . $relativePath;
      if (!is_string($expected) || !is_file($path) || is_link($path) || hash_file('sha256', $path) !== $expected) {
        $damaged[] = (string) $relativePath;
      }
    }
    return $damaged;
  }

  /**
   * Packs a ready version into demo-v{version}.tar.gz plus a .sha256 file.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface $template
   *   The version.
   * @param string|null $outputDirectory
   *   Where to write; default {templates root}/_exports.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success data['archive'] and data['checksum'].
   */
  public function export(SodaScsDemoTemplateInterface $template, ?string $outputDirectory = NULL): SodaScsResult {
    if (!$template->isReady()) {
      return $this->failure(sprintf('Version %s is not ready.', $template->getVersion()));
    }
    $directory = $template->getArtifactDirectory();
    $manifest = $template->getManifest();
    $damaged = self::damagedFiles((array) ($manifest['checksums'] ?? []), $directory);
    if (!is_dir($directory) || $damaged !== []) {
      return $this->failure(sprintf('Files of version %s are missing or damaged: %s', $template->getVersion(), implode(', ', $damaged) ?: $directory));
    }
    // Versions built before 4.8 lack the base URIs of the source WissKI;
    // without them, demos on the other site keep this site's URIs.
    $wisskiApp = $manifest['apps'][SodaScsDemoLabelBuilder::APP_WISSKI] ?? NULL;
    if (is_array($wisskiApp) && !is_array($wisskiApp['uriBases'] ?? NULL)) {
      $bases = $this->uriRewriter->templateSourceBases($template);
      if ($bases === NULL) {
        return $this->failure(sprintf('Version %s does not list the base URIs of its source WissKI, and that WissKI cannot be read any more. Build a new version and export that one.', $template->getVersion()));
      }
      $manifest['apps'][SodaScsDemoLabelBuilder::APP_WISSKI]['uriBases'] = $bases;
    }
    // Rewritten on every export (older versions have none yet); it is not
    // part of the checksums.
    if (!self::writeManifestFile($directory, $manifest)) {
      return $this->failure(sprintf('Could not write %s into %s.', self::MANIFEST_FILE, $directory));
    }

    $outputDirectory ??= $this->templateBuilder->templatesRoot() . '/' . self::EXPORTS_DIRECTORY;
    if (!is_dir($outputDirectory) && !@mkdir($outputDirectory, 0775, TRUE) && !is_dir($outputDirectory)) {
      return $this->failure(sprintf('Could not create %s.', $outputDirectory));
    }
    $archiveName = 'demo-v' . $template->getVersion() . '.tar.gz';
    $archive = rtrim($outputDirectory, '/') . '/' . $archiveName;
    $partial = $archive . '.partial';
    $tar = $this->runTar(['tar', '-czf', $partial, '-C', dirname($directory), basename($directory)]);
    if ($tar !== NULL) {
      @unlink($partial);
      return $this->failure('Could not pack the version: ' . $tar);
    }
    $checksum = (string) hash_file('sha256', $partial);
    if (!rename($partial, $archive) || file_put_contents($archive . self::CHECKSUM_SUFFIX, $checksum . '  ' . $archiveName . "\n") === FALSE) {
      @unlink($partial);
      return $this->failure(sprintf('Could not write %s.', $archive));
    }
    $this->logger->info('Exported demo version @version to @archive.', [
      '@archive' => $archive,
      '@version' => $template->getVersion(),
    ]);
    return SodaScsResult::success(
      message: sprintf('Version %s exported to %s.', $template->getVersion(), $archive),
      data: ['archive' => $archive, 'checksum' => $checksum],
    );
  }

  /**
   * Imports an archive written by export() as a new ready version.
   *
   * @param string $archive
   *   Path of demo-v{version}.tar.gz; the .sha256 file must sit next to it.
   * @param bool $activate
   *   Whether to make the version the active one.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success data['template'].
   */
  public function import(string $archive, bool $activate = FALSE): SodaScsResult {
    if (!preg_match(self::ARCHIVE_NAME_PATTERN, basename($archive), $nameMatch) || !is_file($archive) || is_link($archive)) {
      return $this->failure(sprintf('%s is not a demo version archive (demo-v{major.minor.patch}.tar.gz).', $archive));
    }
    $version = $nameMatch[1];
    $checksumError = $this->checkArchiveChecksum($archive);
    if ($checksumError !== NULL) {
      return $this->failure($checksumError);
    }
    $versionError = $this->demoManager->validateNewVersion($version);
    if ($versionError !== NULL) {
      return $this->failure(sprintf('Version %s cannot be imported: %s', $version, $versionError));
    }
    $entryError = $this->checkEntries($archive, 'v' . $version);
    if ($entryError !== NULL) {
      return $this->failure($entryError);
    }

    // Only a failed build of the same version may exist; it is replaced once
    // the archive passed every check.
    $failedBuild = $this->demoManager->templateWithVersion($version);
    if ($failedBuild !== NULL && $failedBuild->getStatus() !== SodaScsDemoTemplateInterface::STATUS_FAILED) {
      return $this->failure(sprintf('Version %s exists already.', $version));
    }

    $root = $this->templateBuilder->templatesRoot();
    $target = $root . '/v' . $version;
    if ($failedBuild === NULL && file_exists($target)) {
      return $this->failure(sprintf('%s exists already.', $target));
    }
    $workDirectory = $root . '/' . self::IMPORT_DIRECTORY_PREFIX . bin2hex(random_bytes(6));
    if (!@mkdir($workDirectory, 0775, TRUE)) {
      return $this->failure(sprintf('Could not create %s.', $workDirectory));
    }
    $moved = FALSE;
    $saved = FALSE;
    try {
      $tar = $this->runTar(['tar', '-xzf', $archive, '-C', $workDirectory, '--no-same-owner', '--no-same-permissions']);
      if ($tar !== NULL) {
        return $this->failure('Could not unpack the archive: ' . $tar);
      }
      $unpacked = $workDirectory . '/v' . $version;
      $manifest = $this->readManifest($unpacked, $version);
      if (is_string($manifest)) {
        return $this->failure($manifest);
      }
      $this->setArtifactOwner($unpacked);
      // Deleting a failed build also removes what it left in v{version}/.
      if ($failedBuild !== NULL) {
        $deleted = $this->demoManager->deleteTemplate($failedBuild);
        if (!$deleted->success) {
          return $this->failure(sprintf('The failed build of version %s could not be removed: %s', $version, $deleted->message));
        }
      }
      if (file_exists($target)) {
        return $this->failure(sprintf('%s exists already.', $target));
      }
      if (!rename($unpacked, $target)) {
        return $this->failure(sprintf('Could not move the version to %s.', $target));
      }
      $moved = TRUE;

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface $template */
      $template = $this->entityTypeManager->getStorage('soda_scs_demo_template')->create([
        'artifactDirectory' => $target,
        'changelog' => (string) ($manifest['changelog'] ?? ''),
        'label' => 'Demo v' . $version,
        'origin' => SodaScsDemoTemplateInterface::ORIGIN_IMPORTED,
        'status' => SodaScsDemoTemplateInterface::STATUS_READY,
        'version' => $version,
      ]);
      $template->setManifest($manifest)->save();
      $saved = TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Import of demo archive @archive failed: @message', [
        '@archive' => $archive,
        '@message' => $e->getMessage(),
      ]);
      return $this->failure('The import failed: ' . $e->getMessage());
    }
    finally {
      if ($moved && !$saved) {
        $this->removeDirectory($target);
      }
      $this->removeDirectory($workDirectory);
    }

    $this->logger->notice('Imported demo version @version from @archive.', [
      '@archive' => $archive,
      '@version' => $version,
    ]);
    $message = sprintf('Version %s imported.', $version);
    if ($activate) {
      $activated = $this->demoManager->setActiveTemplate($template);
      if (!$activated->success) {
        return SodaScsResult::failure(error: $activated->error ?? $activated->message, message: $message . ' ' . $activated->message);
      }
      $message .= ' ' . $activated->message;
    }
    return SodaScsResult::success(message: $message, data: ['template' => $template]);
  }

  /**
   * Writes manifest.json into a version directory.
   *
   * @param string $directory
   *   The version directory.
   * @param array $manifest
   *   The manifest.
   *
   * @return bool
   *   Whether the file was written.
   */
  public static function writeManifestFile(string $directory, array $manifest): bool {
    $manifest = ['formatVersion' => self::FORMAT_VERSION] + $manifest;
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json !== FALSE && file_put_contents($directory . '/' . self::MANIFEST_FILE, $json . "\n") !== FALSE;
  }

  /**
   * Compares an archive with the checksum in its .sha256 file.
   *
   * @return string|null
   *   NULL when it matches, otherwise the reason.
   */
  protected function checkArchiveChecksum(string $archive): ?string {
    $checksumFile = $archive . self::CHECKSUM_SUFFIX;
    $line = is_file($checksumFile) ? (string) file_get_contents($checksumFile) : '';
    if (!preg_match('/^([0-9a-f]{64})\b/', trim($line), $match)) {
      return sprintf('%s is missing or holds no SHA-256 checksum.', $checksumFile);
    }
    if (!hash_equals($match[1], (string) hash_file('sha256', $archive))) {
      return sprintf('The checksum of %s does not match %s.', $archive, $checksumFile);
    }
    return NULL;
  }

  /**
   * Refuses archives with entries outside v{version}/ or that are no files.
   *
   * @return string|null
   *   NULL when every entry is a plain file or directory under the version
   *   directory, otherwise the reason.
   */
  protected function checkEntries(string $archive, string $topDirectory): ?string {
    $names = $this->listTar(['tar', '-tzf', $archive]);
    $details = $this->listTar(['tar', '-tvzf', $archive]);
    if ($names === NULL || $details === NULL || count($names) !== count($details) || $names === []) {
      return sprintf('Could not read the entries of %s.', $archive);
    }
    foreach ($names as $index => $name) {
      $type = $details[$index][0] ?? '';
      $segments = explode('/', rtrim($name, '/'));
      if (str_starts_with($name, '/') || in_array('..', $segments, TRUE) || $segments[0] !== $topDirectory) {
        return sprintf('The archive entry %s is outside %s/.', $name, $topDirectory);
      }
      if (!in_array($type, ['-', 'd'], TRUE)) {
        return sprintf('The archive entry %s is not a plain file or directory.', $name);
      }
    }
    return NULL;
  }

  /**
   * A failed result; the caller (drush) prints the reason.
   */
  protected function failure(string $reason): SodaScsResult {
    return SodaScsResult::failure(error: $reason, message: $reason, log: FALSE);
  }

  /**
   * Runs a tar listing.
   *
   * @return string[]|null
   *   The lines, or NULL when tar failed.
   */
  protected function listTar(array $command): ?array {
    $process = new Process($command, NULL, NULL, NULL, self::TAR_TIMEOUT_SECONDS);
    $process->run();
    if (!$process->isSuccessful()) {
      return NULL;
    }
    return array_values(array_filter(explode("\n", $process->getOutput()), static fn (string $line): bool => $line !== ''));
  }

  /**
   * Reads and checks manifest.json of an unpacked version.
   *
   * @return array|string
   *   The manifest, or the reason it is refused.
   */
  protected function readManifest(string $directory, string $version): array|string {
    $manifestFile = $directory . '/' . self::MANIFEST_FILE;
    $manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), TRUE) : NULL;
    if (!is_array($manifest)) {
      return sprintf('The archive has no readable %s.', self::MANIFEST_FILE);
    }
    if (($manifest['formatVersion'] ?? NULL) !== self::FORMAT_VERSION) {
      return sprintf('%s has format %s; this site reads format %d.', self::MANIFEST_FILE, var_export($manifest['formatVersion'] ?? NULL, TRUE), self::FORMAT_VERSION);
    }
    if (($manifest['version'] ?? NULL) !== $version) {
      return sprintf('%s names version %s, the archive %s.', self::MANIFEST_FILE, var_export($manifest['version'] ?? NULL, TRUE), $version);
    }
    $checksums = $manifest['checksums'] ?? NULL;
    if (!is_array($checksums) || $checksums === []) {
      return sprintf('%s lists no checksums.', self::MANIFEST_FILE);
    }
    $damaged = self::damagedFiles($checksums, $directory);
    if ($damaged !== []) {
      return 'Files of the archive are missing or damaged: ' . implode(', ', $damaged);
    }
    $unlisted = array_diff($this->relativeFiles($directory), array_keys($checksums), [self::MANIFEST_FILE]);
    if ($unlisted !== []) {
      return 'The archive holds files the manifest does not list: ' . implode(', ', $unlisted);
    }
    // The provisioner copies this directory into the demo's Team Folder.
    $teamFolder = $manifest['teamFolder'] ?? NULL;
    if ($teamFolder !== NULL && (!is_array($teamFolder) || ($teamFolder['directory'] ?? NULL) !== SodaScsDemoTemplateBuilder::TEAM_FOLDER_DIRECTORY)) {
      return sprintf('%s names an invalid Team Folder directory.', self::MANIFEST_FILE);
    }
    foreach ((array) ($manifest['apps'] ?? []) as $app => $entry) {
      if (!is_array($entry)) {
        return sprintf('%s has an invalid entry for %s.', self::MANIFEST_FILE, $app);
      }
      foreach (['directory', 'file'] as $key) {
        $path = $entry[$key] ?? NULL;
        if ($path === NULL) {
          continue;
        }
        $listed = is_string($path) && $path !== '' && !str_contains($path, '..') && array_filter(
          array_keys($checksums),
          static fn (string $file): bool => $file === $path || str_starts_with($file, rtrim($path, '/') . '/'),
        ) !== [];
        if (!$listed) {
          return sprintf('%s names %s for %s, which the archive does not hold.', self::MANIFEST_FILE, var_export($path, TRUE), $app);
        }
      }
    }
    return $manifest;
  }

  /**
   * Relative paths of all files under a directory.
   *
   * @return string[]
   *   The paths.
   */
  protected function relativeFiles(string $directory): array {
    $files = [];
    $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
      /** @var \SplFileInfo $item */
      if (!$item->isDir()) {
        $files[] = substr($item->getPathname(), strlen($directory) + 1);
      }
    }
    return $files;
  }

  /**
   * Deletes a directory tree below the templates root.
   */
  protected function removeDirectory(string $directory): void {
    if (!is_dir($directory) || !str_starts_with($directory, $this->templateBuilder->templatesRoot() . '/')) {
      return;
    }
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
      /** @var \SplFileInfo $item */
      $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($directory);
  }

  /**
   * Runs tar.
   *
   * @return string|null
   *   NULL on success, otherwise the error output.
   */
  protected function runTar(array $command): ?string {
    $process = new Process($command, NULL, NULL, NULL, self::TAR_TIMEOUT_SECONDS);
    $process->run();
    return $process->isSuccessful() ? NULL : (trim($process->getErrorOutput()) ?: 'tar failed');
  }

  /**
   * Gives an imported version the owner of built ones (www-data).
   *
   * Drush may run as root; the sidecar containers that restore a demo read
   * the files as www-data.
   */
  protected function setArtifactOwner(string $directory): void {
    $owner = SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_UID;
    @chown($directory, $owner);
    @chgrp($directory, $owner);
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($items as $item) {
      /** @var \SplFileInfo $item */
      @chown($item->getPathname(), $owner);
      @chgrp($item->getPathname(), $owner);
    }
  }

}
