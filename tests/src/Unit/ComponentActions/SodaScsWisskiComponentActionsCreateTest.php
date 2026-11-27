<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions;
use Drupal\soda_scs_manager\Entity\SodaScsComponent;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Exception\SodaScsUserFacingException;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsPortainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWisskiVolumeHelpers;
use Drupal\soda_scs_manager\Service\SodaScsWisskiVarnishCache;
use Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\Service\NextcloudMountManager;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\soda_scs_manager\Unit\SodaScsCreatePathTestTrait;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

require_once __DIR__ . '/SodaScsSleepStub.php';

/**
 * Failure results of SodaScsWisskiComponentActions::createComponent().
 *
 * Issue 3637658: every failure returns success FALSE, an error and a
 * translated message; the Portainer failures no longer read the result of the
 * request that threw or failed. A Drupal container that is not found right
 * after the stack was created does not fail the creation: the container ID
 * stays empty. Issue 3637662: a WissKI without the default data model gets an
 * empty recipe version.
 *
 * Issue 3638552: a Keycloak client that createComponent() created is deleted
 * with its groups when a later step fails; a client that already existed
 * (409) is left alone. deleteKeycloakResources() treats a missing client or
 * group as deleted and stops at the first failed request.
 *
 * The wait for the container runs against a sleep() stand-in
 * (SodaScsSleepStub.php), so the tests do not wait.
 */
#[CoversMethod(SodaScsWisskiComponentActions::class, 'createComponent')]
#[CoversMethod(SodaScsWisskiComponentActions::class, 'waitForNewContainerId')]
#[CoversMethod(SodaScsWisskiComponentActions::class, 'resolveWisskiVersionSettings')]
#[CoversMethod(SodaScsWisskiComponentActions::class, 'usesWisskiDefaultDataModel')]
#[CoversMethod(SodaScsWisskiComponentActions::class, 'createSnapshot')]
#[CoversMethod(SodaScsWisskiComponentActions::class, 'refreshComponentContainerId')]
#[CoversMethod(SodaScsWisskiComponentActions::class, 'restoreFromSnapshot')]
#[CoversMethod(SodaScsWisskiComponentActions::class, 'deleteKeycloakResources')]
#[CoversMethod(SodaScsWisskiComponentActions::class, 'deleteComponent')]
#[Group('soda_scs_manager')]
class SodaScsWisskiComponentActionsCreateTest extends UnitTestCase {

  use ProphecyTrait;
  use SodaScsCreatePathTestTrait;

  /**
   * The mocked bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $bundleInfo;

  /**
   * The mocked messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $messenger;

  /**
   * The mocked Nextcloud helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $nextcloudHelpers;

  /**
   * The mocked Nextcloud mount manager.
   *
   * @var \Drupal\soda_scs_manager\Service\NextcloudMountManager|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $mountManager;

  /**
   * The mocked Portainer request actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $portainer;

  /**
   * The mocked Docker run request actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $dockerRun;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $logger;

  /**
   * The mocked container helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $containerHelpers;

  /**
   * The mocked service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $serviceHelpers;

  /**
   * The mocked service key actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $serviceKeyActions;

  /**
   * The mocked Keycloak token helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $keycloakHelpers;

  /**
   * The mocked Keycloak client request actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $keycloakClient;

  /**
   * The mocked Keycloak group request actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $keycloakGroups;

  /**
   * The mocked Portainer helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsPortainerHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $portainerHelpers;

  /**
   * The mocked WissKI volume helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsWisskiVolumeHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $wisskiVolumeHelpers;

  /**
   * The mocked Varnish cache service.
   *
   * @var \Drupal\soda_scs_manager\Service\SodaScsWisskiVarnishCache|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $varnishCache;

  /**
   * The service under test.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions
   */
  protected SodaScsWisskiComponentActions $actions;

