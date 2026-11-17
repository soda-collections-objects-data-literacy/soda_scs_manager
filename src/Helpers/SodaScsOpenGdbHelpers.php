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
   * App settings the SCS Manager sends with every grant update of an account.
   */
  public const USER_APP_SETTINGS = [
    'DEFAULT_INFERENCE' => TRUE,
    'DEFAULT_SAMEAS' => TRUE,
    'DEFAULT_VIS_GRAPH_SCHEMA' => TRUE,
    'EXECUTE_COUNT' => TRUE,
    'IGNORE_SHARED_QUERIES' => FALSE,
  ];

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
   * @param string|null $machineName
   *   The machine name of the repository.
   *
   * @return array
   *   The user.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsRequestException
   *   If the request fails.
   */
  public function createUser(string $username, string $password, ?string $machineName = NULL) {
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
   * Sets an account's grants to read and write on the given repositories.
   *
   * Replaces the whole grant list with ROLE_USER and the repository rights,
   * so it is meant for an account that was just created.
   *
   * @param string $username
   *   The username of the account.
   * @param string[] $machineNames
   *   The repository ids.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsHelpersException
   *   If OpenGDB does not accept the grants.
   */
  public function setUserRepositoryGrants(string $username, array $machineNames): void {
    $authorities = ['ROLE_USER'];
    foreach ($machineNames as $machineName) {
      $authorities[] = "READ_REPO_$machineName";
      $authorities[] = "WRITE_REPO_$machineName";
    }
    try {
      $request = $this->sodaScsOpenGdbServiceActions->buildUpdateRequest([
        'type' => 'user',
        'queryParams' => [],
        'routeParams' => ['username' => $username],
        'body' => [
          'grantedAuthorities' => $authorities,
          'appSettings' => self::USER_APP_SETTINGS,
        ],
      ]);
      $response = $this->sodaScsOpenGdbServiceActions->makeRequest($request);
    }
    catch (\Exception $e) {
      throw SodaScsHelpersException::opengdbFailed($e->getMessage(), 'set_user_repository_grants', ['username' => $username], $e);
    }
    if (empty($response['success'])) {
      throw SodaScsHelpersException::opengdbFailed('OpenGDB did not accept the repository grants.', 'set_user_repository_grants', ['username' => $username], new \Exception($response['error'] ?? 'no details'));
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
