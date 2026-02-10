<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
      return new JsonResponse(['status' => 'not_found'], 404);
    }

    try {
      $bundle = $stack->get('bundle')->value;
      switch ($bundle) {
        case 'soda_scs_wisski_stack':
          $wisskiHealth = $this->sodaScsStackHelpers
            ->checkWisskiHealth($stack);
          if (!$wisskiHealth) {
            return new JsonResponse([
              'status' => [
                'message' => $this->t("WissKI health check failed for stack @stack. Message: @message", [
                  '@stack' => $stack->id(),
                  '@message' => $wisskiHealth['message'],
                ]),
                'success' => FALSE,
              ],
              'code' => $wisskiHealth['code'],
            ]);
          }
          return new JsonResponse(['status' => $wisskiHealth]);

        case 'soda_scs_jupyter_stack':
          $jupyterHealth = $this->sodaScsStackHelpers->checkJupyterHealth($stack);
          if (!$jupyterHealth) {
            return new JsonResponse(
              [
                'status' => [
                  'message' => $this->t("Jupyter health check failed for stack @stack.", ['@stack' => $stack->id()]),
                  'success' => FALSE,
                ],
                'code' => $jupyterHealth['code'] ?? 500,
              ],
            );
          }
          return new JsonResponse(['status' => $jupyterHealth]);

        case 'soda_scs_nextcloud_stack':
          $nextcloudHealth = $this->sodaScsStackHelpers
            ->checkNextcloudHealth($stack);
          if (!$nextcloudHealth) {
            return new JsonResponse([
              'status' => [
                'message' => $this->t("Nextcloud health check failed for stack @stack.", ['@stack' => $stack->id()]),
                'success' => FALSE,
              ],
              'code' => $nextcloudHealth['code'] ?? 500,
            ]);
          }
          return new JsonResponse(['status' => $nextcloudHealth]);

        default:
          return new JsonResponse(
            [
              'status' => [
                'message' => $this->t("Health check failed for stack @stack with message: @message", [
                  '@stack' => $stack->id(),
                  '@message' => 'Unknown stack type.',
                ]),
                'success' => FALSE,
              ],
              'code' => 500,
            ],
            500,
          );
      }
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'status' => [
          'message' => $this->t("Health check failed for stack @stack with message: @message", [
            '@stack' => $stack->id(),
            '@message' => $e->getMessage(),
          ]),
        ],
        'code' => $e->getCode(),
      ], 500);
    }
  }

}
