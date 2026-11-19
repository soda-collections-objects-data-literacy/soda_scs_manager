<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Service;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Service\SodaScsWisskiVarnishCache;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Service\SodaScsWisskiVarnishCache
 * @group soda_scs_manager
 */
class SodaScsWisskiVarnishCacheTest extends UnitTestCase {

  /**
   * @covers ::varnishContainerName
   */
  public function testVarnishContainerName(): void {
    $service = $this->createService($this->createMock(SodaScsContainerHelpers::class));

    $this->assertSame('wisski-demo--varnish', $service->varnishContainerName('wisski-demo'));
  }

  /**
   * @covers ::clear
   */
  public function testClearRejectsInvalidMachineNameWithoutDocker(): void {
    $helpers = $this->createMock(SodaScsContainerHelpers::class);
    $helpers->expects($this->never())->method('findLiveContainerByName');
    $helpers->expects($this->never())->method('executeDockerExecCommand');

    $result = $this->createService($helpers)->clear('WissKI Demo');

    $this->assertFalse($result->success);
    $this->assertSame('This WissKI instance has no valid machine name.', $result->message);
  }

  /**
   * @covers ::clear
   */
  public function testClearRejectsStoppedContainer(): void {
    $helpers = $this->createMock(SodaScsContainerHelpers::class);
    $helpers->expects($this->once())
      ->method('findLiveContainerByName')
      ->with('wisski-demo--varnish')
      ->willReturn(SodaScsResult::success(
        data: [
          'containerId' => 'abc',
          'containerName' => 'wisski-demo--varnish',
          'state' => 'exited',
        ],
        message: 'resolved',
      ));
    $helpers->expects($this->never())->method('executeDockerExecCommand');

    $result = $this->createService($helpers)->clear('wisski-demo');

    $this->assertFalse($result->success);
    $this->assertSame('The Varnish container of this WissKI instance is not running.', $result->message);
  }

  /**
   * @covers ::clear
   */
  public function testClearBansEveryCachedUrl(): void {
    $helpers = $this->createMock(SodaScsContainerHelpers::class);
    $helpers->method('findLiveContainerByName')->willReturn(SodaScsResult::success(
      data: [
        'containerId' => 'abc',
        'containerName' => 'wisski-demo--varnish',
        'state' => 'running',
      ],
      message: 'resolved',
    ));
    $helpers->expects($this->once())
      ->method('executeDockerExecCommand')
      ->with([
        'cmd' => SodaScsWisskiVarnishCache::BAN_COMMAND,
        'containerName' => 'abc',
        'timeout' => 30,
        'user' => 'root',
      ])
      ->willReturn(SodaScsResult::success(data: ['exitCode' => 0], message: 'ok'));

    $result = $this->createService($helpers)->clear('wisski-demo');

    $this->assertTrue($result->success);
    $this->assertSame('wisski-demo--varnish', $result->data['containerName']);
  }

  /**
   * @covers ::restart
   */
  public function testRestartRejectsInvalidMachineNameWithoutDocker(): void {
    $this->useLoggerFactoryForFailureResults($this->createMock(LoggerChannelInterface::class));
    $helpers = $this->createMock(SodaScsContainerHelpers::class);
    $helpers->expects($this->never())->method('findLiveContainerByName');
    $helpers->expects($this->never())->method('restartContainer');

    $result = $this->createService($helpers)->restart('WissKI Demo');

    $this->assertFalse($result->success);
    $this->assertSame('Invalid WissKI machine name.', $result->error);
  }

