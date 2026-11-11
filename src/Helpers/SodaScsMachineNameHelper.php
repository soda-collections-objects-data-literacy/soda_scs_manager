<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Component\Transliteration\TransliterationInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Derives machine names from human-readable labels.
 *
 * Single source of truth for the server side; js/machineNameGenerator.js only
 * previews the result.
 */
class SodaScsMachineNameHelper {

  /**
   * Maximum length of a project machine name.
   */
  public const PROJECT_MAX_LENGTH = 64;

  /**
   * Maximum length of a stack/component application machine name.
   *
   * Matches the create-form validation in SodaScsStackCreateForm.
   */
  public const APPLICATION_MAX_LENGTH = 30;

  /**
   * Project machine names that must never be used.
   *
   * These collide with platform-wide Nextcloud Team Folders.
   */
  public const RESERVED_PROJECT_MACHINE_NAMES = ['scs-share'];

  /**
   * Constructs the helper.
   */
  public function __construct(
    #[Autowire(service: 'transliteration')]
    protected TransliterationInterface $transliteration,
  ) {}

  /**
   * Derives a machine name: a-z, 0-9 and minus, starting with a letter.
   *
   * Transliterates first so German umlauts become "oe", "ue", "ae".
   *
   * @param string $label
   *   The human-readable label.
   * @param int $maxLength
   *   Maximum length of the result.
   *
   * @return string
   *   The machine name, or an empty string when the label has no letters.
   */
  public function fromLabel(string $label, int $maxLength = self::PROJECT_MAX_LENGTH): string {
    $transliterated = $this->transliteration->transliterate(trim($label), 'de');
    $machineName = (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($transliterated));
    $machineName = (string) preg_replace('/^[^a-z]+/', '', $machineName);
    return rtrim(mb_substr($machineName, 0, max(1, $maxLength)), '-');
  }

  /**
   * Whether a project machine name is reserved for platform resources.
   */
  public function isReservedProjectMachineName(string $machineName): bool {
    return in_array($machineName, self::RESERVED_PROJECT_MACHINE_NAMES, TRUE);
  }

}
