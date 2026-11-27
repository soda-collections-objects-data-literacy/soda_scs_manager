<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the import of uploaded ontology files into WebProtégé projects.
 */
#[CoversMethod(SodaScsWebprotegeHelpers::class, 'importOntologyDocument')]
#[CoversMethod(SodaScsWebprotegeHelpers::class, 'parserErrorFromResponse')]
#[Group('soda_scs_manager')]
class SodaScsWebprotegeHelpersOntologyImportTest extends SodaScsWebprotegeHelpersTestBase {

  /**
   * Smallest RDF/XML document: one ontology, no imports.
   */
  private const RDF_XML_DOCUMENT = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:owl="http://www.w3.org/2002/07/owl#"><owl:Ontology rdf:about="http://example.org/o"/></rdf:RDF>';

  /**
   * Turtle document that imports CIDOC CRM and a second public ontology.
   */
  private const TURTLE_WITH_IMPORTS = "@prefix owl: <http://www.w3.org/2002/07/owl#> .\n<http://example.org/o> a owl:Ontology ;\n  owl:imports <http://www.cidoc-crm.org/cidoc-crm/>, <https://example.org/base.ttl> .\n";

  /**
   * Seconds the upload request may take (ONTOLOGY_IMPORT_TIMEOUT).
   */
  private const IMPORT_TIMEOUT = 240;

  /**
   * Tests that importOntologyDocument() posts content type of the extension.
   */
  #[DataProvider('providerOntologyExtensions')]
  public function testImportOntologyDocumentPostsContentTypeOfTheExtension(string $extension, string $expectedMimeType): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerAxiomsAccepted();

    $document = in_array(strtolower($extension), ['owl', 'rdf'], TRUE) ? self::RDF_XML_DOCUMENT : 'document body';

    $result = $this->createHelper()->importOntologyDocument('Add classes', $document, $extension, $this->createProject($values));