  /**
   * The mocked file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    SodaScsSleepStub::$seconds = [];

    $this->bundleInfo = $this->prophesize(EntityTypeBundleInfoInterface::class);
    $this->bundleInfo->getBundleInfo('soda_scs_component')->willReturn([
      'soda_scs_wisski_component' => [
        'label' => 'WissKI',
        'description' => 'A WissKI.',
        'imageUrl' => '',
      ],
    ]);

    $configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $configFactory->getEditable('soda_scs_manager.settings')->willReturn($this->prophesize(Config::class)->reveal());

    $this->logger = $this->prophesize(LoggerChannelInterface::class);
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('soda_scs_manager')->willReturn($this->logger->reveal());

    $this->messenger = $this->prophesize(MessengerInterface::class);

    $componentHelpers = $this->prophesize(SodaScsComponentHelpers::class);
    $componentHelpers->createSecret()->willReturn('not-a-secret');
    $componentHelpers->resolveConnectedComponents(Argument::any())->willReturn(['sql' => NULL, 'triplestore' => NULL]);

    $this->serviceHelpers = $this->prophesize(SodaScsServiceHelpers::class);
    $this->serviceHelpers->initWisskiInstanceSettings()->willReturn([]);
    $this->serviceHelpers->wisskiInstanceBaseUrl(Argument::type('string'))->willReturn('https://wisski.invalid');
    $this->containerHelpers = $this->prophesize(SodaScsContainerHelpers::class);

    $this->keycloakHelpers = $this->prophesize(SodaScsKeycloakHelpers::class);
    $this->keycloakHelpers->getKeycloakToken()->willReturn('not-a-token');

    $projectHelpers = $this->prophesize(SodaScsProjectHelpers::class);
    $projectHelpers->getUserSsoUuid(Argument::any())->willReturn(NULL);

    $this->nextcloudHelpers = $this->prophesize(SodaScsNextcloudHelpers::class);
    $this->nextcloudHelpers->isBearerEnabled()->willReturn(FALSE);
    $this->nextcloudHelpers->ensureCredentials(Argument::cetera())
      ->willReturn(['username' => 'alice', 'appPassword' => 'not-a-password']);

    $this->mountManager = $this->prophesize(NextcloudMountManager::class);
    $this->mountManager->disabledMountSource()->willReturn('');
    $this->mountManager->ensureUserMountDirectory(Argument::any())->will(function () {});
    $this->mountManager->ensureProjectTeamFolderReady(Argument::cetera())->willReturn(TRUE);
    $this->mountManager->projectTeamFolderMountSource(Argument::cetera())->willReturn('/mnt/team');

    $this->keycloakClient = $this->prophesize(SodaScsServiceRequestInterface::class);
    $this->keycloakClient->buildCreateRequest(Argument::any())->willReturn([]);
    $this->keycloakClient->makeRequest(Argument::any())->willReturn(['success' => TRUE]);

    $this->keycloakGroups = $this->prophesize(SodaScsServiceRequestInterface::class);
    $this->keycloakGroups->buildCreateRequest(Argument::any())->willReturn([]);
    $this->keycloakGroups->buildGetAllRequest(Argument::any())->willReturn([]);
    $this->keycloakGroups->makeRequest(Argument::any())->will(fn() => [
      'success' => TRUE,
      'data' => [
        'keycloakResponse' => new Response(200, [], json_encode([
          ['name' => 'wisski-foo-admin', 'id' => 'group-admin'],
          ['name' => 'wisski-foo-user', 'id' => 'group-user'],
        ])),
      ],
    ]);

    // A failed create deletes the Keycloak client: by default none is left.
    $this->stubKeycloakResources(clients: []);

    $this->portainerHelpers = $this->prophesize(SodaScsPortainerHelpers::class);
    $this->portainerHelpers->removeVolumesOfComposeStack(Argument::any())->willReturn(SodaScsResult::success(data: [], message: 'Volumes removed.'));

    $this->portainer = $this->prophesize(SodaScsServiceRequestInterface::class);
    $this->portainer->buildCreateRequest(Argument::type('array'))->willReturn(['request' => 'portainer']);

    $this->dockerRun = $this->prophesize(SodaScsRunRequestInterface::class);
    $this->dockerRun->buildGetAllRequest(Argument::type('array'))->willReturn(['request' => 'docker']);

    $serviceKey = $this->prophesize(SodaScsServiceKeyInterface::class);
    $serviceKey->get('servicePassword')->willReturn($this->fieldList('not-a-password'));
    $serviceKey->save()->shouldNotBeCalled();
    $this->serviceKeyActions = $this->prophesize(SodaScsServiceKeyActionsInterface::class);
    $this->serviceKeyActions->getServiceKey(Argument::type('array'))->willReturn($serviceKey->reveal());

    $this->wisskiVolumeHelpers = $this->prophesize(SodaScsWisskiVolumeHelpers::class);
    $this->varnishCache = $this->prophesize(SodaScsWisskiVarnishCache::class);
    $this->fileSystem = $this->prophesize(FileSystemInterface::class);

    $this->actions = new SodaScsWisskiComponentActions(
      $this->bundleInfo->reveal(),
      $configFactory->reveal(),
      $this->prophesize(Connection::class)->reveal(),
      $this->prophesize(EntityTypeManagerInterface::class)->reveal(),
      $this->prophesize(ClientInterface::class)->reveal(),
      $loggerFactory->reveal(),
      $this->messenger->reveal(),
      $this->fileSystem->reveal(),
      $componentHelpers->reveal(),
      $this->containerHelpers->reveal(),
      $this->prophesize(SodaScsHelpers::class)->reveal(),
      $this->prophesize(SodaScsExecRequestInterface::class)->reveal(),
      $this->dockerRun->reveal(),
      $this->keycloakHelpers->reveal(),
      $this->nextcloudHelpers->reveal(),
      $this->mountManager->reveal(),
      $this->keycloakClient->reveal(),
      $this->keycloakGroups->reveal(),
      $this->prophesize(SodaScsServiceRequestInterface::class)->reveal(),
      $this->portainerHelpers->reveal(),
      $this->portainer->reveal(),
      $projectHelpers->reveal(),
      $this->serviceHelpers->reveal(),
      $this->serviceKeyActions->reveal(),
      $this->prophesize(SodaScsSnapshotHelpers::class)->reveal(),
      $this->prophesize(SodaScsServiceActionsInterface::class)->reveal(),
      $this->prophesize(SodaScsStackHelpers::class)->reveal(),
      $this->getStringTranslationStub(),
      $this->wisskiVolumeHelpers->reveal(),
      $this->varnishCache->reveal(),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * A missing bundle info is returned as a failure with a message.
   */
  public function testCreateComponentFailsWhenBundleInfoIsMissing(): void {
    $this->bundleInfo->getBundleInfo('soda_scs_component')->willReturn([]);
    $this->logger->log('error', Argument::type('string'), Argument::type('array'))->shouldBeCalledOnce();
    $this->messenger->addError(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->component());

    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(\Exception::class, $result['error']);
    $this->assertSame('The WissKI instance could not be prepared.', $result['message']->getUntranslatedString());
  }

  /**
   * An unconnected Nextcloud account is a user-facing failure with a link.
   */
  public function testCreateComponentReturnsUserFacingReasonWhenNextcloudIsNotConnected(): void {
    $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
    $urlGenerator->generateFromRoute('openid_connect.accounts_controller_index', ['user' => 7], Argument::cetera())
      ->willReturn('/user/7/connected-accounts');
    $container = new ContainerBuilder();
    $container->set('url_generator', $urlGenerator->reveal());
    \Drupal::setContainer($container);
    $this->nextcloudHelpers->ensureCredentials(Argument::cetera())->willReturn(NULL);
    $this->portainer->makeRequest(Argument::cetera())->shouldNotBeCalled();
    $this->messenger->addError(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->component());

    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(SodaScsUserFacingException::class, $result['error']);
    $this->assertSame($result['error']->getUserMessage(), $result['message']);
    $this->assertStringContainsString('/user/7/connected-accounts', (string) $result['message']);
    $this->assertStringNotContainsString('Nextcloud account of user', (string) $result['message']);
  }

  /**
   * A Team Folder that is not ready is a user-facing failure.
   */
  public function testCreateComponentReturnsUserFacingReasonWhenTeamFolderIsNotReady(): void {
    $this->mountManager->ensureProjectTeamFolderReady(Argument::cetera())->willReturn(FALSE);
    $this->portainer->makeRequest(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->component());

    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(SodaScsUserFacingException::class, $result['error']);
    $this->assertSame($result['error']->getUserMessage(), $result['message']);
    $this->assertStringContainsString('Project One', (string) $result['message']);
  }

  /**
   * An exception of the Portainer request is returned as the error.
   */
  public function testCreateComponentReturnsExceptionOfPortainerRequest(): void {
    $failure = new \RuntimeException('Connection refused.');
    $this->portainer->makeRequest(['request' => 'portainer'])->willThrow($failure);
    $this->dockerRun->makeRequest(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->component());

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
    $this->assertSame('The WissKI container could not be created.', $result['message']->getUntranslatedString());
    $this->assertNull($result['data']['portainerCreateRequestResult']);
  }

  /**
   * A Portainer error answer without details still has a non-empty error.
   */
  public function testCreateComponentFailsWhenPortainerAnswersWithoutDetails(): void {
    $this->portainer->makeRequest(['request' => 'portainer'])->willReturn(['success' => FALSE]);
    $this->dockerRun->makeRequest(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->component());

    $this->assertCompleteFailure($result);
    $this->assertSame('Portainer returned status 0.', $result['error']);
  }

  /**
   * A Portainer error answer keeps its error text and status code.
   */
  public function testCreateComponentReturnsErrorTextAndStatusOfPortainerAnswer(): void {
    $this->portainer->makeRequest(['request' => 'portainer'])
      ->willReturn(['success' => FALSE, 'error' => 'Conflict', 'statusCode' => 409]);
    $this->dockerRun->makeRequest(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->component());

    $this->assertCompleteFailure($result);
    $this->assertSame('Conflict', $result['error']);
    $this->assertSame(409, $result['statusCode']);
  }

  /**
   * The container ID of the new stack is stored when the first lookup finds it.
   */
  public function testCreateComponentStoresContainerIdFoundOnFirstLookup(): void {
    $this->portainerCreatesStack();
    $this->containerHelpers->findLiveContainerByName('wisski-foo--drupal')
      ->willReturn($this->containerFound('abc123'))
      ->shouldBeCalledOnce();
    $this->logger->warning(Argument::cetera())->shouldNotBeCalled();
    $component = $this->component();

    $result = $this->actions->createComponent($component);

    $this->assertTrue($result['success']);
    $this->assertNull($result['error']);
    $this->assertSame('abc123', $component->setValues['containerId']);
    $this->assertSame('wisski-foo--drupal', $component->setValues['containerName']);
    $this->assertSame(42, $component->setValues['externalId']);
    $this->assertSame([], SodaScsSleepStub::$seconds, 'No wait when the container is there.');
  }

