<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Service\SodaScsOrphanCleanup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms bulk deletion of selected orphan resources.
 */
final class SodaScsOrphanCleanupConfirmForm extends ConfirmFormBase {

  use DependencySerializationTrait;

  /**
   * Selected orphan keys.
   *
   * @var list<string>
   */
  protected array $selection = [];

  public function __construct(
    protected SodaScsOrphanCleanup $orphanCleanup,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.orphan_cleanup'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_orphan_cleanup_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the selected orphan resources?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This permanently deletes the selected stacks, components, Portainer stacks, databases and triplestore repositories. Deletion runs one item at a time with a progress page. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('soda_scs_manager.orphans');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete selected');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->selection = SodaScsOrphanCleanupForm::loadStoredSelection($this->tempStoreFactory);
    if ($this->selection === []) {
      $this->messenger()->addWarning($this->t('No orphan resources were selected.'));
      $form['back'] = [
        '#type' => 'link',
        '#title' => $this->t('Back to orphan resources'),
        '#url' => $this->getCancelUrl(),
      ];
      return $form;
    }

    $form = parent::buildForm($form, $form_state);
    $form['selection'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Selected (@count)', ['@count' => count($this->selection)]),
      '#items' => array_map([$this, 'formatKeyLabel'], $this->selection),
    ];
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#button_type'] = 'danger';
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selection = SodaScsOrphanCleanupForm::loadStoredSelection($this->tempStoreFactory);
    SodaScsOrphanCleanupForm::clearStoredSelection($this->tempStoreFactory);
    $ordered = $this->orphanCleanup->orderKeys($selection);

    $form_state->setRedirect('soda_scs_manager.orphans');
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Deleting orphan resources'))
      ->setInitMessage($this->t('Preparing to delete the selected orphan resources.'))
      ->setProgressMessage($this->t('@current of @total'))
      ->setErrorMessage($this->t('The orphan cleanup stopped with an error.'))
      ->addOperation([$this, 'deleteOrphansStep'], [$ordered])
      ->setFinishCallback([$this, 'finishDeleteBatch']);
    $this->setBatch($batch->toArray());
  }

  /**
   * Batch step: deletes one selected orphan per request.
   *
   * @param list<string> $keys
   *   Ordered selection keys.
   * @param array $context
   *   Batch context.
   */
  public function deleteOrphansStep(array $keys, array &$context): void {
    if (!isset($context['sandbox']['keys'])) {
      $context['sandbox']['keys'] = array_values($keys);
      $context['sandbox']['total'] = count($keys);
      $context['sandbox']['current'] = 0;
      $context['sandbox']['allowed'] = $this->orphanCleanup->currentAllowedKeys();
      $context['results']['deleted'] = 0;
      $context['results']['skipped'] = 0;
      $context['results']['errors'] = [];
    }

    $total = (int) $context['sandbox']['total'];
    $current = (int) $context['sandbox']['current'];
    if ($total === 0 || $current >= $total) {
      $context['finished'] = 1;
      return;
    }

    $key = $context['sandbox']['keys'][$current];
    $result = $this->orphanCleanup->deleteOne(
      $key,
      $context['sandbox']['allowed'],
    );
    if ($result['status'] === 'deleted') {
      $context['results']['deleted']++;
      $context['message'] = $this->t('Deleted @item.', [
        '@item' => $this->formatKeyLabel($key),
      ]);
    }
    elseif ($result['status'] === 'error') {
      $context['results']['skipped']++;
      if ($result['error'] !== NULL) {
        $context['results']['errors'][] = $result['error'];
      }
      $context['message'] = $this->t('Could not delete @item.', [
        '@item' => $this->formatKeyLabel($key),
      ]);
    }
    else {
      $context['results']['skipped']++;
      $context['message'] = $this->t('Skipped @item.', [
        '@item' => $this->formatKeyLabel($key),
      ]);
    }

    $context['sandbox']['current'] = $current + 1;
    $context['finished'] = $context['sandbox']['current'] / $total;
  }

  /**
   * Batch finish callback: reports counts and returns to the orphan page.
   *
   * @param bool $success
   *   FALSE when a batch request ended with an error.
   * @param array $results
   *   Results collected by the steps.
   */
  public function finishDeleteBatch(bool $success, array $results): void {
    foreach ($results['errors'] ?? [] as $error) {
      $this->messenger()->addError($error);
    }
    if (!$success) {
      $this->messenger()->addError($this->t('The orphan cleanup stopped with an error.'));
    }
    $deleted = (int) ($results['deleted'] ?? 0);
    $skipped = (int) ($results['skipped'] ?? 0);
    if ($deleted > 0) {
      $this->messenger()->addStatus($this->t('Deleted @count orphan resource(s).', [
        '@count' => $deleted,
      ]));
    }
    if ($skipped > 0) {
      $this->messenger()->addWarning($this->t('Skipped @count item(s) that were no longer orphans or could not be deleted.', [
        '@count' => $skipped,
      ]));
    }
  }

  /**
   * Hands the batch to the Batch API.
   *
   * @param array $batch
   *   The batch definition.
   */
  protected function setBatch(array $batch): void {
    batch_set($batch);
  }

  /**
   * Formats a selection key for the confirm list and batch messages.
   *
   * @param string $key
   *   Selection key.
   *
   * @return string
   *   Human-readable label.
   */
  public function formatKeyLabel(string $key): string {
    $parsed = SodaScsOrphanCleanup::parseSelectionKey($key);
    if ($parsed === NULL) {
      return $key;
    }
    [$kind, $parts] = $parsed;
    return match ($kind) {
      SodaScsOrphanCleanup::KIND_DANGLING => (string) $this->t('Entity @type @id', [
        '@type' => $parts[0] ?? '?',
        '@id' => $parts[1] ?? '?',
      ]),
      SodaScsOrphanCleanup::KIND_PORTAINER => (string) $this->t('Portainer stack @name (ID @id)', [
        '@name' => $parts[1] ?? '?',
        '@id' => $parts[0] ?? '?',
      ]),
      SodaScsOrphanCleanup::KIND_DATABASE => (string) $this->t('Database @name', [
        '@name' => $parts[0] ?? '?',
      ]),
      SodaScsOrphanCleanup::KIND_REPOSITORY => (string) $this->t('Repository @name', [
        '@name' => $parts[0] ?? '?',
      ]),
      default => $key,
    };
  }

}
