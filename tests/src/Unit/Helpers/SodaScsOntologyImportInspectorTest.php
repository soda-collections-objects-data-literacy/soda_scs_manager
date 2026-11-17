<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Exception\SodaScsOntologyImportsException;
use Drupal\soda_scs_manager\Helpers\SodaScsOntologyImportInspector;
use Drupal\Tests\UnitTestCase;

/**
 * Tests reading and checking the owl:imports of uploaded ontologies.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsOntologyImportInspector
 * @group soda_scs_manager
 */
class SodaScsOntologyImportInspectorTest extends UnitTestCase {

  private const OWL_PREFIX = "@prefix owl: <http://www.w3.org/2002/07/owl#> .\n";

  private const RDF_XML_OPEN = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:owl="http://www.w3.org/2002/07/owl#">';

  /**
   * Builds an inspector whose host lookups return the given addresses.
   *
   * @param array<string, list<string>> $resolvedHosts
   *   Addresses by host; unknown hosts do not resolve.
   */
  private function createInspector(array $resolvedHosts = []): SodaScsOntologyImportInspector {
    return new class($resolvedHosts) extends SodaScsOntologyImportInspector {

      /**
       * @param array<string, list<string>> $resolvedHosts
       *   Addresses by host.
       */
      public function __construct(private array $resolvedHosts) {}

      /**
       * {@inheritdoc}
       */
      protected function resolveHost(string $host): array {
        return $this->resolvedHosts[$host] ?? [];
      }

    };
  }

  /**
   * @covers ::importIris
   * @dataProvider providerDocumentsWithImports
   *
   * @param string $mimeType
   *   Content type of the document.
   * @param string $document
   *   The document.
   * @param list<string> $expected
   *   The import IRIs.
   */
  public function testImportIrisReadsEveryImportSpelling(string $mimeType, string $document, array $expected): void {
    $this->assertSame($expected, $this->createInspector()->importIris($document, $mimeType));
  }

  /**
   * Documents with imports, in the spellings each syntax allows.
   */
  public static function providerDocumentsWithImports(): array {
    return [
      'turtle prefixed name, two objects' => [
        'text/ttl',
        self::OWL_PREFIX . "<http://ex.org/o> owl:imports <http://a.org/x>, <http://b.org/y> .",
        ['http://a.org/x', 'http://b.org/y'],
      ],
      'turtle full predicate' => [
        'text/ttl',
        "<http://ex.org/o> <http://www.w3.org/2002/07/owl#imports> <http://a.org/x> .",
        ['http://a.org/x'],
      ],
      'turtle other prefix' => [
        'text/ttl',
        "PREFIX o: <http://www.w3.org/2002/07/owl#>\n<http://ex.org/o> o:imports <http://a.org/x> .",
        ['http://a.org/x'],
      ],
      'turtle unicode escape' => [
        'text/ttl',
        "<http://ex.org/o> <http://www.w3.org/2002/07/owl#\\u0069mports> <http://a.org/x> .",
        ['http://a.org/x'],
      ],
      'turtle prefixed object' => [
        'text/ttl',
        self::OWL_PREFIX . "@prefix crm: <http://www.cidoc-crm.org/> .\n<http://ex.org/o> owl:imports crm:cidoc-crm .",
        ['http://www.cidoc-crm.org/cidoc-crm'],
      ],
      'turtle after other predicates' => [
        'text/ttl',
        self::OWL_PREFIX . "<http://ex.org/o> a owl:Ontology ;\n  owl:imports <http://a.org/x> ;\n  owl:versionInfo \"1\" .",
        ['http://a.org/x'],
      ],
      'turtle duplicates once' => [
        'text/ttl',
        self::OWL_PREFIX . "<http://ex.org/o> owl:imports <http://a.org/x> .\n<http://ex.org/p> owl:imports <http://a.org/x> .",
        ['http://a.org/x'],
      ],
      'turtle similar names are no imports' => [
        'text/ttl',
        "@prefix ex: <http://ex.org/> .\nex:A ex:importsTax ex:B ; ex:imports ex:C .",
        [],
      ],
      'functional syntax iri and prefixed name' => [
        'text/owl-functional',
        "Prefix(:=<http://ex.org/>)\nOntology(<http://ex.org/o>\nImport(<http://a.org/x>)\nImport(:y)\n)",
        ['http://a.org/x', 'http://ex.org/y'],
      ],
      'functional syntax without imports' => [
        'text/owl-functional',
        "Ontology(<http://ex.org/o>\nDeclaration(Class(<http://ex.org/A>))\n)",
        [],
      ],
      'rdf/xml resource' => [
        'application/rdf+xml',
        self::RDF_XML_OPEN . '<owl:Ontology rdf:about="http://ex.org/o"><owl:imports rdf:resource="http://a.org/x"/></owl:Ontology></rdf:RDF>',
        ['http://a.org/x'],
      ],
      'rdf/xml nested ontology' => [
        'application/rdf+xml',
        self::RDF_XML_OPEN . '<owl:Ontology rdf:about="http://ex.org/o"><owl:imports><owl:Ontology rdf:about="http://a.org/x"/></owl:imports></owl:Ontology></rdf:RDF>',
        ['http://a.org/x'],
      ],
      'rdf/xml internal entity' => [
        'application/rdf+xml',
        '<?xml version="1.0"?><!DOCTYPE rdf:RDF [<!ENTITY a "http://a.org/">]>' . self::RDF_XML_OPEN . '<owl:Ontology rdf:about="http://ex.org/o"><owl:imports rdf:resource="&a;x"/></owl:Ontology></rdf:RDF>',
        ['http://a.org/x'],
      ],
      'rdf/xml ontology as root' => [
        'application/rdf+xml',
        '<owl:Ontology xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:owl="http://www.w3.org/2002/07/owl#" rdf:about="http://ex.org/o"><owl:imports rdf:resource="http://a.org/x"/></owl:Ontology>',
        ['http://a.org/x'],
      ],
    ];
  }

