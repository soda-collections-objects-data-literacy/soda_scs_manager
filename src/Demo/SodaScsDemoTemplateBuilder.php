<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsVolumeArchiveHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\Service\SodaScsJupyterHubApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds a versioned demo template from the central demo project.
 *
 * Artifacts land in {snapshot root}/_demo-templates/v{N}/; the manifest on
 * the template entity lists them with their SHA-256 checksums.
 */
class SodaScsDemoTemplateBuilder {

  use StringTranslationTrait;

  public const TEMPLATES_DIRECTORY = '_demo-templates';
  public const JUPYTER_VOLUME_FILE = 'jupyter-home.tar.gz';
  public const TEAM_FOLDER_DIRECTORY = 'team-folder';
  public const WEBPROTEGE_ONTOLOGY_FILE = 'webprotege-ontology.zip';
  public const WISSKI_DIRECTORY = 'wisski';

  /**
   * File owner of artifacts, matching snapshot files (www-data).
   */
  protected const ARTIFACT_OWNER = SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_UID;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.jupyter.helpers')]
    protected SodaScsJupyterHelpers $jupyterHelpers,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
    #[Autowire(service: 'soda_scs_manager.demo.project_apps')]
    protected SodaScsDemoProjectApps $projectApps,
    #[Autowire(service: 'soda_scs_manager.snapshot.helpers')]
    protected SodaScsSnapshotHelpers $snapshotHelpers,
    #[Autowire(service: 'soda_scs_manager.demo.team_folder_copier')]
    protected SodaScsTeamFolderCopier $teamFolderCopier,
    #[Autowire(service: 'datetime.time')]
    protected TimeInterface $time,
    #[Autowire(service: 'soda_scs_manager.volume_archive.helpers')]
    protected SodaScsVolumeArchiveHelpers $volumeArchiveHelpers,
    #[Autowire(service: 'soda_scs_manager.webprotege.helpers')]
    protected SodaScsWebprotegeHelpers $webprotegeHelpers,
    #[Autowire(service: 'soda_scs_manager.demo.wisski_transfer')]
    protected SodaScsDemoWisskiTransfer $wisskiTransfer,
  ) {}

  /**
   * Exports every demo application of the source project into the template.
   *
   * Sets the template to ready on success and to failed otherwise.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  public function build(SodaScsDemoTemplateInterface $template, ?callable $reportStep = NULL): void {
    $reportStep ??= static function (string $message): void {};
    $source = $template->get('sourceProject')->entity;
    if (!$source instanceof SodaScsProjectInterface) {
      $this->markFailed($template, 'The source project no longer exists.');
      throw new SodaScsDemoException('The source project of the demo template no longer exists.');
    }

    try {
      $directory = (string) $template->getArtifactDirectory();
      $this->createDirectory($directory);
      $manifest = [
        'version' => $template->getVersion(),
        'changelog' => $template->getChangelog(),
        'sourceProjectId' => (int) $source->id(),
        'createdAt' => $this->time->getCurrentTime(),
        'apps' => [],
        'checksums' => [],
      ];

      $wisskiStack = $this->projectApps->wisskiStack($source);
      if ($wisskiStack !== NULL) {
        $reportStep((string) $this->t('Exporting the WissKI'));
        $this->createDirectory($directory . '/' . self::WISSKI_DIRECTORY);
        $this->wisskiTransfer->export($wisskiStack, $directory . '/' . self::WISSKI_DIRECTORY);
        $manifest['apps'][SodaScsDemoLabelBuilder::APP_WISSKI] = [
          'directory' => self::WISSKI_DIRECTORY,
          'sourceLabel' => (string) $wisskiStack->label(),
          'sourceStackId' => (int) $wisskiStack->id(),
        ];
      }

      if ($this->projectApps->jupyterStack($source) !== NULL) {
        $reportStep((string) $this->t('Exporting the Jupyter home directory'));
        $this->exportJupyterHome($source, $directory);
        $manifest['apps'][SodaScsDemoLabelBuilder::APP_JUPYTER] = ['file' => self::JUPYTER_VOLUME_FILE];
      }

      $reportStep((string) $this->t('Copying the Team Folder'));
      $teamFolder = SodaScsDemoException::assertSuccess(
        $this->teamFolderCopier->exportTeamFolder($source, $directory . '/' . self::TEAM_FOLDER_DIRECTORY),
        'Export Team Folder',
      );
      $manifest['teamFolder'] = [
        'directory' => self::TEAM_FOLDER_DIRECTORY,
        'fileCount' => (int) ($teamFolder->data['fileCount'] ?? 0),
      ];

      $webprotegeComponent = $this->projectApps->webprotegeComponent($source);
      if ($webprotegeComponent !== NULL) {
        $reportStep((string) $this->t('Exporting the ontology'));
        SodaScsDemoException::assertSuccess(
          $this->webprotegeHelpers->downloadOntologyArchive($source, $directory . '/' . self::WEBPROTEGE_ONTOLOGY_FILE),
          'Export WebProtégé ontology',
        );
        $manifest['apps'][SodaScsDemoLabelBuilder::APP_WEBPROTEGE] = [
          'file' => self::WEBPROTEGE_ONTOLOGY_FILE,
          'sourceComponentId' => (int) $webprotegeComponent->id(),
          'sourceLabel' => (string) $webprotegeComponent->label(),
        ];
      }

      $manifest['checksums'] = $this->checksums($directory);
      $this->assertManifestFilesExist($manifest, $directory);
      $template->setManifest($manifest)
        ->setStatus(SodaScsDemoTemplateInterface::STATUS_READY)
        ->save();
    }
    catch (\Throwable $e) {
      $this->markFailed($template, $e->getMessage());
      throw $e instanceof SodaScsDemoException ? $e : new SodaScsDemoException($e->getMessage(), 0, $e);
    }
  }

  /**
   * Creates the template entity of a version in state "building".
   *
   * @param string $changelog
   *   What changed compared to the previous version.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $source
   *   The central demo project.
   * @param string $version
   *   A valid, unused version (see SodaScsDemoVersion).
   */
  public function createPendingTemplate(string $changelog, SodaScsProjectInterface $source, string $version): SodaScsDemoTemplateInterface {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface $template */
    $template = $this->entityTypeManager->getStorage('soda_scs_demo_template')->create([
      'artifactDirectory' => $this->templatesRoot() . '/v' . $version,
      'changelog' => $changelog,
      'label' => 'Demo v' . $version,
      'sourceProject' => $source->id(),
      'status' => SodaScsDemoTemplateInterface::STATUS_BUILDING,
      'version' => $version,
    ]);
    $template->save();
    return $template;
  }

  /**
   * Deletes the artifacts of a template from disk.
   */
  public function deleteArtifacts(SodaScsDemoTemplateInterface $template): void {
    $directory = (string) $template->getArtifactDirectory();
    if ($directory === '' || !str_starts_with($directory, $this->templatesRoot() . '/') || !is_dir($directory)) {
      return;
    }
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
      /** @var \SplFileInfo $item */
      if ($item->isDir() && !$item->isLink()) {
        @rmdir($item->getPathname());
      }
      else {
        @unlink($item->getPathname());
      }
    }
    if (!@rmdir($directory)) {
      $this->logger->warning('Could not delete all artifacts of demo template v@version in @directory.', [
        '@directory' => $directory,
        '@version' => $template->getVersion(),
      ]);
    }
  }

  /**
   * Root directory of all template versions.
   */
  public function templatesRoot(): string {
    return $this->snapshotHelpers->getSnapshotFilesystemRoot() . '/' . self::TEMPLATES_DIRECTORY;
  }

  /**
   * Fails the build when a file the manifest announces was not written.
   *
   * Volume archives are written by a short-lived container. A wrong host
   * path used to leave them outside the directory this process can see,
   * and the template was still marked ready.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function assertManifestFilesExist(array $manifest, string $directory): void {
    foreach ($this->manifestFiles($manifest['apps'] ?? []) as $relativePath) {
      if (!is_file($directory . '/' . $relativePath)) {
        throw new SodaScsDemoException(sprintf('Template file %s was not written.', $relativePath));
      }
    }
  }

  /**
   * SHA-256 of every file in the template, keyed by relative path.
   *
   * @return array<string, string>
   *   Checksums.
   */
  protected function checksums(string $directory): array {
    $checksums = [];
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($items as $item) {
      /** @var \SplFileInfo $item */
      if ($item->isFile()) {
        $checksums[substr($item->getPathname(), strlen($directory) + 1)] = (string) hash_file('sha256', $item->getPathname());
      }
    }
    ksort($checksums);
    return $checksums;
  }

  /**
   * Creates a directory that the database and sidecar containers can write.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function createDirectory(string $directory): void {
    if (!is_dir($directory) && !@mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new SodaScsDemoException(sprintf('Could not create directory %s.', $directory));
    }
    // mariadb-dump writes as www-data; the queue may run as root.
    @chown($directory, self::ARTIFACT_OWNER);
    @chgrp($directory, self::ARTIFACT_OWNER);
  }

  /**
   * Archives the project's Jupyter home volume.
   *
   * The server is stopped for a consistent copy and started again if it ran.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function exportJupyterHome(SodaScsProjectInterface $source, string $directory): void {
    $wasRunning = $this->jupyterHelpers->projectServerState($source) === SodaScsJupyterHubApiClient::STATE_RUNNING;
    SodaScsDemoException::assertSuccess($this->jupyterHelpers->stopProjectServerAndWait($source), 'Stop Jupyter server');
    try {
      SodaScsDemoException::assertSuccess(
        $this->volumeArchiveHelpers->exportVolume(
          SodaScsJupyterHelpers::projectVolumeName($source),
          $directory,
          self::JUPYTER_VOLUME_FILE,
          [SodaScsDemoApiCredentials::CREDENTIALS_FILE],
        ),
        'Export Jupyter home',
      );
    }
    finally {
      if ($wasRunning) {
        $this->jupyterHelpers->startProjectServer($source);
      }
    }
  }

  /**
   * Relative paths of the files a manifest entry announces.
   *
   * @return string[]
   *   Paths relative to the template directory.
   */
  protected function manifestFiles(array $apps): array {
    $files = [];
    foreach ($apps as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      if (isset($entry['file']) && is_string($entry['file'])) {
        $files[] = $entry['file'];
      }
      if (isset($entry['directory']) && is_string($entry['directory'])) {
        $files[] = $entry['directory'] . '/' . SodaScsDemoWisskiTransfer::DATABASE_FILE;
        $files[] = $entry['directory'] . '/' . SodaScsDemoWisskiTransfer::TRIPLESTORE_BASENAME . '.nq';
        $files[] = $entry['directory'] . '/' . SodaScsDemoWisskiTransfer::VOLUMES_FILE;
      }
      if (array_is_list($entry)) {
        foreach ($entry as $item) {
          if (is_array($item) && isset($item['file']) && is_string($item['file'])) {
            $files[] = $item['file'];
          }
        }
      }
    }
    return $files;
  }

  /**
   * Stores the failure on the template.
   */
  protected function markFailed(SodaScsDemoTemplateInterface $template, string $reason): void {
    $this->logger->error('Building demo template v@version failed: @reason', [
      '@reason' => $reason,
      '@version' => $template->getVersion(),
    ]);
    $template->setStatus(SodaScsDemoTemplateInterface::STATUS_FAILED)->save();
  }

}
