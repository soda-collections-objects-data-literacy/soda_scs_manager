<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudGroupHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\RequestInterface;

/**
 * Deleting and listing Nextcloud groups through the provisioning API.
 *
 * Issue 3642180. The HTTP client is a Guzzle MockHandler: the real request
 * builders of SodaScsNextcloudServiceActions run, no request leaves the test.
 */
#[CoversMethod(SodaScsNextcloudGroupHelpers::class, 'deleteGroup')]
#[CoversMethod(SodaScsNextcloudGroupHelpers::class, 'listGroupMemberCounts')]
#[CoversMethod(SodaScsNextcloudServiceActions::class, 'buildDeleteGroupRequest')]
#[CoversMethod(SodaScsNextcloudServiceActions::class, 'buildGroupDetailsRequest')]
#[Group('soda_scs_manager')]
class SodaScsNextcloudGroupHelpersTest extends UnitTestCase {

  /**
   * Nextcloud base URL of the fixture (with a trailing slash on purpose).
   */
  private const BASE_URL = 'https://nextcloud.example.com/';

  /**
   * The canned responses.
   */
  private MockHandler $mockHandler;

  /**
   * The requests the client sent.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface}>
   */
  private array $history = [];

  /**
   * The logger channel double.
   */
  private LoggerChannelInterface&MockObject $logger;

  /**
   * Nextcloud settings the service helpers return.
   *
   * @var array<string, string>
   */
  private array $settings = [
    'baseUrl'       => self::BASE_URL,
    'adminUsername' => 'ncadmin',
    'adminPassword' => 'test-password',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->mockHandler = new MockHandler();
    $this->history = [];
    $this->logger = $this->createMock(LoggerChannelInterface::class);
  }

  /**
   * The delete is an admin OCS DELETE on /cloud/groups/{groupid}.
   */
  public function testDeleteGroupSendsOcsDeleteWithAdminAuth(): void {
    $this->mockHandler->append($this->ocsResponse(100));

    $this->assertTrue($this->helpers()->deleteGroup('10119'));

    $request = $this->sentRequest();
    $this->assertSame('DELETE', $request->getMethod());
    $this->assertSame('https://nextcloud.example.com/ocs/v1.php/cloud/groups/10119?format=json', (string) $request->getUri());
    $this->assertSame('true', $request->getHeaderLine('OCS-APIRequest'));
    $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    $this->assertSame('Basic ' . base64_encode('ncadmin:test-password'), $request->getHeaderLine('Authorization'));
  }

  /**
   * The group id is one encoded path segment, never a path of its own.
   */
  public function testDeleteGroupEncodesTheGroupId(): void {
    $this->mockHandler->append($this->ocsResponse(100));

    $this->helpers()->deleteGroup('keycloak-a b/../users');

    $this->assertSame(
      '/ocs/v1.php/cloud/groups/keycloak-a%20b%2F..%2Fusers',
      $this->sentRequest()->getUri()->getPath(),
    );
  }

  /**
   * A group that does not exist (OCS 101) counts as deleted, without a log.
   */
  public function testDeleteGroupTreatsUnknownGroupAsDeleted(): void {
    $this->mockHandler->append($this->ocsResponse(101));
    $this->logger->expects($this->never())->method('warning');

    $this->assertTrue($this->helpers()->deleteGroup('sql-gone-admin'));
  }

  /**
   * Another OCS status (e.g. 102: could not delete) is a logged failure.
   */
  public function testDeleteGroupFailsOnOtherOcsStatus(): void {
    $this->mockHandler->append($this->ocsResponse(102));
    $this->logger->expects($this->once())->method('warning')
      ->with($this->stringContains('Could not delete Nextcloud group'), $this->callback(
        static fn (array $context): bool => $context['@group'] === 'ts-foo-user' && $context['@status'] === 102,
      ));

    $this->assertFalse($this->helpers()->deleteGroup('ts-foo-user'));
  }

  /**
   * An HTTP error from Nextcloud is a logged failure.
   */
  public function testDeleteGroupFailsOnHttpError(): void {
    $this->mockHandler->append(new Response(500, [], 'Internal Server Error'));
    $this->logger->expects($this->once())->method('warning')
      ->with($this->stringContains('Could not delete Nextcloud group'), $this->callback(
        static fn (array $context): bool => $context['@status'] === 'none',
      ));

    $this->assertFalse($this->helpers()->deleteGroup('10119'));
  }

  /**
   * The request dump of a failed call is logged without the admin login.
   */
  public function testDeleteGroupFailureLogsRequestWithoutAuthorization(): void {
    $this->mockHandler->append(new Response(401, [], 'Unauthorized'));
    $requestDumps = [];
    $this->logger->method('debug')->willReturnCallback(
      static function (string $message, array $context) use (&$requestDumps): void {
        if (isset($context['%request'])) {
          $requestDumps[] = $context['%request'];
        }
      },
    );

    $this->assertFalse($this->helpers()->deleteGroup('10119'));
    $this->assertCount(1, $requestDumps);
    $this->assertStringContainsString('DELETE', $requestDumps[0]);
    $this->assertStringNotContainsStringIgnoringCase('authorization', $requestDumps[0]);
    $this->assertStringNotContainsString(base64_encode('ncadmin:test-password'), $requestDumps[0]);
  }

