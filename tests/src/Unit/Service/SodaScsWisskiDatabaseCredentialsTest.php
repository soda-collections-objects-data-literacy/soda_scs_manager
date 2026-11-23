<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Service;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Service\SodaScsWisskiDatabaseCredentials;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The database account in the settings.php of a running WissKI is replaced.
 *
 * Issue 3640913: the WissKI entrypoint writes DB_USER and DB_PASSWORD into
 * settings.php once, so a project handed to another owner needs the file
 * changed by hand. The docker exec is a double; one group of tests runs the
 * script it would send against a settings.php in a temporary directory.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Service\SodaScsWisskiDatabaseCredentials
 * @group soda_scs_manager
 */
class SodaScsWisskiDatabaseCredentialsTest extends UnitTestCase {

  /**
   * The container helpers double.
   */
  private SodaScsContainerHelpers&MockObject $containerHelpers;

  /**
   * Directory that holds the settings.php of the script tests.
   */
  private string $siteDirectory = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->containerHelpers = $this->createMock(SodaScsContainerHelpers::class);
    $this->containerHelpers->method('getComponentContainerName')->willReturn('wisski-demo--drupal');

    // Failure results log through \Drupal::logger().
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (['FROM_USER', 'FROM_PASSWORD', 'TO_USER', 'TO_PASSWORD'] as $name) {
      putenv('SCS_DB_' . $name);
    }
    if ($this->siteDirectory !== '') {
      @unlink($this->siteDirectory . '/settings.php');
      @rmdir($this->siteDirectory);
    }
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * The exec runs drush in the WissKI container as www-data.
   *
   * @covers ::replace
   */
  public function testReplaceRunsDrushInTheWisskiContainer(): void {
    $this->containerHelpers->expects($this->once())->method('executeDockerExecCommand')
      ->with($this->callback(function (array $request): bool {
        $this->assertSame('wisski-demo--drupal', $request['containerName']);
        $this->assertSame('www-data', $request['user']);
        $this->assertSame(['drush', 'php:eval'], array_slice($request['cmd'], 0, 2));
        $this->assertCount(3, $request['cmd']);
        return TRUE;
      }))
      ->willReturn($this->drushOutput('SCS_DB_CREDENTIALS:updated'));

    $this->service()->replace($this->createMock(SodaScsComponentInterface::class), 'old-secret', 'olduser', 'new-secret', 'newuser');
  }

  /**
   * The passwords travel in the environment, not in the command line.
   *
   * @covers ::replace
   */
  public function testReplaceKeepsCredentialsOutOfTheCommand(): void {
    $this->containerHelpers->expects($this->once())->method('executeDockerExecCommand')
      ->with($this->callback(function (array $request): bool {
        $command = implode(' ', $request['cmd']);
        foreach (['old-secret', 'new-secret', 'olduser', 'newuser'] as $value) {
          $this->assertStringNotContainsString($value, $command);
        }
        $this->assertSame([
          'SCS_DB_FROM_PASSWORD=old-secret',
          'SCS_DB_FROM_USER=olduser',
          'SCS_DB_TO_PASSWORD=new-secret',
          'SCS_DB_TO_USER=newuser',
        ], $request['env']);
        return TRUE;
      }))
      ->willReturn($this->drushOutput('SCS_DB_CREDENTIALS:updated'));

    $this->service()->replace($this->createMock(SodaScsComponentInterface::class), 'old-secret', 'olduser', 'new-secret', 'newuser');
  }

  /**
   * Updated and unchanged are successes with their state.
   *
   * @covers ::replace
   */
  public function testReplaceMapsMarkerToState(): void {
    foreach (['updated', 'unchanged'] as $state) {
      $this->containerHelpers = $this->createMock(SodaScsContainerHelpers::class);
      $this->containerHelpers->method('executeDockerExecCommand')
        ->willReturn($this->drushOutput("Deprecated: noise\nSCS_DB_CREDENTIALS:$state\n"));

      $result = $this->replaceCredentials();

      $this->assertTrue($result->success, $state);
      $this->assertSame(['state' => $state], $result->data);
    }
  }

