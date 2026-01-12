<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a confirmation form for updating all components.
 */
class SodaScsComponentAutomatedUpdatesConfirmForm extends ConfirmFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The SODa SCS Drupal helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers
   */
  protected SodaScsDrupalHelpers $sodaScsDrupalHelpers;

  /**
   * The progress helper service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper
   */
  protected SodaScsProgressHelper $sodaScsProgressHelper;

  /**
   * The SODa SCS component helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected SodaScsComponentHelpers $sodaScsComponentHelpers;

  /**
   * Constructs a new SodaScsComponentAutomatedUpdatesConfirmForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers $sodaScsDrupalHelpers
   *   The SODa SCS Drupal helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper $sodaScsProgressHelper
   *   The progress helper service.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers $sodaScsComponentHelpers
   *   The SODa SCS component helpers.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    SodaScsDrupalHelpers $sodaScsDrupalHelpers,
    SodaScsProgressHelper $sodaScsProgressHelper,
    SodaScsComponentHelpers $sodaScsComponentHelpers,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->sodaScsDrupalHelpers = $sodaScsDrupalHelpers;
    $this->sodaScsProgressHelper = $sodaScsProgressHelper;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.drupal.helpers'),
      $container->get('soda_scs_manager.progress.helpers'),
      $container->get('soda_scs_manager.component.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_component_automated_updates_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to update all WissKI components with automated updates enabled?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.soda_scs_component.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Update');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Query for all WissKI components with automated updates enabled.
    /** @var \Drupal\Core\Entity\EntityStorageInterface $componentStorage */
    $componentStorage = $this->entityTypeManager->getStorage('soda_scs_component');
    $query = $componentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'soda_scs_wisski_component')
      ->condition('automatedUpdates', TRUE);
    $componentIds = $query->execute();

    if (empty($componentIds)) {
      $this->messenger()->addWarning($this->t('No WissKI components with automated updates enabled found.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    // Load all components.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface[] $components */
    $components = $componentStorage->loadMultiple($componentIds);

    // Check health status and filter out unhealthy components.
    $healthyComponents = [];
    $unhealthyComponents = [];
    foreach ($components as $component) {
      $healthResult = $this->sodaScsComponentHelpers->drupalHealthCheck($component);
      $isHealthy = is_array($healthResult)
        && ($healthResult['success'] ?? FALSE)
        && ($healthResult['status'] ?? '') === 'running';

      if ($isHealthy) {
        $healthyComponents[] = $component;
      }
      else {
        $unhealthyComponents[] = [
          'component' => $component,
          'health' => $healthResult,
        ];
      }
    }

    // Show warning if some components are unhealthy.
    if (!empty($unhealthyComponents)) {
      $unhealthyLabels = [];
      foreach ($unhealthyComponents as $item) {
        $status = is_array($item['health']) ? ($item['health']['status'] ?? 'unknown') : 'unknown';
        $unhealthyLabels[] = $item['component']->label() . ' (' . $status . ')';
      }
      $this->messenger()->addWarning($this->t('Skipping @count unhealthy component(s): @components', [
        '@count' => count($unhealthyComponents),
        '@components' => implode(', ', $unhealthyLabels),
      ]));
    }

    // If no healthy components, show message and return.
    if (empty($healthyComponents)) {
      $this->messenger()->addError($this->t('No healthy WissKI components with automated updates enabled found. All components must be running before they can be updated.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    // Prepare batch operations only for healthy components.
    $operations = [];
    foreach ($healthyComponents as $component) {
      $operations[] = [
        [self::class, 'batchUpdateComponent'],
        [
          $component->id(),
          $component->label(),
        ],
      ];
    }

    // Create a batch operation.
    $batch = [
      'title' => $this->t('Updating WissKI components with automated updates...'),
      'operations' => $operations,
      'finished' => [self::class, 'batchFinished'],
      'progress_message' => $this->t('Processed @current out of @total.'),
      'redirect' => $this->getCancelUrl(),
    ];

    batch_set($batch);
  }

  /**
   * Batch operation callback to update a single component.
   *
   * @param int $componentId
   *   The component ID.
   * @param string $componentLabel
   *   The component label.
   * @param array $context
   *   The batch context.
   */
  public static function batchUpdateComponent(int $componentId, string $componentLabel, array &$context): void {
    $t = \Drupal::translation();
    /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager */
    $entityTypeManager = \Drupal::entityTypeManager();
    /** @var \Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers $sodaScsDrupalHelpers */
    $sodaScsDrupalHelpers = \Drupal::service('soda_scs_manager.drupal.helpers');
    /** @var \Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper $sodaScsProgressHelper */
    $sodaScsProgressHelper = \Drupal::service('soda_scs_manager.progress.helpers');

    // Initialize results if not set.
    if (!isset($context['results']['updated'])) {
      $context['results']['updated'] = [];
    }
    if (!isset($context['results']['failed'])) {
      $context['results']['failed'] = [];
    }
    if (!isset($context['results']['skipped'])) {
      $context['results']['skipped'] = [];
    }

    // Load the component.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null $component */
    $component = $entityTypeManager->getStorage('soda_scs_component')->load($componentId);

    if (!$component) {
      $context['results']['failed'][] = $componentLabel . ' (not found)';
      $context['message'] = $t->translate('Failed to load component: @label', ['@label' => $componentLabel]);
      return;
    }

    // Create an operation UUID for progress tracking.
    $operationUuid = $sodaScsProgressHelper->createOperation('automated_drupal_packages_update', 'started');
    if (!$operationUuid) {
      $context['results']['failed'][] = $componentLabel . ' (failed to create operation)';
      $context['message'] = $t->translate('Failed to create operation for: @label', ['@label' => $componentLabel]);
      return;
    }

    // Update the component.
    $updateResult = $sodaScsDrupalHelpers->updateDrupalPackages(
      $component,
      $operationUuid,
      'latest',
    );

    // Update operation status.
    if ($updateResult->success) {
      if (!empty($updateResult->data['skipped'])) {
        $sodaScsProgressHelper->updateOperation($operationUuid, ['status' => 'completed']);
        $context['results']['skipped'][] = $componentLabel;
        $context['message'] = $t->translate('Skipped @label (already up to date)', ['@label' => $componentLabel]);
      }
      else {
        $sodaScsProgressHelper->updateOperation($operationUuid, ['status' => 'completed']);
        $context['results']['updated'][] = $componentLabel;
        $context['message'] = $t->translate('Updated @label', ['@label' => $componentLabel]);
      }
    }
    else {
      $sodaScsProgressHelper->updateOperation($operationUuid, ['status' => 'failed']);
      $context['results']['failed'][] = $componentLabel . ' (' . $updateResult->message . ')';
      $context['message'] = $t->translate('Failed to update @label: @message', [
        '@label' => $componentLabel,
        '@message' => $updateResult->message,
      ]);
    }
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   The batch results.
   * @param array $operations
   *   The operations that were executed.
   */
  public static function batchFinished(bool $success, array $results, array $operations): RedirectResponse {
    $messenger = \Drupal::messenger();
    $t = \Drupal::translation();
    /** @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator */
    $cacheTagsInvalidator = \Drupal::service('cache_tags.invalidator');

    // Invalidate cache tags for all components.
    $cacheTagsInvalidator->invalidateTags(['soda_scs_manager:drupal_packages']);

    if ($success) {
      $updatedCount = count($results['updated'] ?? []);
      $skippedCount = count($results['skipped'] ?? []);
      $failedCount = count($results['failed'] ?? []);

      if ($updatedCount > 0) {
        $messenger->addStatus($t->translate('Successfully updated @count component(s).', ['@count' => $updatedCount]));
      }

      if ($skippedCount > 0) {
        $messenger->addStatus($t->translate('Skipped @count component(s) (already up to date).', ['@count' => $skippedCount]));
      }

      if ($failedCount > 0) {
        $messenger->addError($t->translate('Failed to update @count component(s).', ['@count' => $failedCount]));
        foreach ($results['failed'] as $failed) {
          $messenger->addError($failed);
        }
      }
    }
    else {
      $messenger->addError($t->translate('An error occurred while updating components.'));
    }

    // Redirect to the components page.
    $redirectUrl = Url::fromRoute('entity.soda_scs_component.collection');
    return new RedirectResponse($redirectUrl->toString());
  }

}
