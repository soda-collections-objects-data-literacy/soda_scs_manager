<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsWebprotegeServiceActions;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

/**
 * Tests WebProtégé REST request construction.
 */
#[CoversMethod(SodaScsWebprotegeServiceActions::class, 'buildCreateRequest')]
#[CoversMethod(SodaScsWebprotegeServiceActions::class, 'buildUpdateRequest')]
#[CoversMethod(SodaScsWebprotegeServiceActions::class, 'buildAddAxiomsRequest')]
#[CoversMethod(SodaScsWebprotegeServiceActions::class, 'buildDeleteRequest')]
#[CoversMethod(SodaScsWebprotegeServiceActions::class, 'makeRequest')]
#[Group('soda_scs_manager')]
class SodaScsWebprotegeServiceActionsTest extends UnitTestCase {

  /**
   * WebProtégé settings with an API key.
   */
  private const API_SETTINGS = [
    'host'       => 'https://webprotege.example.com',
    'apiBaseUrl' => 'http://webprotege:8080',
    'apiKey'     => 'secret-api-key',
  ];

  /**
   * Tests that buildCreateRequest() uses API key header and create body.
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
   * Tests that buildUpdateRequest() puts collaborator edit permission.
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
   * Tests that buildCreateRequest() fails when API key missing.
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
   * Tests that buildAddAxiomsRequest() defaults to functional syntax.
   */
  public function testBuildAddAxiomsRequestDefaultsToFunctionalSyntax(): void {
    $actions = $this->createActions([
      'host' => 'https://webprotege.example.com',
      'apiBaseUrl' => 'http://webprotege:8080',
      'apiKey' => 'secret-api-key',
    ]);

    $request = $actions->buildAddAxiomsRequest('Add classes', 'Ontology()', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

    $this->assertTrue($request['success']);
    $this->assertSame('POST', $request['method']);
    $this->assertSame('text/owl-functional', $request['headers']['Content-Type']);
    $this->assertSame('apikey secret-api-key', $request['headers']['Authorization']);
    $this->assertSame('Ontology()', $request['body']);
    $this->assertSame(300, $request['timeout']);
    $this->assertSame(
      'http://webprotege:8080/data/projects/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/axioms?msg=Add+classes',
      $request['route'],
    );
  }

  /**
   * Tests that buildAddAxiomsRequest() uses given timeout.
   */
  public function testBuildAddAxiomsRequestUsesGivenTimeout(): void {
    $actions = $this->createActions([
      'host' => 'https://webprotege.example.com',
      'apiBaseUrl' => 'http://webprotege:8080',
      'apiKey' => 'secret-api-key',
    ]);

    $request = $actions->buildAddAxiomsRequest('Add classes', '<a> <b> <c> .', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'text/ttl', 240);

    $this->assertSame(240, $request['timeout']);
  }

  /**
   * Tests that buildAddAxiomsRequest() sends given content type.
   */
  public function testBuildAddAxiomsRequestSendsGivenContentType(): void {
    $actions = $this->createActions([
      'host' => 'https://webprotege.example.com',
      'apiBaseUrl' => 'http://webprotege:8080',
      'apiKey' => 'secret-api-key',
    ]);

    $request = $actions->buildAddAxiomsRequest('Add classes', '<a> <b> <c> .', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'text/ttl');

    $this->assertTrue($request['success']);
    $this->assertSame('text/ttl', $request['headers']['Content-Type']);
    $this->assertSame('<a> <b> <c> .', $request['body']);
  }

  /**
   * Tests that buildAddAxiomsRequest() fails when API key missing.
   */
  public function testBuildAddAxiomsRequestFailsWhenApiKeyMissing(): void {
    $actions = $this->createActions([
      'host' => 'https://webprotege.example.com',
      'apiBaseUrl' => 'http://webprotege:8080',
      'apiKey' => '',
    ]);

    $request = $actions->buildAddAxiomsRequest('Add classes', 'Ontology()', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'text/ttl');

    $this->assertFalse($request['success']);
  }

  /**
   * Content types of the import: one per extension, all with a parser.
   */
  public function testOntologyDocumentMimeTypesCoverTheImportExtensions(): void {
    $this->assertSame(
      ['ofn', 'owl', 'rdf', 'ttl'],
      array_keys(SodaScsWebprotegeServiceActions::ONTOLOGY_DOCUMENT_MIME_TYPES),
    );
    $this->assertSame('text/owl-functional', SodaScsWebprotegeServiceActions::ONTOLOGY_DOCUMENT_MIME_TYPES['ofn']);
  }

  /**
   * The user delete lists 404 as expected; a project delete lists nothing.
   */
  public function testBuildDeleteRequestExpects404OnlyForUserDelete(): void {
    $actions = $this->createActions(self::API_SETTINGS);

    $userRequest = $actions->buildDeleteRequest([
      'type' => 'user',
      'routeParams' => ['userName' => 'never_in_webprotege'],
    ]);
    $projectRequest = $actions->buildDeleteRequest([
      'type' => 'project',
      'routeParams' => ['projectId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
    ]);

    $this->assertSame('DELETE', $userRequest['method']);
    $this->assertSame('http://webprotege:8080/data/users/never_in_webprotege', $userRequest['route']);
    $this->assertSame([404], $userRequest['expectedStatusCodes']);
    $this->assertArrayNotHasKey('expectedStatusCodes', $projectRequest);
  }

  /**
   * An expected status is a notice, not an error; the result still fails.
   *
   * Issue 3642180: deleting an account that never opened WebProtégé logged
   * the 404 of DELETE /data/users/{name} as an error.
   */
  public function testMakeRequestLogsExpectedStatusAsNotice(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('notice')
      ->with('WebProtégé answered @status to @method @route.', [
        '@status' => 404,
        '@method' => 'DELETE',
        '@route' => 'http://webprotege:8080/data/users/never_in_webprotege',
      ]);
    $logger->expects($this->never())->method('log');
    $logger->expects($this->never())->method('error');
    $actions = $this->createActions(self::API_SETTINGS, [new Response(404)], $logger);

    $result = $actions->makeRequest($actions->buildDeleteRequest([
      'type' => 'user',
      'routeParams' => ['userName' => 'never_in_webprotege'],
    ]));

    $this->assertFalse($result['success']);
    $this->assertSame(404, $result['statusCode']);
  }

  /**
   * A status that is not listed is still logged as an error.
   */
  public function testMakeRequestLogsUnexpectedStatusOfUserDeleteAsError(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('notice');
    $logger->expects($this->once())->method('log')
      ->with(LogLevel::ERROR, 'WebProtégé request failed: @message', $this->anything());
    $actions = $this->createActions(self::API_SETTINGS, [new Response(500)], $logger);

    $result = $actions->makeRequest($actions->buildDeleteRequest([
      'type' => 'user',
      'routeParams' => ['userName' => 'alice'],
    ]));

    $this->assertFalse($result['success']);
    $this->assertSame(500, $result['statusCode']);
  }

  /**
   * A 404 of a request without expected statuses stays an error.
   */
  public function testMakeRequestLogs404WithoutExpectedStatusAsError(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('notice');
    $logger->expects($this->once())->method('log')
      ->with(LogLevel::ERROR, 'WebProtégé request failed: @message', $this->anything());
    $actions = $this->createActions(self::API_SETTINGS, [new Response(404)], $logger);

    $result = $actions->makeRequest($actions->buildDeleteRequest([
      'type' => 'project',
      'routeParams' => ['projectId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
    ]));

    $this->assertFalse($result['success']);
    $this->assertSame(404, $result['statusCode']);
  }

  /**
   * Builds the actions; with responses, the client is a Guzzle MockHandler.
   *
   * @param array{host: string, apiBaseUrl: string, apiKey: string} $settings
   *   The WebProtégé settings.
   * @param \Psr\Http\Message\ResponseInterface[]|null $responses
   *   Canned responses, or NULL for a client double that is never called.
   * @param \Drupal\Core\Logger\LoggerChannelInterface|null $logger
   *   The logger channel, or NULL for a factory double.
   */
  private function createActions(array $settings, ?array $responses = NULL, ?LoggerChannelInterface $logger = NULL): SodaScsWebprotegeServiceActions {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($this->createMock(Config::class));

    $serviceHelpers = $this->createMock(SodaScsServiceHelpers::class);
    $serviceHelpers->method('initWebprotegeInstanceSettings')->willReturn($settings);

    $httpClient = $responses === NULL
      ? $this->createMock(ClientInterface::class)
      : new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    if ($logger !== NULL) {
      $loggerFactory->method('get')->willReturn($logger);
    }

    return new SodaScsWebprotegeServiceActions(
      $configFactory,
      $httpClient,
      $loggerFactory,
      $this->createMock(MessengerInterface::class),
      $serviceHelpers,
      $this->createMock(TranslationInterface::class),
    );
  }

}
