<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Form\FormStateInterface;
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
   * Prefix of the SQL database (component) a create action stores.
   */
  public const SQL_PREFIX = 'sql-';

  /**
   * Prefix of the WissKI stack machine name once its creation finished.
   */
  public const STACK_PREFIX = 'stack-';

  /**
   * Prefix of the triplestore repository (component) a create action stores.
   */
  public const TRIPLESTORE_PREFIX = 'ts-';

  /**
   * Prefix of the WissKI component a create action stores.
   */
  public const WISSKI_PREFIX = 'wisski-';

  /**
   * Prefixes the create actions put before an entered machine name.
   *
   * Keyed by bundle, then by the entity type that stores the name. A WissKI
   * stack keeps the bare name until its creation finishes, so the bare name
   * counts among stacks. Bundles that are not listed store the name unchanged
   * as a stack or a component.
   */
  public const STORED_MACHINE_NAME_PREFIXES = [
    'soda_scs_sql_component'         => ['soda_scs_component' => [self::SQL_PREFIX]],
    'soda_scs_triplestore_component' => ['soda_scs_component' => [self::TRIPLESTORE_PREFIX]],
    'soda_scs_wisski_component'      => ['soda_scs_component' => [self::WISSKI_PREFIX]],
    'soda_scs_wisski_stack'          => [
      'soda_scs_component' => [self::SQL_PREFIX, self::TRIPLESTORE_PREFIX, self::WISSKI_PREFIX],
      'soda_scs_stack'     => [self::STACK_PREFIX, ''],
    ],
  ];

  /**
   * Where bundles without own prefixes store the name.
   */
  private const UNPREFIXED_MACHINE_NAMES = [
    'soda_scs_component' => [''],
    'soda_scs_stack'     => [''],
  ];

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
   * Form API value callback: derive machineName before #required runs.
   *
   * FormValidator checks element #required while walking children, before
   * form #validate. Filling the value here keeps the field required and
   * still accepts an empty POST from the project wizard.
   *
   * @param array $element
   *   The machineName textfield.
   * @param mixed $input
   *   The posted value, or FALSE when the element was not submitted.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state. The label is read from user input.
   *
   * @return string|null
   *   The posted or derived machine name, or NULL when there was no input.
   */
  public static function valueCallback(array &$element, mixed $input, FormStateInterface $form_state): ?string {
    if ($input === FALSE || $input === NULL) {
      return NULL;
    }
    if (!is_scalar($input)) {
      $input = '';
    }
    $posted = str_replace(["\r", "\n"], '', (string) $input);
    if (trim($posted) !== '') {
      return $posted;
    }
    $label = (string) ($form_state->getUserInput()['label'][0]['value'] ?? '');
    // Value callbacks are static; the helper is a service.
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $helper = \Drupal::service('soda_scs_manager.machine_name.helpers');
    return $helper->fromLabel($label, self::APPLICATION_MAX_LENGTH);
  }

  /**
   * Whether a project machine name is reserved for platform resources.
   */
  public function isReservedProjectMachineName(string $machineName): bool {
    return in_array($machineName, self::RESERVED_PROJECT_MACHINE_NAMES, TRUE);
  }

  /**
   * Machine names an application of this bundle stores for an entered name.
   *
   * The create actions add a prefix per resource (SQL database "sql-",
   * triplestore repository "ts-", WissKI component "wisski-", WissKI stack
   * "stack-"), so a uniqueness check must compare these names, not the
   * entered one.
   *
   * @param string $bundle
   *   The stack or component bundle that will be created.
   * @param string $machineName
   *   The machine name as entered in the create form.
   *
   * @return array<string, string[]>
   *   The stored machine names, keyed by entity type ID.
   */
  public static function storedMachineNames(string $bundle, string $machineName): array {
    $prefixesByEntityType = self::STORED_MACHINE_NAME_PREFIXES[$bundle] ?? self::UNPREFIXED_MACHINE_NAMES;
    return array_map(
      static fn (array $prefixes): array => array_map(static fn (string $prefix): string => $prefix . $machineName, $prefixes),
      $prefixesByEntityType,
    );
  }

}
