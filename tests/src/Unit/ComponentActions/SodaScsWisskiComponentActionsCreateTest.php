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
use Drupal\Tests\soda_scs_manager\Unit\SodaScsCreatePathTestTrait;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Failure results of SodaScsWisskiComponentActions::createComponent().
 *
 * Issue 3637658: every failure returns success FALSE, an error and a
 * translated message; the Portainer and container-lookup failures no longer
 * read the result of the request that threw or failed.
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

    $serviceHelpers = $this->prophesize(SodaScsServiceHelpers::class);
    $serviceHelpers->initWisskiInstanceSettings()->willReturn([]);
    $serviceHelpers->wisskiInstanceBaseUrl(Argument::type('string'))->willReturn('https://wisski.invalid');

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
    $serviceKeyActions = $this->prophesize(SodaScsServiceKeyActionsInterface::class);
    $serviceKeyActions->getServiceKey(Argument::type('array'))->willReturn($serviceKey->reveal());

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
      $this->prophesize(SodaScsContainerHelpers::class)->reveal(),
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
      $serviceHelpers->reveal(),
      $serviceKeyActions->reveal(),
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
   * A failed container lookup returns a failure and saves nothing.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentFailsWhenContainerLookupFails(): void {
    $this->portainerCreatesStack();
    $this->dockerRun->makeRequest(['request' => 'docker'])->willReturn(['success' => FALSE]);
    $component = $this->component();

    $result = $this->actions->createComponent($component);

    $this->assertCompleteFailure($result);
    $this->assertSame('Container wisski-foo--drupal not found.', $result['error']);
    $this->assertSame('The WissKI container was not found after it was created.', $result['message']->getUntranslatedString());
    $this->assertSame(0, $component->saveCount);
  }

  /**
   * An empty container list returns a failure and saves nothing.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentFailsWhenContainerListIsEmpty(): void {
    $this->portainerCreatesStack();
    $this->dockerRun->makeRequest(['request' => 'docker'])->willReturn([
      'success' => TRUE,
      'data' => ['portainerResponse' => new Response(200, [], '[]')],
    ]);
    $component = $this->component();

    $result = $this->actions->createComponent($component);

    $this->assertCompleteFailure($result);
    $this->assertSame('Container wisski-foo--drupal not found.', $result['error']);
    $this->assertSame(0, $component->saveCount);
  }

  /**
   * Lets the Portainer request succeed.
   */
  protected function portainerCreatesStack(): void {
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
   * @return \Drupal\soda_scs_manager\Entity\SodaScsComponent
   *   The component, with a `saveCount` property.
   */
  protected function component(): SodaScsComponent {
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
        return $this;
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
