<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

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
    return $this->t('This permanently deletes the selected stacks, components, Portainer stacks, databases and triplestore repositories. This action cannot be undone.');
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
    $form['#attached']['library'][] = 'soda_scs_manager/throbberOverlay';
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-component--component--form-submit';
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

    $result = $this->orphanCleanup->deleteSelected($selection);
    foreach ($result['errors'] as $error) {
      $this->messenger()->addError($error);
    }
    if ($result['deleted'] > 0) {
      $this->messenger()->addStatus($this->t('Deleted @count orphan resource(s).', [
        '@count' => $result['deleted'],
      ]));
    }
    if ($result['skipped'] > 0) {
      $this->messenger()->addWarning($this->t('Skipped @count item(s) that were no longer orphans or could not be deleted.', [
        '@count' => $result['skipped'],
      ]));
    }

    $form_state->setRedirect('soda_scs_manager.orphans');
  }

  /**
   * Formats a selection key for the confirm list.
   *
   * @param string $key
   *   Selection key.
   *
   * @return string
   *   Human-readable label.
   */
  protected function formatKeyLabel(string $key): string {
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
