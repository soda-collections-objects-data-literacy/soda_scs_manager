<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\soda_scs_manager\Service\NextcloudMounterClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Service\NextcloudMounterClient
 * @group soda_scs_manager
 */
class NextcloudMounterClientTest extends UnitTestCase {

  /**
   * Temporary mounts root of the current test, empty when none was made.
   */
  private string $mountsRoot = '';

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->mountsRoot !== '' && is_dir($this->mountsRoot)) {
      $this->removeTree($this->mountsRoot);
    }
    parent::tearDown();
  }

  /**
   * @covers ::obscure
   * @covers ::listMounts
   * @covers ::isListed
   */
  public function testObscureAndListMounts(): void {
    putenv('NEXTCLOUD_MOUNTER_RC_URL=http://mounter.test:5572');
    putenv('NEXTCLOUD_MOUNTER_RC_USER=user');
    putenv('NEXTCLOUD_MOUNTER_RC_PASS=pass');

    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->exactly(2))
      ->method('request')
      ->willReturnCallback(function (string $method, string $uri, array $options) {
        $this->assertSame('POST', $method);
        $this->assertSame(['user', 'pass'], $options['auth']);
        if (str_ends_with($uri, '/core/obscure')) {
          $this->assertSame(['clear' => 'secret'], $options['json']);
          return new Response(200, [], json_encode(['obscured' => 'obscured-secret']));
        }
        if (str_ends_with($uri, '/mount/listmounts')) {
          return new Response(200, [], json_encode([
            'mountPoints' => [
              ['MountPoint' => '/mnt/nextcloud/alice'],
            ],
          ]));
        }
        $this->fail('Unexpected URI: ' . $uri);
      });

    $logger = $this->createMock(LoggerInterface::class);
    $client = new NextcloudMounterClient($http, $logger);

    $this->assertSame('obscured-secret', $client->obscure('secret'));
    $mounts = $client->listMounts();
    $this->assertTrue($client->isListed('alice', $mounts));
    $this->assertFalse($client->isListed('bob', $mounts));
  }

  /**
   * @covers ::forgetDirectoryCache
   */
  public function testForgetDirectoryCacheTargetsTheMountsVfs(): void {
    putenv('NEXTCLOUD_MOUNTER_RC_URL=http://mounter.test:5572');
    putenv('NEXTCLOUD_MOUNTER_RC_USER=user');
    putenv('NEXTCLOUD_MOUNTER_RC_PASS=pass');

    $forgotten = [];
    $http = $this->createMock(ClientInterface::class);
    $http->method('request')
      ->willReturnCallback(function (string $method, string $uri, array $options) use (&$forgotten) {
        if (str_ends_with($uri, '/mount/listmounts')) {
          return new Response(200, [], json_encode([
            'mountPoints' => [
              ['Fs' => ':webdav{AAAAA}:', 'MountPoint' => '/mnt/nextcloud/alice'],
              ['Fs' => ':webdav{BBBBB}:', 'MountPoint' => '/mnt/nextcloud/bob'],
            ],
          ]));
        }
        if (str_ends_with($uri, '/vfs/forget')) {
          $forgotten[] = $options['json'];
          return new Response(200, [], '{}');
        }
        $this->fail('Unexpected URI: ' . $uri);
      });

    $client = new NextcloudMounterClient($http, $this->createMock(LoggerInterface::class));
    $client->forgetDirectoryCache('bob');
    $client->forgetDirectoryCache('carol');

    $this->assertSame([['fs' => ':webdav{BBBBB}:']], $forgotten);
  }

  /**
   * @covers ::sidecarMountPoint
   * @covers ::hostMountPath
   */
  public function testPaths(): void {
    $http = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $client = new NextcloudMounterClient($http, $logger);
    $this->assertSame('/mnt/nextcloud/alice', $client->sidecarMountPoint('alice'));
    $this->assertSame('/mnt/nextcloud-mounts/alice', $client->hostMountPath('alice'));
  }

  /**
   * @covers ::ensureMountDirectory
   */
  public function testEnsureMountDirectoryCreatesDirectoryWithoutRcCall(): void {
    $root = $this->createMountsRoot(0755);
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('debug');

    $this->createClient($http, $logger, $root)->ensureMountDirectory('a/b');

    $this->assertDirectoryExists($root . '/a/b');
  }

  /**
   * @covers ::ensureMountDirectory
   */
  public function testEnsureMountDirectoryIgnoresExistingDirectory(): void {
    $root = $this->createMountsRoot(0755);
    mkdir($root . '/alice', 0755);
    chmod($root, 0555);
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');

    $this->createClient($http, $this->createMock(LoggerInterface::class), $root)->ensureMountDirectory('alice');

    $this->assertDirectoryExists($root . '/alice');
  }

  /**
   * @covers ::ensureMountDirectory
   * @covers ::createMountDirectoryInSidecar
   */
  public function testEnsureMountDirectoryFallsBackToSidecarWhenRootIsReadOnly(): void {
    $root = $this->createMountsRoot(0555);
    $requests = [];
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())
      ->method('request')
      ->willReturnCallback(function (string $method, string $uri, array $options) use (&$requests, $root) {
        $requests[] = [$method, $uri, $options['json']];
        $this->createDirectoryLikeSidecar($root, 'alice');
        return new Response(200, [], '{}');
      });
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('debug')
      ->with($this->anything(), ['@path' => $root . '/alice']);

    $this->createClient($http, $logger, $root)->ensureMountDirectory('alice');

    $this->assertDirectoryExists($root . '/alice');
    $this->assertCount(1, $requests);
    $this->assertSame('POST', $requests[0][0]);
    $this->assertSame('http://mounter.test:5572/operations/mkdir', $requests[0][1]);
    $this->assertSame(['fs' => '/mnt/nextcloud', 'remote' => 'alice'], $requests[0][2]);
  }

  /**
   * @covers ::ensureMountDirectory
   * @covers ::createMountDirectoryInSidecar
   */
  public function testEnsureMountDirectorySendsPathSoFarForNestedSegments(): void {
    $root = $this->createMountsRoot(0755);
    mkdir($root . '/a', 0755);
    chmod($root . '/a', 0555);
    $this->skipWhenRoot();
    $remotes = [];
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())
      ->method('request')
      ->willReturnCallback(function (string $method, string $uri, array $options) use (&$remotes, $root) {
        $remotes[] = $options['json'];
        $this->createDirectoryLikeSidecar($root . '/a', 'b');
        return new Response(200, [], '{}');
      });

    $this->createClient($http, $this->createMock(LoggerInterface::class), $root)->ensureMountDirectory('a/b');

    $this->assertDirectoryExists($root . '/a/b');
    $this->assertSame([['fs' => '/mnt/nextcloud', 'remote' => 'a/b']], $remotes);
  }

  /**
   * @covers ::ensureMountDirectory
   * @covers ::createMountDirectoryInSidecar
   */
  public function testEnsureMountDirectoryThrowsWithPreviousWhenRcCallFails(): void {
    $root = $this->createMountsRoot(0555);
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())
      ->method('request')
      ->willThrowException(new ConnectException('no route', new Request('POST', 'http://mounter.test:5572/operations/mkdir')));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('debug');

    try {
      $this->createClient($http, $logger, $root)->ensureMountDirectory('alice');
      $this->fail('Expected a RuntimeException.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame(sprintf('Could not create mount directory %s/alice.', $root), $e->getMessage());
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
      $this->assertSame('Nextcloud mounter rc call failed: operations/mkdir', $e->getPrevious()->getMessage());
    }
    $this->assertDirectoryDoesNotExist($root . '/alice');
  }

  /**
   * @covers ::ensureMountDirectory
   * @covers ::createMountDirectoryInSidecar
   */
  public function testEnsureMountDirectoryThrowsWhenRcAnswerIsNotJson(): void {
    $root = $this->createMountsRoot(0555);
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())
      ->method('request')
      ->willReturn(new Response(200, [], 'not json'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(sprintf('Could not create mount directory %s/alice.', $root));

    $this->createClient($http, $this->createMock(LoggerInterface::class), $root)->ensureMountDirectory('alice');
  }

  /**
   * @covers ::ensureMountDirectory
   * @covers ::createMountDirectoryInSidecar
   */
  public function testEnsureMountDirectoryThrowsWhenSidecarAnswersButDirectoryIsMissing(): void {
    $root = $this->createMountsRoot(0555);
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())
      ->method('request')
      ->willReturn(new Response(200, [], '{}'));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('debug');

    try {
      $this->createClient($http, $logger, $root)->ensureMountDirectory('alice');
      $this->fail('Expected a RuntimeException.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame(sprintf('Could not create mount directory %s/alice.', $root), $e->getMessage());
      $this->assertNull($e->getPrevious());
    }
  }

  /**
   * Creates a client whose mounts root is a directory of this test.
   */
  private function createClient(ClientInterface $http, LoggerInterface $logger, string $mountsRoot): NextcloudMounterClient {
    putenv('NEXTCLOUD_MOUNTER_RC_URL=http://mounter.test:5572');
    putenv('NEXTCLOUD_MOUNTER_RC_USER=user');
    putenv('NEXTCLOUD_MOUNTER_RC_PASS=pass');
    return new class($http, $logger, $mountsRoot) extends NextcloudMounterClient {

      public function __construct(ClientInterface $http, LoggerInterface $logger, private string $root) {
        parent::__construct($http, $logger);
      }

      /**
       * {@inheritdoc}
       */
      protected function mountsRootInContainer(): string {
        return $this->root;
      }

    };
  }

  /**
   * Makes a temporary mounts root with the given permissions.
   */
  private function createMountsRoot(int $permissions): string {
    $root = sys_get_temp_dir() . '/scs-mounter-' . bin2hex(random_bytes(6));
    mkdir($root, 0755);
    chmod($root, $permissions);
    $this->mountsRoot = $root;
    if ($permissions === 0555) {
      $this->skipWhenRoot();
    }
    return $root;
  }

  /**
   * Skips the test as root, which ignores read-only directory permissions.
   */
  private function skipWhenRoot(): void {
    if (posix_geteuid() === 0) {
      $this->markTestSkipped('Root ignores directory permissions, so a directory cannot be made read-only.');
    }
  }

  /**
   * Creates a directory in a read-only parent, as the root sidecar would.
   */
  private function createDirectoryLikeSidecar(string $parent, string $name): void {
    $permissions = fileperms($parent) & 0777;
    chmod($parent, 0755);
    mkdir($parent . '/' . $name, 0755);
    chmod($parent, $permissions);
  }

  /**
   * Removes a directory tree, including read-only directories.
   */
  private function removeTree(string $directory): void {
    chmod($directory, 0755);
    foreach (scandir($directory) as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $path = $directory . '/' . $entry;
      is_dir($path) ? $this->removeTree($path) : unlink($path);
    }
    rmdir($directory);
  }

}
