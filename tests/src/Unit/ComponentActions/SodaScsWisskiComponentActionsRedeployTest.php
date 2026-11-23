<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsPortainerServiceActions;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Redeploying a WissKI stack with some environment values replaced.
 *
 * Issue 3640913: handing a project to another owner redeploys its WissKI with
 * the database, triplestore and Nextcloud accounts of the new owner. The
 * Portainer and container calls are doubles.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions
 * @group soda_scs_manager
 */
class SodaScsWisskiComponentActionsRedeployTest extends UnitTestCase {

  /**
   * The Portainer request actions double.
   */
  private SodaScsPortainerServiceActions&MockObject $portainer;

  /**
   * The container helpers double.
   */
  private SodaScsContainerHelpers&MockObject $containerHelpers;

  /**
   * Requests that were sent to Portainer, as built by the request actions.
   *
   * @var list<string>
   */
  private array $sentRequests = [];

  /**
   * Parameters of the redeploy request.
   *
   * @var array<string, mixed>|null
   */
  private ?array $redeployParams = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sentRequests = [];
    $this->redeployParams = NULL;
    $this->portainer = $this->createMock(SodaScsPortainerServiceActions::class);
    $this->portainer->method('buildGetRequest')->willReturn(['request' => 'get']);
    $this->portainer->method('buildGitRedeployRequest')->willReturnCallback(function (array $params): array {
      $this->redeployParams = $params;
      return ['request' => 'redeploy'];
    });
    $this->containerHelpers = $this->createMock(SodaScsContainerHelpers::class);

