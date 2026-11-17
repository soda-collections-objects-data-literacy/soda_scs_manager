<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\soda_scs_manager\Exception\SodaScsOntologyImportsException;
use Drupal\soda_scs_manager\RequestActions\SodaScsWebprotegeServiceActions;

/**
 * Reads the owl:imports of an uploaded ontology and checks where they point.
 *
 * WebProtégé fetches every import of a posted document itself, from inside
 * the Docker network. Imports are wanted, but an import of an internal
 * address (a container name, a private IP, file:) would let users reach
 * services that are not public. The manager therefore checks the direct
 * imports before it posts the document. Imports of imported ontologies and
 * HTTP redirects are fetched by WebProtégé without this check.
 *
 * Reading is fail-closed: an import whose target cannot be read makes the
 * whole document unreadable, so nothing unchecked reaches WebProtégé.
 */
class SodaScsOntologyImportInspector {

  /**
   * IRI of the owl:imports property.
   */
  public const OWL_IMPORTS = self::OWL_NAMESPACE . 'imports';

  /**
   * OWL namespace.
   */
  private const OWL_NAMESPACE = 'http://www.w3.org/2002/07/owl#';

  /**
   * RDF namespace.
   */
  private const RDF_NAMESPACE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

  /**
   * Prefixed name of OWL functional syntax and Turtle (prefix may be empty).
   */
  private const PREFIXED_NAME = '(?<prefix>[A-Za-z][\w.-]*)?:(?<local>(?:[\w-]|\\\\.)(?:[\w.-]|\\\\.)*)?';

  /**
   * Returns the targets of the document's direct owl:imports.
   *
   * @param string $document
   *   The ontology document.
   * @param string $mimeType
   *   One of SodaScsWebprotegeServiceActions::ONTOLOGY_DOCUMENT_MIME_TYPES.
   *
   * @return list<string>
   *   Import IRIs as written in the document, without duplicates.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsOntologyImportsException
   *   When the document is OWL/XML, or an import cannot be read.
   */
  public function importIris(string $document, string $mimeType): array {
    $iris = match ($mimeType) {
      SodaScsWebprotegeServiceActions::FUNCTIONAL_SYNTAX_MIME_TYPE => $this->functionalSyntaxImports($document),
      'application/rdf+xml' => $this->rdfXmlImports($document),
      'text/ttl' => $this->turtleImports($document),
      default => throw new SodaScsOntologyImportsException(sprintf('Unsupported content type %s.', $mimeType), SodaScsOntologyImportsException::UNREADABLE),
    };
    return array_values(array_unique($iris));
  }

