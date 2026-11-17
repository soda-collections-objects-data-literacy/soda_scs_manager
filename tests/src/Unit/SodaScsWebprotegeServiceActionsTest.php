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
   * @covers ::buildAddAxiomsRequest
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
   * @covers ::buildAddAxiomsRequest
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
   * @covers ::buildAddAxiomsRequest
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
   * @covers ::buildAddAxiomsRequest
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
