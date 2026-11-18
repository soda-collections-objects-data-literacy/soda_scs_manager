<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Service\SodaScsOrphanCleanup;
use Drupal\soda_scs_manager\Service\SodaScsOrphanDetector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists orphan resources with checkboxes for bulk delete.
 */
final class SodaScsOrphanCleanupForm extends FormBase {

  /**
   * Tempstore key for the selected orphan keys.
   */
  private const TEMPSTORE_KEY = 'orphan_cleanup_selection';

  public function __construct(
    protected SodaScsOrphanDetector $orphanDetector,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.orphan_detector'),
      $container->get('tempstore.private'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_orphan_cleanup_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $report = $this->orphanDetector->detect();
    $total = $this->orphanDetector->countOrphans($report);

    foreach ($report['errors'] as $error) {
      $this->messenger()->addError($error);
    }

    $form['summary'] = [
      '#markup' => '<p>' . $this->t('Scanned at @time. Found @count orphan resource(s). Select items and delete them.', [
        '@time' => $this->dateFormatter->format($report['scannedAt'], 'short'),
        '@count' => $total,
      ]) . '</p>',
    ];

    $form['dangling'] = $this->buildDanglingSection($report['danglingProjectEntities'] ?? []);
    $form['portainer'] = $this->buildPortainerSection($report['portainerStacks'] ?? []);
    $form['databases'] = $this->buildSimpleSection(
      'databases',
      (string) $this->t('Orphan databases (@count)', [
        '@count' => count($report['databases'] ?? []),
      ]),
      (string) $this->t('MariaDB databases that no SQL component owns by machine name. Shared infrastructure databases such as Keycloak, Nextcloud, phpMyAdmin and the manager database are ignored.'),
      (string) $this->t('Database'),
      $report['databases'] ?? [],
      SodaScsOrphanCleanup::KIND_DATABASE,
      (string) $this->t('No orphan databases found.'),
    );
    $form['repositories'] = $this->buildSimpleSection(
      'repositories',
      (string) $this->t('Orphan triplestore repositories (@count)', [
        '@count' => count($report['repositories'] ?? []),
      ]),
      (string) $this->t('Triplestore repositories that no triplestore component owns by machine name. System repositories are ignored.'),
      (string) $this->t('Repository'),
      $report['repositories'] ?? [],
      SodaScsOrphanCleanup::KIND_REPOSITORY,
      (string) $this->t('No orphan triplestore repositories found.'),
    );

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Delete selected'),
        '#button_type' => 'danger',
      ],
    ];

