<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsHealthStatus;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class StackBundleController.
 */
class SodaScsStackController extends ControllerBase {

  /**
   * The SODa SCS Manager component helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers
   */
  protected $sodaScsStackHelpers;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritDoc}
   */
  public function __construct(SodaScsStackHelpers $sodaScsStackHelpers, EntityTypeManagerInterface $entity_type_manager) {
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('soda_scs_manager.stack.helpers'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Check the status of a component.
   */
  public function stackStatus($stack_id) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
    $stack = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->load($stack_id);

    if (!$stack) {
      return $this->healthJson(SodaScsHealthStatus::payload(
        SodaScsHealthStatus::UNKNOWN,
        (string) $this->t('Stack not found.'),
      ));
    }

    try {
      $bundle = $stack->get('bundle')->value;
      $health = match ($bundle) {
        'soda_scs_wisski_stack' => $this->sodaScsStackHelpers->checkWisskiHealth($stack),
        'soda_scs_jupyter_stack' => $this->sodaScsStackHelpers->checkJupyterHealth($stack),
        default => SodaScsHealthStatus::payload(
          SodaScsHealthStatus::UNKNOWN,
          (string) $this->t('Unknown stack type.'),
        ),
      };

      return $this->healthJson($health);
    }
    catch (\Exception $e) {
      return $this->healthJson(SodaScsHealthStatus::payload(
        SodaScsHealthStatus::UNKNOWN,
        (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::UNKNOWN),
        $e->getMessage(),
      ));
    }
  }

  /**
   * Returns a 200 JSON health payload for the polling UI.
   *
   * @param mixed $health
   *   Raw health-check array.
   */
  private function healthJson(mixed $health): JsonResponse {
    return new JsonResponse(['status' => SodaScsHealthStatus::normalizePayload($health)]);
  }

}
