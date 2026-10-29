<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Service;

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
   * Service with a translation stub so messages stay the source strings.
   */
  private function createService(SodaScsContainerHelpers $helpers): SodaScsWisskiVarnishCache {
    $service = new SodaScsWisskiVarnishCache(
      $this->createMock(LoggerChannelInterface::class),
      $helpers,
    );
    $service->setStringTranslation($this->getStringTranslationStub());
    return $service;
  }

}
