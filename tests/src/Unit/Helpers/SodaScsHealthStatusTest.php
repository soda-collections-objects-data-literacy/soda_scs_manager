<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\soda_scs_manager\Helpers\SodaScsHealthStatus;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SodaScsHealthStatus.
 */
#[CoversMethod(SodaScsHealthStatus::class, 'fromDockerState')]
#[CoversMethod(SodaScsHealthStatus::class, 'fromHttpCode')]
#[CoversMethod(SodaScsHealthStatus::class, 'fromStatus')]
#[CoversMethod(SodaScsHealthStatus::class, 'combine')]
#[CoversMethod(SodaScsHealthStatus::class, 'normalizePayload')]
#[CoversMethod(SodaScsHealthStatus::class, 'duringSetup')]
#[CoversMethod(SodaScsHealthStatus::class, 'fromDockerInspect')]
#[Group('soda_scs_manager')]
class SodaScsHealthStatusTest extends UnitTestCase {

  /**
   * Tests fromDockerState().
   */
  #[DataProvider('dockerStateProvider')]
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
   * Tests fromHttpCode().
   */
  #[DataProvider('httpCodeProvider')]
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
   * Tests fromStatus().
   */
  #[DataProvider('statusAliasProvider')]
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
   * Tests that combine() container stopped skips HTTP.
   */
  public function testCombineContainerStoppedSkipsHttp(): void {
    $this->assertSame(
      SodaScsHealthStatus::STOPPED,
      SodaScsHealthStatus::combine('exited', 137, NULL, 200, TRUE),
    );
  }

  /**
   * Tests that combine() running uses HTTP.
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
   * Tests that normalizePayload() rewrites legacy status.
   */
  public function testNormalizePayloadRewritesLegacyStatus(): void {
    // normalizePayload() casts the status label (TranslatableMarkup) to string.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

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
   * Tests duringSetup().
   */
  #[DataProvider('duringSetupProvider')]
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
   * Tests that fromDockerInspect() reads nested health.
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
