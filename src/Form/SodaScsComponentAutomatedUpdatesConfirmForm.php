<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsHealthStatus;
use Drupal\soda_scs_manager\Service\SodaScsWisskiUpdateQueue;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers $sodaScsComponentHelpers
   *   The SODa SCS component helpers.
   * @param \Drupal\soda_scs_manager\Service\SodaScsWisskiUpdateQueue $wisskiUpdateQueue
   *   Queue that runs WissKI updates in the background.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    protected SodaScsWisskiUpdateQueue $wisskiUpdateQueue,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.component.helpers'),
      $container->get('soda_scs_manager.wisski_update_queue'),
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
        && SodaScsHealthStatus::isRunning((string) ($healthResult['status'] ?? ''));

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

    // Each update outlives a web request, so they run as queued jobs, one
    // after the other; every component page shows the progress of its own.
    $queuedLabels = [];
    foreach ($healthyComponents as $component) {
      $result = $this->wisskiUpdateQueue->enqueue($component, 'latest');
      if ($result->success) {
        $queuedLabels[] = $component->label();
        continue;
      }
      $this->messenger()->addError($result->message);
    }
    if ($queuedLabels !== []) {
      $this->messenger()->addStatus($this->t('Queued updates for @count component(s): @components. They run one after the other in the background.', [
        '@components' => implode(', ', $queuedLabels),
        '@count'      => count($queuedLabels),
      ]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