  /**
   * A container that shows up on a later lookup is found after a poll wait.
   */
  public function testCreateComponentWaitsUntilContainerAppears(): void {
    $this->portainerCreatesStack();
    $this->containerHelpers->findLiveContainerByName('wisski-foo--drupal')->willReturn(
      $this->containerMissing(),
      $this->containerMissing(),
      $this->containerFound('abc123'),
    );
    $this->logger->warning(Argument::cetera())->shouldNotBeCalled();
    $component = $this->component();

    $result = $this->actions->createComponent($component);

    $this->assertTrue($result['success']);
    $this->assertSame('abc123', $component->setValues['containerId']);
    $this->assertSame([5, 5], SodaScsSleepStub::$seconds);
  }

  /**
   * A container that never shows up does not fail the creation.
   *
   * The ID stays NULL (inspectComponentContainer() resolves it by name later),
   * the stack and the component are kept and a warning is logged.
   */
  public function testCreateComponentSucceedsWithEmptyContainerIdWhenContainerNeverAppears(): void {
    $this->portainerCreatesStack();
    $this->containerHelpers->findLiveContainerByName('wisski-foo--drupal')
      ->willReturn($this->containerMissing())
      ->shouldBeCalledTimes(6);
    $this->logger->warning(
      Argument::containingString('not found after'),
      Argument::that(fn(array $context) => $context['@containerName'] === 'wisski-foo--drupal'
        && $context['@seconds'] === 30
        && $context['@message'] === 'Container not found for name: wisski-foo--drupal')
    )->shouldBeCalledOnce();
    $this->logger->error(Argument::cetera())->shouldNotBeCalled();
    $component = $this->component();

    $result = $this->actions->createComponent($component);

    $this->assertTrue($result['success']);
    $this->assertNull($result['error']);
    $this->assertSame($component, $result['data']['wisskiComponent']);
    $this->assertNull($component->setValues['containerId']);
    $this->assertSame('wisski-foo--drupal', $component->setValues['containerName']);
    $this->assertSame(42, $component->setValues['externalId']);
    $this->assertGreaterThan(0, $component->saveCount, 'The component is saved without a container ID.');
    $this->assertSame([5, 5, 5, 5, 5], SodaScsSleepStub::$seconds);
  }

  /**
   * A lookup that answers without a container ID counts as not found.
   */
  public function testCreateComponentTreatsLookupWithoutContainerIdAsNotFound(): void {
    $this->portainerCreatesStack();
    $this->containerHelpers->findLiveContainerByName('wisski-foo--drupal')
      ->willReturn($this->containerFound(''));
    $this->logger->warning(Argument::cetera())->shouldBeCalledOnce();
    $component = $this->component();

    $result = $this->actions->createComponent($component);

    $this->assertTrue($result['success']);
    $this->assertNull($component->setValues['containerId']);
  }

  /**
   * A WissKI without the default data model gets an empty recipe version.
   */
  public function testCreateComponentPassesEmptyRecipeVersionWithoutDefaultDataModel(): void {
    $this->productionVersionSettings();
    $this->portainerCreatesStack();
    $this->portainer->buildCreateRequest(Argument::that(
      fn(array $params) => $params['wisskiDefaultDataModelRecipeVersion'] === ''
        && $params['wisskiStarterRecipeVersion'] === 'starter-1'
    ))->willReturn(['request' => 'portainer'])->shouldBeCalledOnce();
    $this->containerHelpers->findLiveContainerByName(Argument::any())->willReturn($this->containerFound('abc123'));

    $result = $this->actions->createComponent($this->component(['withDefaultDataModel' => '0']));

    $this->assertTrue($result['success']);
  }

  /**
   * A WissKI with the default data model keeps the resolved recipe version.
   */
  public function testCreateComponentPassesRecipeVersionWithDefaultDataModel(): void {
    $this->productionVersionSettings();
    $this->portainerCreatesStack();
    $this->portainer->buildCreateRequest(Argument::that(
      fn(array $params) => $params['wisskiDefaultDataModelRecipeVersion'] === 'ddm-1'
    ))->willReturn(['request' => 'portainer'])->shouldBeCalledOnce();
    $this->containerHelpers->findLiveContainerByName(Argument::any())->willReturn($this->containerFound('abc123'));

    $result = $this->actions->createComponent($this->component(['withDefaultDataModel' => '1']));

    $this->assertTrue($result['success']);
  }

  /**
   * Components created before the field existed keep the recipe version.
   */
  #[DataProvider('legacyDefaultDataModelProvider')]
  public function testCreateComponentKeepsRecipeVersionWhenFieldIsNullOrMissing(array $extraFields): void {
    $this->productionVersionSettings();
    $this->portainerCreatesStack();
    $this->portainer->buildCreateRequest(Argument::that(
      fn(array $params) => $params['wisskiDefaultDataModelRecipeVersion'] === 'ddm-1'
    ))->willReturn(['request' => 'portainer'])->shouldBeCalledOnce();
    $this->containerHelpers->findLiveContainerByName(Argument::any())->willReturn($this->containerFound('abc123'));

    $result = $this->actions->createComponent($this->component($extraFields));

    $this->assertTrue($result['success']);
  }

  /**
   * Components whose field is NULL or absent.
   *
   * @return array<string, array>
   *   The extra fields of the component.
   */
  public static function legacyDefaultDataModelProvider(): array {
    return [
      'field is NULL' => [['withDefaultDataModel' => NULL]],
      'no such field' => [[]],
    ];
  }

  /**
   * An empty development recipe setting becomes the apply flag.
   *
   * An empty version would otherwise mean "plain WissKI" to the image.
   */
  #[DataProvider('emptyRecipeSettingProvider')]
  public function testCreateComponentPassesApplyFlagWhenDevelopmentRecipeSettingIsEmpty(array $fields): void {
    $this->serviceHelpers->initWisskiInstanceSettings()->willReturn([
      'varnishImageDevelopmentVersion' => 'varnish-dev',
      'stackDevelopmentVersion' => 'stack-dev',
      'defaultDataModelRecipeDevelopmentVersion' => '',
      'imageDevelopmentVersion' => 'image-dev',
      'starterRecipeDevelopmentVersion' => 'starter-dev',
    ]);
    $this->portainerCreatesStack();
    $this->portainer->buildCreateRequest(Argument::that(
      fn(array $params) => $params['wisskiDefaultDataModelRecipeVersion'] === 'true'
        && $params['wisskiStarterRecipeVersion'] === 'starter-dev'
    ))->willReturn(['request' => 'portainer'])->shouldBeCalledOnce();
    $this->containerHelpers->findLiveContainerByName(Argument::any())->willReturn($this->containerFound('abc123'));

    $result = $this->actions->createComponent($this->component($fields + ['developmentInstance' => 1]));

    $this->assertTrue($result['success']);
  }

