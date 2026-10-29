<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers
 * @group soda_scs_manager
 */
class SodaScsWebprotegeHelpersOntologyTest extends UnitTestCase {

  /**
   * @covers ::removeImportDeclarations
   */
  public function testRemoveImportDeclarationsDropsOnlyImportLines(): void {
    $document = <<<'OFN'
Prefix(owl:=<http://www.w3.org/2002/07/owl#>)
Ontology(<http://example.org/demo>
Import(<http://example.org/imported>)
    Import(<http://example.org/second>)
Declaration(Class(<http://example.org/demo#Thing>))
)
OFN;

    $result = SodaScsWebprotegeHelpers::removeImportDeclarations($document);

    $this->assertStringNotContainsString('Import(', $result);
    $this->assertStringContainsString('Prefix(owl:=<http://www.w3.org/2002/07/owl#>)', $result);
    $this->assertStringContainsString('Declaration(Class(<http://example.org/demo#Thing>))', $result);
  }

}
