<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsTriplestoreComponentActions;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\Tests\soda_scs_manager\Unit\SodaScsCreatePathTestTrait;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Failure results of SodaScsTriplestoreComponentActions::createComponent().
 *
 * Issue 3637658: every failure returns success FALSE, an error and a
 * translated message; a failed repository request stops the creation.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\ComponentActions\SodaScsTriplestoreComponentActions
 * @group soda_scs_manager
 */
class SodaScsTriplestoreComponentActionsCreateTest extends UnitTestCase {

  use ProphecyTrait;
  use SodaScsCreatePathTestTrait;

  /**
   * The mocked OpenGDB request actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $openGdb;

  /**
   * The mocked Keycloak helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $keycloakHelpers;

  /**
   * The mocked messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $messenger;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $logger;

  /**
   * The component created by the storage.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $component;

  /**
   * The service under test.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsTriplestoreComponentActions
   */
  protected SodaScsTriplestoreComponentActions $actions;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $bundleInfo = $this->prophesize(EntityTypeBundleInfoInterface::class);
    $bundleInfo->getBundleInfo('soda_scs_component')->willReturn([
      'soda_scs_triplestore_component' => [
        'label' => 'Triplestore',
        'description' => 'A triplestore.',
        'imageUrl' => '',
      ],
    ]);

    $owner = $this->prophesize(UserInterface::class);
    $owner->getDisplayName()->willReturn('alice');
    $this->component = $this->prophesize(SodaScsComponentInterface::class);
    $this->component->set(Argument::cetera())->willReturn($this->component->reveal());
    $this->component->label()->willReturn('Foo');
    $this->component->getOwner()->willReturn($owner->reveal());
    $this->component->get('partOfProjects')->willReturn($this->fieldList());
    $this->component->save()->willReturn(1);
    $this->component->get('machineName')->willReturn($this->fieldList('ts-foo'));
    $this->component->delete()->willReturn(NULL);

    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->create(Argument::type('array'))->willReturn($this->component->reveal());
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $entityTypeManager->getStorage('soda_scs_component')->willReturn($storage->reveal());

    $configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $configFactory->getEditable('soda_scs_manager.settings')->willReturn($this->prophesize(Config::class)->reveal());

    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $this->logger = $this->prophesize(LoggerChannelInterface::class);
    $loggerFactory->get('soda_scs_manager')->willReturn($this->logger->reveal());

    $this->messenger = $this->prophesize(MessengerInterface::class);

    $passwordKey = $this->prophesize(SodaScsServiceKeyInterface::class);
    $passwordKey->id()->willReturn('11');
    $tokenKey = $this->prophesize(SodaScsServiceKeyInterface::class);
    $tokenKey->id()->willReturn('12');
    $triplestoreHelpers = $this->prophesize(SodaScsTriplestoreHelpers::class);
    $triplestoreHelpers->ensureUserDataExists(Argument::any())->willReturn([
      'passwordServiceKey' => $passwordKey->reveal(),
      'tokenServiceKey' => $tokenKey->reveal(),
      'grantedAuthorities' => ['ROLE_USER'],
    ]);

    $this->openGdb = $this->prophesize(SodaScsOpenGdbRequestInterface::class);
    $this->openGdb->buildCreateRequest(Argument::type('array'))->willReturn(['request' => 'create']);
    $this->openGdb->buildUpdateRequest(Argument::type('array'))->willReturn(['request' => 'update']);

    $this->keycloakHelpers = $this->prophesize(SodaScsKeycloakHelpers::class);
    $projectHelpers = $this->prophesize(SodaScsProjectHelpers::class);
    $projectHelpers->getUserSsoUuid(Argument::any())->willReturn(NULL);