  /**
   * An empty production recipe setting becomes the apply flag.
   */
  #[DataProvider('emptyRecipeSettingProvider')]
  public function testCreateComponentPassesApplyFlagWhenProductionRecipeSettingIsEmpty(array $fields): void {
    $this->serviceHelpers->initWisskiInstanceSettings()->willReturn([
      'defaultDataModelRecipeProductionVersion' => '',
      'starterRecipeProductionVersion' => 'starter-1',
    ]);
    $this->portainerCreatesStack();
    $this->portainer->buildCreateRequest(Argument::that(
      fn(array $params) => $params['wisskiDefaultDataModelRecipeVersion'] === 'true'
        && $params['wisskiStarterRecipeVersion'] === 'starter-1'
    ))->willReturn(['request' => 'portainer'])->shouldBeCalledOnce();
    $this->containerHelpers->findLiveContainerByName(Argument::any())->willReturn($this->containerFound('abc123'));

    $result = $this->actions->createComponent($this->component($fields));

    $this->assertTrue($result['success']);
  }

  /**
   * Fields of a component that uses the default data model.
   *
   * @return array<string, array>
   *   The extra fields of the component.
   */
  public static function emptyRecipeSettingProvider(): array {
    return [
      'field is TRUE' => [['withDefaultDataModel' => 1]],
      'field is NULL' => [['withDefaultDataModel' => NULL]],
      'no such field' => [[]],
    ];
  }

  /**
   * An empty recipe setting stays empty without the default data model.
   */
  public function testCreateComponentKeepsEmptyRecipeVersionWithoutDefaultDataModelWhenSettingIsEmpty(): void {
    $this->serviceHelpers->initWisskiInstanceSettings()->willReturn([
      'defaultDataModelRecipeProductionVersion' => '',
    ]);
    $this->portainerCreatesStack();
    $this->portainer->buildCreateRequest(Argument::that(
      fn(array $params) => $params['wisskiDefaultDataModelRecipeVersion'] === ''
    ))->willReturn(['request' => 'portainer'])->shouldBeCalledOnce();
    $this->containerHelpers->findLiveContainerByName(Argument::any())->willReturn($this->containerFound('abc123'));

    $result = $this->actions->createComponent($this->component(['withDefaultDataModel' => 0]));

    $this->assertTrue($result['success']);
  }

  /**
   * A snapshot of a WissKI without a stored container ID needs the sync.
   *
   * A failed sync is returned as it is and no Docker request is made.
   */
  public function testCreateSnapshotReturnsFailureWhenContainerIdSyncFails(): void {
    $failure = SodaScsResult::failure(
      error: 'Container not found for name: wisski-foo--drupal',
      message: 'WissKI container not found.',
      log: FALSE,
    );
    $component = $this->component(['containerId' => NULL]);
    $this->containerHelpers->syncComponentContainerId($component)->willReturn($failure)->shouldBeCalledOnce();
    $this->dockerRun->buildInspectRequest(Argument::cetera())->shouldNotBeCalled();
    $this->dockerRun->buildStopRequest(Argument::cetera())->shouldNotBeCalled();
    $this->dockerRun->makeRequest(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createSnapshot($component, 'snap-1', 1700000000);

    $this->assertSame($failure, $result);
    $this->assertFalse($result->success);
  }

  /**
   * A successful sync lets the snapshot go on with the Docker requests.
   *
   * The inspect request fails here, which ends the run after the sync.
   */
  public function testCreateSnapshotContinuesWithInspectAfterContainerIdSync(): void {
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('soda_scs_manager')->willReturn($this->logger->reveal());
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory->reveal());
    \Drupal::setContainer($container);
    $component = $this->component(['containerId' => '']);
    $this->containerHelpers->syncComponentContainerId($component)
      ->willReturn(SodaScsResult::success(['containerId' => 'abc123'], 'Refreshed.'))
      ->shouldBeCalledOnce();
    $this->dockerRun->buildInspectRequest(Argument::type('array'))->willReturn(['request' => 'inspect'])->shouldBeCalledOnce();
    $this->dockerRun->makeRequest(['request' => 'inspect'])->willReturn(['success' => FALSE, 'error' => 'Not found.']);

    $result = $this->actions->createSnapshot($component, 'snap-1', 1700000000);

    $this->assertFalse($result->success);
    $this->assertSame('Snapshot creation failed: Could not inspect container.', $result->message);
  }

  /**
   * A stored container ID is not synced before the snapshot.
   */
  public function testCreateSnapshotDoesNotSyncWhenContainerIdIsStored(): void {
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('soda_scs_manager')->willReturn($this->logger->reveal());
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory->reveal());
    \Drupal::setContainer($container);
    $component = $this->component(['containerId' => 'abc123']);
    $this->containerHelpers->syncComponentContainerId(Argument::cetera())->shouldNotBeCalled();
    $this->dockerRun->buildInspectRequest(Argument::that(
      fn(array $params) => $params['routeParams']['containerId'] === 'abc123'
    ))->willReturn(['request' => 'inspect'])->shouldBeCalledOnce();
    $this->dockerRun->makeRequest(['request' => 'inspect'])->willReturn(['success' => FALSE, 'error' => 'Not found.']);

    $result = $this->actions->createSnapshot($component, 'snap-1', 1700000000);

