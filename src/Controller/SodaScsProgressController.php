<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\soda_scs_manager\Progress\SodaScsProgressTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for operation progress status endpoints.
 */
final class SodaScsProgressController extends ControllerBase {

  /**
   * The progress tracker service.
   *
   * @var \Drupal\soda_scs_manager\Progress\SodaScsProgressTracker
   */
  protected SodaScsProgressTracker $progressTracker;

  /**
   * Constructs a new SodaScsProgressController.
   *
   * @param \Drupal\soda_scs_manager\Progress\SodaScsProgressTracker $progressTracker
   *   The progress tracker service.
   */
  public function __construct(
    SodaScsProgressTracker $progressTracker,
  ) {
    $this->progressTracker = $progressTracker;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('soda_scs_manager.progress_tracker'),
    );
  }

  /**
   * Get the current progress of an operation.
   *
   * @param string $operation_id
   *   The operation ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with progress data.
   */
  public function getProgress(string $operation_id): JsonResponse {
    $progress = $this->progressTracker->getProgress($operation_id);

    if ($progress === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Operation not found or expired.',
      ], 404);
    }

    return new JsonResponse([
      'success' => TRUE,
      'progress' => $progress,
    ]);
  }

}
