<?php

namespace Drupal\wisski_cloud_account_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\wisski_cloud_account_manager\WisskiCloudAccountManagerDaemonApiActions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

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
   * Page list the account statuses.
   *
   * @return array
   *   The page build array.
   */
  public function accountManagingPage(): array {
    $currentUser = \Drupal::currentUser();
    $healthCheck = $this->wisskiCloudAccountManagerDaemonApiActions->healthCheck();
    $accounts = $this->wisskiCloudAccountManagerDaemonApiActions->getAccounts();
    // If the user is not an admin, filter the accounts to only include their own.
    if (!$currentUser->hasPermission('admister wisski cloud account manager')) {
    $accounts = array_filter($accounts, function($account) use ($currentUser) {
      return $account['uid'] === $currentUser->id();
    });
  }
    return [
      '#theme' => 'wisski_cloud_account_manager_account_managing_page',
      '#accounts' => $accounts,
      '#healthCheck' => $healthCheck,
      '#attached' => [
        'library' => [
          'wisski_cloud_account_manager/accountOptions',
          'wisski_cloud_account_manager/globalStyling',
          'wisski_cloud_account_manager/provisionStatus'
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  // @todo Implement healthcheckPage().
  public function healthcheckPage(): array {
    return [
      '#theme' => 'wisski_cloud_account_manager_healthcheck_page',
      '#healthCheck' => $this->wisskiCloudAccountManagerDaemonApiActions->healthCheck(),
    ];
  }

  public function provisionStatusPage($aid) {
    $accounts = $this->wisskiCloudAccountManagerDaemonApiActions->getAccounts($aid);
    // Return the status as a JSON response.
    switch ($accounts[0]['provisioned']) {
      case '0':
        return new JsonResponse(['status' => 'no']);
      case '1':
        return new JsonResponse(['status' => 'ongoing']);
      case '2':
        return new JsonResponse(['status' => 'yes']);
      case '3':
        return new JsonResponse(['status' => 'unknown']);
    }
    return new JsonResponse(['status' => $accounts[0]['provisioned']]);
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
    $user = $this->wisskiCloudAccountManagerDaemonApiActions->validateAccount($validationCode);
    
    return [
      '#markup' => $this->t('Your WissKI Cloud account for user :name is now validated. You can now <a href="/user">login</a>. Do not forget to start your provision by navigating to WissKI Cloud->Manage instances, and select the option "provise".', [
        ':name' => $user['name'],
      ]),
    ];
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
  public function forceValidationPage(string $aid): array {
    $user = $this->wisskiCloudAccountManagerDaemonApiActions->forceValidateAccount($aid);
    dpm($user);
    return [
      '#markup' => $this->t('Your WissKI Cloud account for user :name is now validated. You can now <a href="/user">login</a>. Do not forget to start your provision by navigating to WissKI Cloud->Manage instances, and select the option "provise".', [
        ':name' => $user['name'],
      ]),
    ];
  }

}

  