    $this->assertTrue($result->success);
    $this->assertSame(['axioms'], $this->sentRequests);
    $this->assertSame(['Add classes', $document, self::PROJECT_UUID, $expectedMimeType, self::IMPORT_TIMEOUT], $this->axiomsCalls[0]);
  }

  /**
   * Extensions, also in upper case, with the content type posted.
   */
  public static function providerOntologyExtensions(): array {
    return [
      'functional syntax' => ['ofn', 'text/owl-functional'],
      'rdf/xml owl' => ['owl', 'application/rdf+xml'],
      'rdf/xml rdf' => ['rdf', 'application/rdf+xml'],
      'turtle' => ['ttl', 'text/ttl'],
      'upper case turtle' => ['TTL', 'text/ttl'],
      'mixed case functional syntax' => ['Ofn', 'text/owl-functional'],
    ];
  }

  /**
   * Tests importOntologyDocument().
   *
   * Posts nothing for unsupported extension.
   */
  public function testImportOntologyDocumentPostsNothingForUnsupportedExtension(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerAxiomsAccepted();

    $result = $this->createHelper()->importOntologyDocument('Add classes', 'document body', 'txt', $this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame([], $this->sentRequests);
    $this->assertSame([], $this->axiomsCalls);
    $this->assertSame('Could not import the ontology into demo project.', $result->message);
  }

  /**
   * Tests importOntologyDocument().
   *
   * Posts nothing when no project is linked.
   */
  public function testImportOntologyDocumentPostsNothingWhenNoProjectIsLinked(): void {
    $values = [];
    $this->answerAxiomsAccepted();

    $result = $this->createHelper()->importOntologyDocument('Add classes', 'document body', 'ttl', $this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame([], $this->sentRequests);
    $this->assertSame([], $this->axiomsCalls);
  }

  /**
   * Tests importOntologyDocument().
   *
   * Posts document with its imports unchanged.
   *
   * @param string $extension
   *   File extension.
   * @param string $document
   *   Document with owl:imports of public addresses.
   * @param list<string> $expectedImports
   *   The imports the result reports.
   */
  #[DataProvider('providerDocumentsWithPublicImports')]
  public function testImportOntologyDocumentPostsDocumentWithItsImportsUnchanged(string $extension, string $document, array $expectedImports): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerAxiomsAccepted();

    $result = $this->createHelper()->importOntologyDocument('Add classes', $document, $extension, $this->createProject($values));

    $this->assertTrue($result->success);
    $this->assertSame($document, $this->axiomsCalls[0][1]);
    $this->assertSame($expectedImports, $result->data['imports']);
  }

  /**
   * Documents in every format whose imports point at public addresses.
   */
  public static function providerDocumentsWithPublicImports(): array {
    return [
      'functional syntax' => [
        'ofn',
        "Prefix(:=<http://example.org/>)\nOntology(<http://example.org/o>\nImport(<http://www.cidoc-crm.org/cidoc-crm/>)\nDeclaration(Class(:A))\n)\n",
        ['http://www.cidoc-crm.org/cidoc-crm/'],
      ],
      'turtle' => [
        'ttl',
        self::TURTLE_WITH_IMPORTS,
        ['http://www.cidoc-crm.org/cidoc-crm/', 'https://example.org/base.ttl'],
      ],
      'rdf/xml' => [
        'owl',
        '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:owl="http://www.w3.org/2002/07/owl#"><owl:Ontology rdf:about="http://example.org/o"><owl:imports rdf:resource="http://www.cidoc-crm.org/cidoc-crm/"/></owl:Ontology></rdf:RDF>',
        ['http://www.cidoc-crm.org/cidoc-crm/'],
      ],
    ];
  }

  /**
   * Tests that importOntologyDocument() refuses imports of internal addresses.
   *
   * @param string $import
   *   Import IRI the document names.
   * @param array<string, list<string>> $resolvedHosts
   *   Addresses the host lookup returns.
   */
  #[DataProvider('providerBlockedImports')]
  public function testImportOntologyDocumentRefusesImportsOfInternalAddresses(string $import, array $resolvedHosts): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerAxiomsAccepted();
    $this->resolvedHosts = $resolvedHosts;
    $logged = [];
    $this->logger->expects($this->once())->method('warning')->willReturnCallback(
      static function (string $message, array $context) use (&$logged): void {
        $logged = $context;
      }
    );
    $document = "@prefix owl: <http://www.w3.org/2002/07/owl#> .\n<http://example.org/o> owl:imports <http://www.cidoc-crm.org/cidoc-crm/>, <" . $import . "> .\n";

    $result = $this->createHelper()->importOntologyDocument('Add classes', $document, 'ttl', $this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame([], $this->sentRequests);
    $this->assertSame('The ontology file imports ' . $import . ', which is not a public http or https address. WebProtégé can only load imports that anyone can download from the internet.', $result->message);
    $this->assertSame($import, $logged['@iri']);
    $this->assertSame(7, $logged['@project']);
  }

  /**
   * Imports WebProtégé must not fetch.
   */
  public static function providerBlockedImports(): array {
    return [
      'container name' => ['http://core--portainer--app:9000/api/endpoints', []],
      'private address' => ['http://10.0.0.5/secret.owl', []],
      'host on a private address' => [
        'https://intranet.example.org/o.owl',
        ['intranet.example.org' => ['192.168.1.20']],
      ],
      'local file' => ['file:///etc/passwd', []],
    ];
  }

  /**
   * Tests that importOntologyDocument() refuses import it cannot read.
   */
  public function testImportOntologyDocumentRefusesImportItCannotRead(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerAxiomsAccepted();
    $this->logger->expects($this->once())->method('warning');

    $result = $this->createHelper()->importOntologyDocument('Add classes', "<http://example.org/o> <http://www.w3.org/2002/07/owl#imports> <other.ttl> .\n", 'ttl', $this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame([], $this->sentRequests);
    $this->assertSame('The ontology file or one of its imports (owl:imports) could not be read. Check that the file is valid RDF/XML, Turtle or OWL functional syntax and that every import is a full http or https address.', $result->message);
  }

  /**
   * Tests that importOntologyDocument() refuses OWL XML with hint.
   */
  public function testImportOntologyDocumentRefusesOwlXmlWithHint(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerAxiomsAccepted();
    $document = '<?xml version="1.0"?><Ontology xmlns="http://www.w3.org/2002/07/owl#" ontologyIRI="http://example.org/o"><Declaration><Class IRI="#A"/></Declaration></Ontology>';

    $result = $this->createHelper()->importOntologyDocument('Add classes', $document, 'owl', $this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame([], $this->sentRequests);
    $this->assertSame('This file is OWL/XML, which cannot be imported. Save the ontology as RDF/XML or Turtle in Protégé and upload that file.', $result->message);
  }

  /**
   * Tests importOntologyDocument().
   *
   * Names imports when WebProtégé cannot read them.
   */
  #[DataProvider('providerImportFailureStatuses')]
  public function testImportOntologyDocumentNamesImportsWhenWebprotegeCannotReadThem(int $statusCode): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerAxiomsRejected($statusCode, '{"errorMessage":"An error occurred while parsing the posted axioms","lineNumber":-1,"columnNumber":-1}');

    $result = $this->createHelper()->importOntologyDocument('Add classes', self::TURTLE_WITH_IMPORTS, 'ttl', $this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame('WebProtégé could not read the ontology file or one of its imports. Check that the file is valid RDF/XML, Turtle or OWL functional syntax and that every import can be downloaded.', $result->message);
  }

  /**
   * Statuses of a failed import.
   *
   * Unparsable file (400), import is no ontology (500).
   */
  public static function providerImportFailureStatuses(): array {
    return ['unparsable' => [400], 'import is no ontology' => [500]];
  }

  /**
   * Tests that importOntologyDocument() reports success and logs import.
   */
  public function testImportOntologyDocumentReportsSuccessAndLogsImport(): void {
    $values = ['webprotegeProjectId' => strtoupper(self::PROJECT_UUID)];
    $this->answerAxiomsAccepted();
    $this->logger->expects($this->never())->method('error');
    $this->logger->expects($this->once())->method('info')->with(
      $this->stringContains('Imported an ontology document'),
      $this->callback(static fn(array $context): bool => $context['@id'] === self::PROJECT_UUID && $context['@project'] === 7 && $context['@format'] === 'text/ttl'),
    );

    $result = $this->createHelper()->importOntologyDocument('Add classes', 'document body', 'ttl', $this->createProject($values));

    $this->assertTrue($result->success);
    $this->assertSame(['imports' => [], 'webprotegeProjectId' => self::PROJECT_UUID], $result->data);
    $this->assertSame('Imported the ontology into demo project.', $result->message);
    $this->assertSame(self::PROJECT_UUID, $this->axiomsCalls[0][2]);
  }

  /**
   * Tests importOntologyDocument().
   *
   * Logs parser error and keeps it from user message on 400.
   */
  public function testImportOntologyDocumentLogsParserErrorAndKeepsItFromUserMessageOn400(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerAxiomsRejected(400, '{"errorMessage":"Encountered unexpected token Foo","lineNumber":3,"columnNumber":14}');
    $logged = [];
    $this->logger->expects($this->once())->method('error')->willReturnCallback(
      static function (string $message, array $context) use (&$logged): void {
        $logged = $context;
      }
    );
    $this->logger->expects($this->never())->method('info');

    $result = $this->createHelper()->importOntologyDocument('Add classes', 'broken', 'ttl', $this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame('WebProtégé could not read the ontology file. Check that it is valid RDF/XML, Turtle or OWL functional syntax.', $result->message);
    $this->assertStringNotContainsString('unexpected token', $result->message);
    $this->assertStringNotContainsString('line 3', $result->message);
    $this->assertSame('Encountered unexpected token Foo (line 3, column 14)', $logged['@error']);
    $this->assertSame(400, $logged['@status']);
    $this->assertSame(self::PROJECT_UUID, $logged['@id']);
    $this->assertSame(7, $logged['@project']);
    $this->assertSame('text/ttl', $logged['@format']);
  }

  /**
   * Tests importOntologyDocument().
   *
   * Logs request error when 400 body is not JSON.
   */
  public function testImportOntologyDocumentLogsRequestErrorWhen400BodyIsNotJson(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerAxiomsRejected(400, '<html>bad request</html>');
    $logged = [];
    $this->logger->expects($this->once())->method('error')->willReturnCallback(
      static function (string $message, array $context) use (&$logged): void {
        $logged = $context;
      }
    );

    $result = $this->createHelper()->importOntologyDocument('Add classes', self::RDF_XML_DOCUMENT, 'owl', $this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame('HTTP 400 from WebProtégé', $logged['@error']);
    $this->assertStringContainsString('could not read the ontology file', $result->message);
    $this->assertStringNotContainsString('<html>', $result->message);
  }

  /**
   * Tests importOntologyDocument().
   *
   * Returns generic message when WebProtégé fails with 500.
   */
  public function testImportOntologyDocumentReturnsGenericMessageWhenWebprotegeFailsWith500(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerAxiomsRejected(500, '{"errorMessage":"stack trace with internals","lineNumber":1,"columnNumber":1}');
    $logged = [];
    $this->logger->expects($this->once())->method('error')->willReturnCallback(
      static function (string $message, array $context) use (&$logged): void {
        $logged = $context;
      }
    );

    $result = $this->createHelper()->importOntologyDocument('Add classes', 'document body', 'ofn', $this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame('Could not import the ontology into demo project.', $result->message);
    $this->assertSame('HTTP 500 from WebProtégé', $result->error);
    $this->assertSame(500, $logged['@status']);
  }

  /**
   * Tests that importOntologyDocument() fails without response body.
   */
  public function testImportOntologyDocumentFailsWithoutResponseBody(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answers['axioms'] = ['success' => FALSE, 'error' => 'Connection refused'];
    $logged = [];
    $this->logger->expects($this->once())->method('error')->willReturnCallback(
      static function (string $message, array $context) use (&$logged): void {
        $logged = $context;
      }
    );

    $result = $this->createHelper()->importOntologyDocument('Add classes', 'document body', 'ofn', $this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame('Connection refused', $result->error);
    $this->assertSame('Connection refused', $logged['@error']);
    $this->assertSame(0, $logged['@status']);
  }

  /**
   * Tests that parserErrorFromResponse() reads message line and column.
   */
  public function testParserErrorFromResponseReadsMessageLineAndColumn(): void {
    $response = new Response(400, [], '{"errorMessage":"Unexpected token","lineNumber":12,"columnNumber":5}');

    $this->assertSame('Unexpected token (line 12, column 5)', SodaScsWebprotegeHelpers::parserErrorFromResponse($response));
  }

  /**
   * Tests that parserErrorFromResponse() marks missing position with minus one.
   */
  public function testParserErrorFromResponseMarksMissingPositionWithMinusOne(): void {
    $response = new Response(400, [], '{"errorMessage":"Unexpected token"}');

    $this->assertSame('Unexpected token (line -1, column -1)', SodaScsWebprotegeHelpers::parserErrorFromResponse($response));
  }

  /**
   * Tests that parserErrorFromResponse() cuts long messages.
   */
  public function testParserErrorFromResponseCutsLongMessages(): void {
    $response = new Response(400, [], json_encode([
      'errorMessage' => str_repeat('x', 800),
      'lineNumber' => 1,
      'columnNumber' => 2,
    ]));

    $this->assertSame(str_repeat('x', 500) . ' (line 1, column 2)', SodaScsWebprotegeHelpers::parserErrorFromResponse($response));
  }

  /**
   * Tests that parserErrorFromResponse() returns null without parser message.
   */
  #[DataProvider('providerUnreadableParserResponses')]
  public function testParserErrorFromResponseReturnsNullWithoutParserMessage(mixed $response): void {
    $this->assertNull(SodaScsWebprotegeHelpers::parserErrorFromResponse($response));
  }

  /**
   * Responses that carry no parser message.
   */
  public static function providerUnreadableParserResponses(): array {
    return [
      'null' => [NULL],
      'string' => ['{"errorMessage":"x"}'],
      'object without body' => [new \stdClass()],
      'html body' => [new Response(400, [], '<html>bad request</html>')],
      'empty body' => [new Response(400, [], '')],
      'json list' => [new Response(400, [], '["a"]')],
      'json without message' => [new Response(400, [], '{"lineNumber":1}')],
      'message is no string' => [new Response(400, [], '{"errorMessage":{"a":1}}')],
    ];
  }

}