  /**
   * @covers ::importIris
   * @dataProvider providerUnreadableImports
   *
   * @param string $mimeType
   *   Content type of the document.
   * @param string $document
   *   The document.
   * @param int $code
   *   Expected exception code.
   */
  public function testImportIrisRefusesWhatItCannotRead(string $mimeType, string $document, int $code): void {
    $this->expectException(SodaScsOntologyImportsException::class);
    $this->expectExceptionCode($code);

    $this->createInspector()->importIris($document, $mimeType);
  }

  /**
   * Documents whose imports cannot be checked.
   */
  public static function providerUnreadableImports(): array {
    $unreadable = SodaScsOntologyImportsException::UNREADABLE;
    return [
      'turtle relative import' => [
        'text/ttl',
        self::OWL_PREFIX . "<http://ex.org/o> owl:imports <other.ttl> .",
        $unreadable,
      ],
      'turtle relative predicate' => [
        'text/ttl',
        "@base <http://www.w3.org/2002/07/owl#> .\n<http://ex.org/o> <imports> <http://a.org/x>, <x> .",
        $unreadable,
      ],
      'turtle literal object' => [
        'text/ttl',
        self::OWL_PREFIX . "<http://ex.org/o> owl:imports \"http://a.org/x\" .",
        $unreadable,
      ],
      'turtle unknown prefix' => ['text/ttl', self::OWL_PREFIX . "<http://ex.org/o> owl:imports nope:x .", $unreadable],
      'turtle comment that names owl:imports' => [
        'text/ttl',
        self::OWL_PREFIX . "# owl:imports is resolved by WebProtégé\n<http://ex.org/o> a owl:Ontology .",
        $unreadable,
      ],
      'functional syntax unknown prefix' => [
        'text/owl-functional',
        "Ontology(<http://ex.org/o>\nImport(nope:x)\n)",
        $unreadable,
      ],
      'rdf/xml relative resource' => [
        'application/rdf+xml',
        self::RDF_XML_OPEN . '<owl:Ontology rdf:about="http://ex.org/o"><owl:imports rdf:resource="other.owl"/></owl:Ontology></rdf:RDF>',
        $unreadable,
      ],
      'rdf/xml empty imports' => [
        'application/rdf+xml',
        self::RDF_XML_OPEN . '<owl:Ontology rdf:about="http://ex.org/o"><owl:imports/></owl:Ontology></rdf:RDF>',
        $unreadable,
      ],
      'rdf/xml nested node without iri' => [
        'application/rdf+xml',
        self::RDF_XML_OPEN . '<owl:Ontology rdf:about="http://ex.org/o"><owl:imports><owl:Ontology/></owl:imports></owl:Ontology></rdf:RDF>',
        $unreadable,
      ],
      'malformed rdf/xml' => ['application/rdf+xml', self::RDF_XML_OPEN . '<owl:Ontology>', $unreadable],
      'owl/xml' => [
        'application/rdf+xml',
        '<Ontology xmlns="http://www.w3.org/2002/07/owl#" ontologyIRI="http://ex.org/o"><Import>http://a.org/x</Import></Ontology>',
        SodaScsOntologyImportsException::OWL_XML,
      ],
      'unsupported content type' => ['application/owl+xml', '<Ontology/>', $unreadable],
    ];
  }