    $this->actions = new SodaScsTriplestoreComponentActions(
      $configFactory->reveal(),
      $entityTypeManager->reveal(),
      $bundleInfo->reveal(),
      $loggerFactory->reveal(),
      $this->messenger->reveal(),
      $this->openGdb->reveal(),
      $this->prophesize(SodaScsRunRequestInterface::class)->reveal(),
      $this->prophesize(SodaScsServiceKeyActionsInterface::class)->reveal(),
      $this->prophesize(SodaScsOpenGdbHelpers::class)->reveal(),
      $this->prophesize(SodaScsSnapshotHelpers::class)->reveal(),
      $triplestoreHelpers->reveal(),
      $this->keycloakHelpers->reveal(),
      $projectHelpers->reveal(),
    );
    $this->actions->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * A failed repository answer stops the creation.
   *
   * @covers ::createComponent
   *
   * @dataProvider failedRepositoryResponseProvider
   */
  public function testCreateComponentStopsWhenRepositoryRequestIsNotSuccessful(array $response): void {
    $this->openGdb->makeRequest(['request' => 'create'])->willReturn($response);
    $this->openGdb->buildUpdateRequest(Argument::cetera())->shouldNotBeCalled();
    $this->keycloakHelpers->createAndPopulateComponentAccessGroups(Argument::cetera())->shouldNotBeCalled();
    $this->component->save()->shouldNotBeCalled();
    $this->messenger->addError(Argument::cetera())->shouldNotBeCalled();
    // Nothing was created, so nothing is rolled back.
    $this->openGdb->buildDeleteRequest(Argument::cetera())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::cetera())->shouldNotBeCalled();
    $this->component->delete()->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(\RuntimeException::class, $result['error']);
    $this->assertStringContainsString('ts-foo', $result['error']->getMessage());
    $this->assertSame('The triplestore could not be created.', $result['message']->getUntranslatedString());
    $this->assertSame($response, $result['data']['createRepoResponse']);
  }

  /**
   * Failed answers of the OpenGDB request layer.
   *
   * @return array<string, array>
   *   The responses.
   */
  public static function failedRepositoryResponseProvider(): array {
    return [
      'success FALSE with error' => [['success' => FALSE, 'error' => 'HTTP 500', 'message' => 'Request failed.']],
      'success FALSE without details' => [['success' => FALSE]],
      'empty answer' => [[]],
    ];
  }

  /**
   * An exception of the repository request is returned as the error.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentReturnsExceptionOfRepositoryRequest(): void {
    $failure = new \RuntimeException('Connection refused.');
    $this->openGdb->makeRequest(['request' => 'create'])->willThrow($failure);
    $this->component->save()->shouldNotBeCalled();
    $this->openGdb->buildDeleteRequest(Argument::cetera())->shouldNotBeCalled();
    $this->component->delete()->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
    $this->assertNull($result['data']['createRepoResponse']);
  }

  /**
   * A failing rights update is returned, the component is not saved.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentReturnsExceptionOfRightsUpdate(): void {
    $this->openGdb->makeRequest(['request' => 'create'])->willReturn(['success' => TRUE]);
    $failure = new \RuntimeException('Cannot update user.');
    $this->openGdb->makeRequest(['request' => 'update'])->willThrow($failure);
    $this->keycloakHelpers->createAndPopulateComponentAccessGroups(Argument::cetera())->shouldNotBeCalled();
    $this->component->save()->shouldNotBeCalled();
    $this->expectRollback();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
    $this->assertSame('The triplestore access rights could not be set.', $result['message']->getUntranslatedString());
  }

  /**
   * A failing Keycloak step is returned, the component is not saved.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentReturnsExceptionOfKeycloakGroups(): void {
    $this->openGdb->makeRequest(['request' => 'create'])->willReturn(['success' => TRUE]);
    $this->openGdb->makeRequest(['request' => 'update'])->willReturn(['success' => TRUE]);
    $failure = new \RuntimeException('Keycloak group request failed.');
    $this->keycloakHelpers->createAndPopulateComponentAccessGroups('ts-foo', NULL, [])->willThrow($failure);
    $this->component->save()->shouldNotBeCalled();
    $this->expectRollback();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
    $this->assertSame('The access groups for the triplestore could not be created.', $result['message']->getUntranslatedString());
  }

  /**
   * A failing save deletes repository, rights and groups again.
   *
   * @covers ::createComponent
   * @covers ::rollBackCreateComponent
   */
  public function testCreateComponentRollsBackWhenSavingTheComponentFails(): void {
    $this->openGdb->makeRequest(['request' => 'create'])->willReturn(['success' => TRUE]);
    $this->openGdb->makeRequest(['request' => 'update'])->willReturn(['success' => TRUE]);
    $this->keycloakHelpers->createAndPopulateComponentAccessGroups('ts-foo', NULL, [])->willReturn([]);
    $failure = new \RuntimeException('Storage failed.');
    $this->component->save()->willThrow($failure);
    $this->expectRollback();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
    $this->assertSame('The triplestore could not be created.', $result['message']->getUntranslatedString());
    $this->assertSame(['success' => TRUE], $result['data']['createRepoResponse']);
  }

  /**
   * A failed rollback is logged and the original failure is returned.
   *
   * @covers ::createComponent
   * @covers ::rollBackCreateComponent
   */
  public function testCreateComponentReturnsOriginalFailureWhenRollbackFails(): void {
    $this->openGdb->makeRequest(['request' => 'create'])->willReturn(['success' => TRUE]);
    $this->openGdb->makeRequest(['request' => 'update'])->willReturn(['success' => TRUE]);
    $failure = new \RuntimeException('Keycloak group request failed.');
    $this->keycloakHelpers->createAndPopulateComponentAccessGroups(Argument::cetera())->willThrow($failure);
    $this->openGdb->buildDeleteRequest(Argument::cetera())->willReturn(['request' => 'delete-repo']);
    $this->openGdb->makeRequest(['request' => 'delete-repo'])
      ->willReturn([
        'success' => FALSE,
        'error' => 'HTTP 500',
        'data' => ['openGdbResponse' => new \RuntimeException('Server error.', 500)],
      ]);
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
    $this->component->delete()->shouldNotBeCalled();
    // The creation failure and the failed delete are logged through log().
    $this->logger->log('error', Argument::type('string'), Argument::type('array'))->shouldBeCalledTimes(2);
    $this->logger->error(Argument::containingString('Could not remove the repository'), Argument::withEntry('@machineName', 'ts-foo'))->shouldBeCalledOnce();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
  }

  /**
   * A repository that cannot be deleted keeps the component and the groups.
   *
   * @covers ::deleteComponent
   *
   * @dataProvider failedRepositoryDeleteProvider
   */
  public function testDeleteComponentKeepsComponentWhenRepositoryDeleteFails(array $response): void {
    $this->openGdb->buildDeleteRequest(Argument::cetera())->willReturn(['request' => 'delete-repo']);
    $this->openGdb->makeRequest(['request' => 'delete-repo'])->willReturn($response);
    $this->openGdb->buildGetRequest(Argument::cetera())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
    $component = $this->deletableComponent();
    $component->delete()->shouldNotBeCalled();
    $this->messenger->addError(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('Repository ts-foo could not be deleted', $result['error']);
  }

  /**
   * Repository delete answers that are no success and no 404.
   *
   * @return array<string, array>
   *   The answers of the OpenGDB request layer.
   */
  public static function failedRepositoryDeleteProvider(): array {
    return [
      'server error' => [
        [
          'success' => FALSE,
          'error' => 'HTTP 500',
          'data' => ['openGdbResponse' => new \RuntimeException('Server error.', 500)],
        ],
      ],
      'forbidden' => [
        [
          'success' => FALSE,
          'error' => 'HTTP 403',
          'data' => ['openGdbResponse' => new \RuntimeException('Forbidden.', 403)],
        ],
      ],
      'no exception in the answer' => [['success' => FALSE, 'error' => 'Connection refused.']],
      'no details at all' => [['success' => FALSE]],
    ];
  }

  /**
   * A repository delete that throws keeps the component.
   *
   * @covers ::deleteComponent
   */
  public function testDeleteComponentKeepsComponentWhenRepositoryDeleteThrows(): void {
    $this->openGdb->buildDeleteRequest(Argument::cetera())->willReturn(['request' => 'delete-repo']);
    $this->openGdb->makeRequest(['request' => 'delete-repo'])->willThrow(new \RuntimeException('Connection refused.'));
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
    $component = $this->deletableComponent();
    $component->delete()->shouldNotBeCalled();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame('Connection refused.', $result['error']);
  }

  /**
   * A repository that is already gone (404) does not stop the delete.
   *
   * @covers ::deleteComponent
   */
  public function testDeleteComponentGoesOnWhenRepositoryIsAlreadyGone(): void {
    $this->stubUserOfDeletedComponent();
    $this->openGdb->buildDeleteRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'repository'))->willReturn(['request' => 'delete-repo']);
    $this->openGdb->makeRequest(['request' => 'delete-repo'])
      ->willReturn([
        'success' => FALSE,
        'error' => 'HTTP 404',
        'data' => ['openGdbResponse' => new \RuntimeException('Not found.', 404)],
      ]);
    $this->messenger->addError(Argument::cetera())->willReturn($this->messenger->reveal())->shouldBeCalledOnce();
    $this->keycloakHelpers->deleteComponentAccessGroups('ts-foo')->willReturn([])->shouldBeCalledOnce();
    $component = $this->deletableComponent();
    $component->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertTrue($result['success']);
  }

  /**
   * The grants of the deleted repository leave the list; the rest is a list.
   *
   * Issue 3638647: array_filter() left gaps in the keys, which made OpenGDB
   * receive a JSON object instead of a list.
   *
   * @covers ::deleteComponent
   */
  public function testDeleteComponentSendsRemainingGrantsAsJsonList(): void {
    $this->stubRepositoryDeleted();
    $this->stubUserGrants(['ROLE_USER', 'READ_REPO_ts-foo', 'WRITE_REPO_ts-foo', 'READ_REPO_ts-b', 'WRITE_REPO_ts-b']);
    $sent = $this->captureUserUpdate(['success' => TRUE, 'statusCode' => 200]);
    $this->openGdb->buildDeleteRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups('ts-foo')->willReturn([])->shouldBeCalledOnce();
    $component = $this->deletableComponent();
    $component->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertTrue($result['success']);
    $this->assertSame('alice', $sent['params']['routeParams']['username']);
    $this->assertSame('user', $sent['params']['type']);
    $authorities = $sent['params']['body']['grantedAuthorities'];
    $this->assertSame([0, 1, 2], array_keys($authorities));
    $this->assertSame('["ROLE_USER","READ_REPO_ts-b","WRITE_REPO_ts-b"]', json_encode($authorities));
  }

  /**
   * The last repository removed leaves ROLE_USER; the user is never deleted.
   *
   * @covers ::deleteComponent
   */
  public function testDeleteComponentKeepsUserWithRoleUserWhenLastRepositoryIsRemoved(): void {
    $this->stubRepositoryDeleted();
    $this->stubUserGrants(['ROLE_USER', 'READ_REPO_ts-foo', 'WRITE_REPO_ts-foo']);
    $sent = $this->captureUserUpdate(['success' => TRUE, 'statusCode' => 200]);
    $this->openGdb->buildDeleteRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups('ts-foo')->willReturn([])->shouldBeCalledOnce();
    $component = $this->deletableComponent();
    $component->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertTrue($result['success']);
    $this->assertSame('["ROLE_USER"]', json_encode($sent['params']['body']['grantedAuthorities']));
    $this->assertNull($result['data']['openGdbDeleteUserResponse']);
  }

  /**
   * A user without a grant of this repository is not updated.
   *
   * @covers ::deleteComponent
   */
  public function testDeleteComponentSendsNoUpdateWhenUserHasNoGrantOfRepository(): void {
    $this->stubRepositoryDeleted();
    $this->stubUserGrants(['ROLE_USER', 'READ_REPO_ts-b', 'WRITE_REPO_ts-b']);
    $this->openGdb->buildUpdateRequest(Argument::cetera())->shouldNotBeCalled();
    $this->openGdb->buildDeleteRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups('ts-foo')->willReturn([])->shouldBeCalledOnce();
    $component = $this->deletableComponent();
    $component->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertTrue($result['success']);
    $this->assertNull($result['data']['openGdbUpdateUserResponse']);
  }

  /**
   * A grant update that OpenGDB refuses keeps the component and the groups.
   *
   * @covers ::deleteComponent
   */
  public function testDeleteComponentKeepsComponentWhenGrantUpdateIsRefused(): void {
    $this->stubRepositoryDeleted();
    $this->stubUserGrants(['ROLE_USER', 'READ_REPO_ts-foo', 'WRITE_REPO_ts-foo']);
    $this->captureUserUpdate([
      'success' => FALSE,
      'error' => 'Server error.',
      'statusCode' => 500,
    ]);
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
    $component = $this->deletableComponent();
    $component->delete()->shouldNotBeCalled();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('OpenGDB answered HTTP 500: Server error.', $result['error']);
    $this->assertFalse($result['data']['openGdbUpdateUserResponse']['success']);
  }

  /**
   * A grant update that throws keeps the component and the groups.
   *
   * @covers ::deleteComponent
   */
  public function testDeleteComponentKeepsComponentWhenGrantUpdateThrows(): void {
    $this->stubRepositoryDeleted();
    $this->stubUserGrants(['ROLE_USER', 'READ_REPO_ts-foo']);
    $this->openGdb->buildUpdateRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))->willReturn(['request' => 'update-user']);
    $this->openGdb->makeRequest(['request' => 'update-user'])->willThrow(new \RuntimeException('Connection refused.'));
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
    $component = $this->deletableComponent();
    $component->delete()->shouldNotBeCalled();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame('Connection refused.', $result['error']);
  }

  /**
   * A missing user has no grants to remove; the delete goes on.
   *
   * The user-404 answer of the request layer carries no statusCode, only the
   * exception's code.
   *
   * @covers ::deleteComponent
   * @covers ::getOpenGdbStatusCode
   */
  public function testDeleteComponentContinuesWhenUserIsMissing(): void {
    $this->stubRepositoryDeleted();
    $this->openGdb->buildGetRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))->willReturn(['request' => 'get-user']);
    $this->openGdb->makeRequest(['request' => 'get-user'])->willReturn([
      'success' => FALSE,
      'error' => 'Client error: 404 Not Found',
      'data' => ['openGdbResponse' => new \RuntimeException('Not found.', 404)],
    ]);
    $this->openGdb->buildUpdateRequest(Argument::cetera())->shouldNotBeCalled();
    $this->openGdb->buildDeleteRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))->shouldNotBeCalled();
    $this->logger->warning(Argument::containingString('does not exist; no grants to remove'), Argument::withEntry('@component', 'ts-foo'))->shouldBeCalledOnce();
    $this->logger->error(Argument::containingString('Could not get OpenGDB user'), Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups('ts-foo')->willReturn([])->shouldBeCalledOnce();
    $component = $this->deletableComponent();
    $component->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertTrue($result['success']);
    $this->assertNull($result['data']['openGdbUpdateUserResponse']);
  }

  /**
   * A failed user lookup stops before any grant is touched.
   *
   * @covers ::deleteComponent
   * @covers ::getOpenGdbStatusCode
   *
   * @dataProvider failedUserLookupProvider
   */
  public function testDeleteComponentKeepsComponentWhenUserLookupFails(array $response, int $expectedStatus): void {
    $this->stubRepositoryDeleted();
    $this->openGdb->buildGetRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))->willReturn(['request' => 'get-user']);
    $this->openGdb->makeRequest(['request' => 'get-user'])->willReturn($response);
    $this->openGdb->buildUpdateRequest(Argument::cetera())->shouldNotBeCalled();
    $this->openGdb->buildDeleteRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
    $component = $this->deletableComponent();
    $component->delete()->shouldNotBeCalled();
    $this->logger->error(
      Argument::containingString('Could not get OpenGDB user'),
      Argument::withEntry('@status', $expectedStatus)
    )->shouldBeCalledOnce();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame($response['error'], $result['error']);
    $this->assertNull($result['data']['openGdbUpdateUserResponse']);
    $this->assertSame($response, $result['data']['openGdbGetUserResponse']);
  }

  /**
   * Failed user lookups other than a missing user: server error, no status.
   *
   * @return array<string, array>
   *   The answers of the OpenGDB request layer and the logged status.
   */
  public static function failedUserLookupProvider(): array {
    return [
      'server error with status code' => [
        [
          'success' => FALSE,
          'error' => 'HTTP 500',
          'statusCode' => 500,
          'data' => ['openGdbResponse' => new ServerException('Server error.', new Request('GET', 'user'), new Response(500))],
        ],
        500,
      ],
      'status only on the exception' => [
        [
          'success' => FALSE,
          'error' => 'HTTP 502',
          'data' => ['openGdbResponse' => new \RuntimeException('Bad gateway.', 502)],
        ],
        502,
      ],
      'no exception and no details' => [
        ['success' => FALSE, 'error' => 'Connection refused.'],
        0,
      ],
    ];
  }

  /**
   * A user answer without a usable grant list keeps the component.
   *
   * @covers ::deleteComponent
   *
   * @dataProvider malformedUserBodyProvider
   */
  public function testDeleteComponentKeepsComponentWhenUserHasNoGrantList(string $body): void {
    $this->stubRepositoryDeleted();
    $this->openGdb->buildGetRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))->willReturn(['request' => 'get-user']);
    $this->openGdb->makeRequest(['request' => 'get-user'])->willReturn([
      'success' => TRUE,
      'data' => ['openGdbResponse' => new Response(200, [], $body)],
    ]);
    $this->openGdb->buildUpdateRequest(Argument::cetera())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
    $component = $this->deletableComponent();
    $component->delete()->shouldNotBeCalled();
    $this->logger->error(Argument::containingString('no readable grant list'), Argument::any())->shouldBeCalledOnce();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame('No grant list in the OpenGDB user response.', $result['error']);
  }

  /**
   * User bodies without a grantedAuthorities array.
   *
   * @return array<string, array>
   *   The raw response bodies.
   */
  public static function malformedUserBodyProvider(): array {
    return [
      'key missing' => ['{"username":"alice"}'],
      'key is a string' => ['{"grantedAuthorities":"ROLE_USER"}'],
      'key is null' => ['{"grantedAuthorities":null}'],
      'empty body' => [''],
      'no JSON' => ['<html>Bad gateway</html>'],
    ];
  }

  /**
   * The main path still saves the component and reports success.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentSavesComponentWhenAllStepsSucceed(): void {
    $this->openGdb->makeRequest(['request' => 'create'])->willReturn(['success' => TRUE]);
    $this->openGdb->makeRequest(['request' => 'update'])->willReturn(['success' => TRUE]);
    $this->keycloakHelpers->createAndPopulateComponentAccessGroups('ts-foo', NULL, [])->shouldBeCalledOnce();
    $this->component->save()->shouldBeCalledOnce();

    $result = $this->actions->createComponent($this->entity());

    $this->assertTrue($result['success']);
    $this->assertNull($result['error']);
    $this->assertSame($this->component->reveal(), $result['data']['triplestoreComponent']);
  }

  /**
   * Expects that a failed creation deletes everything it created, once.
   */
  protected function expectRollback(): void {
    $this->stubUserOfDeletedComponent();
    $this->openGdb->buildDeleteRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'repository'))->willReturn(['request' => 'delete-repo']);
    $this->openGdb->makeRequest(['request' => 'delete-repo'])->willReturn(['success' => TRUE])->shouldBeCalledOnce();
    $this->keycloakHelpers->deleteComponentAccessGroups('ts-foo')->willReturn([])->shouldBeCalledOnce();
    $this->component->delete()->shouldBeCalledOnce();
  }

  /**
   * Lets the OpenGDB user of the owner hold the rights of the repository.
   *
   * The restoring update is told from the granting one by the rights it
   * carries: the granting one has READ_REPO_ts-foo, the restoring one not.
   */
  protected function stubUserOfDeletedComponent(): void {
    $this->openGdb->buildGetRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))->willReturn(['request' => 'get-user']);
    $userBody = json_encode([
      'grantedAuthorities' => ['ROLE_USER', 'READ_REPO_ts-foo', 'WRITE_REPO_ts-foo', 'READ_REPO_other'],
    ]);
    $this->openGdb->makeRequest(['request' => 'get-user'])->willReturn([
      'success' => TRUE,
      'data' => ['openGdbResponse' => new Response(200, [], $userBody)],
    ]);
    $this->openGdb->buildUpdateRequest(Argument::that(
      fn(array $params) => !in_array('READ_REPO_ts-foo', $params['body']['grantedAuthorities'] ?? [], TRUE)
    ))->willReturn(['request' => 'restore-rights']);
    $this->openGdb->makeRequest(['request' => 'restore-rights'])->willReturn(['success' => TRUE]);
  }

  /**
   * Lets the repository delete succeed.
   */
  protected function stubRepositoryDeleted(): void {
    $this->openGdb->buildDeleteRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'repository'))->willReturn(['request' => 'delete-repo']);
    $this->openGdb->makeRequest(['request' => 'delete-repo'])->willReturn(['success' => TRUE]);
  }

  /**
   * Lets OpenGDB answer the user lookup with the given grants.
   *
   * @param string[] $grants
   *   The grantedAuthorities of the user.
   */
  protected function stubUserGrants(array $grants): void {
    $this->openGdb->buildGetRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))->willReturn(['request' => 'get-user']);
    $this->openGdb->makeRequest(['request' => 'get-user'])->willReturn([
      'success' => TRUE,
      'data' => ['openGdbResponse' => new Response(200, [], json_encode(['grantedAuthorities' => $grants]))],
    ]);
  }

  /**
   * Answers the user update and records the request params it was built from.
   *
   * @param array $response
   *   The answer of the update request.
   *
   * @return \ArrayObject
   *   The holder whose 'params' entry is set when the update is built.
   */
  protected function captureUserUpdate(array $response): \ArrayObject {
    $sent = new \ArrayObject(['params' => NULL]);
    $this->openGdb->buildUpdateRequest(Argument::that(fn(array $params) => ($params['type'] ?? '') === 'user'))
      ->will(function (array $args) use ($sent) {
        $sent['params'] = $args[0];
        return ['request' => 'update-user'];
      })
      ->shouldBeCalledOnce();
    $this->openGdb->makeRequest(['request' => 'update-user'])->willReturn($response);
    return $sent;
  }

  /**
   * A component double for deleteComponent().
   *
   * @return \Prophecy\Prophecy\ObjectProphecy
   *   The component prophecy.
   */
  protected function deletableComponent() {
    $owner = $this->prophesize(UserInterface::class);
    $owner->getDisplayName()->willReturn('alice');
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->getOwner()->willReturn($owner->reveal());
    $component->get('machineName')->willReturn($this->fieldList('ts-foo'));
    return $component;
  }

  /**
   * Builds the component the stack hands to createComponent().
   */
  protected function entity(): SodaScsComponentInterface {
    $entity = $this->prophesize(SodaScsComponentInterface::class);
    $entity->hasField('publicRead')->willReturn(FALSE);
    $entity->get('label')->willReturn($this->fieldList('Foo'));
    $entity->get('machineName')->willReturn($this->fieldList('foo'));
    $entity->get('partOfProjects')->willReturn($this->fieldList());
    $entity->getOwnerId()->willReturn(7);
    return $entity->reveal();
  }

}
