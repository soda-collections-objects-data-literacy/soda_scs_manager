<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\ComponentActions\SodaScsSqlComponentActions;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
use Drupal\soda_scs_manager\Exception\SodaScsSqlServiceException;
use Drupal\soda_scs_manager\Exception\SodaScsUserFacingException;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsDatabaseHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerExecServiceActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\Tests\soda_scs_manager\Unit\SodaScsCreatePathTestTrait;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Failure results of SodaScsSqlComponentActions::createComponent().
 *
 * Issue 3637658: every failure returns success FALSE, an error and a
 * translated message; nothing escapes the method.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\ComponentActions\SodaScsSqlComponentActions
 * @group soda_scs_manager
 */
class SodaScsSqlComponentActionsCreateTest extends UnitTestCase {

  use ProphecyTrait;
  use SodaScsCreatePathTestTrait;

  /**
   * The mocked bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $bundleInfo;

  /**
   * The mocked settings.
   *
   * @var \Drupal\Core\Config\Config|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $settings;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $logger;

  /**
   * The mocked messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $messenger;

  /**
   * The mocked SQL service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $sqlServiceActions;

  /**
   * The mocked Keycloak helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $keycloakHelpers;

  /**
   * The component created by the storage.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $sqlComponent;

  /**
   * The service under test.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsSqlComponentActions
   */
  protected SodaScsSqlComponentActions $actions;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->bundleInfo = $this->prophesize(EntityTypeBundleInfoInterface::class);
    $this->bundleInfo->getBundleInfo('soda_scs_component')->willReturn([
      'soda_scs_sql_component' => [
        'label' => 'Database',
        'description' => 'A database.',
        'imageUrl' => '',
      ],
    ]);

    $this->sqlComponent = $this->prophesize(SodaScsComponentInterface::class);
    $this->sqlComponent->bundle()->willReturn('soda_scs_sql_component');
    $this->sqlComponent->set(Argument::cetera())->willReturn($this->sqlComponent->reveal());
    $this->sqlComponent->get('partOfProjects')->willReturn($this->fieldList());
    $this->sqlComponent->save()->willReturn(1);

    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->create(Argument::type('array'))->willReturn($this->sqlComponent->reveal());
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $entityTypeManager->getStorage('soda_scs_component')->willReturn($storage->reveal());

    $this->settings = $this->prophesize(Config::class);
    $this->settings->get('dbHost')->willReturn('db.invalid');
    $this->settings->get('dbRootPassword')->willReturn('not-a-password');
    $configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $configFactory->getEditable('soda_scs_manager.settings')->willReturn($this->settings->reveal());

    $this->logger = $this->prophesize(LoggerChannelInterface::class);
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('soda_scs_manager')->willReturn($this->logger->reveal());

    $this->messenger = $this->prophesize(MessengerInterface::class);

    $serviceKey = $this->prophesize(SodaScsServiceKeyInterface::class);
    $serviceKey->get('servicePassword')->willReturn($this->fieldList('not-a-password'));
    $serviceKeyActions = $this->prophesize(SodaScsServiceKeyActionsInterface::class);
    $serviceKeyActions->getServiceKey(Argument::type('array'))->willReturn($serviceKey->reveal());

    $this->sqlServiceActions = $this->prophesize(SodaScsServiceActionsInterface::class);
    $this->keycloakHelpers = $this->prophesize(SodaScsKeycloakHelpers::class);
    $projectHelpers = $this->prophesize(SodaScsProjectHelpers::class);
    $projectHelpers->getUserSsoUuid(Argument::any())->willReturn(NULL);