    $form['#attached']['library'][] = 'soda_scs_manager/throbberOverlay';
    $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-component--component--form-submit';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->collectSelectedKeys($form_state) === []) {
      $form_state->setErrorByName('actions', $this->t('Select at least one orphan resource to delete.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $keys = $this->collectSelectedKeys($form_state);
    $store = $this->tempStoreFactory->get('soda_scs_manager');
    $store->set(self::TEMPSTORE_KEY, $keys);
    $form_state->setRedirect('soda_scs_manager.orphans.confirm');
  }

  /**
   * Reads the selected keys from the private tempstore.
   *
   * @return list<string>
   *   Selection keys, or an empty list.
   */
  public static function loadStoredSelection(PrivateTempStoreFactory $tempStoreFactory): array {
    $keys = $tempStoreFactory->get('soda_scs_manager')->get(self::TEMPSTORE_KEY);
    return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
  }

  /**
   * Clears the stored selection.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   */
  public static function clearStoredSelection(PrivateTempStoreFactory $tempStoreFactory): void {
    $tempStoreFactory->get('soda_scs_manager')->delete(self::TEMPSTORE_KEY);
  }

  /**
   * Collects checked tableselect values from all sections.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return list<string>
   *   Selected keys.
   */
  protected function collectSelectedKeys(FormStateInterface $form_state): array {
    $keys = [];
    foreach (['dangling', 'portainer', 'databases', 'repositories'] as $section) {
      $values = $form_state->getValue([$section, 'items']) ?? [];
      if (!is_array($values)) {
        continue;
      }
      foreach ($values as $key => $checked) {
        // Tableselect stores 0 for unchecked rows and the option key (string)
        // for checked ones.
        if (!$checked || !is_string($key) || $key === '' || $key === '0') {
          continue;
        }
        $keys[] = $key;
      }
    }
    return array_values(array_unique($keys));
  }

  /**
   * Builds the dangling-project tableselect section.
   *
   * @param array $entities
   *   Dangling entities from the detector.
   *
   * @return array
   *   Form section.
   */
  protected function buildDanglingSection(array $entities): array {
    $help = (string) $this->t('Stacks or components whose project field still points at a project ID that no longer exists (for example after a project was deleted without removing its applications).');
    $section = $this->sectionWrapper(
      (string) $this->t('Stacks and components with a deleted project (@count)', [
        '@count' => count($entities),
      ]),
      $help,
      $entities !== [],
    );

    if ($entities === []) {
      $section['empty'] = ['#markup' => '<p>' . $this->t('No stacks or components with a deleted project found.') . '</p>'];
      return $section;
    }

    $options = [];
    foreach ($entities as $entity) {
      $key = SodaScsOrphanCleanup::selectionKey(
        SodaScsOrphanCleanup::KIND_DANGLING,
        $entity['entityType'],
        $entity['id'],
      );
      $routeName = $entity['entityType'] === 'soda_scs_stack'
        ? 'entity.soda_scs_stack.canonical'
        : 'entity.soda_scs_component.canonical';
      $routeParam = $entity['entityType'] === 'soda_scs_stack'
        ? 'soda_scs_stack'
        : 'soda_scs_component';
      $labelText = $entity['label'] !== '' ? $entity['label'] : $entity['machineName'];
      $link = Link::fromTextAndUrl(
        $labelText,
        Url::fromRoute($routeName, [$routeParam => $entity['id']]),
      )->toString();
      $options[$key] = [
        'type' => $entity['entityType'] === 'soda_scs_stack'
          ? $this->t('Stack')
          : $this->t('Component'),
        'bundle' => $entity['bundle'],
        'label' => ['data' => ['#markup' => $link]],
        'machine' => $entity['machineName'],
        'project' => implode(', ', $entity['missingProjectIds']),
      ];
    }

    $section['items'] = [
      '#type' => 'tableselect',
      '#header' => [
        'type' => $this->t('Type'),
        'bundle' => $this->t('Bundle'),
        'label' => $this->t('Label'),
        'machine' => $this->t('Machine name'),
        'project' => $this->t('Missing project ID'),
      ],
      '#options' => $options,
      '#empty' => $this->t('No stacks or components with a deleted project found.'),
      '#js_select' => TRUE,
      '#attributes' => ['title' => $help],
    ];

    return $section;
  }

  /**
   * Builds the Portainer orphan tableselect section.
   *
   * @param array $stacks
   *   Orphan Portainer stacks.
   *
   * @return array
   *   Form section.
   */
  protected function buildPortainerSection(array $stacks): array {
    $help = (string) $this->t('Portainer stacks that have no matching WissKI component (by Portainer stack ID in externalId or by machine name) and no matching stack entity. Leftovers after an incomplete delete.');
    $section = $this->sectionWrapper(
      (string) $this->t('Orphan Portainer stacks (@count)', [
        '@count' => count($stacks),
      ]),
      $help,
      $stacks !== [],
    );

    if ($stacks === []) {
      $section['empty'] = ['#markup' => '<p>' . $this->t('No orphan Portainer stacks found.') . '</p>'];
      return $section;
    }

    $options = [];
    foreach ($stacks as $stack) {
      $key = SodaScsOrphanCleanup::selectionKey(
        SodaScsOrphanCleanup::KIND_PORTAINER,
        $stack['id'],
        $stack['name'],
      );
      $options[$key] = [
        'name' => $stack['name'],
        'id' => $stack['id'],
        'status' => $stack['status'],
      ];
    }

    $section['items'] = [
      '#type' => 'tableselect',
      '#header' => [
        'name' => $this->t('Name'),
        'id' => $this->t('Portainer ID'),
        'status' => $this->t('Status'),
      ],
      '#options' => $options,
      '#empty' => $this->t('No orphan Portainer stacks found.'),
      '#js_select' => TRUE,
      '#attributes' => ['title' => $help],
    ];

    return $section;
  }

  /**
   * Builds a single-column orphan tableselect section.
   *
   * @param string $key
   *   Form key.
   * @param string $title
   *   Details title.
   * @param string $help
   *   Hover help and description.
   * @param string $columnLabel
   *   Table header.
   * @param list<string> $items
   *   Names.
   * @param string $kind
   *   Selection kind.
   * @param string $emptyMessage
   *   Empty message.
   *
   * @return array
   *   Form section.
   */
  protected function buildSimpleSection(string $key, string $title, string $help, string $columnLabel, array $items, string $kind, string $emptyMessage): array {
    $section = $this->sectionWrapper($title, $help, $items !== []);
    if ($items === []) {
      $section['empty'] = ['#markup' => '<p>' . $emptyMessage . '</p>'];
      return $section;
    }

    $options = [];
    foreach ($items as $name) {
      $options[SodaScsOrphanCleanup::selectionKey($kind, $name)] = [
        'name' => $name,
      ];
    }

    $section['items'] = [
      '#type' => 'tableselect',
      '#header' => ['name' => $columnLabel],
      '#options' => $options,
      '#empty' => $emptyMessage,
      '#js_select' => TRUE,
      '#attributes' => ['title' => $help],
    ];

    return $section;
  }

  /**
   * Details wrapper with description and hover title.
   *
   * @param string $title
   *   Details title.
   * @param string $help
   *   Explanation of the category.
   * @param bool $open
   *   Whether the details start open.
   *
   * @return array
   *   Details element.
   */
  protected function sectionWrapper(string $title, string $help, bool $open): array {
    return [
      '#type' => 'details',
      '#title' => $title,
      '#description' => $help,
      '#open' => $open,
      // Keep each tableselect under its section key; without #tree all four
      // sections share the child key "items" and overwrite each other.
      '#tree' => TRUE,
      '#attributes' => [
        'title' => $help,
        'class' => ['soda-scs-manager--orphan-section'],
      ],
    ];
  }

}
