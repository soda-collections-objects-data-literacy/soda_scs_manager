<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsWebprotegeServiceActions;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;

/**
 * Tests WebProtégé REST request construction.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\RequestActions\SodaScsWebprotegeServiceActions
 */
class SodaScsWebprotegeServiceActionsTest extends UnitTestCase {

  /**
   * @covers ::buildCreateRequest
   */
  public function testBuildCreateRequestUsesApiKeyHeaderAndCreateBody(): void {
    $actions = $this->createActions([
      'host' => 'https://webprotege.example.com',
      'apiBaseUrl' => 'http://webprotege:8080',
      'apiKey' => 'secret-api-key',
    ]);

    $request = $actions->buildCreateRequest([
      'body' => [
        'projectOwner' => 'alice',
        'displayName' => 'Demo project',
        'langTag' => 'en',
        'description' => 'SCS project 42',
      ],
    ]);

    $this->assertTrue($request['success']);
    $this->assertSame('POST', $request['method']);
    $this->assertSame('http://webprotege:8080/data/projects', $request['route']);
    $this->assertSame('apikey secret-api-key', $request['headers']['Authorization']);
    $this->assertSame('application/json', $request['headers']['Content-Type']);
    $this->assertSame(
      [
        'projectOwner' => 'alice',
        'displayName' => 'Demo project',
        'langTag' => 'en',
        'description' => 'SCS project 42',
      ],
      json_decode($request['body'], TRUE),
    );
  }

  /**
   * @covers ::buildUpdateRequest
   */
  public function testBuildUpdateRequestPutsCollaboratorEditPermission(): void {
    $actions = $this->createActions([
      'host' => 'https://webprotege.example.com',
      'apiBaseUrl' => 'http://webprotege:8080',
      'apiKey' => 'secret-api-key',
    ]);

    $request = $actions->buildUpdateRequest([
      'routeParams' => [
        'projectId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'userName' => 'bob',
      ],
      'body' => ['permission' => 'EDIT'],
    ]);

    $this->assertTrue($request['success']);
    $this->assertSame('PUT', $request['method']);
    $this->assertSame(
      'http://webprotege:8080/data/projects/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/collaborators/bob',
      $request['route'],
    );
    $this->assertSame('apikey secret-api-key', $request['headers']['Authorization']);
    $this->assertSame(['permission' => 'EDIT'], json_decode($request['body'], TRUE));
  }

  /**
   * @covers ::buildCreateRequest
   */
  public function testBuildCreateRequestFailsWhenApiKeyMissing(): void {
    $actions = $this->createActions([
      'host' => 'https://webprotege.example.com',
      'apiBaseUrl' => '',
      'apiKey' => '',
    ]);

    $request = $actions->buildCreateRequest(['body' => []]);
    $this->assertFalse($request['success']);
    $this->assertSame('WebProtégé API key is not configured.', $request['error']);
  }

  /**
   * @param array{host: string, apiBaseUrl: string, apiKey: string} $settings
   */
  private function createActions(array $settings): SodaScsWebprotegeServiceActions {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($this->createMock(Config::class));

    $serviceHelpers = $this->createMock(SodaScsServiceHelpers::class);
    $serviceHelpers->method('initWebprotegeInstanceSettings')->willReturn($settings);

    return new SodaScsWebprotegeServiceActions(
      $configFactory,
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(MessengerInterface::class),
      $serviceHelpers,
      $this->createMock(TranslationInterface::class),
    );
  }

}
