<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoStatusBuilder;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Creates user demo projects and reports their progress.
 */
final class SodaScsDemoProjectController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected SodaScsDemoManager $demoManager,
    protected SodaScsDemoStatusBuilder $statusBuilder,
    protected UserDataInterface $userData,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.demo.manager'),
      $container->get('soda_scs_manager.demo.status_builder'),
      $container->get('user.data'),
    );
  }

  /**
   * Creates the current user's demo project and queues filling it.
   *
   * CSRF is enforced via route requirement _csrf_request_header_token.
   */
  public function createDemo(): JsonResponse {
    $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    if (!$user instanceof UserInterface) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Unauthorized'], 401);
    }

    $result = $this->demoManager->requestDemoProject($user);
    if (!$result->success) {
      return new JsonResponse(['success' => FALSE, 'error' => $result->message], 409);
    }

    $this->userData->set('soda_scs_manager', (int) $user->id(), 'coworking_intro_completed', 1);
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $result->data['project'];
    return new JsonResponse([
      'success' => TRUE,
      'message' => $result->message,
      'projectId' => (string) $project->id(),
      'projectUrl' => $project->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ]);
  }

  /**
   * Current demo status of a project for polling.
   */
  public function status(SodaScsProjectInterface $soda_scs_project): JsonResponse {
    if (!$this->demoManager->isDemoProject($soda_scs_project)) {
      return new JsonResponse(['error' => 'Not a demo project.'], 404);
    }
    $response = new JsonResponse($this->statusBuilder->data($soda_scs_project));
    $response->setPrivate();
    $response->setMaxAge(0);
    return $response;
  }

  /**
   * Status of every demo version, for the admin page while a build runs.
   */
  public function versionsStatus(): JsonResponse {
    $versions = [];
    foreach ($this->demoManager->templatesByVersion() as $template) {
      $versions[] = [
        'id' => (string) $template->id(),
        'status' => $template->getStatus(),
        'text' => $this->demoManager->templateStatusText($template),
      ];
    }
    $response = new JsonResponse(['versions' => $versions]);
    $response->setPrivate();
    $response->setMaxAge(0);
    return $response;
  }

}
