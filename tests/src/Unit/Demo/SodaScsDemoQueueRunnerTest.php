<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoQueueRunner;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests which log file a demo queue run writes to.
 *
 * A run started by root could not open the web server's log in the sticky
 * /tmp, so it never started (#3638553).
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsDemoQueueRunner
 * @group soda_scs_manager
 */
class SodaScsDemoQueueRunnerTest extends UnitTestCase {

  /**
   * The web server user and an unknown user share LOG_FILE.
   *
   * @covers ::logFile
   * @dataProvider sharedLogUsers
   */
  public function testLogFileIsSharedForTheWebServer(string $user): void {
    $this->assertSame(SodaScsDemoQueueRunner::LOG_FILE, $this->runner($user)->logFile());
  }

  /**
   * Users that write the shared log file.
   */
  public static function sharedLogUsers(): array {
    return [
      'web server' => ['www-data'],
      'no posix extension' => [''],
    ];
  }

  /**
   * Every other user, root among them, gets a file of its own.
   *
   * @covers ::logFile
   * @dataProvider ownLogUsers
   */
  public function testLogFileIsOwnForOtherUsers(string $user, string $expected): void {
    $this->assertSame($expected, $this->runner($user)->logFile());
  }

  /**
   * Users with their own log file, and the file.
   */
  public static function ownLogUsers(): array {
    return [
      'root' => ['root', '/tmp/scs-demo-queue-root.log'],
      'developer' => ['developer', '/tmp/scs-demo-queue-developer.log'],
      'uid without passwd entry' => ['1234', '/tmp/scs-demo-queue-1234.log'],
      'unsafe characters' => ['a.b c/..', '/tmp/scs-demo-queue-a_b_c___.log'],
    ];
  }

  /**
   * The command redirects into the user's file.
   *
   * @covers ::start
   */
  public function testStartRedirectsIntoTheLogFileOfTheUser(): void {
    $runner = $this->runner('root', [FALSE, TRUE]);

    $result = $runner->start();

    $this->assertTrue($result->success);
    $this->assertStringContainsString(">> '/tmp/scs-demo-queue-root.log' 2>&1", $runner->spawnedCommand);
  }

  /**
   * A failed start names the log file that was used.
   *
   * @covers ::start
   */
  public function testFailedStartNamesTheLogFileOfTheUser(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with($this->anything(), $this->callback(static fn (array $context): bool => $context['@log'] === '/tmp/scs-demo-queue-root.log'));
    $runner = $this->runner('root', [FALSE, FALSE], $logger);

    $result = $runner->start();

    $this->assertFalse($result->success);
    $this->assertStringContainsString('/tmp/scs-demo-queue-root.log', $result->message);
  }

  /**
   * A runner for the given process user that never spawns a process.
   *
   * @param string $user
   *   The process user.
   * @param bool[] $running
   *   What isRunning() answers, call by call.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The logger, or NULL for a mock without expectations.
   */
  protected function runner(string $user, array $running = [], ?LoggerInterface $logger = NULL): TestDemoQueueRunner {
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('numberOfItems')->willReturn(1);
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->willReturn($queue);
    $runner = new TestDemoQueueRunner($logger ?? $this->createMock(LoggerInterface::class), $queueFactory);
    $runner->setStringTranslation($this->getStringTranslationStub());
    $runner->user = $user;
    $runner->running = $running;
    return $runner;
  }

}

/**
 * Runner with a fixed process user and no real background process.
 */
class TestDemoQueueRunner extends SodaScsDemoQueueRunner {

  /**
   * The process user to report.
   */
  public string $user = '';

  /**
   * Answers of isRunning(), call by call.
   *
   * @var bool[]
   */
  public array $running = [];

  /**
   * The command start() would have run.
   */
  public string $spawnedCommand = '';

  /**
   * {@inheritdoc}
   */
  public function isRunning(): bool {
    return (bool) array_shift($this->running);
  }

  /**
   * {@inheritdoc}
   */
  protected function processUserName(): string {
    return $this->user;
  }

  /**
   * {@inheritdoc}
   */
  protected function spawn(string $command): int {
    $this->spawnedCommand = $command;
    return 0;
  }

}
