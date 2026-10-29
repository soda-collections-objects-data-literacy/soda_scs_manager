<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Regression test: stopContainer() must not stop a container that is not running.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers
 * @group soda_scs_manager
 */
class SodaScsContainerHelpersStopContainerTest extends UnitTestCase {

  /**
   * Container ID used by the tests.
   */
  protected const CONTAINER_ID = 'abc123';

  /**
   * @covers ::stopContainer
   */
  public function testStoppedContainerIsNotStoppedAgain(): void {
    $dockerRunActions = $this->createMock(SodaScsDockerRunServiceActions::class);
    $dockerRunActions->expects($this->never())->method('buildStopRequest');
    $dockerRunActions->expects($this->never())->method('makeRequest');
    $helpers = $this->createHelpers('exited', $dockerRunActions);
    $helpers->expects($this->never())->method('waitForContainerState');

    $result = $helpers->stopContainer(self::CONTAINER_ID);

    $this->assertTrue($result->success);
    $this->assertSame('Container already stopped.', $result->message);
  }

  /**
   * @covers ::stopContainer
   */
  public function testRunningContainerIsStopped(): void {
    $dockerRunActions = $this->createMock(SodaScsDockerRunServiceActions::class);
    $dockerRunActions->expects($this->once())->method('buildStopRequest')->willReturn(['method' => 'POST']);
    $dockerRunActions->expects($this->once())->method('makeRequest')->willReturn(['success' => TRUE]);
    $helpers = $this->createHelpers('running', $dockerRunActions);
    $helpers->expects($this->once())
      ->method('waitForContainerState')
      ->with(self::CONTAINER_ID, 'exited')
      ->willReturn(SodaScsResult::success(data: [], message: 'exited'));

    $result = $helpers->stopContainer(self::CONTAINER_ID);

    $this->assertTrue($result->success);
    $this->assertSame('Container stopped successfully.', $result->message);
  }

  /**
   * Helpers whose inspect call reports the given container status.
   */
  protected function createHelpers(string $status, SodaScsDockerRunServiceActions $dockerRunActions): SodaScsContainerHelpers&MockObject {
    $helpers = $this->createPartialMock(SodaScsContainerHelpers::class, ['inspectContainer', 'waitForContainerState']);
    $helpers->method('inspectContainer')->willReturn(SodaScsResult::success(
      data: [self::CONTAINER_ID => ['State' => ['Status' => $status]]],
      message: 'inspected',
    ));
    $property = new \ReflectionProperty(SodaScsContainerHelpers::class, 'sodaScsDockerRunServiceActions');
    $property->setValue($helpers, $dockerRunActions);
    return $helpers;
  }

}