    $this->actions = new SodaScsSqlComponentActions(
      $this->bundleInfo->reveal(),
      $configFactory->reveal(),
      $this->prophesize(Connection::class)->reveal(),
      $entityTypeManager->reveal(),
      $this->prophesize(ClientInterface::class)->reveal(),
      $loggerFactory->reveal(),
      $this->messenger->reveal(),
      $this->prophesize(SodaScsComponentHelpers::class)->reveal(),
      $this->prophesize(SodaScsContainerHelpers::class)->reveal(),
      $this->prophesize(SodaScsHelpers::class)->reveal(),
      $this->keycloakHelpers->reveal(),
      $projectHelpers->reveal(),
      $this->prophesize(SodaScsSnapshotHelpers::class)->reveal(),
      $this->prophesize(SodaScsDockerExecServiceActions::class)->reveal(),
      $this->prophesize(SodaScsDockerRunServiceActions::class)->reveal(),
      $this->sqlServiceActions->reveal(),
      $serviceKeyActions->reveal(),
      $this->getStringTranslationStub(),
      $this->prophesize(SodaScsDatabaseHelpers::class)->reveal(),
    );
  }

  /**
   * An existing database is a user-facing failure, not an empty array.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentReturnsUserFacingReasonWhenDatabaseExists(): void {
    $this->sqlServiceActions->existService('sql-foo')->willReturn(['execStatus' => 0, 'result' => TRUE]);
    $this->sqlServiceActions->createService(Argument::cetera())->shouldNotBeCalled();
    $this->sqlServiceActions->grantServiceRights(Argument::cetera())->shouldNotBeCalled();
    $this->messenger->addError(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->entity());

    $this->assertNotSame([], $result);
    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(SodaScsUserFacingException::class, $result['error']);
    $this->assertSame('Database sql-foo already exists.', $result['error']->getMessage());
    $this->assertSame($result['error']->getUserMessage(), $result['message']);
    $this->assertSame(
      'A database with this application name already exists. Choose another name.',
      $result['message']->getUntranslatedString(),
    );
  }

  /**
   * A SodaScsSqlServiceException no longer escapes createComponent().
   *
   * @covers ::createComponent
   */
  public function testCreateComponentCatchesSqlServiceExceptionOfGrant(): void {
    $this->sqlServiceActions->existService('sql-foo')->willReturn(['execStatus' => 0, 'result' => FALSE]);
    $this->sqlServiceActions->existServiceUser(Argument::any())->willReturn(['execStatus' => 0, 'result' => 1]);
    $this->sqlServiceActions->grantServiceRights('alice', 'sql-foo', ['ALL PRIVILEGES'])
      ->willReturn(['execStatus' => 1, 'command' => 'GRANT', 'output' => 'denied']);
    $this->sqlServiceActions->createService(Argument::cetera())->shouldNotBeCalled();
    $this->messenger->addError(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(SodaScsSqlServiceException::class, $result['error']);
    $this->assertSame('The database could not be created.', $result['message']->getUntranslatedString());
  }

  /**
   * A failing create database command is returned as a failure.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentCatchesSqlServiceExceptionOfCreateDatabase(): void {
    $this->sqlServiceActions->existService('sql-foo')->willReturn(['execStatus' => 0, 'result' => FALSE]);
    $this->sqlServiceActions->existServiceUser(Argument::any())->willReturn(['execStatus' => 0, 'result' => 1]);
    $this->sqlServiceActions->grantServiceRights(Argument::cetera())->willReturn(['execStatus' => 0]);
    $this->sqlServiceActions->createService(Argument::any())
      ->willReturn(['execStatus' => 1, 'command' => 'CREATE DATABASE', 'output' => 'boom']);
    $this->sqlComponent->save()->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(SodaScsSqlServiceException::class, $result['error']);
  }

  /**
   * A failing existence check is returned as a failure, nothing is created.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentFailsWhenExistenceCheckFails(): void {
    $this->sqlServiceActions->existService('sql-foo')
      ->willReturn(['execStatus' => 2, 'command' => 'SHOW DATABASES', 'output' => 'no connection']);
    $this->sqlServiceActions->createService(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(SodaScsSqlServiceException::class, $result['error']);
  }

  /**
   * An exception of the Keycloak step is returned, the component not saved.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentCatchesKeycloakFailure(): void {
    $this->sqlServiceActions->existService('sql-foo')->willReturn(['execStatus' => 0, 'result' => FALSE]);
    $this->sqlServiceActions->existServiceUser(Argument::any())->willReturn(['execStatus' => 0, 'result' => 1]);
    $this->sqlServiceActions->grantServiceRights(Argument::cetera())->willReturn(['execStatus' => 0]);
    $this->sqlServiceActions->createService(Argument::any())->willReturn(['execStatus' => 0]);
    $keycloakFailure = new \RuntimeException('Keycloak group request failed.');
    $this->keycloakHelpers
      ->createAndPopulateComponentAccessGroups('sql-foo', NULL, [])
      ->willThrow($keycloakFailure);
    $this->sqlComponent->save()->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertSame($keycloakFailure, $result['error']);
  }

  /**
   * A missing setting still yields the complete failure shape.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentFailsWhenDatabaseHostSettingIsMissing(): void {
    $this->settings->get('dbHost')->willReturn('');
    $this->sqlServiceActions->existService(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(MissingDataException::class, $result['error']);
    $this->assertSame('The database could not be created.', $result['message']->getUntranslatedString());
  }

  /**
   * The failure is logged with the original exception.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentLogsTheFailure(): void {
    $this->sqlServiceActions->existService('sql-foo')->willReturn(['execStatus' => 0, 'result' => TRUE]);
    $this->logger->log('error', Argument::type('string'), Argument::type('array'))->shouldBeCalledOnce();

    $result = $this->actions->createComponent($this->entity());

    $this->assertFalse($result['success']);
  }

  /**
   * A missing bundle info is returned as a failure.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentFailsWhenBundleInfoIsMissing(): void {
    $this->bundleInfo->getBundleInfo('soda_scs_component')->willReturn(['soda_scs_sql_component' => []]);

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(\Exception::class, $result['error']);
  }

  /**
   * Builds the component the stack hands to createComponent().
   */
  protected function entity(): SodaScsComponentInterface {
    $owner = $this->prophesize(UserInterface::class);
    $owner->getDisplayName()->willReturn('alice');
    $entity = $this->prophesize(SodaScsComponentInterface::class);
    $entity->get('machineName')->willReturn($this->fieldList('foo'));
    $entity->get('label')->willReturn($this->fieldList('Foo'));
    $entity->get('partOfProjects')->willReturn($this->fieldList());
    $entity->getOwnerId()->willReturn(7);
    $entity->getOwner()->willReturn($owner->reveal());
    return $entity->reveal();
  }

}
