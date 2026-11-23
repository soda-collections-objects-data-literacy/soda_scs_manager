<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * A versioned demo project template built from the central demo project.
 */
interface SodaScsDemoTemplateInterface extends ContentEntityInterface {

  /**
   * The template artifacts are being exported.
   */
  public const STATUS_BUILDING = 'building';

  /**
   * The export failed; the template cannot be used.
   */
  public const STATUS_FAILED = 'failed';

  /**
   * All artifacts are exported and verified.
   */
  public const STATUS_READY = 'ready';

  /**
   * Built on this site from the demo source project.
   */
  public const ORIGIN_BUILT = 'built';

  /**
   * Imported from an archive of another site; it has no source project.
   */
  public const ORIGIN_IMPORTED = 'imported';

  /**
   * Absolute directory that holds the artifacts of this version.
   */
  public function getArtifactDirectory(): string;

  /**
   * What changed compared to the previous version, as plain text.
   */
  public function getChangelog(): string;

  /**
   * Manifest with one entry per exported application.
   *
   * @return array<string, mixed>
   *   The decoded manifest, empty while building.
   */
  public function getManifest(): array;

  /**
   * One of the STATUS_* constants.
   */
  public function getStatus(): string;

  /**
   * The semantic version "major.minor.patch", e.g. "1.2.0".
   *
   * @see \Drupal\soda_scs_manager\Demo\SodaScsDemoVersion
   */
  public function getVersion(): string;

  /**
   * One of the ORIGIN_* constants.
   */
  public function getOrigin(): string;

  /**
   * Whether the version was imported from another site.
   */
  public function isImported(): bool;

  /**
   * Whether all artifacts are exported and the template can be applied.
   */
  public function isReady(): bool;

  /**
   * Stores the manifest as JSON.
   *
   * @param array<string, mixed> $manifest
   *   The manifest.
   */
  public function setManifest(array $manifest): static;

  /**
   * Sets one of the STATUS_* constants.
   */
  public function setStatus(string $status): static;

}