  /**
   * A body that is not OCS JSON is a failure, not a success.
   */
  public function testDeleteGroupFailsOnMalformedBody(): void {
    $this->mockHandler->append(new Response(200, [], '<html>maintenance</html>'));
    $this->logger->expects($this->once())->method('warning');

    $this->assertFalse($this->helpers()->deleteGroup('10119'));
  }

  /**
   * An empty id and Nextcloud's admin group are never sent to Nextcloud.
   */
  public function testDeleteGroupRefusesEmptyIdAndAdminGroup(): void {
    $helpers = $this->helpers();

    $this->assertFalse($helpers->deleteGroup(''));
    $this->assertFalse($helpers->deleteGroup('admin'));
    $this->assertSame([], $this->history);
  }

  /**
   * Without admin credentials no request goes out and a warning is logged.
   */
  public function testDeleteGroupWithoutAdminCredentialsSendsNothing(): void {
    $this->settings['adminPassword'] = '{empty}';
    $this->logger->expects($this->once())->method('warning')
      ->with($this->stringContains('not deleted'), $this->callback(
        static fn (array $context): bool => $context['@error'] === 'Nextcloud admin credentials are not configured.',
      ));

    $this->assertFalse($this->helpers()->deleteGroup('10119'));
    $this->assertSame([], $this->history);
  }

  /**
   * The list is an admin OCS GET on /cloud/groups/details.
   */
  public function testListGroupMemberCountsSendsDetailsRequest(): void {
    $this->mockHandler->append($this->ocsResponse(100, ['groups' => []]));

    $this->assertSame([], $this->helpers()->listGroupMemberCounts());

    $request = $this->sentRequest();
    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('https://nextcloud.example.com/ocs/v1.php/cloud/groups/details?format=json', (string) $request->getUri());
    $this->assertSame('true', $request->getHeaderLine('OCS-APIRequest'));
    $this->assertSame('Basic ' . base64_encode('ncadmin:test-password'), $request->getHeaderLine('Authorization'));
  }

  /**
   * Member counts are keyed by group id; numeric ids stay keys.
   */
  public function testListGroupMemberCountsReturnsCountsById(): void {
    $this->mockHandler->append($this->ocsResponse(100, [
      'groups' => [
        ['id' => 'admin', 'displayname' => 'admin', 'usercount' => 2],
        ['id' => '10119', 'displayname' => '10119', 'usercount' => 0],
        ['id' => 'wisski-foo-user', 'usercount' => '3'],
        ['displayname' => 'no id'],
        ['id' => 'no-count'],
      ],
    ]));

    $this->assertSame(
      ['admin' => 2, '10119' => 0, 'wisski-foo-user' => 3, 'no-count' => 0],
      $this->helpers()->listGroupMemberCounts(),
    );
  }

  /**
   * An OCS failure status gives NULL, not an empty list.
   */
  public function testListGroupMemberCountsReturnsNullOnOcsFailure(): void {
    $this->mockHandler->append($this->ocsResponse(997, ['groups' => []]));

    $this->assertNull($this->helpers()->listGroupMemberCounts());
  }

  /**
   * An HTTP error gives NULL.
   */
  public function testListGroupMemberCountsReturnsNullOnHttpError(): void {
    $this->mockHandler->append(new Response(503, [], ''));

    $this->assertNull($this->helpers()->listGroupMemberCounts());
  }

  /**
   * A body that is not JSON gives NULL.
   */
  public function testListGroupMemberCountsReturnsNullOnMalformedBody(): void {
    $this->mockHandler->append(new Response(200, [], 'not json'));

    $this->assertNull($this->helpers()->listGroupMemberCounts());
  }

  /**
   * Without admin credentials the list gives NULL and sends nothing.
   */
  public function testListGroupMemberCountsWithoutAdminCredentialsSendsNothing(): void {
    $this->settings['adminUsername'] = '';

    $this->assertNull($this->helpers()->listGroupMemberCounts());
    $this->assertSame([], $this->history);
  }

  /**
   * Builds the helpers around the real request actions and the mock client.
   */
  private function helpers(): SodaScsNextcloudGroupHelpers {
    $stack = HandlerStack::create($this->mockHandler);
    $stack->push(Middleware::history($this->history));
    $client = new Client(['handler' => $stack]);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->logger);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($this->createMock(Config::class));

    $serviceHelpers = $this->createMock(SodaScsServiceHelpers::class);
    $serviceHelpers->method('initNextcloudSettings')->willReturn($this->settings);

    $actions = new SodaScsNextcloudServiceActions(
      $configFactory,
      $client,
      $this->createMock(MessengerInterface::class),
      $loggerFactory,
      $serviceHelpers,
      $this->getStringTranslationStub(),
    );
    return new SodaScsNextcloudGroupHelpers($loggerFactory, $actions);
  }

  /**
   * An OCS v1 JSON response (HTTP 200) with the given status.
   *
   * @param int $statusCode
   *   The OCS status code.
   * @param array $data
   *   The OCS data.
   */
  private function ocsResponse(int $statusCode, array $data = []): Response {
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
      'ocs' => [
        'meta' => ['status' => $statusCode === 100 ? 'ok' : 'failure', 'statuscode' => $statusCode, 'message' => ''],
        'data' => $data,
      ],
    ]));
  }

  /**
   * The one request the client sent.
   */
  private function sentRequest(): RequestInterface {
    $this->assertCount(1, $this->history);
    return $this->history[0]['request'];
  }

}