    // Failure results log through \Drupal::logger().
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);

    $this->containerHelpers->method('syncComponentContainerId')->willReturn(SodaScsResult::success(data: ['containerId' => 'new'], message: 'synced'));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * The given values replace the stack variables, all others stay.
   *
   * @covers ::redeployWithEnvironment
   */
  public function testEnvironmentValuesAreMergedIntoTheStackEnvironment(): void {
    $this->portainerAnswers([
      'Env' => [
        ['name' => 'DB_USER', 'value' => 'alice'],
        ['name' => 'DB_PASSWORD', 'value' => 'old-secret'],
        ['name' => 'MODE', 'value' => 'production'],
      ],
      'GitConfig' => ['ReferenceName' => 'refs/tags/1.2.3'],
    ]);
    $this->containerReplacedByTheRedeploy();

    $result = $this->actions()->redeployWithEnvironment($this->component(), [
      'DB_USER' => 'scs_demo',
      'TS_USERNAME' => 'scs_demo',
    ]);

    $this->assertTrue($result->success, (string) $result->error);
    $this->assertSame([
      ['name' => 'DB_USER', 'value' => 'scs_demo'],
      ['name' => 'DB_PASSWORD', 'value' => 'old-secret'],
      ['name' => 'MODE', 'value' => 'production'],
      ['name' => 'TS_USERNAME', 'value' => 'scs_demo'],
    ], $this->redeployParams['env']);
  }

  /**
   * The compose reference stays and the image is not pulled again.
   *
   * @covers ::redeployWithEnvironment
   */
  public function testRedeployKeepsReferenceAndImage(): void {
    $this->portainerAnswers(['Env' => [], 'GitConfig' => ['ReferenceName' => 'refs/tags/1.2.3']]);
    $this->containerReplacedByTheRedeploy();

    $this->actions()->redeployWithEnvironment($this->component(), ['DB_USER' => 'scs_demo']);

    $this->assertSame('refs/tags/1.2.3', $this->redeployParams['wisskiComposeStackVersion']);
    $this->assertFalse($this->redeployParams['repullImageAndRedeploy']);
    $this->assertSame(['stackId' => '42'], $this->redeployParams['routeParams']);
  }

  /**
   * A component without Portainer stack id sends nothing.
   *
   * @covers ::redeployWithEnvironment
   */
  public function testComponentWithoutStackIdFailsWithoutRequests(): void {
    $this->portainer->expects($this->never())->method('makeRequest');

    $result = $this->actions()->redeployWithEnvironment($this->component(''), ['DB_USER' => 'scs_demo']);

    $this->assertFalse($result->success);
  }

  /**
   * A failing read of the stack redeploys nothing.
   *
   * @covers ::redeployWithEnvironment
   */
  public function testFailedStackReadSendsNoRedeploy(): void {
    $this->portainer->method('makeRequest')->willReturnCallback(function (array $request): array {
      $this->sentRequests[] = $request['request'];
      return ['success' => FALSE, 'error' => 'HTTP 500'];
    });

    $result = $this->actions()->redeployWithEnvironment($this->component(), ['DB_USER' => 'scs_demo']);

    $this->assertFalse($result->success);
    $this->assertSame(['get'], $this->sentRequests);
  }

  /**
   * A malformed stack answer redeploys nothing; the environment is not guessed.
   *
   * @covers ::redeployWithEnvironment
   */
  public function testMalformedStackAnswerSendsNoRedeploy(): void {
    $this->portainer->method('makeRequest')->willReturnCallback(function (array $request): array {
      $this->sentRequests[] = $request['request'];
      return ['success' => TRUE, 'data' => ['portainerResponse' => new Response(200, [], 'not json')]];
    });

    $result = $this->actions()->redeployWithEnvironment($this->component(), ['DB_USER' => 'scs_demo']);

    $this->assertFalse($result->success);
    $this->assertSame(['get'], $this->sentRequests);
    $this->assertNull($this->redeployParams);
  }

  /**
   * A refused redeploy is a failure and does not wait for a container.
   *
   * @covers ::redeployWithEnvironment
   */
  public function testRefusedRedeployFailsWithoutWaiting(): void {
    $this->portainer->method('makeRequest')->willReturnCallback(function (array $request): array {
      $this->sentRequests[] = $request['request'];
      return $request['request'] === 'get'
        ? ['success' => TRUE, 'data' => ['portainerResponse' => new Response(200, [], json_encode(['Env' => []]))]]
        : ['success' => FALSE, 'error' => 'HTTP 500'];
    });
    $this->containerHelpers->method('findLiveContainerByName')->willReturn(new SodaScsResult(FALSE, NULL, 'none', 'none'));
    $this->containerHelpers->expects($this->never())->method('syncComponentContainerId');

    $result = $this->actions()->redeployWithEnvironment($this->component(), ['DB_USER' => 'scs_demo']);

    $this->assertFalse($result->success);
    $this->assertSame(['get', 'redeploy'], $this->sentRequests);
  }

  /**
   * Lets Portainer answer the stack read with the payload, the redeploy OK.
   *
   * @param array<string, mixed> $stackPayload
   *   The stack as Portainer returns it.
   */
  private function portainerAnswers(array $stackPayload): void {
    $this->portainer->method('makeRequest')->willReturnCallback(function (array $request) use ($stackPayload): array {
      $this->sentRequests[] = $request['request'];
      return $request['request'] === 'get'
        ? ['success' => TRUE, 'data' => ['portainerResponse' => new Response(200, [], json_encode($stackPayload))]]
        : ['success' => TRUE, 'data' => []];
    });
  }

  /**
   * The old container is found first, then a new one that is running.
   */
  private function containerReplacedByTheRedeploy(): void {
    $calls = 0;
    $this->containerHelpers->method('findLiveContainerByName')->willReturnCallback(static function () use (&$calls): SodaScsResult {
      $calls++;
      $id = $calls === 1 ? 'old' : 'new';
      return SodaScsResult::success(data: ['containerId' => $id, 'state' => 'running'], message: 'found');
    });
  }

  /**
   * Builds the service with a double for every constructor argument.
   */
  private function actions(): SodaScsWisskiComponentActions {
    $helpers = $this->createMock(SodaScsHelpers::class);
    $helpers->method('adjustMaxAttempts')->willReturn(3);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('getEditable')->willReturn($this->createMock(Config::class));
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $arguments = [];
    foreach ((new \ReflectionMethod(SodaScsWisskiComponentActions::class, '__construct'))->getParameters() as $parameter) {
      $type = $parameter->getType()->getName();
      $arguments[] = match (TRUE) {
        $type === ConfigFactoryInterface::class => $configFactory,
        $type === LoggerChannelFactoryInterface::class => $loggerFactory,
        $type === SodaScsHelpers::class => $helpers,
        $type === SodaScsContainerHelpers::class => $this->containerHelpers,
        $type === TranslationInterface::class => $this->getStringTranslationStub(),
        $parameter->getName() === 'sodaScsPortainerServiceActions' => $this->portainer,
        default => $this->createMock($type),
      };
    }
    return new SodaScsWisskiComponentActions(...$arguments);
  }

  /**
   * Builds a WissKI component double.
   */
  private function component(string $stackId = '42'): SodaScsComponentInterface {
    $values = ['externalId' => $stackId, 'containerName' => 'wisski-demo--drupal', 'machineName' => 'wisski-demo'];
    $component = $this->createMock(SodaScsComponentInterface::class);
    $component->method('get')->willReturnCallback(function (string $field) use ($values): FieldItemList {
      $list = $this->createMock(FieldItemList::class);
      // The code reads the value with ??, which asks __isset first.
      $list->method('__isset')->willReturn(TRUE);
      $list->method('__get')->with('value')->willReturn($values[$field] ?? NULL);
      return $list;
    });
    return $component;
  }

}
