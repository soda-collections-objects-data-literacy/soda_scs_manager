<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The module only sets semantic classes; all styling lives in the theme.
 */
#[Group('soda_scs_manager')]
class ModuleShipsNoCssTest extends UnitTestCase {

  /**
   * Whole-token patterns of the utility classes the module used to ship.
   *
   * Variant prefixes (hover:, md:, ...) and a leading "!" or "-" are removed
   * before matching.
   */
  private const UTILITY_CLASS_PATTERNS = [
    '(inline-)?(flex|grid|block)',
    'inline',
    'fixed|absolute|relative|sticky|static|truncate|italic|uppercase|underline',
    'border|rounded|shadow|grow|shrink|transition|ring',
    'flex-(wrap|nowrap|col|row|1|auto|none|shrink-0|grow)',
    'shrink-0|grow-0',
    '(items|justify|self|content|place-items|place-content)-[a-z-]+',
    'text-(left|center|right|justify|xs|sm|base|lg|[2-9]?xl)',
    'text-(white|black|transparent|current|[a-z]+-[0-9]{2,3})(/[0-9]+)?',
    'text-(stone|slate|gray|red|green|blue|cyan|emerald|orange|amber|yellow)(-[0-9]+)?',
    'bg-[a-z0-9-]+(/[0-9]+)?',
    'border-([trblxy]|[0-9]+|(white|black|transparent|[a-z]+-[0-9]{2,3}))(-[a-z0-9-]+)?(/[0-9]+)?',
    'rounded-[a-z0-9]+',
    'shadow-[a-z0-9]+',
    'font-(thin|light|normal|medium|semibold|bold|extrabold|black|mono|sans|serif)',
    '-?(m|mx|my|mt|mb|ml|mr|p|px|py|pt|pb|pl|pr|gap|gap-x|gap-y|space-x|space-y)-([0-9.]+|auto)',
    '(w|h|min-w|min-h|max-w|max-h|size)-[a-z0-9/.]+',
    '(z|inset|inset-x|inset-y|top|left|right|bottom)-[a-z0-9/.]+',
    '(opacity|duration|delay|leading|tracking|cursor|overflow|object|ease|order|col-span|row-span|grid-cols|grid-rows)-[a-z0-9-]+',
    'overflow-[a-z-]+',
  ];

  /**
   * Templates of pages the site no longer uses, left unstyled on purpose.
   *
   * The start page and the catalogue were replaced by the dashboard and are
   * left unstyled (#3638885); removing them is left to a follow-up issue. Do
   * not add live templates here.
   */
  private const UNUSED_TEMPLATES = [
    'templates/pages/soda-scs-manager--catalogue.html.twig',
    'templates/pages/soda-scs-manager--start-page.html.twig',
  ];

