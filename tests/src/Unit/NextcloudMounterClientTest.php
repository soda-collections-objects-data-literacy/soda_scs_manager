<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\soda_scs_manager\Service\NextcloudMounterClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Service\NextcloudMounterClient
 * @group soda_scs_manager
 */
class NextcloudMounterClientTest extends UnitTestCase {

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

}
