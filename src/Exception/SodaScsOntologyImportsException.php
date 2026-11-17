<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Exception;

/**
 * Thrown when the imports of an ontology document cannot be read.
 */
final class SodaScsOntologyImportsException extends \RuntimeException implements SodaScsManagerExceptionInterface {

  /**
   * The document is OWL/XML, which WebProtégé's axioms endpoint cannot read.
   */
  public const OWL_XML = 1;

  /**
   * The document or one of its imports could not be read.
   */
  public const UNREADABLE = 2;

}