  /**
   * The module root.
   */
  private function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Whether one class token is a utility class.
   */
  private function isUtilityClass(string $token): bool {
    $base = preg_replace('/^(?:[a-z0-9-]+:)+/', '', $token);
    $base = ltrim((string) $base, '!');
    foreach (self::UTILITY_CLASS_PATTERNS as $pattern) {
      if (preg_match('#^-?(?:' . $pattern . ')$#', $base) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Splits a class string into tokens, dropping Twig and PHP expressions.
   *
   * @return string[]
   *   Class tokens.
   */
  private function classTokens(string $classes): array {
    $classes = preg_replace('/\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}/s', ' ', $classes);
    return preg_split('/\s+/', trim((string) $classes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
  }

  /**
   * Line number of a byte offset.
   */
  private function lineOf(string $contents, int $offset): int {
    return substr_count($contents, "\n", 0, $offset) + 1;
  }

  /**
   * Files below a module sub-path with one of the given extensions.
   *
   * @return string[]
   *   Absolute paths.
   */
  private function files(string $relativePath, array $extensions): array {
    $path = $this->moduleRoot() . '/' . $relativePath;
    if (is_file($path)) {
      return [$path];
    }
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->isFile() && in_array($file->getExtension(), $extensions, TRUE)) {
        $files[] = $file->getPathname();
      }
    }
    sort($files);
    return $files;
  }

  /**
   * Module-relative path for messages.
   */
  private function relative(string $absolutePath): string {
    return substr($absolutePath, strlen($this->moduleRoot()) + 1);
  }

  /**
   * Utility classes found in the class attributes of the Twig templates.
   *
   * @return string[]
   *   "file:line class" entries.
   */
  private function twigUtilityClasses(): array {
    $found = [];
    foreach ($this->files('templates', ['twig']) as $file) {
      if (in_array($this->relative($file), self::UNUSED_TEMPLATES, TRUE)) {
        continue;
      }
      $contents = (string) file_get_contents($file);
      $matches = [];
      preg_match_all('/\bclass\s*=\s*"([^"]*)"|\baddClass\(([^)]*)\)/', $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
      foreach ($matches as $match) {
        $isAttribute = isset($match[1]) && $match[1][1] >= 0;
        $classes = $isAttribute ? $match[1][0] : preg_replace('/[\'",]/', ' ', $match[2][0]);
        $offset = $isAttribute ? $match[1][1] : $match[2][1];
        foreach ($this->classTokens((string) $classes) as $token) {
          if ($this->isUtilityClass($token)) {
            $found[] = $this->relative($file) . ':' . $this->lineOf($contents, $offset) . ' ' . $token;
          }
        }
      }
    }
    return $found;
  }

  /**
   * Utility classes set by PHP render arrays, forms and hooks.
   *
   * @return string[]
   *   "file:line class" entries.
   */
  private function phpUtilityClasses(): array {
    $found = [];
    $files = array_merge(
      $this->files('src', ['php']),
      $this->files('soda_scs_manager.module', ['module']),
      $this->files('soda_scs_manager.install', ['install']),
    );
    foreach ($files as $file) {
      $contents = (string) file_get_contents($file);
      $matches = [];
      preg_match_all(
        '/[\'"]class[\'"]\s*=>\s*(\[.*?\]|\'[^\']*\'|"[^"]*")|(?:addClass|setAttribute)\(([^;]*?)\)\s*[;-]/s',
        $contents,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
      );
      foreach ($matches as $match) {
        $captured = $match[1][1] >= 0 ? $match[1] : ($match[2] ?? NULL);
        if ($captured === NULL || $captured[1] < 0) {
          continue;
        }
        $literals = [];
        preg_match_all('/\'([^\']*)\'|"([^"]*)"/', $captured[0], $literals);
        foreach (array_merge($literals[1], $literals[2]) as $literal) {
          foreach ($this->classTokens($literal) as $token) {
            if ($this->isUtilityClass($token)) {
              $found[] = $this->relative($file) . ':' . $this->lineOf($contents, $captured[1]) . ' ' . $token;
            }
          }
        }
      }
    }
    foreach ($files as $file) {
      $contents = (string) file_get_contents($file);
      $found = array_merge($found, $this->markupUtilityClasses($file, $contents));
    }
    return $found;
  }

  /**
   * Utility classes in class="…" attributes written inside code strings.
   *
   * @return string[]
   *   "file:line class" entries.
   */
  private function markupUtilityClasses(string $file, string $contents): array {
    $found = [];
    $matches = [];
    preg_match_all('/\bclass\s*=\s*\\\\?"([^"\\\\]*)/', $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($matches as $match) {
      foreach ($this->classTokens($match[1][0]) as $token) {
        if ($this->isUtilityClass($token)) {
          $found[] = $this->relative($file) . ':' . $this->lineOf($contents, $match[1][1]) . ' ' . $token;
        }
      }
    }
    return $found;
  }

  /**
   * Utility classes the JavaScript adds, removes or writes into markup.
   *
   * @return string[]
   *   "file:line class" entries.
   */
  private function jsUtilityClasses(): array {
    $found = [];
    foreach ($this->files('js', ['js']) as $file) {
      $contents = (string) file_get_contents($file);
      $matches = [];
      preg_match_all(
        '/(?:classList\.(?:add|remove|toggle)|(?:add|remove|toggle)Class)\(([^)]*)\)|className\s*=\s*([\'"])([^\'"]*)\2/',
        $contents,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
      );
      foreach ($matches as $match) {
        if (isset($match[3]) && $match[3][1] >= 0) {
          $literals = [$match[3][0]];
          $offset = $match[3][1];
        }
        else {
          $strings = [];
          // Only the first argument holds class names (toggle's second is a
          // boolean).
          preg_match('/^\s*([\'"])([^\'"]*)\1/', $match[1][0], $strings);
          $literals = isset($strings[2]) ? [$strings[2]] : [];
          $offset = $match[1][1];
        }
        foreach ($literals as $literal) {
          foreach ($this->classTokens($literal) as $token) {
            if ($this->isUtilityClass($token)) {
              $found[] = $this->relative($file) . ':' . $this->lineOf($contents, $offset) . ' ' . $token;
            }
          }
        }
      }
      $found = array_merge($found, $this->markupUtilityClasses($file, $contents));
    }
    return $found;
  }

  /**
   * The libraries file has no css: key at any level.
   */
  public function testLibrariesDeclareNoCss(): void {
    $libraries = Yaml::decode((string) file_get_contents($this->moduleRoot() . '/soda_scs_manager.libraries.yml'));
    $this->assertNotEmpty($libraries, 'The libraries file parses and declares libraries.');

    $withCss = [];
    foreach ($libraries as $name => $definition) {
      if (is_array($definition) && array_key_exists('css', $definition)) {
        $withCss[] = $name;
      }
    }
    $this->assertSame([], $withCss, 'Libraries with a css: key: ' . implode(', ', $withCss));
  }

  /**
   * The module has no css/ or pcss/ directory and no CSS source file.
   */
  public function testModuleHasNoCssDirectoriesOrFiles(): void {
    $root = $this->moduleRoot();
    foreach (['css', 'pcss'] as $directory) {
      $this->assertDirectoryDoesNotExist($root . '/' . $directory, "The module must not have a $directory/ directory.");
    }
    foreach (['postcss.config.js', 'tailwind.config.js'] as $buildConfig) {
      $this->assertFileDoesNotExist($root . '/' . $buildConfig, "$buildConfig belongs to the removed CSS build.");
    }

    $stylesheets = [];
    foreach (['js', 'templates', 'src', 'assets', 'config'] as $directory) {
      if (is_dir($root . '/' . $directory)) {
        foreach ($this->files($directory, ['css', 'pcss', 'scss']) as $file) {
          $stylesheets[] = $this->relative($file);
        }
      }
    }
    $this->assertSame([], $stylesheets, 'Stylesheets in the module: ' . implode(', ', $stylesheets));
  }

  /**
   * Templates carry no inline style attribute.
   */
  public function testTemplatesHaveNoInlineStyle(): void {
    $found = [];
    foreach ($this->files('templates', ['twig']) as $file) {
      $contents = (string) file_get_contents($file);
      if (preg_match_all('/\sstyle\s*=/i', $contents, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
          $found[] = $this->relative($file) . ':' . $this->lineOf($contents, $match[1]);
        }
      }
    }
    $this->assertSame([], $found, "Inline style= in templates:\n" . implode("\n", $found));
  }

  /**
   * PHP render arrays and hooks set no style attribute.
   */
  public function testPhpRenderArraysHaveNoInlineStyle(): void {
    $found = [];
    $files = array_merge(
      $this->files('src', ['php']),
      $this->files('soda_scs_manager.module', ['module']),
      $this->files('soda_scs_manager.install', ['install']),
    );
    foreach ($files as $file) {
      $contents = (string) file_get_contents($file);
      $pattern = '/[\'"]style[\'"]\s*=>|setAttribute\(\s*[\'"]style[\'"]|\sstyle\s*=\s*\\\\?["\']/i';
      if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
          $found[] = $this->relative($file) . ':' . $this->lineOf($contents, $match[1]);
        }
      }
    }
    $this->assertSame([], $found, "Inline style in PHP:\n" . implode("\n", $found));
  }

  /**
   * Templates use no utility classes.
   */
  public function testTemplatesUseNoUtilityClasses(): void {
    $found = $this->twigUtilityClasses();
    $this->assertSame([], $found, "Utility classes in templates:\n" . implode("\n", $found));
  }

  /**
   * PHP render arrays and hooks use no utility classes.
   */
  public function testPhpRenderArraysUseNoUtilityClasses(): void {
    $found = $this->phpUtilityClasses();
    $this->assertSame([], $found, "Utility classes in PHP:\n" . implode("\n", $found));
  }

  /**
   * JavaScript uses no utility classes.
   */
  public function testJavascriptUsesNoUtilityClasses(): void {
    $found = $this->jsUtilityClasses();
    $this->assertSame([], $found, "Utility classes in js/:\n" . implode("\n", $found));
  }

  /**
   * JavaScript styles nothing inline; computed positions and sizes are fine.
   */
  public function testJavascriptSetsNoInlineLook(): void {
    $found = [];
    foreach ($this->files('js', ['js']) as $file) {
      $contents = (string) file_get_contents($file);
      $pattern = '/\.style\.(display|color|background\w*|margin\w*|padding\w*|border\w*|font\w*)\b/';
      if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
          $found[] = $this->relative($file) . ':' . $this->lineOf($contents, $match[1]) . ' ' . $match[0];
        }
      }
    }
    $this->assertSame([], $found, "Inline look set by js/ (use hidden or a state class):\n" . implode("\n", $found));
  }

  /**
   * The detector flags the utilities the module used to ship.
   */
  #[DataProvider('utilityClassProvider')]
  public function testDetectorFlagsUtilityClass(string $token): void {
    $this->assertTrue($this->isUtilityClass($token), "$token is a utility class.");
  }

  /**
   * Utility classes of the removed Tailwind markup.
   */
  public static function utilityClassProvider(): array {
    $tokens = [
      'flex', 'grid', 'fixed', 'mx-auto', 'm-auto', 'inset-0', 'z-50', 'items-center', 'justify-center',
      'mb-2', 'mt-4', 'px-4', 'py-2', 'p-6', 'mx-4', 'mb-10', 'gap-2', 'max-w-md',
      'text-sm', 'text-xl', 'text-8xl', 'text-center', 'text-gray-600', 'text-red-600', 'text-stone-200',
      'bg-white', 'bg-blue-600', 'bg-black/50', 'rounded', 'rounded-lg', 'shadow-xl',
      'font-semibold', 'font-bold', 'border-t', 'border-gray-100', 'hover:bg-blue-700',
      'md:flex', 'space-y-1', 'flex-wrap', 'flex-shrink-0', 'w-2', 'h-2', 'min-w-0', 'opacity-20',
    ];
    return array_combine($tokens, array_map(static fn (string $token): array => [$token], $tokens));
  }

  /**
   * The detector leaves the module's semantic classes alone.
   */
  #[DataProvider('semanticClassProvider')]
  public function testDetectorIgnoresSemanticClass(string $token): void {
    $this->assertFalse($this->isUtilityClass($token), "$token is a semantic class.");
  }

  /**
   * Semantic and core classes the module sets.
   */
  public static function semanticClassProvider(): array {
    $tokens = [
      'scs-manager--nextcloud-connect-btn', 'soda-scs-manager--view--grid',
      'project-card__delete-confirmation--grid', 'create-project-wizard__form-error',
      'soda-scs-manager--dashboard-heading-with-add', 'overlay', 'overlay--large',
      'btn', 'button', 'button--primary', 'hidden', 'visually-hidden', 'alert', 'alert-info',
      'application__health__icon', 'add-app-popup-card__plus',
    ];
    return array_combine($tokens, array_map(static fn (string $token): array => [$token], $tokens));
  }

  /**
   * Twig expressions inside a class attribute are not class names.
   */
  public function testClassTokensDropTwigExpressions(): void {
    $this->assertSame(
      ['card', 'flex'],
      $this->classTokens('card {{ status_class }} {% if x %}flex{% endif %}'),
    );
  }

}
