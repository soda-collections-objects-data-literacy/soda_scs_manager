<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsConversationBodyFormatter;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsConversationBodyFormatter
 *
 * @group soda_scs_manager
 */
final class SodaScsConversationBodyFormatterTest extends UnitTestCase {

  /**
   * @covers ::toHtml
   */
  public function testEscapesHtmlAndKeepsLineBreaks(): void {
    $html = SodaScsConversationBodyFormatter::toHtml("<b>x</b>\ny");
    $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;<br>', $html);
    $this->assertStringContainsString('y', $html);
  }

  /**
   * @covers ::toHtml
   */
  public function testFormatsHeadingsAndInlineStyles(): void {
    $html = SodaScsConversationBodyFormatter::toHtml(
      "# Title\n**bold** and *italic*\n__also bold__ _also italic_\n++under++ ~~strike~~",
    );
    $this->assertStringContainsString('<h3>Title</h3>', $html);
    $this->assertStringContainsString('<strong>bold</strong>', $html);
    $this->assertStringContainsString('<em>italic</em>', $html);
    $this->assertStringContainsString('<strong>also bold</strong>', $html);
    $this->assertStringContainsString('<em>also italic</em>', $html);
    $this->assertStringContainsString('<u>under</u>', $html);
    $this->assertStringContainsString('<del>strike</del>', $html);
  }

  /**
   * @covers ::toHtml
   */
  public function testFormatsSubheadings(): void {
    $html = SodaScsConversationBodyFormatter::toHtml("## Sub\n### Smaller");
    $this->assertStringContainsString('<h4>Sub</h4>', $html);
    $this->assertStringContainsString('<h5>Smaller</h5>', $html);
  }

  /**
   * @covers ::toHtml
   */
  public function testTurnsMarkdownAndBareHttpsLinksIntoAnchors(): void {
    $html = SodaScsConversationBodyFormatter::toHtml(
      "See [docs](https://example.org/a) and https://example.org/b.",
    );
    $this->assertStringContainsString(
      '<a href="https://example.org/a" rel="noopener noreferrer" target="_blank">docs</a>',
      $html,
    );
    $this->assertStringContainsString(
      '<a href="https://example.org/b" rel="noopener noreferrer" target="_blank">https://example.org/b</a>.',
      $html,
    );
  }

  /**
   * @covers ::toHtml
   */
  public function testRejectsNonHttpSchemes(): void {
    $html = SodaScsConversationBodyFormatter::toHtml('[x](javascript:alert(1))');
    $this->assertStringNotContainsString('<a ', $html);
    $this->assertStringContainsString('[x](javascript:alert(1))', $html);
  }

}
