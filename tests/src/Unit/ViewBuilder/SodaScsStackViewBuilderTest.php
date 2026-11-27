<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\ViewBuilder;

use Drupal\soda_scs_manager\ViewBuilder\SodaScsStackViewBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression tests for the stack entity view builder.
 */
#[CoversMethod(SodaScsStackViewBuilder::class, 'resolveDeleteDestination')]
#[Group('soda_scs_manager')]
class SodaScsStackViewBuilderTest extends UnitTestCase {

  /**
   * Stack pages call this when rendering the delete action.
   */
  public function testResolveDeleteDestinationIsDefined(): void {
    $this->assertTrue(
      method_exists(SodaScsStackViewBuilder::class, 'resolveDeleteDestination'),
      'SodaScsStackViewBuilder must implement resolveDeleteDestination().',
    );
  }

}
