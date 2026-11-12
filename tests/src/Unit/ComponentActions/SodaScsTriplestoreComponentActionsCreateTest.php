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

    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->create(Argument::type('array'))->willReturn($this->component->reveal());
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $entityTypeManager->getStorage('soda_scs_component')->willReturn($storage->reveal());

    $configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $configFactory->getEditable('soda_scs_manager.settings')->willReturn($this->prophesize(Config::class)->reveal());

    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('soda_scs_manager')->willReturn($this->prophesize(LoggerChannelInterface::class)->reveal());

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

    $result = $this->actions->createComponent($this->entity());

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
    $this->assertSame('The access groups for the triplestore could not be created.', $result['message']->getUntranslatedString());
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
