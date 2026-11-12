<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

/**
 * Fixtures shared by the tests of the create path (issue 3637658).
 */
trait SodaScsCreatePathTestTrait {

  /**
   * Builds a stand-in for a field item list.
   *
   * It answers what the create path reads from a field: `->value` and
   * `->referencedEntities()`.
   *
   * @param mixed $value
   *   The field value.
   * @param array $referencedEntities
   *   The entities the field references.
   *
   * @return object
   *   The field list stand-in.
   */
  protected function fieldList(mixed $value = NULL, array $referencedEntities = []): object {
    return new class($value, $referencedEntities) {

      /**
       * Constructs the stand-in.
       */
      public function __construct(
        public mixed $value,
        private array $referencedEntities,
      ) {}

      /**
       * Returns the referenced entities.
       */
      public function referencedEntities(): array {
        return $this->referencedEntities;
      }

    };
  }

  /**
   * Asserts the shape every failure result of the create path must have.
   *
   * @param array $result
   *   The result of a createComponent() or createStack() call.
   */
  protected function assertCompleteFailure(array $result): void {
    $this->assertArrayHasKey('success', $result);
    $this->assertFalse($result['success'], 'A failure result has success FALSE.');
    $this->assertArrayHasKey('error', $result);
    $error = $result['error'];
    $this->assertTrue(
      $error instanceof \Throwable || (is_string($error) && $error !== ''),
      'A failure result has a Throwable or a non-empty string as error.',
    );
    $this->assertArrayHasKey('message', $result);
    $this->assertInstanceOf(
      'Drupal\Core\StringTranslation\TranslatableMarkup',
      $result['message'],
      'The message of a failure result is translatable.',
    );
  }

}
