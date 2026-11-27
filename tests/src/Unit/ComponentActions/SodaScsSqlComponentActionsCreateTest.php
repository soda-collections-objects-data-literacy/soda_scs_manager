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
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Failure results of SodaScsSqlComponentActions::createComponent().
 *
 * Issue 3637658: every failure returns success FALSE, an error and a
 * translated message; nothing escapes the method.
 */
#[CoversMethod(SodaScsSqlComponentActions::class, 'createComponent')]
#[CoversMethod(SodaScsSqlComponentActions::class, 'rollBackCreateComponent')]
#[CoversMethod(SodaScsSqlComponentActions::class, 'deleteComponent')]
#[Group('soda_scs_manager')]
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
    $this->sqlComponent->get('machineName')->willReturn($this->fieldList('sql-foo'));
    $this->sqlComponent->id()->willReturn(NULL);
    $this->sqlComponent->delete()->willReturn(NULL);

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
   */
  public function testCreateComponentCatchesKeycloakFailure(): void {
    $this->databaseCanBeCreated();
    $keycloakFailure = new \RuntimeException('Keycloak group request failed.');
    $this->keycloakHelpers
      ->createAndPopulateComponentAccessGroups('sql-foo', NULL, [])
      ->willThrow($keycloakFailure);
    $this->databaseRollsBack();
    $this->sqlComponent->save()->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertSame($keycloakFailure, $result['error']);
  }

  /**
   * A failure after the database was created deletes it again.
   */
  public function testCreateComponentDeletesDatabaseWhenKeycloakGroupsFail(): void {
    $this->databaseCanBeCreated();
    $this->keycloakHelpers->createAndPopulateComponentAccessGroups(Argument::cetera())
      ->willThrow(new \RuntimeException('Keycloak group request failed.'));
    $this->sqlServiceActions->deleteService($this->sqlComponent->reveal())
      ->willReturn(['success' => TRUE])
      ->shouldBeCalledOnce();
    $this->keycloakHelpers->deleteComponentAccessGroups('sql-foo')
      ->willReturn([
        'adminGroupName' => 'sql-foo-admin',
        'userGroupName' => 'sql-foo-user',
        'adminDeleted' => FALSE,
        'userDeleted' => FALSE,
      ])
      ->shouldBeCalledOnce();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertSame('Keycloak group request failed.', $result['error']->getMessage());
  }

  /**
   * A failure when the component is saved deletes the database as well.
   */
  public function testCreateComponentDeletesDatabaseWhenSavingTheComponentFails(): void {
    $this->databaseCanBeCreated();
    $this->sqlComponent->save()->willThrow(new \RuntimeException('Storage failed.'));
    $this->sqlServiceActions->deleteService($this->sqlComponent->reveal())
      ->willReturn(['success' => TRUE])
      ->shouldBeCalledOnce();
    $this->keycloakHelpers->deleteComponentAccessGroups('sql-foo')->willReturn([
      'adminGroupName' => 'sql-foo-admin',
      'userGroupName' => 'sql-foo-user',
      'adminDeleted' => TRUE,
      'userDeleted' => TRUE,
    ])->shouldBeCalledOnce();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertSame('Storage failed.', $result['error']->getMessage());
  }

  /**
   * A failed rollback is logged and does not hide the original failure.
   */
  public function testCreateComponentReturnsOriginalFailureWhenRollbackFails(): void {
    $this->databaseCanBeCreated();
    $keycloakFailure = new \RuntimeException('Keycloak group request failed.');
    $this->keycloakHelpers->createAndPopulateComponentAccessGroups(Argument::cetera())->willThrow($keycloakFailure);
    $this->sqlServiceActions->deleteService($this->sqlComponent->reveal())
      ->willReturn(['success' => FALSE, 'error' => 'Access denied.']);
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
    // The creation failure and the failed delete are logged through log().
    $this->logger->log('error', Argument::type('string'), Argument::type('array'))->shouldBeCalledTimes(2);
    $this->messenger->addError(Argument::cetera())->willReturn($this->messenger->reveal());
    $this->logger->error(Argument::containingString('Could not remove the database'), Argument::withEntry('@machineName', 'sql-foo'))->shouldBeCalledOnce();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertSame($keycloakFailure, $result['error']);
  }

  /**
   * Failures before the database exists delete nothing.
   */
  #[DataProvider('failureBeforeDatabaseCreationProvider')]
  public function testCreateComponentDeletesNothingWhenItFailsBeforeCreatingTheDatabase(array $createResult): void {
    $this->sqlServiceActions->existService('sql-foo')->willReturn(['execStatus' => 0, 'result' => FALSE]);
    $this->sqlServiceActions->existServiceUser(Argument::any())->willReturn(['execStatus' => 0, 'result' => 1]);
    $this->sqlServiceActions->grantServiceRights(Argument::cetera())->willReturn(['execStatus' => 0]);
    $this->sqlServiceActions->createService(Argument::any())->willReturn($createResult);
    $this->sqlServiceActions->deleteService(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
  }

  /**
   * Failed answers of the create database command.
   *
   * @return array<string, array>
   *   The answer of createService().
   */
  public static function failureBeforeDatabaseCreationProvider(): array {
    return [
      'create command failed' => [['execStatus' => 1, 'command' => 'CREATE DATABASE', 'output' => 'boom']],
    ];
  }

  /**
   * An existing database is never deleted by the rollback.
   */
  public function testCreateComponentDoesNotDeleteExistingDatabase(): void {
    $this->sqlServiceActions->existService('sql-foo')->willReturn(['execStatus' => 0, 'result' => TRUE]);
    $this->sqlServiceActions->deleteService(Argument::any())->shouldNotBeCalled();
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
  }

  /**
   * A database that cannot be deleted keeps the component and the groups.
   */
  #[DataProvider('failedDatabaseDeleteProvider')]
  public function testDeleteComponentKeepsComponentAndGroupsWhenDatabaseDeleteFails(array $deleteResult, string $expectedError): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->id()->willReturn(5);
    $component->get('machineName')->willReturn($this->fieldList('sql-foo'));
    $this->sqlServiceActions->deleteService($component->reveal())->willReturn($deleteResult);
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->shouldNotBeCalled();
    $component->delete()->shouldNotBeCalled();
    $this->messenger->addError(Argument::cetera())->shouldBeCalledOnce();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame($expectedError, $result['error']);
  }

  /**
   * Delete answers that are no success.
   *
   * @return array<string, array>
   *   The answer of deleteService() and the error that is returned.
   */
  public static function failedDatabaseDeleteProvider(): array {
    return [
      'failure with reason' => [
        ['success' => FALSE, 'error' => 'Access denied.'],
        'Database of component 5 could not be deleted: Access denied.',
      ],
      'failure without reason' => [
        ['success' => FALSE],
        'Database of component 5 could not be deleted: no details',
      ],
      'answer without success flag' => [
        [],
        'Database of component 5 could not be deleted: no details',
      ],
    ];
  }

  /**
   * A deleted database takes the groups and the component with it.
   */
  public function testDeleteComponentDeletesDatabaseGroupsAndComponent(): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->get('machineName')->willReturn($this->fieldList('sql-foo'));
    $this->sqlServiceActions->deleteService($component->reveal())->willReturn(['success' => TRUE]);
    $this->keycloakHelpers->deleteComponentAccessGroups('sql-foo')->willReturn([
      'adminGroupName' => 'sql-foo-admin',
      'userGroupName' => 'sql-foo-user',
      'adminDeleted' => TRUE,
      'userDeleted' => TRUE,
    ])->shouldBeCalledOnce();
    $component->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteComponent($component->reveal());

    $this->assertTrue($result['success']);
  }

  /**
   * A missing setting still yields the complete failure shape.
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
   */
  public function testCreateComponentLogsTheFailure(): void {
    $this->sqlServiceActions->existService('sql-foo')->willReturn(['execStatus' => 0, 'result' => TRUE]);
    $this->logger->log('error', Argument::type('string'), Argument::type('array'))->shouldBeCalledOnce();

    $result = $this->actions->createComponent($this->entity());

    $this->assertFalse($result['success']);
  }

  /**
   * A missing bundle info is returned as a failure.
   */
  public function testCreateComponentFailsWhenBundleInfoIsMissing(): void {
    $this->bundleInfo->getBundleInfo('soda_scs_component')->willReturn(['soda_scs_sql_component' => []]);

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertInstanceOf(\Exception::class, $result['error']);
  }

  /**
   * Lets every step up to and including the database creation succeed.
   */
  protected function databaseCanBeCreated(): void {
    $this->sqlServiceActions->existService('sql-foo')->willReturn(['execStatus' => 0, 'result' => FALSE]);
    $this->sqlServiceActions->existServiceUser(Argument::any())->willReturn(['execStatus' => 0, 'result' => 1]);
    $this->sqlServiceActions->grantServiceRights(Argument::cetera())->willReturn(['execStatus' => 0]);
    $this->sqlServiceActions->createService(Argument::any())->willReturn(['execStatus' => 0]);
    $this->keycloakHelpers->createAndPopulateComponentAccessGroups(Argument::cetera())->willReturn([]);
  }

  /**
   * Lets the rollback delete the database and the groups.
   */
  protected function databaseRollsBack(): void {
    $this->sqlServiceActions->deleteService(Argument::any())->willReturn(['success' => TRUE]);
    $this->keycloakHelpers->deleteComponentAccessGroups(Argument::any())->willReturn([
      'adminGroupName' => 'sql-foo-admin',
      'userGroupName' => 'sql-foo-user',
      'adminDeleted' => FALSE,
      'userDeleted' => FALSE,
    ]);
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