  /**
   * @covers ::importIris
   */
  public function testImportIrisNeverLoadsExternalEntities(): void {
    $marker = tempnam(sys_get_temp_dir(), 'scs-entity-');
    $this->assertIsString($marker);
    file_put_contents($marker, 'http://leaked.example.org/');
    $document = '<?xml version="1.0"?><!DOCTYPE rdf:RDF [<!ENTITY ext SYSTEM "file://' . $marker . '">]>' . self::RDF_XML_OPEN . '<owl:Ontology rdf:about="http://ex.org/o"><owl:imports rdf:resource="http://a.org/x"/><owl:versionInfo>&ext;</owl:versionInfo></owl:Ontology></rdf:RDF>';

    try {
      $this->assertSame(['http://a.org/x'], $this->createInspector()->importIris($document, 'application/rdf+xml'));
    }
    finally {
      unlink($marker);
    }
  }

  /**
   * @covers ::isAllowedImportIri
   * @dataProvider providerImportTargets
   *
   * @param bool $expected
   *   Whether WebProtégé may fetch the IRI.
   * @param string $iri
   *   The import IRI.
   * @param array<string, list<string>> $resolvedHosts
   *   Addresses the host lookup returns.
   */
  public function testIsAllowedImportIri(bool $expected, string $iri, array $resolvedHosts = []): void {
    $this->assertSame($expected, $this->createInspector($resolvedHosts)->isAllowedImportIri($iri));
  }

  /**
   * Import targets, public and internal.
   */
  public static function providerImportTargets(): array {
    $public = ['www.cidoc-crm.org' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']];
    return [
      'public http host' => [TRUE, 'http://www.cidoc-crm.org/cidoc-crm/', $public],
      'public https host with port' => [TRUE, 'https://www.cidoc-crm.org:8443/x', $public],
      'public address' => [TRUE, 'http://8.8.8.8/o.owl'],
      'host without dot (container name)' => [FALSE, 'http://core--portainer--app:9000/'],
      'localhost' => [FALSE, 'http://localhost/o.owl'],
      'subdomain of localhost' => [FALSE, 'http://app.localhost/o.owl', ['app.localhost' => ['8.8.8.8']]],
      'loopback address' => [FALSE, 'http://127.0.0.1/o.owl'],
      'private address' => [FALSE, 'http://172.18.0.4/o.owl'],
      'link-local metadata address' => [FALSE, 'http://169.254.169.254/latest/'],
      'carrier-grade nat address' => [FALSE, 'http://100.64.0.1/o.owl'],
      'ipv6 loopback' => [FALSE, 'http://[::1]/o.owl'],
      'ipv6 unique local' => [FALSE, 'http://[fd00::1]/o.owl'],
      'host on a private address' => [
        FALSE,
        'https://intranet.example.org/o.owl',
        ['intranet.example.org' => ['10.1.2.3']],
      ],
      'host on mixed addresses' => [
        FALSE,
        'https://mixed.example.org/o.owl',
        ['mixed.example.org' => ['93.184.215.14', '192.168.0.1']],
      ],
      'host that does not resolve' => [FALSE, 'https://nowhere.example.org/o.owl'],
      'file scheme' => [FALSE, 'file:///etc/passwd'],
      'ftp scheme' => [FALSE, 'ftp://www.cidoc-crm.org/x', $public],
      'urn' => [FALSE, 'urn:webprotege:ontology:1'],
    ];
  }

}
