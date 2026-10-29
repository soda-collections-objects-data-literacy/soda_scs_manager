<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsHealthStatus;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsHealthStatus
 * @group soda_scs_manager
 */
class SodaScsHealthStatusTest extends UnitTestCase {

  /**
   * @covers ::fromDockerState
   * @dataProvider dockerStateProvider
   */
  public function testFromDockerState(string $status, ?int $exitCode, ?string $health, string $expected): void {
    $this->assertSame($expected, SodaScsHealthStatus::fromDockerState($status, $exitCode, $health));
  }

  /**
   * Docker inspect combinations and their canonical status.
   */
  public static function dockerStateProvider(): array {
    return [
      'running' => ['running', 0, NULL, SodaScsHealthStatus::RUNNING],
      'running healthy' => ['running', 0, 'healthy', SodaScsHealthStatus::RUNNING],
      'running unhealthy' => ['running', 0, 'unhealthy', SodaScsHealthStatus::PROBLEM],
      'running health starting' => ['running', 0, 'starting', SodaScsHealthStatus::STARTING],
      'created' => ['created', 0, NULL, SodaScsHealthStatus::STOPPED],
      'paused' => ['paused', 0, NULL, SodaScsHealthStatus::STOPPED],
      'exited zero' => ['exited', 0, NULL, SodaScsHealthStatus::STOPPED],
      'exited sigkill' => ['exited', 137, NULL, SodaScsHealthStatus::STOPPED],
      'removing' => ['removing', 0, NULL, SodaScsHealthStatus::STOPPED],
      'dead' => ['dead', 1, NULL, SodaScsHealthStatus::PROBLEM],
      'restarting' => ['restarting', 0, NULL, SodaScsHealthStatus::STARTING],
      'empty' => ['', NULL, NULL, SodaScsHealthStatus::UNKNOWN],
      'garbage' => ['foo', NULL, NULL, SodaScsHealthStatus::UNKNOWN],
    ];
  }

  /**
   * @covers ::fromHttpCode
   * @dataProvider httpCodeProvider
   */
  public function testFromHttpCode(int $code, bool $containerRunning, string $expected): void {
    $this->assertSame($expected, SodaScsHealthStatus::fromHttpCode($code, $containerRunning));
  }

  /**
   * HTTP probe combinations and their canonical status.
   */
  public static function httpCodeProvider(): array {
    return [
      '200' => [200, TRUE, SodaScsHealthStatus::RUNNING],
      '302' => [302, FALSE, SodaScsHealthStatus::RUNNING],
      '401' => [401, TRUE, SodaScsHealthStatus::RUNNING],
      '403' => [403, FALSE, SodaScsHealthStatus::RUNNING],
      '404' => [404, TRUE, SodaScsHealthStatus::UNKNOWN],
      '502 container up' => [502, TRUE, SodaScsHealthStatus::STARTING],
      '503 container up' => [503, TRUE, SodaScsHealthStatus::STARTING],
      '502 no container' => [502, FALSE, SodaScsHealthStatus::UNKNOWN],
      '503 no container' => [503, FALSE, SodaScsHealthStatus::UNKNOWN],
      '500' => [500, TRUE, SodaScsHealthStatus::PROBLEM],
      '504' => [504, FALSE, SodaScsHealthStatus::PROBLEM],
      'no response container up' => [0, TRUE, SodaScsHealthStatus::PROBLEM],
      'no response no container' => [0, FALSE, SodaScsHealthStatus::UNKNOWN],
    ];
  }

  /**
   * @covers ::fromStatus
   * @dataProvider statusAliasProvider
   */
  public function testFromStatus(?string $status, string $expected): void {
    $this->assertSame($expected, SodaScsHealthStatus::fromStatus($status));
  }

  /**
   * Legacy and alias status strings.
   */
  public static function statusAliasProvider(): array {
    return [
      'canonical running' => ['running', SodaScsHealthStatus::RUNNING],
      'healthy' => ['Healthy', SodaScsHealthStatus::RUNNING],
      'available' => ['available', SodaScsHealthStatus::RUNNING],
      'paused' => ['paused', SodaScsHealthStatus::STOPPED],
      'unhealthy' => ['unhealthy', SodaScsHealthStatus::PROBLEM],
      'unavailable' => ['unavailable', SodaScsHealthStatus::PROBLEM],
      'starting' => ['starting', SodaScsHealthStatus::STARTING],
      'restarting' => ['restarting', SodaScsHealthStatus::STARTING],
      'not found' => ['not_found', SodaScsHealthStatus::UNKNOWN],
      'empty' => ['', SodaScsHealthStatus::UNKNOWN],
      'null' => [NULL, SodaScsHealthStatus::UNKNOWN],
    ];
  }

  /**
   * @covers ::combine
   */
  public function testCombineContainerStoppedSkipsHttp(): void {
    $this->assertSame(
      SodaScsHealthStatus::STOPPED,
      SodaScsHealthStatus::combine('exited', 137, NULL, 200, TRUE),
    );
  }

  /**
   * @covers ::combine
   */
  public function testCombineRunningUsesHttp(): void {
    $this->assertSame(
      SodaScsHealthStatus::RUNNING,
      SodaScsHealthStatus::combine('running', 0, 'healthy', 200, TRUE),
    );
    $this->assertSame(
      SodaScsHealthStatus::STARTING,
      SodaScsHealthStatus::combine('running', 0, NULL, 502, TRUE),
    );
    $this->assertSame(
      SodaScsHealthStatus::UNKNOWN,
      SodaScsHealthStatus::combine('running', 0, NULL, 404, TRUE),
    );
  }

  /**
   * @covers ::normalizePayload
   */
  public function testNormalizePayloadRewritesLegacyStatus(): void {
    $payload = SodaScsHealthStatus::normalizePayload([
      'status' => 'healthy',
      'message' => 'Available',
      'success' => TRUE,
      'error' => '',
      'code' => 200,
    ]);
    $this->assertSame(SodaScsHealthStatus::RUNNING, $payload['status']);
    $this->assertTrue($payload['success']);
    $this->assertSame('Available', $payload['error']);
  }

  /**
   * @covers ::duringSetup
   * @dataProvider duringSetupProvider
   */
  public function testDuringSetup(string $status, bool $setupInProgress, string $expected): void {
    $this->assertSame($expected, SodaScsHealthStatus::duringSetup($status, $setupInProgress));
  }

  /**
   * Stopped during setup stays starting; every other status is unchanged.
   */
  public static function duringSetupProvider(): array {
    return [
      'stopped while setting up' => [SodaScsHealthStatus::STOPPED, TRUE, SodaScsHealthStatus::STARTING],
      'stopped when idle' => [SodaScsHealthStatus::STOPPED, FALSE, SodaScsHealthStatus::STOPPED],
      'running while setting up' => [SodaScsHealthStatus::RUNNING, TRUE, SodaScsHealthStatus::RUNNING],
      'already starting' => [SodaScsHealthStatus::STARTING, TRUE, SodaScsHealthStatus::STARTING],
      'problem while setting up' => [SodaScsHealthStatus::PROBLEM, TRUE, SodaScsHealthStatus::PROBLEM],
    ];
  }

  /**
   * @covers ::fromDockerInspect
   */
  public function testFromDockerInspectReadsNestedHealth(): void {
    $this->assertSame(
      SodaScsHealthStatus::PROBLEM,
      SodaScsHealthStatus::fromDockerInspect([
        'Status' => 'running',
        'ExitCode' => 0,
        'Health' => ['Status' => 'unhealthy'],
      ]),
    );
  }

}
