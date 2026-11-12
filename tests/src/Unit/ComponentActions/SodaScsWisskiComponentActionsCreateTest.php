<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions;
use Drupal\soda_scs_manager\Entity\SodaScsComponent;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
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
 * The wait for the container runs against a sleep() stand-in
 * (SodaScsSleepStub.php), so the tests do not wait.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions
 * @group soda_scs_manager
 */
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
   * The service under test.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions
   */
  protected SodaScsWisskiComponentActions $actions;

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

    $keycloakHelpers = $this->prophesize(SodaScsKeycloakHelpers::class);
    $keycloakHelpers->getKeycloakToken()->willReturn('not-a-token');

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

    $keycloakClient = $this->prophesize(SodaScsServiceRequestInterface::class);
    $keycloakClient->buildCreateRequest(Argument::any())->willReturn([]);
    $keycloakClient->makeRequest(Argument::any())->willReturn(['success' => TRUE]);

    $keycloakGroups = $this->prophesize(SodaScsServiceRequestInterface::class);
    $keycloakGroups->buildCreateRequest(Argument::any())->willReturn([]);
    $keycloakGroups->buildGetAllRequest(Argument::any())->willReturn([]);
    $keycloakGroups->makeRequest(Argument::any())->will(fn() => [
      'success' => TRUE,
      'data' => [
        'keycloakResponse' => new Response(200, [], json_encode([
          ['name' => 'wisski-foo-admin', 'id' => 'group-admin'],
          ['name' => 'wisski-foo-user', 'id' => 'group-user'],
        ])),
      ],
    ]);

    $this->portainer = $this->prophesize(SodaScsServiceRequestInterface::class);
    $this->portainer->buildCreateRequest(Argument::type('array'))->willReturn(['request' => 'portainer']);

    $this->dockerRun = $this->prophesize(SodaScsRunRequestInterface::class);
    $this->dockerRun->buildGetAllRequest(Argument::type('array'))->willReturn(['request' => 'docker']);

    $serviceKey = $this->prophesize(SodaScsServiceKeyInterface::class);
    $serviceKey->get('servicePassword')->willReturn($this->fieldList('not-a-password'));
    $serviceKey->save()->shouldNotBeCalled();
    $this->serviceKeyActions = $this->prophesize(SodaScsServiceKeyActionsInterface::class);
    $this->serviceKeyActions->getServiceKey(Argument::type('array'))->willReturn($serviceKey->reveal());

    $this->actions = new SodaScsWisskiComponentActions(
      $this->bundleInfo->reveal(),
      $configFactory->reveal(),
      $this->prophesize(Connection::class)->reveal(),
      $this->prophesize(EntityTypeManagerInterface::class)->reveal(),
      $this->prophesize(ClientInterface::class)->reveal(),
      $loggerFactory->reveal(),
      $this->messenger->reveal(),
      $this->prophesize(FileSystemInterface::class)->reveal(),
      $componentHelpers->reveal(),
      $this->containerHelpers->reveal(),
      $this->prophesize(SodaScsHelpers::class)->reveal(),
      $this->prophesize(SodaScsExecRequestInterface::class)->reveal(),
      $this->dockerRun->reveal(),
      $keycloakHelpers->reveal(),
      $this->nextcloudHelpers->reveal(),
      $this->mountManager->reveal(),
      $keycloakClient->reveal(),
      $keycloakGroups->reveal(),
      $this->prophesize(SodaScsServiceRequestInterface::class)->reveal(),
      $this->prophesize(SodaScsPortainerHelpers::class)->reveal(),
      $this->portainer->reveal(),
      $projectHelpers->reveal(),
      $this->serviceHelpers->reveal(),
      $this->serviceKeyActions->reveal(),
      $this->prophesize(SodaScsSnapshotHelpers::class)->reveal(),
      $this->prophesize(SodaScsServiceActionsInterface::class)->reveal(),
      $this->prophesize(SodaScsStackHelpers::class)->reveal(),
      $this->getStringTranslationStub(),
      $this->prophesize(SodaScsWisskiVolumeHelpers::class)->reveal(),
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
   *
   * @covers ::createComponent
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
   *
   * @covers ::createComponent
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
   *
   * @covers ::createComponent
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
   *
   * @covers ::createComponent
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
   *
   * @covers ::createComponent
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
   *
   * @covers ::createComponent
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
   *
   * @covers ::createComponent
   * @covers ::waitForNewContainerId
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
   *
   * @covers ::createComponent
   * @covers ::waitForNewContainerId
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
   *
   * @covers ::createComponent
   * @covers ::waitForNewContainerId
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
   *
   * @covers ::createComponent
   * @covers ::waitForNewContainerId
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
   *
   * @covers ::createComponent
   * @covers ::resolveWisskiVersionSettings
   * @covers ::usesWisskiDefaultDataModel
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
   *
   * @covers ::createComponent
   * @covers ::resolveWisskiVersionSettings
   * @covers ::usesWisskiDefaultDataModel
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
   *
   * @covers ::createComponent
   * @covers ::usesWisskiDefaultDataModel
   *
   * @dataProvider legacyDefaultDataModelProvider
   */
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
   *
   * @covers ::createComponent
   * @covers ::resolveWisskiVersionSettings
   *
   * @dataProvider emptyRecipeSettingProvider
   */
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
   *
   * @covers ::createComponent
   * @covers ::resolveWisskiVersionSettings
   *
   * @dataProvider emptyRecipeSettingProvider
   */
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
   *
   * @covers ::createComponent
   * @covers ::resolveWisskiVersionSettings
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
   *
   * @covers ::createSnapshot
   * @covers ::refreshComponentContainerId
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
   *
   * @covers ::createSnapshot
   * @covers ::refreshComponentContainerId
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
   *
   * @covers ::createSnapshot
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
   * The off switch only empties the recipe version, not the other pins.
   *
   * @covers ::createComponent
   * @covers ::resolveWisskiVersionSettings
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
