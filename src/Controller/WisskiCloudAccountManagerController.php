<?php

namespace Drupal\wisski_cloud_account_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Wisski Cloud account manager info controller.
 */
class WisskiCloudAccountManagerController extends ControllerBase {

  /**
   * @var \Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions
   *   The WissKi Cloud account manager daemon API actions service.
   */
  protected WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions;

  /**
   * Class constructor.
   *
   * @param \Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions
   *   The WissKi Cloud account manager daemon API actions service.
   */
  public function __construct(WisskiCloudAccountManagerDaemonApiActions $wisskiCloudAccountManagerDaemonApiActions) {
    $this->wisskiCloudAccountManagerDaemonApiActions = $wisskiCloudAccountManagerDaemonApiActions;
  }

  /**
   * Populate the reachable variables from services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The class container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wisski_cloud_account_manager.daemon_api.actions'),
    );
  }

  /**
   * Info page for terms and conditions.
   *
   * @return array
   *   The page build array.
   */
  public function termsAndConditionsPage(): array {
    $build = [
      '#theme' => 'wisski_cloud_account_manager_terms_and_conditions_page',
      '#date' => date('Y'),
    ];
    return $build;
  }

  /**
   * Page to check the validation and provision status.
   *
   * @param string $validationCode
   *   The token to check the status for.
   *
   * @return array
   *   The page build array.
   */
  public function validationPage(string $validationCode): array {
    $account = $this->wisskiCloudAccountManagerDaemonApiActions->validateAccount($validationCode);
    return [
      '#theme' => 'wisski_cloud_account_manager_validation_page',
      '#account' => $account,
      '#attached' => [
        'library' => [
          'wisski_cloud_account_manager/wisski_cloud_account_manager',
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Page list the account statuses.
   *
   * @return array
   *   The page build array.
   */
  public function accountManagingPage(): array {
    $accounts = $this->wisskiCloudAccountManagerDaemonApiActions->getAccounts();
    return [
      '#theme' => 'wisski_cloud_account_manager_account_managing_page',
      '#accounts' => $accounts,
      '#attached' => [
        'library' => [
          'wisski_cloud_account_manager/wisski_cloud_account_manager',
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