    $this->assertFalse($result->success);
  }

  /**
   * A Varnish restart that fails after the restore is reported as a failure.
   *
   * The volumes are restored and the WissKI runs again, but Varnish still
   * points at the old backend address: the caller must not see a success.
   * The restart is asked for the machine name of the restored instance.
   */
  public function testRestoreFromSnapshotFailsWhenVarnishRestartFails(): void {
    $this->useLoggerFactoryForFailureResults();
    $snapshotFile = $this->tempSnapshotFile();
    try {
      $snapshot = $this->prepareRestoreUntilVarnish($snapshotFile);
      $this->varnishCache->restart('foo')
        ->willReturn(SodaScsResult::failure(error: 'Varnish restart refused.', message: 'Could not restart the Varnish container.', log: FALSE))
        ->shouldBeCalledOnce();

      $result = $this->actions->restoreFromSnapshot($snapshot, NULL);

      $this->assertFalse($result->success);
      $this->assertSame('Restore completed, but failed to restart the Varnish container.', $result->message);
      $this->assertSame('Varnish restart refused.', $result->error);
    }
    finally {
      @unlink($snapshotFile);
    }
  }

  /**
   * A restore that fails before the start does not touch Varnish.
   */
  public function testRestoreFromSnapshotDoesNotRestartVarnishWhenVolumeRestoreFails(): void {
    $this->useLoggerFactoryForFailureResults();
    $snapshotFile = $this->tempSnapshotFile();
    try {
      $snapshot = $this->prepareRestoreUntilVarnish($snapshotFile);
      $this->wisskiVolumeHelpers->importVolumes('foo', dirname($snapshotFile), basename($snapshotFile), FALSE)
        ->willReturn(SodaScsResult::failure(error: 'tar failed.', message: 'Restore failed.', log: FALSE));
      $this->varnishCache->restart(Argument::any())->shouldNotBeCalled();

      $result = $this->actions->restoreFromSnapshot($snapshot, NULL);

      $this->assertFalse($result->success);
      $this->assertSame('Failed to restore the WissKI volumes.', $result->message);
    }
    finally {
      @unlink($snapshotFile);
    }
  }

  /**
   * The off switch only empties the recipe version, not the other pins.
   */
  public function testCreateComponentKeepsOtherVersionPinsWithoutDefaultDataModel(): void {
    $this->productionVersionSettings();
    $this->portainerCreatesStack();
    $this->portainer->buildCreateRequest(Argument::that(
      fn(array $params) => $params['wisskiComposeStackVersion'] === 'stack-1'
        && $params['wisskiBaseImageVersion'] === 'image-1'
        && $params['wisskiStarterRecipeVersion'] === 'starter-1'
    ))->willReturn(['request' => 'portainer'])->shouldBeCalledOnce();
    $this->containerHelpers->findLiveContainerByName(Argument::any())->willReturn($this->containerFound('abc123'));

    $result = $this->actions->createComponent($this->component(['withDefaultDataModel' => 0]));

    $this->assertTrue($result['success']);
  }

  /**
   * The client and both groups of the instance are deleted.
   */
  public function testDeleteKeycloakResourcesDeletesClientAndBothGroups(): void {
    $this->stubKeycloakResources(clients: [['id' => 'client-uuid', 'clientId' => 'wisski-foo']]);
    $this->keycloakClient->makeRequest(['request' => 'client-delete'])->willReturn(['success' => TRUE])->shouldBeCalledOnce();
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willReturn($this->groupsResult())->shouldBeCalledOnce();

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertTrue($result->success);
    $this->assertNull($result->error);
    $this->assertSame(['client', 'wisski-foo-admin', 'wisski-foo-user'], $result->data['deleted']);
  }

  /**
   * A client that is already gone counts as deleted; the groups still go.
   */
  public function testDeleteKeycloakResourcesCountsMissingClientAsDeleted(): void {
    $this->stubKeycloakResources(clients: []);
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willReturn($this->groupsResult())->shouldBeCalledOnce();

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertTrue($result->success);
    $this->assertSame(['wisski-foo-admin', 'wisski-foo-user'], $result->data['deleted']);
  }

  /**
   * Groups that are already gone count as deleted; the client still goes.
   */
  public function testDeleteKeycloakResourcesCountsMissingGroupsAsDeleted(): void {
    $this->stubKeycloakResources(clients: [['id' => 'client-uuid', 'clientId' => 'wisski-foo']]);
    $this->keycloakClient->makeRequest(['request' => 'client-delete'])->willReturn(['success' => TRUE])->shouldBeCalledOnce();
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willReturn($this->groupsResult(FALSE, FALSE));

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertTrue($result->success);
    $this->assertSame(['client'], $result->data['deleted']);
  }

  /**
   * Only the groups that existed are reported as deleted.
   */
  public function testDeleteKeycloakResourcesReportsOnlyTheGroupThatWasDeleted(): void {
    $this->stubKeycloakResources(clients: []);
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willReturn($this->groupsResult(FALSE, TRUE));

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertTrue($result->success);
    $this->assertSame(['wisski-foo-user'], $result->data['deleted']);
  }

  /**
   * Nothing to delete at all is a success, so a repeated call is harmless.
   */
  public function testDeleteKeycloakResourcesSucceedsWhenNothingExists(): void {
    $this->stubKeycloakResources(clients: []);
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willReturn($this->groupsResult(FALSE, FALSE));

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertTrue($result->success);
    $this->assertSame([], $result->data['deleted']);
  }

  /**
   * A client with another client ID in the answer is not deleted.
   */
  public function testDeleteKeycloakResourcesIgnoresClientWithOtherClientId(): void {
    $this->stubKeycloakResources(clients: [['id' => 'other-uuid', 'clientId' => 'wisski-foo-2']]);
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willReturn($this->groupsResult(FALSE, FALSE));

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertTrue($result->success);
    $this->assertNotContains('client', $result->data['deleted']);
  }

  /**
   * With a required client and none found, nothing is deleted.
   */
  public function testDeleteKeycloakResourcesSkipsGroupsWhenClientIsRequiredAndMissing(): void {
    $this->stubKeycloakResources(clients: []);
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();

    $result = $this->actions->deleteKeycloakResources('wisski-foo', 'Foo', TRUE);

    $this->assertTrue($result->success);
    $this->assertSame(['deleted' => [], 'skipped' => 'no client'], $result->data);
  }

  /**
   * A required client that exists is deleted together with the groups.
   */
  public function testDeleteKeycloakResourcesDeletesWhenClientIsRequiredAndPresent(): void {
    $this->stubKeycloakResources(clients: [['id' => 'client-uuid', 'clientId' => 'wisski-foo', 'name' => 'Foo']]);
    $this->keycloakClient->makeRequest(['request' => 'client-delete'])->willReturn(['success' => TRUE])->shouldBeCalledOnce();
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willReturn($this->groupsResult())->shouldBeCalledOnce();

    $result = $this->actions->deleteKeycloakResources('wisski-foo', 'Foo', TRUE);

    $this->assertTrue($result->success);
    $this->assertSame(['client', 'wisski-foo-admin', 'wisski-foo-user'], $result->data['deleted']);
  }

  /**
   * A client that carries another name belongs to another WissKI.
   */
  public function testDeleteKeycloakResourcesLeavesClientWithOtherNameAlone(): void {
    $this->stubKeycloakResources(clients: [['id' => 'client-uuid', 'clientId' => 'wisski-foo', 'name' => 'Bar']]);
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();

    $result = $this->actions->deleteKeycloakResources('wisski-foo', 'Foo');

    $this->assertTrue($result->success);
    $this->assertSame(['deleted' => [], 'skipped' => 'other client'], $result->data);
  }

  /**
   * A client without a name is not the one named in the call.
   */
  public function testDeleteKeycloakResourcesLeavesUnnamedClientAloneWhenNameIsGiven(): void {
    $this->stubKeycloakResources(clients: [['id' => 'client-uuid', 'clientId' => 'wisski-foo']]);
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();

    $result = $this->actions->deleteKeycloakResources('wisski-foo', 'Foo');

    $this->assertSame('other client', $result->data['skipped']);
  }

  /**
   * Without a client, a given name does not keep the groups from going.
   */
  public function testDeleteKeycloakResourcesDeletesGroupsWithoutClientWhenClientIsNotRequired(): void {
    $this->stubKeycloakResources(clients: []);
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willReturn($this->groupsResult())->shouldBeCalledOnce();

    $result = $this->actions->deleteKeycloakResources('wisski-foo', 'Foo');

    $this->assertTrue($result->success);
    $this->assertSame(['wisski-foo-admin', 'wisski-foo-user'], $result->data['deleted']);
  }

  /**
   * Without a Keycloak token nothing is asked or deleted.
   */
  public function testDeleteKeycloakResourcesFailsWithoutToken(): void {
    $this->keycloakHelpers->getKeycloakToken()->willReturn(NULL);
    $this->keycloakClient->buildGetAllRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertFalse($result->success);
    $this->assertSame('Keycloak token request failed.', $result->error);
  }

  /**
   * A failed client lookup stops the call before anything is deleted.
   */
  public function testDeleteKeycloakResourcesStopsWhenClientLookupFails(): void {
    $this->stubKeycloakResources();
    $this->keycloakClient->makeRequest(['request' => 'client-lookup'])
      ->willReturn(['success' => FALSE, 'error' => 'Server error.']);
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertFalse($result->success);
    $this->assertSame('Keycloak client lookup failed: Server error.', $result->error);
    $this->assertSame('Could not delete the Keycloak client of the WissKI instance.', $result->message);
  }

  /**
   * A client lookup that throws is a failure result, not an exception.
   */
  public function testDeleteKeycloakResourcesReturnsFailureWhenClientLookupThrows(): void {
    $this->stubKeycloakResources();
    $this->keycloakClient->makeRequest(['request' => 'client-lookup'])->willThrow(new \RuntimeException('Connection refused.'));
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertFalse($result->success);
    $this->assertSame('Connection refused.', $result->error);
  }

  /**
   * A malformed client lookup answer is no client; nothing is deleted for it.
   */
  public function testDeleteKeycloakResourcesTreatsMalformedClientAnswerAsNoClient(): void {
    $this->stubKeycloakResources();
    $this->keycloakClient->makeRequest(['request' => 'client-lookup'])->willReturn([
      'success' => TRUE,
      'data' => ['keycloakResponse' => new Response(200, [], 'not json')],
    ]);
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();

    $result = $this->actions->deleteKeycloakResources('wisski-foo', NULL, TRUE);

    $this->assertTrue($result->success);
    $this->assertSame('no client', $result->data['skipped']);
  }

  /**
   * A failed client delete leaves the groups alone.
   */
  public function testDeleteKeycloakResourcesStopsWhenClientDeleteFails(): void {
    $this->stubKeycloakResources(clients: [['id' => 'client-uuid', 'clientId' => 'wisski-foo']]);
    $this->keycloakClient->makeRequest(['request' => 'client-delete'])
      ->willReturn(['success' => FALSE, 'error' => 'Forbidden.']);
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertFalse($result->success);
    $this->assertSame('Keycloak client delete failed: Forbidden.', $result->error);
  }

  /**
   * A failing group delete is a failure result with the reason of the helper.
   */
  public function testDeleteKeycloakResourcesFailsWhenGroupDeleteThrows(): void {
    $this->stubKeycloakResources(clients: [['id' => 'client-uuid', 'clientId' => 'wisski-foo']]);
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')
      ->willThrow(new \Exception('Cannot delete Keycloak user group wisski-foo-user: Forbidden.'));

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertFalse($result->success);
    $this->assertSame('Cannot delete Keycloak user group wisski-foo-user: Forbidden.', $result->error);
    $this->assertSame('Could not delete the Keycloak client of the WissKI instance.', $result->message);
  }

  /**
   * A failed group lookup of the helper stops the delete with its reason.
   */
  public function testDeleteKeycloakResourcesFailsWhenGroupLookupThrows(): void {
    $this->stubKeycloakResources();
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')
      ->willThrow(new \RuntimeException('Keycloak group lookup of wisski-foo-admin failed: Server error.'));

    $result = $this->actions->deleteKeycloakResources('wisski-foo');

    $this->assertFalse($result->success);
    $this->assertSame('Keycloak group lookup of wisski-foo-admin failed: Server error.', $result->error);
  }

  /**
   * A deleted WissKI loses its Portainer stack, volumes, Keycloak and entity.
   */
  public function testDeleteComponentDeletesPortainerStackKeycloakAndEntity(): void {
    $component = $this->deletableComponent();
    $this->portainerAnswersDeleteWith(['success' => TRUE, 'statusCode' => 200]);
    $this->portainerHelpers->removeVolumesOfComposeStack('wisski-foo')->willReturn(SodaScsResult::success(data: [], message: 'Removed.'))->shouldBeCalledOnce();
    $this->stubKeycloakResources();
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willReturn($this->groupsResult())->shouldBeCalledOnce();
    $component->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertTrue($result['success']);
    $this->assertSame(200, $result['statusCode']);
  }

  /**
   * A Portainer stack that is already gone (404) does not stop the delete.
   */
  public function testDeleteComponentGoesOnWhenPortainerStackIsAlreadyGone(): void {
    $component = $this->deletableComponent();
    $this->portainerAnswersDeleteWith(['success' => FALSE, 'error' => 'Not found.', 'statusCode' => 404]);
    $this->stubKeycloakResources();
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willReturn($this->groupsResult())->shouldBeCalledOnce();
    $component->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertTrue($result['success']);
    $this->assertSame(404, $result['statusCode']);
  }

  /**
   * A failed Portainer delete keeps Keycloak, volumes and the entity.
   */
  #[DataProvider('failedPortainerDeleteProvider')]
  public function testDeleteComponentKeepsEverythingWhenPortainerDeleteFails(array $portainerAnswer): void {
    $component = $this->deletableComponent();
    $this->portainerAnswersDeleteWith($portainerAnswer);
    $this->portainerHelpers->removeVolumesOfComposeStack(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->getKeycloakToken()->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $component->delete()->shouldNotBeCalled();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame($portainerAnswer['error'] ?? 'Unknown error occurred', $result['error']);
    $this->assertSame('Could not delete WissKI component @component at portainer.', $result['message']->getUntranslatedString());
  }

  /**
   * Portainer answers that are no success and no 404.
   *
   * @return array<string, array>
   *   The delete answer of Portainer.
   */
  public static function failedPortainerDeleteProvider(): array {
    return [
      'server error' => [['success' => FALSE, 'error' => 'Server error.', 'statusCode' => 500]],
      'forbidden' => [['success' => FALSE, 'error' => 'Forbidden.', 'statusCode' => 403]],
      'no status code' => [['success' => FALSE, 'error' => 'Connection reset.']],
      'no error text' => [['success' => FALSE, 'statusCode' => 502]],
    ];
  }

  /**
   * A Portainer delete that throws keeps Keycloak, volumes and the entity.
   */
  public function testDeleteComponentKeepsEverythingWhenPortainerDeleteThrows(): void {
    $component = $this->deletableComponent();
    $this->portainer->makeRequest(['request' => 'stack-delete'])->willThrow(new \RuntimeException('Connection refused.'));
    $this->portainerHelpers->removeVolumesOfComposeStack(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->getKeycloakToken()->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
    $component->delete()->shouldNotBeCalled();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame('Connection refused.', $result['error']);
  }

  /**
   * A failed Keycloak cleanup keeps the entity, so the delete can be repeated.
   */
  public function testDeleteComponentKeepsEntityWhenKeycloakCleanupFails(): void {
    $component = $this->deletableComponent();
    $this->portainerAnswersDeleteWith(['success' => TRUE, 'statusCode' => 200]);
    $this->stubKeycloakResources();
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willThrow(new \Exception('Forbidden.'));
    $component->delete()->shouldNotBeCalled();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame('Forbidden.', $result['error']);
  }

  /**
   * A Keycloak client that already exists (409) is not ours to delete.
   */
  public function testCreateComponentLeavesExistingKeycloakClientAloneOnConflict(): void {
    $this->stubKeycloakResources();
    $this->keycloakClient->buildCreateRequest(Argument::any())->willReturn(['request' => 'client-create']);
    $this->keycloakClient->makeRequest(['request' => 'client-create'])
      ->willReturn(['success' => FALSE, 'error' => 'Conflict', 'statusCode' => 409]);
    $this->expectNoKeycloakCleanup();
    $this->portainer->makeRequest(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->component());

    $this->assertCompleteFailure($result);
    $this->assertSame('Keycloak create client request failed: Conflict', $result['error']->getMessage());
    $this->assertSame('The WissKI instance could not be prepared.', $result['message']->getUntranslatedString());
  }

  /**
   * The Keycloak client of a failed Portainer answer goes with its groups.
   */
  public function testCreateComponentDeletesKeycloakResourcesWhenPortainerAnswersWithError(): void {
    $this->portainer->makeRequest(['request' => 'portainer'])
      ->willReturn(['success' => FALSE, 'error' => 'Conflict', 'statusCode' => 409]);
    $this->stubKeycloakResources();
    $this->expectKeycloakCleanup();

    $result = $this->actions->createComponent($this->component());

    $this->assertCompleteFailure($result);
    $this->assertSame('Conflict', $result['error']);
  }

  /**
   * The Keycloak client of an unreachable Portainer is deleted with its groups.
   */
  public function testCreateComponentDeletesKeycloakResourcesWhenPortainerRequestThrows(): void {
    $failure = new \RuntimeException('Connection refused.');
    $this->portainer->makeRequest(['request' => 'portainer'])->willThrow($failure);
    $this->stubKeycloakResources();
    $this->expectKeycloakCleanup();

    $result = $this->actions->createComponent($this->component());

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
  }

  /**
   * A step after the Keycloak client that fails deletes client and groups.
   */
  public function testCreateComponentDeletesKeycloakResourcesWhenPreparationFailsAfterClientCreation(): void {
    $this->mountManager->ensureProjectTeamFolderReady(Argument::cetera())->willReturn(FALSE);
    $this->stubKeycloakResources();
    $this->expectKeycloakCleanup();
    $this->portainer->makeRequest(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->component());

    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(SodaScsUserFacingException::class, $result['error']);
  }

  /**
   * A failed Keycloak cleanup does not hide the failure of the creation.
   */
  public function testCreateComponentReturnsPortainerFailureWhenKeycloakCleanupFails(): void {
    $this->portainer->makeRequest(['request' => 'portainer'])
      ->willReturn(['success' => FALSE, 'error' => 'Conflict', 'statusCode' => 409]);
    $this->stubKeycloakResources();
    $this->keycloakClient->makeRequest(['request' => 'client-lookup'])
      ->willReturn(['success' => FALSE, 'error' => 'Server error.']);

    $result = $this->actions->createComponent($this->component());

    $this->assertCompleteFailure($result);
    $this->assertSame('Conflict', $result['error']);
    $this->assertSame(409, $result['statusCode']);
    $this->assertSame('The WissKI container could not be created.', $result['message']->getUntranslatedString());
  }

  /**
   * A created WissKI keeps its Keycloak client and groups.
   */
  public function testCreateComponentKeepsKeycloakResourcesWhenPortainerSucceeds(): void {
    $this->portainerCreatesStack();
    $this->containerHelpers->findLiveContainerByName('wisski-foo--drupal')->willReturn($this->containerFound('abc123'));
    $this->stubKeycloakResources();
    $this->expectNoKeycloakCleanup();

    $result = $this->actions->createComponent($this->component());

    $this->assertTrue($result['success']);
  }

  /**
   * A successful Keycloak answer with a JSON list.
   *
   * @param array $body
   *   The list the answer carries.
   *
   * @return array
   *   The request result.
   */
  protected function keycloakAnswer(array $body): array {
    return [
      'success' => TRUE,
      'data' => ['keycloakResponse' => new Response(200, [], json_encode($body))],
    ];
  }

  /**
   * The answer of the group delete of the Keycloak helpers.
   *
   * @param bool $adminDeleted
   *   Whether the admin group existed and was deleted.
   * @param bool $userDeleted
   *   Whether the user group existed and was deleted.
   *
   * @return array
   *   What deleteComponentAccessGroups() returns for wisski-foo.
   */
  protected function groupsResult(bool $adminDeleted = TRUE, bool $userDeleted = TRUE): array {
    return [
      'adminGroupName' => 'wisski-foo-admin',
      'userGroupName' => 'wisski-foo-user',
      'adminDeleted' => $adminDeleted,
      'userDeleted' => $userDeleted,
    ];
  }

  /**
   * A component double for deleteComponent().
   *
   * @return \Prophecy\Prophecy\ObjectProphecy
   *   The component prophecy.
   */
  protected function deletableComponent() {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->get('externalId')->willReturn($this->fieldList('12'));
    $component->get('machineName')->willReturn($this->fieldList('wisski-foo'));
    $component->getLabel()->willReturn('Foo');
    $this->portainer->buildGetRequest(['routeParams' => ['stackId' => '12']])->willReturn(['request' => 'stack-get']);
    $this->portainer->makeRequest(['request' => 'stack-get'])->willReturn(['success' => TRUE]);
    $this->portainer->buildDeleteRequest(['routeParams' => ['stackId' => '12']])->willReturn(['request' => 'stack-delete']);
    return $component;
  }

  /**
   * Lets Portainer answer the stack delete with the given result.
   *
   * @param array $answer
   *   The result of the delete request.
   */
  protected function portainerAnswersDeleteWith(array $answer): void {
    $this->portainer->makeRequest(['request' => 'stack-delete'])->willReturn($answer);
  }

  /**
   * Lets Keycloak know the clients of wisski-foo and their deletes.
   *
   * The lookups and delete requests are tagged, so a test can tell which
   * request the code made. The groups go through the Keycloak helpers, which
   * a test stubs itself.
   *
   * @param array|null $clients
   *   The answer of the client lookup; the instance's client by default.
   */
  protected function stubKeycloakResources(?array $clients = NULL): void {
    $token = 'not-a-token';
    $clients ??= [['id' => 'client-uuid', 'clientId' => 'wisski-foo']];

    $this->keycloakClient->buildGetAllRequest(['queryParams' => ['clientId' => 'wisski-foo'], 'token' => $token])
      ->willReturn(['request' => 'client-lookup']);
    $this->keycloakClient->makeRequest(['request' => 'client-lookup'])->willReturn($this->keycloakAnswer($clients));
    $this->keycloakClient->buildDeleteRequest(['routeParams' => ['clientUuid' => 'client-uuid'], 'token' => $token])
      ->willReturn(['request' => 'client-delete']);
    $this->keycloakClient->makeRequest(['request' => 'client-delete'])->willReturn(['success' => TRUE]);
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->willReturn($this->groupsResult());
  }

  /**
   * Expects the delete of the client and both groups, once each.
   */
  protected function expectKeycloakCleanup(): void {
    $this->keycloakClient->makeRequest(['request' => 'client-delete'])->shouldBeCalledOnce();
    $this->keycloakHelpers->deleteComponentAccessGroups('wisski-foo')->shouldBeCalledOnce();
  }

  /**
   * Expects that no Keycloak lookup or delete happens.
   */
  protected function expectNoKeycloakCleanup(): void {
    $this->keycloakClient->buildGetAllRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakClient->buildDeleteRequest(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
  }

  /**
   * Lets the settings resolve to the production version pins.
   */
  protected function productionVersionSettings(): void {
    $this->serviceHelpers->initWisskiInstanceSettings()->willReturn([
      'defaultDataModelRecipeProductionVersion' => 'ddm-1',
      'starterRecipeProductionVersion' => 'starter-1',
      'wisskiBaseImageProductionVersion' => 'image-1',
      'wisskiStackProductionVersion' => 'stack-1',
    ]);
  }

  /**
   * Lets failure results log through \Drupal::logger() into the double.
   */
  protected function useLoggerFactoryForFailureResults(): void {
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('soda_scs_manager')->willReturn($this->logger->reveal());
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory->reveal());
    \Drupal::setContainer($container);
  }

  /**
   * Creates the snapshot archive file the restore looks for on disk.
   */
  protected function tempSnapshotFile(): string {
    $path = tempnam(sys_get_temp_dir(), 'scs-snapshot-');
    $this->assertIsString($path);
    return $path;
  }

  /**
   * Stubs every request of a restore up to the Varnish restart.
   *
   * The WissKI container "foo--drupal" is stopped, the rollback backup and the
   * volume import succeed and the container starts again.
   */
  protected function prepareRestoreUntilVarnish(string $snapshotFile): SodaScsSnapshotInterface {
    $component = $this->component();
    $componentField = $this->createMock(FieldItemList::class);
    $componentField->method('__isset')->with('entity')->willReturn(TRUE);
    $componentField->method('__get')->with('entity')->willReturn($component);
    $file = new class($snapshotFile) {

      /**
       * Constructs the stand-in of the snapshot file entity.
       */
      public function __construct(private string $path) {}

      /**
       * Returns the URI of the file.
       */
      public function getFileUri(): string {
        return $this->path;
      }

    };
    $snapshot = $this->createMock(SodaScsSnapshotInterface::class);
    $snapshot->method('get')->with('snapshotOfComponent')->willReturn($componentField);
    $snapshot->method('getFile')->willReturn($file);
    $snapshot->method('label')->willReturn('Snapshot');
    $this->fileSystem->realpath($snapshotFile)->willReturn($snapshotFile);

    $this->dockerRun->buildGetAllRequest(Argument::type('array'))->willReturn(['request' => 'get-all']);
    $this->dockerRun->makeRequest(['request' => 'get-all'])->willReturn($this->portainerJson([['Id' => 'cid-wisski']]));
    $this->dockerRun->buildInspectRequest(Argument::type('array'))->willReturn(['request' => 'inspect']);
    $this->dockerRun->makeRequest(['request' => 'inspect'])->willReturn($this->portainerJson(['State' => ['Running' => FALSE]]));
    $this->dockerRun->buildCreateRequest(Argument::type('array'))->willReturn(['request' => 'create']);
    $this->dockerRun->makeRequest(['request' => 'create'])->willReturn($this->portainerJson(['Id' => 'cid-rollback']));
    $this->dockerRun->buildStartRequest(Argument::type('array'))->willReturn(['request' => 'start']);
    $this->dockerRun->makeRequest(['request' => 'start'])->willReturn(['success' => TRUE]);
    $this->containerHelpers->waitForContainerState('cid-rollback', 'exited')
      ->willReturn(SodaScsResult::success(data: [], message: 'exited'));
    $this->wisskiVolumeHelpers->importVolumes('foo', dirname($snapshotFile), basename($snapshotFile), FALSE)
      ->willReturn(SodaScsResult::success(data: [], message: 'Imported.'));
    return $snapshot;
  }

  /**
   * A successful Portainer answer with a JSON body.
   */
  protected function portainerJson(array $body): array {
    return [
      'success' => TRUE,
      'data' => ['portainerResponse' => new Response(200, [], json_encode($body))],
    ];
  }

  /**
   * A successful container lookup result.
   */
  protected function containerFound(string $containerId): SodaScsResult {
    return SodaScsResult::success(['containerId' => $containerId], 'Container resolved.');
  }

  /**
   * A container lookup result that found nothing.
   */
  protected function containerMissing(): SodaScsResult {
    return SodaScsResult::failure(
      error: 'Container not found for name: wisski-foo--drupal',
      message: 'Container not found.',
      log: FALSE,
    );
  }

  /**
   * Lets the Portainer request succeed.
   */
  protected function portainerCreatesStack(): void {
    // The success path saves the service key; the failure paths must not.
    $serviceKey = $this->prophesize(SodaScsServiceKeyInterface::class);
    $serviceKey->get('servicePassword')->willReturn($this->fieldList('not-a-password'));
    $serviceKey->save()->willReturn(1);
    $this->serviceKeyActions->getServiceKey(Argument::type('array'))->willReturn($serviceKey->reveal());
    $this->portainer->makeRequest(['request' => 'portainer'])->willReturn([
      'success' => TRUE,
      'data' => ['portainerResponse' => new Response(200, [], json_encode(['Id' => 42]))],
    ]);
  }

  /**
   * Builds the unsaved WissKI component the stack action hands over.
   *
   * A real entity class without constructor: the method assigns
   * `$component->serviceKey[]`, which a mock object cannot take.
   *
   * @param array $extraFields
   *   Field values to add, by field name; a field that is not listed does not
   *   exist on the component.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsComponent
   *   The component, with `saveCount` and `setValues` properties.
   */
  protected function component(array $extraFields = []): SodaScsComponent {
    $owner = $this->prophesize(UserInterface::class);
    $owner->id()->willReturn(7);
    $owner->getDisplayName()->willReturn('alice');

    $project = $this->prophesize(SodaScsProjectInterface::class);
    $project->id()->willReturn(3);
    $project->label()->willReturn('Project One');
    $project->get('members')->willReturn($this->fieldList());
    $project->get('groupId')->willReturn($this->fieldList('1000'));

    $fields = [
      'machineName' => $this->fieldList('foo'),
      'label' => $this->fieldList('Foo'),
      'version' => $this->fieldList(''),
      'developmentInstance' => $this->fieldList(0),
      'defaultLanguage' => $this->fieldList('en'),
      'flavours' => $this->fieldList(NULL),
      'partOfProjects' => $this->fieldList(NULL, [$project->reveal()]),
    ];
    foreach ($extraFields as $name => $value) {
      $fields[$name] = $this->fieldList($value);
    }

    return new class($owner->reveal(), $fields) extends SodaScsComponent {

      /**
       * The service keys the method collects.
       */
      public array $serviceKey = [];

      /**
       * How often save() ran.
       */
      public int $saveCount = 0;

      /**
       * The values set on the component, by field name.
       */
      public array $setValues = [];

      /**
       * Constructs the component without the entity machinery.
       */
      public function __construct(protected UserInterface $stubOwner, protected array $fieldStubs) {}

      /**
       * {@inheritdoc}
       */
      public function get($field_name) {
        return $this->fieldStubs[$field_name];
      }

      /**
       * {@inheritdoc}
       */
      public function set($name, $value, $notify = TRUE) {
        $this->setValues[$name] = $value;
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function hasField($field_name) {
        return array_key_exists($field_name, $this->fieldStubs);
      }

      /**
       * {@inheritdoc}
       */
      public function getOwner() {
        return $this->stubOwner;
      }

      /**
       * {@inheritdoc}
       */
      public function getOwnerId() {
        return 7;
      }

      /**
       * {@inheritdoc}
       */
      public function id() {
        return 5;
      }

      /**
       * {@inheritdoc}
       */
      public function save() {
        $this->saveCount++;
        return 1;
      }

    };
  }

}