  /**
   * A WissKI without a Varnish container has nothing to restart.
   *
   * @covers ::restart
   */
  public function testRestartSucceedsWithoutVarnishContainer(): void {
    $helpers = $this->createMock(SodaScsContainerHelpers::class);
    $helpers->expects($this->once())
      ->method('findLiveContainerByName')
      ->with('wisski-demo--varnish')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'Container not found for name: wisski-demo--varnish', 'not found'));
    $helpers->expects($this->never())->method('restartContainer');

    $result = $this->createService($helpers)->restart('wisski-demo');

    $this->assertTrue($result->success);
    $this->assertSame('wisski-demo--varnish', $result->data['containerName']);
  }

  /**
   * Another lookup error is no proof that nothing runs: it is a failure.
   *
   * @covers ::restart
   */
  public function testRestartFailsWhenTheLookupFailsForAnotherReason(): void {
    $this->useLoggerFactoryForFailureResults($this->createMock(LoggerChannelInterface::class));
    $helpers = $this->createMock(SodaScsContainerHelpers::class);
    $helpers->method('findLiveContainerByName')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'Portainer answered 500.', 'failed'));
    $helpers->expects($this->never())->method('restartContainer');

    $result = $this->createService($helpers)->restart('wisski-demo');

    $this->assertFalse($result->success);
    $this->assertSame('Portainer answered 500.', $result->error);
  }

  /**
   * A stopped Varnish container is left stopped.
   *
   * @covers ::restart
   */
  public function testRestartLeavesStoppedContainerAlone(): void {
    $helpers = $this->createMock(SodaScsContainerHelpers::class);
    $helpers->method('findLiveContainerByName')->willReturn(SodaScsResult::success(
      data: ['containerId' => 'abc', 'state' => 'exited'],
      message: 'resolved',
    ));
    $helpers->expects($this->never())->method('restartContainer');

    $result = $this->createService($helpers)->restart('wisski-demo');

    $this->assertTrue($result->success);
    $this->assertSame('Varnish container is not running.', $result->message);
  }

  /**
   * A running Varnish container is restarted by its ID.
   *
   * @covers ::restart
   */
  public function testRestartRestartsRunningContainer(): void {
    $helpers = $this->createMock(SodaScsContainerHelpers::class);
    $helpers->method('findLiveContainerByName')->willReturn(SodaScsResult::success(
      data: ['containerId' => 'abc', 'state' => 'running'],
      message: 'resolved',
    ));
    $helpers->expects($this->once())
      ->method('restartContainer')
      ->with('abc')
      ->willReturn(SodaScsResult::success(data: [], message: 'restarted'));

    $result = $this->createService($helpers)->restart('wisski-demo');

    $this->assertTrue($result->success);
    $this->assertSame('wisski-demo--varnish', $result->data['containerName']);
  }

  /**
   * A failing restart is an error in the log and a failure for the caller.
   *
   * @covers ::restart
   */
  public function testRestartFailureIsLoggedAndReturned(): void {
    $helpers = $this->createMock(SodaScsContainerHelpers::class);
    $helpers->method('findLiveContainerByName')->willReturn(SodaScsResult::success(
      data: ['containerId' => 'abc', 'state' => 'running'],
      message: 'resolved',
    ));
    $helpers->method('restartContainer')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'Docker answered 500.', 'failed'));
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error')->with(
      $this->stringContains('Restarting Varnish container'),
      ['@container' => 'wisski-demo--varnish', '@error' => 'Docker answered 500.', '@name' => 'wisski-demo'],
    );

    $result = $this->createService($helpers, $logger)->restart('wisski-demo');

    $this->assertFalse($result->success);
    $this->assertSame('Docker answered 500.', $result->error);
  }

  /**
   * Lets failure results log through \Drupal::logger() into a double.
   */
  private function useLoggerFactoryForFailureResults(LoggerChannelInterface $logger): void {
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Service with a translation stub so messages stay the source strings.
   */
  private function createService(SodaScsContainerHelpers $helpers, ?LoggerChannelInterface $logger = NULL): SodaScsWisskiVarnishCache {
    $service = new SodaScsWisskiVarnishCache(
      $logger ?? $this->createMock(LoggerChannelInterface::class),
      $helpers,
    );
    $service->setStringTranslation($this->getStringTranslationStub());
    return $service;
  }

}
