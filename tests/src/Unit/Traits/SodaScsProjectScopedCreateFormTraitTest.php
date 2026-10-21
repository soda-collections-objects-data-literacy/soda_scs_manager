<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Traits;

use Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait
 *
 * @group soda_scs_manager
 */
class SodaScsProjectScopedCreateFormTraitTest extends UnitTestCase {

  /**
   * Only the query string "1" skips the project picker.
   *
   * @covers ::queryDefersProjectSelection
   */
  public function testQueryDefersProjectSelectionOnlyForTheStringOne(): void {
    $this->assertTrue(DeferProjectSelectionProbe::queryDefersProjectSelection('1'));
    $this->assertFalse(DeferProjectSelectionProbe::queryDefersProjectSelection('0'));
    $this->assertFalse(DeferProjectSelectionProbe::queryDefersProjectSelection('true'));
    $this->assertFalse(DeferProjectSelectionProbe::queryDefersProjectSelection(1));
    $this->assertFalse(DeferProjectSelectionProbe::queryDefersProjectSelection(NULL));
  }

}

/**
 * Exposes the trait method under test.
 */
class DeferProjectSelectionProbe {

  use SodaScsProjectScopedCreateFormTrait;

}