  /**
   * A settings.php the script could not match is a failure naming the key.
   *
   * @covers ::replace
   */
  public function testReplaceFailsOnMismatch(): void {
    $component = $this->createMock(SodaScsComponentInterface::class);
    $component->method('id')->willReturn(12);
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn($this->drushOutput('SCS_DB_CREDENTIALS:mismatch password'));

    $result = $this->service()->replace($component, 'a', 'b', 'c', 'd');

    $this->assertFalse($result->success);
    $this->assertSame('settings.php of WissKI 12: mismatch password.', $result->error);
  }

  /**
   * A write that failed in the container is a failure.
   *
   * @covers ::replace
   */
  public function testReplaceFailsWhenWriteFails(): void {
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn($this->drushOutput('SCS_DB_CREDENTIALS:write failed'));

    $result = $this->replaceCredentials();

    $this->assertFalse($result->success);
    $this->assertStringContainsString('write failed', (string) $result->error);
  }

  /**
   * A failing exec returns its error.
   *
   * @covers ::replace
   */
  public function testReplaceFailsWhenExecFails(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(new SodaScsResult(FALSE, ['output' => 'SCS_DB_CREDENTIALS:updated'], 'container is not running', 'failed'));

    $result = $this->replaceCredentials();

    $this->assertFalse($result->success);
    $this->assertSame('container is not running', $result->error);
  }

  /**
   * Output without the marker is a failure, whatever the exec returned.
   *
   * @covers ::replace
   */
  public function testReplaceFailsWithoutMarkerInOutput(): void {
    foreach (['Some warning only', '', 'SCS_DB_CREDENTIALS:exploded'] as $output) {
      $this->containerHelpers = $this->createMock(SodaScsContainerHelpers::class);
      $this->containerHelpers->method('executeDockerExecCommand')->willReturn($this->drushOutput($output));

      $result = $this->replaceCredentials();

      $this->assertFalse($result->success);
      $this->assertSame('Missing result in drush output.', $result->error);
    }
  }

  /**
   * The passwords reach neither the failure error nor its message.
   *
   * @covers ::replace
   */
  public function testReplaceKeepsPasswordsOutOfFailures(): void {
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn($this->drushOutput('SCS_DB_CREDENTIALS:mismatch password'));

    $result = $this->service()->replace($this->createMock(SodaScsComponentInterface::class), 'old-secret', 'olduser', 'new-secret', 'newuser');

    $this->assertStringNotContainsString('old-secret', (string) $result->error . $result->message);
    $this->assertStringNotContainsString('new-secret', (string) $result->error . $result->message);
  }

  /**
   * The script replaces both values of the default connection.
   *
   * @covers ::replace
   */
  public function testScriptReplacesUserAndPassword(): void {
    $path = $this->writeSettings("'username' => 'olduser',\n  'password' => 'old-secret',\n");

    $output = $this->runScript('olduser', 'old-secret', 'newuser', 'new-secret');

    $this->assertSame('SCS_DB_CREDENTIALS:updated', $output);
    $code = (string) file_get_contents($path);
    $this->assertStringContainsString("'username' => 'newuser',", $code);
    $this->assertStringContainsString("'password' => 'new-secret',", $code);
    $this->assertStringNotContainsString('olduser', $code);
    $this->assertStringNotContainsString('old-secret', $code);
  }

  /**
   * A second run with the same values changes nothing.
   *
   * @covers ::replace
   */
  public function testScriptIsIdempotent(): void {
    $this->writeSettings("'username' => 'olduser',\n  'password' => 'old-secret',\n");
    $this->runScript('olduser', 'old-secret', 'newuser', 'new-secret');

    $this->assertSame('SCS_DB_CREDENTIALS:unchanged', $this->runScript('olduser', 'old-secret', 'newuser', 'new-secret'));
  }

  /**
   * A value that occurs twice is refused and the file stays as it is.
   *
   * @covers ::replace
   */
  public function testScriptRefusesAmbiguousValueAndLeavesFile(): void {
    $original = "'username' => 'olduser',\n  'password' => 'old-secret',\n'username' => 'olduser',\n";
    $path = $this->writeSettings($original);

    $output = $this->runScript('olduser', 'old-secret', 'newuser', 'new-secret');

    $this->assertSame('SCS_DB_CREDENTIALS:mismatch username', $output);
    $this->assertSame($original, (string) file_get_contents($path));
  }

