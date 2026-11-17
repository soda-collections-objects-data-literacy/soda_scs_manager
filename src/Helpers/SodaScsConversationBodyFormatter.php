<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;

/**
 * Turns stored conversation text into safe HTML for the thread.
 *
 * Supports a small Markdown subset after HTML escaping:
 * - Headings: `#`, `##`, `###`
 * - Bold: `**text**` or `__text__`
 * - Italic: `*text*` or `_text_`
 * - Underline: `++text++`
 * - Strikethrough: `~~text~~`
 * - Links: `[label](https://…)` and bare http(s) URLs
 * - Line breaks.
 */
final class SodaScsConversationBodyFormatter {

  /**
   * Escapes the text and applies the allowed Markdown.
   *
   * @param string $body
   *   The stored message body.
   *
   * @return string
   *   Safe HTML (still needs Markup::create at the render edge).
   */
  public static function toHtml(string $body): string {
    $text = Html::escape($body);
    // Links first, as placeholders, so italic `_` cannot touch target="_blank".
    [$text, $links] = self::extractLinks($text);
    $text = self::replaceInline($text, '/~~(.+?)~~/', 'del');
    $text = self::replaceInline($text, '/\*\*(.+?)\*\*/', 'strong');
    $text = self::replaceInline($text, '/__(.+?)__/', 'strong');
    $text = self::replaceInline($text, '/\+\+(.+?)\+\+/', 'u');
    $text = self::replaceInline($text, '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', 'em');
    $text = self::replaceInline($text, '/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/', 'em');
    $text = self::replaceHeadings($text);
    $text = self::restoreLinks($text, $links);
    return nl2br($text, FALSE);
  }

  /**
   * Replaces Markdown and bare links with placeholders.
   *
   * @return array{0: string, 1: list<string>}
   *   Text with placeholders, and the link HTML snippets.
   */
  private static function extractLinks(string $text): array {
    $links = [];
    $store = static function (string $html) use (&$links): string {
      $links[] = $html;
      return '%%SCSLINK' . (count($links) - 1) . '%%';
    };

    $text = preg_replace_callback(
      '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/',
      static function (array $matches) use ($store): string {
        $url = self::sanitizeUrl($matches[2]);
        if ($url === NULL) {
          return $matches[0];
        }
        return $store('<a href="' . Html::escape($url) . '" rel="noopener noreferrer" target="_blank">' . $matches[1] . '</a>');
      },
      $text,
    ) ?? $text;

    $text = preg_replace_callback(
      '/(?<!["\'>])(https?:\/\/[^\s<]+)/',
      static function (array $matches) use ($store): string {
        $escapedUrl = rtrim($matches[1], '.,);]');
        $url = self::sanitizeUrl($escapedUrl);
        if ($url === NULL) {
          return $matches[1];
        }
        $suffix = substr($matches[1], strlen($escapedUrl));
        return $store('<a href="' . Html::escape($url) . '" rel="noopener noreferrer" target="_blank">' . $escapedUrl . '</a>') . $suffix;
      },
      $text,
    ) ?? $text;

    return [$text, $links];
  }

  /**
   * Wraps `#` / `##` / `###` lines in heading tags.
   */
  private static function replaceHeadings(string $text): string {
    $text = preg_replace('/^### (.+)$/m', '<h5>$1</h5>', $text) ?? $text;
    $text = preg_replace('/^## (.+)$/m', '<h4>$1</h4>', $text) ?? $text;
    return preg_replace('/^# (.+)$/m', '<h3>$1</h3>', $text) ?? $text;
  }

  /**
   * Replaces a delimited inline pattern with a single HTML tag.
   */
  private static function replaceInline(string $text, string $pattern, string $tag): string {
    return preg_replace(
      $pattern,
      '<' . $tag . '>$1</' . $tag . '>',
      $text,
    ) ?? $text;
  }

  /**
   * Puts extracted link HTML back in place of the placeholders.
   *
   * @param string $text
   *   Text that still contains placeholders.
   * @param list<string> $links
   *   Link HTML snippets.
   */
  private static function restoreLinks(string $text, array $links): string {
    foreach ($links as $index => $html) {
      $text = str_replace('%%SCSLINK' . $index . '%%', $html, $text);
    }
    return $text;
  }

  /**
   * Keeps only absolute http(s) URLs.
   */
  private static function sanitizeUrl(string $url): ?string {
    $url = Html::decodeEntities($url);
    if (!UrlHelper::isValid($url, TRUE) || !preg_match('#^https?://#i', $url)) {
      return NULL;
    }
    return $url;
  }

}