  /**
   * Whether WebProtégé may fetch this import.
   *
   * Only http and https IRIs whose host resolves to public addresses only.
   * Container names have no dot and are refused before any lookup.
   */
  public function isAllowedImportIri(string $iri): bool {
    $parts = parse_url($iri);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
    if (!in_array($scheme, ['http', 'https'], TRUE) || $host === '') {
      return FALSE;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return self::isPublicAddress($host);
    }
    if (!str_contains($host, '.') || str_ends_with($host, '.localhost')) {
      return FALSE;
    }
    $addresses = $this->resolveHost($host);
    if ($addresses === []) {
      return FALSE;
    }
    foreach ($addresses as $address) {
      if (!self::isPublicAddress($address)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Looks up the IPv4 and IPv6 addresses of a host name.
   *
   * @return list<string>
   *   The addresses; empty when the name does not resolve.
   */
  protected function resolveHost(string $host): array {
    $addresses = @gethostbynamel($host) ?: [];
    $records = @dns_get_record($host, DNS_AAAA) ?: [];
    foreach ($records as $record) {
      if (isset($record['ipv6'])) {
        $addresses[] = $record['ipv6'];
      }
    }
    return array_values($addresses);
  }

  /**
   * Imports of an OWL functional syntax document: Import(<iri>) or a name.
   *
   * @return list<string>
   *   The import IRIs.
   */
  private function functionalSyntaxImports(string $document): array {
    $prefixes = [];
    preg_match_all('/Prefix\(\s*([A-Za-z][\w.-]*)?:\s*=\s*<([^>]*)>\s*\)/', $document, $declarations, PREG_SET_ORDER);
    foreach ($declarations as $declaration) {
      $prefixes[$declaration[1]] = $declaration[2];
    }

    $iris = [];
    preg_match_all('/\bImport\(\s*([^)\s]*)\s*\)?/', $document, $imports);
    foreach ($imports[1] as $target) {
      $iris[] = $this->expandTerm($target, $prefixes);
    }
    return $iris;
  }

  /**
   * Imports of an RDF/XML document: owl:imports with rdf:resource or a node.
   *
   * Internal entities are expanded, external ones are never loaded.
   *
   * @return list<string>
   *   The import IRIs.
   */
  private function rdfXmlImports(string $document): array {
    $previous = libxml_use_internal_errors(TRUE);
    libxml_clear_errors();
    try {
      $reader = \XMLReader::XML($document, NULL, LIBXML_NONET);
      $iris = [];
      $isRoot = TRUE;
      $awaitingNode = FALSE;
      while ($reader->read()) {
        if ($reader->nodeType !== \XMLReader::ELEMENT) {
          continue;
        }
        if ($isRoot && self::isOwlXmlRoot($reader)) {
          throw new SodaScsOntologyImportsException('The document is OWL/XML.', SodaScsOntologyImportsException::OWL_XML);
        }
        $isRoot = FALSE;
        if ($awaitingNode) {
          $iris[] = $this->absoluteIri($reader->getAttributeNs('about', self::RDF_NAMESPACE));
          $awaitingNode = FALSE;
          continue;
        }
        if ($reader->namespaceURI !== self::OWL_NAMESPACE || $reader->localName !== 'imports') {
          continue;
        }
        $resource = $reader->getAttributeNs('resource', self::RDF_NAMESPACE);
        if ($resource !== NULL) {
          $iris[] = $this->absoluteIri($resource);
          continue;
        }
        if ($reader->isEmptyElement) {
          throw new SodaScsOntologyImportsException('An owl:imports element names no ontology.', SodaScsOntologyImportsException::UNREADABLE);
        }
        $awaitingNode = TRUE;
      }
      $error = libxml_get_last_error();
      if ($error !== FALSE) {
        throw new SodaScsOntologyImportsException(sprintf('Malformed RDF/XML at line %d: %s', $error->line, trim($error->message)), SodaScsOntologyImportsException::UNREADABLE);
      }
      if ($awaitingNode) {
        throw new SodaScsOntologyImportsException('An owl:imports element names no ontology.', SodaScsOntologyImportsException::UNREADABLE);
      }
      return $iris;
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
  }

  /**
   * Imports of a Turtle document.
   *
   * The predicate counts as owl:imports in every spelling Turtle allows: the
   * full IRI, \u escapes, any prefix bound to the OWL namespace, and relative
   * IRIs (which @base could turn into owl:imports). Matches inside comments
   * or literals are read too: such a document is refused rather than let an
   * import through unchecked.
   *
   * @return list<string>
   *   The import IRIs.
   */
  private function turtleImports(string $document): array {
    $document = self::decodeUnicodeEscapes($document);
    $prefixes = [];
    preg_match_all('/(?:@prefix|\bPREFIX)\s+([A-Za-z][\w.-]*)?:\s*<([^>]*)>/i', $document, $declarations, PREG_SET_ORDER);
    foreach ($declarations as $declaration) {
      $prefixes[$declaration[1]] = $declaration[2];
    }

    $iris = [];
    $predicate = '/<(?<iri>[^>\s]*)>|' . self::PREFIXED_NAME . '/';
    preg_match_all($predicate, $document, $terms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
    foreach ($terms as $term) {
      if (!$this->isTurtleImportsPredicate($term, $prefixes)) {
        continue;
      }
      $offset = $term[0][1] + strlen($term[0][0]);
      foreach ($this->turtleObjectList($document, $offset) as $object) {
        $iris[] = $this->expandTerm($object, $prefixes);
      }
    }
    return $iris;
  }

  /**
   * Whether the matched Turtle term is, or may become, owl:imports.
   *
   * @param array<int|string, array{0: string|null, 1: int}> $term
   *   One match of turtleImports() with offsets.
   * @param array<string, string> $prefixes
   *   Prefix IRIs by prefix.
   */
  private function isTurtleImportsPredicate(array $term, array $prefixes): bool {
    if (isset($term['iri'][0])) {
      $iri = $term['iri'][0];
      return $iri === self::OWL_IMPORTS || (!self::isAbsoluteIri($iri) && str_ends_with($iri, 'imports'));
    }
    if (($term['local'][0] ?? NULL) !== 'imports') {
      return FALSE;
    }
    $namespace = $prefixes[$term['prefix'][0] ?? ''] ?? NULL;
    return $namespace === self::OWL_NAMESPACE || ($namespace !== NULL && !self::isAbsoluteIri($namespace));
  }

  /**
   * Reads the comma-separated objects that follow a Turtle predicate.
   *
   * @return list<string>
   *   The objects as written: <iri> or a prefixed name.
   */
  private function turtleObjectList(string $document, int $offset): array {
    $object = '/\G\s*(<[^>\s]*>|' . self::PREFIXED_NAME . ')\s*(,)?/';
    $objects = [];
    do {
      if (preg_match($object, $document, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1 || $match[1] === '') {
        throw new SodaScsOntologyImportsException(sprintf('An owl:imports at offset %d is not followed by an IRI.', $offset), SodaScsOntologyImportsException::UNREADABLE);
      }
      $objects[] = rtrim($match[1], '.');
      $offset += strlen($match[0]);
      $hasMore = ($match[4] ?? NULL) === ',';
    } while ($hasMore);
    return $objects;
  }

  /**
   * Expands <iri> or prefix:local to an absolute IRI.
   *
   * @param string $term
   *   The term as written in the document.
   * @param array<string, string> $prefixes
   *   Prefix IRIs by prefix.
   */
  private function expandTerm(string $term, array $prefixes): string {
    if (str_starts_with($term, '<') && str_ends_with($term, '>')) {
      return $this->absoluteIri(substr($term, 1, -1));
    }
    $colon = strpos($term, ':');
    if ($colon === FALSE || !isset($prefixes[substr($term, 0, $colon)])) {
      throw new SodaScsOntologyImportsException(sprintf('Import target "%s" is no IRI.', mb_substr($term, 0, 200)), SodaScsOntologyImportsException::UNREADABLE);
    }
    return $this->absoluteIri($prefixes[substr($term, 0, $colon)] . substr($term, $colon + 1));
  }

  /**
   * Returns the IRI when it is absolute.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsOntologyImportsException
   *   For a missing or relative IRI: its target depends on a base IRI.
   */
  private function absoluteIri(?string $iri): string {
    if ($iri === NULL || !self::isAbsoluteIri($iri)) {
      throw new SodaScsOntologyImportsException(sprintf('Import target "%s" is no absolute IRI.', mb_substr((string) $iri, 0, 200)), SodaScsOntologyImportsException::UNREADABLE);
    }
    return $iri;
  }

  /**
   * Replaces Turtle's \uXXXX and \UXXXXXXXX escapes.
   */
  private static function decodeUnicodeEscapes(string $document): string {
    return (string) preg_replace_callback(
      '/\\\\(?:u([0-9A-Fa-f]{4})|U([0-9A-Fa-f]{8}))/',
      static fn(array $match): string => mb_chr((int) hexdec($match[1] !== '' ? $match[1] : $match[2]), 'UTF-8') ?: '',
      $document,
    );
  }

  /**
   * Whether an IRI has a scheme.
   */
  private static function isAbsoluteIri(string $iri): bool {
    return preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $iri) === 1;
  }

  /**
   * Whether the root element is an OWL/XML ontology, not an RDF/XML one.
   *
   * RDF/XML may also start with owl:Ontology, but then it carries rdf:about
   * or another RDF attribute; OWL/XML carries ontologyIRI instead.
   */
  private static function isOwlXmlRoot(\XMLReader $reader): bool {
    if ($reader->namespaceURI !== self::OWL_NAMESPACE || $reader->localName !== 'Ontology') {
      return FALSE;
    }
    if ($reader->getAttribute('ontologyIRI') !== NULL) {
      return TRUE;
    }
    while ($reader->moveToNextAttribute()) {
      if ($reader->namespaceURI === self::RDF_NAMESPACE) {
        $reader->moveToElement();
        return FALSE;
      }
    }
    $reader->moveToElement();
    return TRUE;
  }

  /**
   * Whether an IP address is globally routable.
   */
  private static function isPublicAddress(string $address): bool {
    return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== FALSE;
  }

}