  /**
   * A settings.php changed by hand (value missing) is left alone.
   *
   * @covers ::replace
   */
  public function testScriptRefusesMissingValueAndLeavesFile(): void {
    $original = "'username' => 'somebody-else',\n  'password' => 'old-secret',\n";
    $path = $this->writeSettings($original);

    $output = $this->runScript('olduser', 'old-secret', 'newuser', 'new-secret');

    $this->assertSame('SCS_DB_CREDENTIALS:mismatch username', $output);
    $this->assertSame($original, (string) file_get_contents($path));
  }

  /**
   * Quotes and backslashes in a password are written as valid PHP.
   *
   * @covers ::replace
   */
  public function testScriptEscapesSpecialCharacters(): void {
    $path = $this->writeSettings("'username' => 'olduser',\n  'password' => 'old-secret',\n");
    $password = "it's a \\ back";

    $this->assertSame('SCS_DB_CREDENTIALS:updated', $this->runScript('olduser', 'old-secret', 'newuser', $password));

    $databases = NULL;
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged -- reads the settings.php fragment that the generated script wrote.
    eval('$databases = [' . file_get_contents($path) . '];');
    $this->assertSame($password, $databases['password']);
  }

  /**
   * The file permissions are the same after the write.
   *
   * @covers ::replace
   */
  public function testScriptKeepsFilePermissions(): void {
    $path = $this->writeSettings("'username' => 'olduser',\n  'password' => 'old-secret',\n");
    chmod($path, 0444);

    $this->assertSame('SCS_DB_CREDENTIALS:updated', $this->runScript('olduser', 'old-secret', 'newuser', 'new-secret'));

    clearstatcache(TRUE, $path);
    $this->assertSame(0444, fileperms($path) & 0777);
    chmod($path, 0644);
  }

  /**
   * Calls replace() with fixed values.
   */
  private function replaceCredentials(): SodaScsResult {
    return $this->service()->replace($this->createMock(SodaScsComponentInterface::class), 'a', 'b', 'c', 'd');
  }

  /**
   * A drush answer with the given output.
   */
  private function drushOutput(string $output): SodaScsResult {
    return SodaScsResult::success(['output' => $output], 'ok');
  }

  /**
   * Runs the script that replace() sends, against the temporary settings.php.
   *
   * @return string
   *   What the script printed.
   */
  private function runScript(string $fromUser, string $fromPassword, string $toUser, string $toPassword): string {
    $request = NULL;
    $helpers = $this->createMock(SodaScsContainerHelpers::class);
    $helpers->expects($this->once())->method('executeDockerExecCommand')
      ->willReturnCallback(function (array $captured) use (&$request): SodaScsResult {
        $request = $captured;
        return SodaScsResult::success(['output' => 'SCS_DB_CREDENTIALS:updated'], 'ok');
      });
    (new SodaScsWisskiDatabaseCredentials($helpers))
      ->replace($this->createMock(SodaScsComponentInterface::class), $fromPassword, $fromUser, $toPassword, $toUser);

    foreach ($request['env'] as $entry) {
      putenv($entry);
    }
    $container = \Drupal::getContainer();
    $container->setParameter('site.path', $this->relativeSitePath());
    ob_start();
    try {
      // The script is the code drush php:eval runs in the WissKI container.
      // phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged -- the test runs the script that replace() sends to the container.
      eval($request['cmd'][2]);
    }
    finally {
      $output = (string) ob_get_clean();
    }
    return $output;
  }

  /**
   * Service under test.
   */
  private function service(): SodaScsWisskiDatabaseCredentials {
    return new SodaScsWisskiDatabaseCredentials($this->containerHelpers);
  }

  /**
   * Path from DRUPAL_ROOT to the temporary site directory.
   */
  private function relativeSitePath(): string {
    return str_repeat('../', substr_count(trim(DRUPAL_ROOT, '/'), '/') + 1) . ltrim($this->siteDirectory, '/');
  }

  /**
   * Writes a settings.php with the given connection lines.
   *
   * @return string
   *   The file path.
   */
  private function writeSettings(string $connectionLines): string {
    $this->siteDirectory = sys_get_temp_dir() . '/scs-db-credentials-' . bin2hex(random_bytes(4));
    mkdir($this->siteDirectory);
    $path = $this->siteDirectory . '/settings.php';
    file_put_contents($path, $connectionLines);
    return $path;
  }

}
