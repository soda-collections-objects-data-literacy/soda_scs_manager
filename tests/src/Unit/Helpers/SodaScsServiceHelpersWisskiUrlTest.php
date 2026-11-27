<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the WissKI URLs built by SodaScsServiceHelpers.
 */
#[CoversMethod(SodaScsServiceHelpers::class, 'wisskiPublicInstanceId')]
#[Group('soda_scs_manager')]
class SodaScsServiceHelpersWisskiUrlTest extends UnitTestCase {

  /**
   * The .wisski subdomain already names the app, so the host drops one prefix.
   */
  public function testPublicInstanceIdDropsOneInternalPrefix(): void {
    $this->assertSame('robert-demo', SodaScsServiceHelpers::wisskiPublicInstanceId('wisski-robert-demo'));
    $this->assertSame('robert-demo', SodaScsServiceHelpers::wisskiPublicInstanceId('stack-robert-demo'));
    $this->assertSame('robert-demo', SodaScsServiceHelpers::wisskiPublicInstanceId('robert-demo'));
    $this->assertSame('wisski-collection', SodaScsServiceHelpers::wisskiPublicInstanceId('wisski-wisski-collection'));
    $this->assertSame('wisski-', SodaScsServiceHelpers::wisskiPublicInstanceId('wisski-'));
  }

}
