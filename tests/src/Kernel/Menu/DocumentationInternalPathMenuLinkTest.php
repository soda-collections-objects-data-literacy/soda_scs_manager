<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Menu;

use Drupal\soda_scs_manager\Plugin\Menu\DocumentationInternalPathMenuLink;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Tests the documentation menu link before the documentation book exists.
 *
 * @group soda_scs_manager
 */
final class DocumentationInternalPathMenuLinkTest extends SodaScsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['path_alias'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
  }

  /**
   * Tests that the unrouted path renders with outbound path processing.
   *
   * Drupal 11.4 throws "Source path … has to start with a slash" when the
   * path reaches the alias processor without its leading slash.
   */
  public function testUnroutedPathKeepsLeadingSlash(): void {
    $link = DocumentationInternalPathMenuLink::create($this->container, [], 'soda_scs_manager.documentation', [
      'id'          => 'soda_scs_manager.documentation',
      'title'       => 'Documentation',
      'description' => '',
      'options'     => [],
      'route_name'  => '<front>',
      'provider'    => 'soda_scs_manager',
    ]);

    $url = $link->getUrlObject();

    $this->assertFalse($url->isRouted());
    $this->assertStringEndsWith(DocumentationInternalPathMenuLink::DOCUMENTATION_PATH, $url->toString());
  }

}
