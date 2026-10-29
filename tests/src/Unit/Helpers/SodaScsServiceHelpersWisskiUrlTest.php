<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
 * @group soda_scs_manager
 */
class SodaScsServiceHelpersWisskiUrlTest extends UnitTestCase {

  /**
   * The .wisski subdomain already names the app, so the host drops one prefix.
   *
   * @covers ::wisskiPublicInstanceId
   */
  public function testPublicInstanceIdDropsOneInternalPrefix(): void {
    $this->assertSame('robert-demo', SodaScsServiceHelpers::wisskiPublicInstanceId('wisski-robert-demo'));
    $this->assertSame('robert-demo', SodaScsServiceHelpers::wisskiPublicInstanceId('stack-robert-demo'));
    $this->assertSame('robert-demo', SodaScsServiceHelpers::wisskiPublicInstanceId('robert-demo'));
    $this->assertSame('wisski-collection', SodaScsServiceHelpers::wisskiPublicInstanceId('wisski-wisski-collection'));
    $this->assertSame('wisski-', SodaScsServiceHelpers::wisskiPublicInstanceId('wisski-'));
  }

}
