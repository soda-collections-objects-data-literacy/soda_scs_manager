<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Stores whether a user has finished the guided onboarding tour.
 */
final class SodaScsOnboardingController extends ControllerBase {

  /**
   * user.data module key shared with the co-working intro flags.
   */
  private const USER_DATA_MODULE = 'soda_scs_manager';

  /**
   * user.data name of the tour completion flag.
   */
  private const USER_DATA_TOUR_COMPLETED = 'onboarding_tour_completed';

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected UserDataInterface $userData,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('user.data'),
    );
  }

  /**
   * Marks the tour as finished or skipped so it does not start again.
   *
   * CSRF is enforced via route requirement _csrf_request_header_token.
   */
  public function complete(): JsonResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return new JsonResponse(['error' => 'Unauthorized'], 401);
    }

    $this->userData->set(self::USER_DATA_MODULE, (int) $account->id(), self::USER_DATA_TOUR_COMPLETED, 1);

    return new JsonResponse(['success' => TRUE]);
  }

  /**
   * Tells the client whether the tour should start on its own.
   */
  public function state(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $tourCompleted = (bool) $this->userData->get(self::USER_DATA_MODULE, $uid, self::USER_DATA_TOUR_COMPLETED);

    $response = new JsonResponse(['autoStart' => !$tourCompleted]);
    $response->setPrivate();
    $response->setMaxAge(0);
    return $response;
  }

}
