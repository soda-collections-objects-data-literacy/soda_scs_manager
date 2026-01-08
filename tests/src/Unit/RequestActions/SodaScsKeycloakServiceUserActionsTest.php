<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\RequestActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceUserActions;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the SodaScsKeycloakServiceUserActions service.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceUserActions
 */
class SodaScsKeycloakServiceUserActionsTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The service under test.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceUserActions
   */
  protected SodaScsKeycloakServiceUserActions $keycloakServiceUserActions;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $configFactory;

  /**
   * The mocked HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $httpClient;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $entityTypeManager;

  /**
   * The mocked messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $messenger;

  /**
   * The mocked request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $requestStack;

  /**
   * The mocked logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $loggerFactory;

  /**
   * The mocked service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $serviceHelpers;

  /**
   * The mocked string translation.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $stringTranslation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create mock objects.
    $this->configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $this->httpClient = $this->prophesize(ClientInterface::class);
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->messenger = $this->prophesize(MessengerInterface::class);
    $this->requestStack = $this->prophesize(RequestStack::class);
    $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $this->serviceHelpers = $this->prophesize(SodaScsServiceHelpers::class);
    $this->stringTranslation = $this->prophesize(TranslationInterface::class);

    // Setup config mock.
    $config = $this->prophesize(Config::class);
    $this->configFactory->get('soda_scs_manager.settings')->willReturn($config->reveal());

    // Setup logger mock.
    $logger = $this->prophesize(LoggerChannelInterface::class);
    $this->loggerFactory->get('soda_scs_manager')->willReturn($logger->reveal());

    // Setup service helpers mock with default settings.
    $this->setupDefaultKeycloakSettings();

    // Create the service instance.
    $this->keycloakServiceUserActions = new SodaScsKeycloakServiceUserActions(
      $this->configFactory->reveal(),
      $this->httpClient->reveal(),
      $this->entityTypeManager->reveal(),
      $this->messenger->reveal(),
      $this->requestStack->reveal(),
      $this->loggerFactory->reveal(),
      $this->serviceHelpers->reveal(),
      $this->stringTranslation->reveal()
    );
  }

  /**
   * Sets up default Keycloak settings for mocks.
   */
  protected function setupDefaultKeycloakSettings(): void {
    $generalSettings = [
      'host' => 'https://keycloak.example.com',
      'realm' => 'test-realm',
      'adminUsername' => 'admin',
      'adminPassword' => 'admin-password',
      'tokenUrl' => '/realms/master/protocol/openid-connect/token',
    ];

    $usersSettings = [
      'baseUrl' => '/admin/realms/{realm}/users',
      'createUrl' => '',
      'readAllUrl' => '',
      'readAllGroupsUrl' => '/{userId}/groups',
      'readOneUrl' => '/{userId}',
      'updateUrl' => '/{userId}',
      'deleteUrl' => '/{userId}',
      'addUserToGroupUrl' => '/{userId}/groups/{groupId}',
      'removeUserFromGroupUrl' => '/{userId}/groups/{groupId}',
      'healthCheckUrl' => '/health',
    ];

    $this->serviceHelpers->initKeycloakGeneralSettings()->willReturn($generalSettings);
    $this->serviceHelpers->initKeycloakUsersSettings()->willReturn($usersSettings);
  }

  /**
   * Tests buildCreateRequest method.
   *
   * @covers ::buildCreateRequest
   */
  public function testBuildCreateRequest(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'routeParams' => [
        'realm' => 'test-realm',
      ],
      'body' => [
        'username' => 'testuser',
        'email' => 'test@example.com',
        'firstName' => 'Test',
        'lastName' => 'User',
        'enabled' => TRUE,
        'credentials' => [
          [
            'type' => 'password',
            'value' => 'test-password',
            'temporary' => FALSE,
          ],
        ],
      ],
    ];

    $result = $this->keycloakServiceUserActions->buildCreateRequest($requestParams);

    $this->assertTrue($result['success']);
    $this->assertEquals('POST', $result['method']);
    $this->assertStringContainsString('keycloak.example.com', $result['route']);
    $this->assertArrayHasKey('Authorization', $result['headers']);
    $this->assertEquals('Bearer test-access-token', $result['headers']['Authorization']);
    $this->assertNotEmpty($result['body']);
  }

  /**
   * Tests buildGetRequest method.
   *
   * @covers ::buildGetRequest
   */
  public function testBuildGetRequest(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'type' => 'user',
      'routeParams' => [
        'userId' => 'user-uuid-123',
      ],
    ];

    $result = $this->keycloakServiceUserActions->buildGetRequest($requestParams);

    $this->assertTrue($result['success']);
    $this->assertEquals('GET', $result['method']);
    $this->assertStringContainsString('user-uuid-123', $result['route']);
    $this->assertArrayHasKey('Authorization', $result['headers']);
    $this->assertEquals('Bearer test-access-token', $result['headers']['Authorization']);
  }

  /**
   * Tests buildGetAllRequest method for users.
   *
   * @covers ::buildGetAllRequest
   */
  public function testBuildGetAllRequestUsers(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'type' => 'user',
    ];

    $result = $this->keycloakServiceUserActions->buildGetAllRequest($requestParams);

    $this->assertTrue($result['success']);
    $this->assertEquals('GET', $result['method']);
    $this->assertEquals('user', $result['type']);
    $this->assertArrayHasKey('Authorization', $result['headers']);
  }

  /**
   * Tests buildGetAllRequest method for groups.
   *
   * @covers ::buildGetAllRequest
   */
  public function testBuildGetAllRequestGroups(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'type' => 'group',
      'routeParams' => [
        'userId' => 'user-uuid-123',
      ],
    ];

    $result = $this->keycloakServiceUserActions->buildGetAllRequest($requestParams);

    $this->assertTrue($result['success']);
    $this->assertEquals('GET', $result['method']);
    $this->assertEquals('group', $result['type']);
    $this->assertStringContainsString('user-uuid-123', $result['route']);
  }

  /**
   * Tests buildUpdateRequest method for updating user.
   *
   * @covers ::buildUpdateRequest
   */
  public function testBuildUpdateRequestUpdateUser(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'type' => 'updateUser',
      'routeParams' => [
        'userId' => 'user-uuid-123',
      ],
      'body' => [
        'firstName' => 'Updated',
        'lastName' => 'Name',
      ],
    ];

    $result = $this->keycloakServiceUserActions->buildUpdateRequest($requestParams);

    $this->assertTrue($result['success']);
    $this->assertEquals('PUT', $result['method']);
    $this->assertStringContainsString('user-uuid-123', $result['route']);
    $this->assertNotEmpty($result['body']);
  }

  /**
   * Tests buildUpdateRequest method for adding user to group.
   *
   * @covers ::buildUpdateRequest
   */
  public function testBuildUpdateRequestAddUserToGroup(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'type' => 'addUserToGroup',
      'routeParams' => [
        'userId' => 'user-uuid-123',
        'groupId' => 'group-uuid-456',
      ],
    ];

    $result = $this->keycloakServiceUserActions->buildUpdateRequest($requestParams);

    $this->assertTrue($result['success']);
    $this->assertEquals('PUT', $result['method']);
    $this->assertStringContainsString('user-uuid-123', $result['route']);
    $this->assertStringContainsString('group-uuid-456', $result['route']);
  }

  /**
   * Tests buildDeleteRequest method for deleting user.
   *
   * @covers ::buildDeleteRequest
   */
  public function testBuildDeleteRequestUser(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'type' => 'user',
      'routeParams' => [
        'userId' => 'user-uuid-123',
      ],
    ];

    $result = $this->keycloakServiceUserActions->buildDeleteRequest($requestParams);

    $this->assertTrue($result['success']);
    $this->assertEquals('DELETE', $result['method']);
    $this->assertEquals('user', $result['type']);
    $this->assertStringContainsString('user-uuid-123', $result['route']);
    $this->assertArrayHasKey('Authorization', $result['headers']);
  }

  /**
   * Tests buildDeleteRequest method for removing user from group.
   *
   * @covers ::buildDeleteRequest
   */
  public function testBuildDeleteRequestRemoveFromGroup(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'type' => 'removeUserFromGroup',
      'routeParams' => [
        'userId' => 'user-uuid-123',
        'groupId' => 'group-uuid-456',
      ],
    ];

    $result = $this->keycloakServiceUserActions->buildDeleteRequest($requestParams);

    $this->assertTrue($result['success']);
    $this->assertEquals('DELETE', $result['method']);
    $this->assertStringContainsString('user-uuid-123', $result['route']);
    $this->assertStringContainsString('group-uuid-456', $result['route']);
  }

  /**
   * Tests buildTokenRequest method.
   *
   * @covers ::buildTokenRequest
   */
  public function testBuildTokenRequest(): void {
    $result = $this->keycloakServiceUserActions->buildTokenRequest([]);

    $this->assertTrue($result['success']);
    $this->assertEquals('POST', $result['method']);
    $this->assertStringContainsString('token', $result['route']);
    $this->assertEquals('application/x-www-form-urlencoded', $result['headers']['Content-Type']);
    $this->assertArrayHasKey('form_params', $result);
    $this->assertEquals('password', $result['form_params']['grant_type']);
    $this->assertEquals('admin-cli', $result['form_params']['client_id']);
    $this->assertEquals('admin', $result['form_params']['username']);
  }

  /**
   * Tests buildHealthCheckRequest method.
   *
   * @covers ::buildHealthCheckRequest
   */
  public function testBuildHealthCheckRequest(): void {
    $requestParams = [];

    $result = $this->keycloakServiceUserActions->buildHealthCheckRequest($requestParams);

    $this->assertTrue($result['success']);
    $this->assertEquals('GET', $result['method']);
    $this->assertStringContainsString('health', $result['route']);
  }

  /**
   * Tests makeRequest method with successful response.
   *
   * @covers ::makeRequest
   */
  public function testMakeRequestSuccess(): void {
    $mockResponse = new Response(200, [], '{"id": "user-123"}');

    $this->httpClient
      ->request('GET', 'https://keycloak.example.com/test', [
        'headers' => ['Authorization' => 'Bearer token'],
      ])
      ->willReturn($mockResponse);

    $request = [
      'method' => 'GET',
      'route' => 'https://keycloak.example.com/test',
      'headers' => ['Authorization' => 'Bearer token'],
    ];

    $result = $this->keycloakServiceUserActions->makeRequest($request);

    $this->assertTrue($result['success']);
    $this->assertEquals(200, $result['statusCode']);
    $this->assertArrayHasKey('keycloakResponse', $result['data']);
  }

  /**
   * Tests makeRequest method with request body.
   *
   * @covers ::makeRequest
   */
  public function testMakeRequestWithBody(): void {
    $mockResponse = new Response(201, [], '{"id": "new-user-123"}');

    $body = json_encode(['username' => 'testuser']);

    $this->httpClient
      ->request('POST', 'https://keycloak.example.com/users', [
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer token',
        ],
        'body' => $body,
      ])
      ->willReturn($mockResponse);

    $request = [
      'method' => 'POST',
      'route' => 'https://keycloak.example.com/users',
      'headers' => [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer token',
      ],
      'body' => $body,
    ];

    $result = $this->keycloakServiceUserActions->makeRequest($request);

    $this->assertTrue($result['success']);
    $this->assertEquals(201, $result['statusCode']);
  }

  /**
   * Tests query parameters are added to route.
   */
  public function testQueryParametersAddedToRoute(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'type' => 'user',
      'queryParams' => [
        'search' => 'testuser',
        'max' => 10,
      ],
    ];

    $result = $this->keycloakServiceUserActions->buildGetAllRequest($requestParams);

    $this->assertStringContainsString('search=testuser', $result['route']);
    $this->assertStringContainsString('max=10', $result['route']);
  }

  /**
   * Tests route parameters are replaced correctly.
   */
  public function testRouteParametersReplaced(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'type' => 'user',
      'routeParams' => [
        'userId' => 'specific-user-id',
      ],
    ];

    $result = $this->keycloakServiceUserActions->buildGetRequest($requestParams);

    $this->assertStringContainsString('specific-user-id', $result['route']);
    $this->assertStringNotContainsString('{userId}', $result['route']);
  }

  /**
   * Tests buildDeleteRequest with unknown type returns failure.
   */
  public function testBuildDeleteRequestUnknownType(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'type' => 'unknown-type',
      'routeParams' => [
        'userId' => 'user-uuid-123',
      ],
    ];

    $result = $this->keycloakServiceUserActions->buildDeleteRequest($requestParams);

    $this->assertFalse($result['success']);
  }

  /**
   * Tests buildUpdateRequest with unknown type returns failure.
   */
  public function testBuildUpdateRequestUnknownType(): void {
    $requestParams = [
      'token' => 'test-access-token',
      'type' => 'unknown-type',
      'routeParams' => [
        'userId' => 'user-uuid-123',
      ],
    ];

    $result = $this->keycloakServiceUserActions->buildUpdateRequest($requestParams);

    $this->assertFalse($result['success']);
  }

}
