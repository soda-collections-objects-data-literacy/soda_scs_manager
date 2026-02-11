<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\Exception\SodaScsHelpersException;

/**
 * Helper class for SCS OpenGDB operations.
 */
class SodaScsOpenGdbHelpers {

  /**
   * The SCS OpenGDB service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface
   */
  protected SodaScsOpenGdbRequestInterface $sodaScsOpenGdbServiceActions;


  /**
   * The SCS Service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  /**
   * The SCS Service Key actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * Constructor.
   *
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface $sodaScsOpenGdbServiceActions
   *   The SCS OpenGDB service actions.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers $sodaScsServiceHelpers
   *   The SCS Service helpers.
   * @param \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions
   *   The SCS Service Key actions.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.opengdb_service.actions')]
    SodaScsOpenGdbRequestInterface $sodaScsOpenGdbServiceActions,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    #[Autowire(service: 'soda_scs_manager.service_key.actions')]
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
  ) {
    $this->sodaScsOpenGdbServiceActions = $sodaScsOpenGdbServiceActions;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
  }

  /**
   * Get User By Name.
   *
   * @param string $username
   *   The username of the user.
   *
   * @return array|null
   *   The user.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsRequestException
   *   If the request fails.
   */
  public function getUserDataByName(string $username) {
    $requestParams = [
      'type' => 'user',
      'queryParams' => [],
      'routeParams' => ['username' => $username],
    ];
    $request = $this->sodaScsOpenGdbServiceActions->buildGetRequest($requestParams);
    $response = $this->sodaScsOpenGdbServiceActions->makeRequest($request);

    if (!$response['success']) {
      if ($response['data']['openGdbResponse']->getCode() === 404) {
        return NULL;
      }
      throw new \Exception('Failed to get user by name.');
    }
    else {
      return json_decode($response['data']['openGdbResponse']->getBody()->getContents(), TRUE);
    }

  }

  /**
   * Create User.
   *
   * @param string $username
   *   The username of the user.
   * @param string $password
   *   The password of the user.
   * @param string $machineName
   *   The machine name of the repository.
   *
   * @return array
   *   The user.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsRequestException
   *   If the request fails.
   */
  public function createUser(string $username, string $password, string $machineName = NULL) {
    try {
      $createUserRequestParams = [
        'type' => 'user',
        'queryParams' => [],
        'routeParams' => ['username' => $username],
        'body' => [
          'password' => $password,
          'machineName' => $machineName,
        ],
      ];
      $openGdbCreateUserRequest = $this->sodaScsOpenGdbServiceActions->buildCreateRequest($createUserRequestParams);
      $createUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbCreateUserRequest);

      return $createUserResponse;
    }
    catch (\Exception $e) {
      throw SodaScsHelpersException::opengdbFailed($e->getMessage(), 'create_user', ['username' => $username], $e);
    }
  }

  /**
   * Update User.
   *
   * @param string $username
   *   The username of the user.
   * @param string $password
   *   The password of the user.
   *
   * @return array
   *   The user.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsRequestException
   *   If the request fails.
   */
  public function updateUser(string $username, string $password) {
    try {
      $updateUserRequestParams = [
        'type' => 'user',
        'queryParams' => [],
        'routeParams' => ['username' => $username],
        'body' => [
          'password' => $password,
        ],
      ];
      $openGdbUpdateUserRequest = $this->sodaScsOpenGdbServiceActions->buildUpdateRequest($updateUserRequestParams);
      $updateUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbUpdateUserRequest);

      return $updateUserResponse;
    }
    catch (\Exception $e) {
      throw SodaScsHelpersException::opengdbFailed($e->getMessage(), 'update_user', ['username' => $username], $e);
    }
  }

  /**
   * Create Service Key (Token).
   *
   * @param string $username
   *   The username of the user.
   * @param string $password
   *   The password of the user.
   *
   * @return string
   *   The user token.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsHelpersException
   *   If the operation fails.
   */
  public function createUserToken(string $username, string $password) {
    try {
      // Open GDB /api-token-auth/ expects form fields username and password.
      $requestParams = [
        'routeParams' => ['username' => $username],
        'body' => [
          'username' => $username,
          'password' => $password,
        ],
      ];
      // Build the request.
      $request = $this->sodaScsOpenGdbServiceActions->buildTokenRequest($requestParams);
      // Make the request.
      $response = $this->sodaScsOpenGdbServiceActions->makeRequest($request);
      // Check if the request was successful.
      if (!$response['success']) {
        $errorMessage = $response['error'] ?? 'Unknown error occurred';
        throw SodaScsHelpersException::opengdbFailed('Failed to create service key token.', 'create_user_token', ['username' => $username], new \Exception($errorMessage));
      }
      return json_decode($response['data']['openGdbResponse']->getBody()->getContents(), TRUE)['token'];
    }
    catch (\Exception $e) {
      throw SodaScsHelpersException::opengdbFailed($e->getMessage(), 'create_user_token', ['username' => $username], $e);
    }
  }

}
