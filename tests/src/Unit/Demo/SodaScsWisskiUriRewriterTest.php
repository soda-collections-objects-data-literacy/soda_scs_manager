<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Demo\SodaScsWisskiUriRewriter;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Tests the URI rewrite of a copied demo WissKI.
 *
 * Issue 3640914. Docker is a double; N-Quads files are temporary files. The
 * script that rewrites the database runs in the Kernel test.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsWisskiUriRewriter
 * @group soda_scs_manager
 */
class SodaScsWisskiUriRewriterTest extends UnitTestCase {

  private const SOURCE_GRAPH = 'https://source.example.org/contents/';

  private const SOURCE_SITE = 'https://source.example.org/';

  private const TARGET_GRAPH = 'https://target.example.org/contents/';

  private const TARGET_SITE = 'https://target.example.org/';

  /**
   * The container helpers double.
   */
  private SodaScsContainerHelpers&MockObject $containerHelpers;

  /**
   * The WissKI component double.
   */
  private SodaScsComponentInterface&MockObject $component;

  /**
   * The entity type manager double; it hands out the stack storage.
   */
  private EntityTypeManagerInterface&MockObject $entityTypeManager;

  /**
   * The stack storage double.
   */
  private EntityStorageInterface&MockObject $stackStorage;

  /**
   * The project apps double.
   */
  private SodaScsDemoProjectApps&MockObject $projectApps;

  /**
   * Temporary files of the test, removed in tearDown().
   *
   * @var string[]
   */
  private array $files = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // SodaScsResult::failure() logs through \Drupal::logger().
    $container = new ContainerBuilder();
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);

    $this->containerHelpers = $this->createMock(SodaScsContainerHelpers::class);
    $this->containerHelpers->method('getComponentContainerName')->willReturn('wisski-demo--drupal');
    $this->component = $this->createMock(SodaScsComponentInterface::class);
    $this->component->method('id')->willReturn(12);
    // @phpstan-ignore drupal.entityStoragePropertyAssignment (a double the test configures per case, not an injected service)
    $this->stackStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->with('soda_scs_stack')->willReturn($this->stackStorage);
    $this->projectApps = $this->createMock(SodaScsDemoProjectApps::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->files as $file) {
      @unlink($file);
    }
    parent::tearDown();
  }

  /**
   * Both differing bases are mapped to those of the target.
   *
   * @covers ::buildMap
   */
  public function testBuildMapMapsSiteAndGraphBase(): void {
    $map = SodaScsWisskiUriRewriter::buildMap(
      ['graph' => self::SOURCE_GRAPH, 'site' => self::SOURCE_SITE],
      ['graph' => self::TARGET_GRAPH, 'site' => self::TARGET_SITE],
    );

    $this->assertSame([self::SOURCE_SITE => self::TARGET_SITE, self::SOURCE_GRAPH => self::TARGET_GRAPH], $map);
  }

  /**
   * Equal bases need no rewrite.
   *
   * @covers ::buildMap
   */
  public function testBuildMapIsEmptyForIdenticalBases(): void {
    $bases = ['graph' => self::TARGET_GRAPH, 'site' => self::TARGET_SITE];

    $this->assertSame([], SodaScsWisskiUriRewriter::buildMap($bases, $bases));
  }

  /**
   * A base that equals the target's is left out, the other is kept.
   *
   * @covers ::buildMap
   */
  public function testBuildMapKeepsOnlyTheBaseThatDiffers(): void {
    $map = SodaScsWisskiUriRewriter::buildMap(
      ['graph' => self::SOURCE_GRAPH, 'site' => self::TARGET_SITE],
      ['graph' => self::TARGET_GRAPH, 'site' => self::TARGET_SITE],
    );

    $this->assertSame([self::SOURCE_GRAPH => self::TARGET_GRAPH], $map);
  }

  /**
   * Missing or non-string source bases are skipped.
   *
   * @covers ::buildMap
   */
  public function testBuildMapSkipsMissingAndNonStringBases(): void {
    $targetBases = ['graph' => self::TARGET_GRAPH, 'site' => self::TARGET_SITE];

    $this->assertSame([], SodaScsWisskiUriRewriter::buildMap([], $targetBases));
    $this->assertSame([], SodaScsWisskiUriRewriter::buildMap(['graph' => ['x'], 'site' => 5], $targetBases));
  }

  /**
   * Source bases that are no bases (an imported manifest) are dropped.
   *
   * @covers ::buildMap
   */
  #[DataProvider('invalidBases')]
  public function testBuildMapDropsInvalidSourceBases(string $invalid): void {
    $map = SodaScsWisskiUriRewriter::buildMap(
      ['graph' => $invalid, 'site' => $invalid],
      ['graph' => self::TARGET_GRAPH, 'site' => self::TARGET_SITE],
    );

    $this->assertSame([], $map);
  }

  /**
   * Accepts scheme, host and a path that ends with a slash.
   *
   * @covers ::isValidBase
   */
  #[DataProvider('validBases')]
  public function testIsValidBaseAccepts(string $base): void {
    $this->assertTrue(SodaScsWisskiUriRewriter::isValidBase($base));
  }

  /**
   * Refuses everything else.
   *
   * @covers ::isValidBase
   */
  #[DataProvider('invalidBases')]
  public function testIsValidBaseRefuses(string $base): void {
    $this->assertFalse(SodaScsWisskiUriRewriter::isValidBase($base));
  }

  /**
   * A trailing line break is not part of a base.
   *
   * Fails while VALID_BASE_PATTERN ends in a plain "$" (it matches before a
   * final newline): the pattern needs the D modifier or "\z".
   *
   * @covers ::isValidBase
   */
  public function testIsValidBaseRefusesTrailingNewline(): void {
    $this->assertFalse(SodaScsWisskiUriRewriter::isValidBase("https://demo.example.org/\n"));
  }

  /**
   * Valid bases.
   *
   * @return array<string, array{string}>
   *   The bases by case.
   */
  public static function validBases(): array {
    return [
      'site' => ['https://demo.example.org/'],
      'graph with path' => ['https://demo.example.org/contents/'],
      'deep path' => ['https://demo.example.org/a/b/c/'],
      'http with port' => ['http://demo.example.org:8080/'],
    ];
  }

  /**
   * Invalid bases.
   *
   * @return array<string, array{string}>
   *   The bases by case.
   */
  public static function invalidBases(): array {
    return [
      'empty' => [''],
      'no trailing slash' => ['https://demo.example.org'],
      'path without trailing slash' => ['https://demo.example.org/contents'],
      'other scheme' => ['javascript://demo.example.org/'],
      'no scheme' => ['demo.example.org/'],
      'empty host' => ['https:///'],
      'space' => ['https://demo example.org/'],
      'angle bracket' => ['https://demo.example.org/<x>/'],
      'quote' => ['https://demo.example.org/"x/'],
    ];
  }

  /**
   * The bases come from the environment of the running container.
   *
   * @covers ::ownBases
   */
  public function testOwnBasesReadsGraphAndDomainFromTheEnvironment(): void {
    $this->containerHelpers->expects($this->once())->method('executeDockerExecCommand')
      ->with([
        'cmd' => ['printenv', 'WISSKI_DEFAULT_GRAPH', 'DRUPAL_DOMAIN'],
        'containerName' => 'wisski-demo--drupal',
        'user' => 'www-data',
      ])
      ->willReturn(SodaScsResult::success(['output' => "https://target.example.org/contents/\ntarget.example.org\n"], 'ok'));

    $result = $this->rewriter()->ownBases($this->component);

    $this->assertTrue($result->success);
    $this->assertSame(['graph' => self::TARGET_GRAPH, 'site' => self::TARGET_SITE], $result->data['bases']);
  }

  /**
   * Windows line endings in the output do not end up in the bases.
   *
   * @covers ::ownBases
   */
  public function testOwnBasesAcceptsCarriageReturns(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(SodaScsResult::success(['output' => "https://target.example.org/contents/\r\ntarget.example.org\r\n"], 'ok'));

    $result = $this->rewriter()->ownBases($this->component);

    $this->assertSame(['graph' => self::TARGET_GRAPH, 'site' => self::TARGET_SITE], $result->data['bases']);
  }

  /**
   * A failing printenv (container down) is a failure, not empty bases.
   *
   * @covers ::ownBases
   */
  public function testOwnBasesFailsWhenTheCommandFails(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'exit code 1', 'failed'));

    $result = $this->rewriter()->ownBases($this->component);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('WissKI 12 has no valid WISSKI_DEFAULT_GRAPH or DRUPAL_DOMAIN', (string) $result->error);
  }

  /**
   * A variable that is not set makes the output too short.
   *
   * @covers ::ownBases
   */
  #[DataProvider('brokenEnvironmentOutputs')]
  public function testOwnBasesFailsWhenVariableIsMissingOrInvalid(string $output): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(SodaScsResult::success(['output' => $output], 'ok'));

    $this->assertFalse($this->rewriter()->ownBases($this->component)->success);
  }

  /**
   * Outputs of printenv that carry no usable bases.
   *
   * @return array<string, array{string}>
   *   The outputs by case.
   */
  public static function brokenEnvironmentOutputs(): array {
    return [
      'empty output' => [''],
      'graph only' => ["https://target.example.org/contents/\n"],
      'graph without slash' => ["https://target.example.org/contents\ntarget.example.org\n"],
      'domain with path' => ["https://target.example.org/contents/\ntarget.example.org/x y\n"],
      'domain empty' => ["https://target.example.org/contents/\n\n"],
    ];
  }

  /**
   * The script runs as www-data with the map as JSON in the environment.
   *
   * @covers ::rewriteDatabase
   */
  public function testRewriteDatabaseRunsDrushWithTheMapInTheEnvironment(): void {
    $map = [self::SOURCE_SITE => self::TARGET_SITE, self::SOURCE_GRAPH => self::TARGET_GRAPH];
    $this->containerHelpers->expects($this->once())->method('executeDockerExecCommand')
      ->with($this->callback(function (array $options) use ($map): bool {
        $this->assertSame(['drush', 'php:eval'], array_slice($options['cmd'], 0, 2));
        $this->assertStringContainsString('SCS_URI_MAP', $options['cmd'][2]);
        $this->assertSame('wisski-demo--drupal', $options['containerName']);
        $this->assertSame('www-data', $options['user']);
        $this->assertSame(900, $options['timeout']);
        $this->assertCount(1, $options['env']);
        $this->assertSame('SCS_URI_MAP=' . json_encode($map, JSON_UNESCAPED_SLASHES), $options['env'][0]);
        return TRUE;
      }))
      ->willReturn(SodaScsResult::success(['output' => 'SCS_URI_REWRITE:{"rows":4,"config":1}'], 'ok'));

    $result = $this->rewriter()->rewriteDatabase($this->component, $map);

    $this->assertTrue($result->success);
    $this->assertSame(['config' => 1, 'rows' => 4], $result->data);
  }

  /**
   * Notices before the marker in the drush output do not matter.
   *
   * @covers ::rewriteDatabase
   */
  public function testRewriteDatabaseFindsTheMarkerAmongNotices(): void {
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn(SodaScsResult::success(
      ['output' => "Deprecated: something in x.php\nSCS_URI_REWRITE:{\"rows\":0,\"config\":2}\n"],
      'ok',
    ));

    $result = $this->rewriter()->rewriteDatabase($this->component, [self::SOURCE_SITE => self::TARGET_SITE]);

    $this->assertTrue($result->success);
    $this->assertSame(2, $result->data['config']);
  }

  /**
   * A failing drush call fails the rewrite and names the reason.
   *
   * @covers ::rewriteDatabase
   */
  public function testRewriteDatabaseFailsWhenDrushFails(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'exit code 255', 'failed'));

    $result = $this->rewriter()->rewriteDatabase($this->component, [self::SOURCE_SITE => self::TARGET_SITE]);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('URI rewrite in WissKI 12: exit code 255', (string) $result->error);
  }

  /**
   * A drush call that succeeds without the marker is not a success.
   *
   * @covers ::rewriteDatabase
   */
  #[DataProvider('unusableScriptOutputs')]
  public function testRewriteDatabaseFailsWithoutUsableMarker(string $output): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(SodaScsResult::success(['output' => $output], 'ok'));

    $result = $this->rewriter()->rewriteDatabase($this->component, [self::SOURCE_SITE => self::TARGET_SITE]);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('missing result in drush output', (string) $result->error);
  }

  /**
   * Outputs of a drush run that did not report counts.
   *
   * @return array<string, array{string}>
   *   The outputs by case.
   */
  public static function unusableScriptOutputs(): array {
    return [
      'empty' => [''],
      'no marker' => ['Done.'],
      'invalid json' => ['SCS_URI_REWRITE:{"rows":'],
      'counts missing' => ['SCS_URI_REWRITE:{"rows":1}'],
    ];
  }

  /**
   * The script's own error is reported.
   *
   * @covers ::rewriteDatabase
   */
  public function testRewriteDatabaseReportsTheErrorOfTheScript(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(SodaScsResult::success(['output' => 'SCS_URI_REWRITE:{"error":"empty map"}'], 'ok'));

    $result = $this->rewriter()->rewriteDatabase($this->component, []);

    $this->assertFalse($result->success);
    $this->assertStringContainsString('empty map', (string) $result->error);
  }

  /**
   * IRIs and literals of the source bases are replaced line by line.
   *
   * @covers ::rewriteNquads
   */
  public function testRewriteNquadsReplacesBasesInIrisAndLiterals(): void {
    $source = $this->temporaryFile(
      '<https://source.example.org/contents/a> <https://source.example.org/contents/p> "file https://source.example.org/sites/default/files/x.png" <https://source.example.org/contents/g> .' . "\n"
      . '<https://source.example.org/wisski/navigate/3/view> <urn:p> "y" .' . "\n"
    );
    $target = $this->temporaryFile('');

    SodaScsWisskiUriRewriter::rewriteNquads($source, $target, [
      self::SOURCE_SITE => self::TARGET_SITE,
      self::SOURCE_GRAPH => self::TARGET_GRAPH,
    ]);

    $this->assertSame(
      '<https://target.example.org/contents/a> <https://target.example.org/contents/p> "file https://target.example.org/sites/default/files/x.png" <https://target.example.org/contents/g> .' . "\n"
      . '<https://target.example.org/wisski/navigate/3/view> <urn:p> "y" .' . "\n",
      file_get_contents($target),
    );
  }

  /**
   * The graph base is a longer match than the site base and wins.
   *
   * @covers ::rewriteNquads
   */
  public function testRewriteNquadsPrefersTheLongerBase(): void {
    $source = $this->temporaryFile('<https://source.example.org/contents/a> <https://source.example.org/b> "z" .' . "\n");
    $target = $this->temporaryFile('');

    SodaScsWisskiUriRewriter::rewriteNquads($source, $target, [
      self::SOURCE_SITE => 'https://site.example.net/',
      self::SOURCE_GRAPH => 'https://graph.example.net/ns/',
    ]);

    $this->assertSame('<https://graph.example.net/ns/a> <https://site.example.net/b> "z" .' . "\n", file_get_contents($target));
  }

  /**
   * URIs minted by other WissKIs stay as they are.
   *
   * @covers ::rewriteNquads
   */
  public function testRewriteNquadsLeavesForeignBasesAlone(): void {
    $line = '<https://older.example.org/contents/a> <https://older.example.org/wisski/navigate/9/view> "o" <https://older.example.org/contents/g> .' . "\n";
    $source = $this->temporaryFile($line);
    $target = $this->temporaryFile('');

    SodaScsWisskiUriRewriter::rewriteNquads($source, $target, [
      self::SOURCE_SITE => self::TARGET_SITE,
      self::SOURCE_GRAPH => self::TARGET_GRAPH,
    ]);

    $this->assertSame($line, file_get_contents($target));
  }

  /**
   * The source file is not changed, an empty file stays empty.
   *
   * @covers ::rewriteNquads
   */
  public function testRewriteNquadsKeepsTheSourceFileAndHandlesEmptyFiles(): void {
    $line = '<https://source.example.org/contents/a> <urn:p> "x" .' . "\n";
    $source = $this->temporaryFile($line);
    $empty = $this->temporaryFile('');
    $target = $this->temporaryFile('old');

    SodaScsWisskiUriRewriter::rewriteNquads($source, $target, [self::SOURCE_GRAPH => self::TARGET_GRAPH]);
    $this->assertSame($line, file_get_contents($source));

    SodaScsWisskiUriRewriter::rewriteNquads($empty, $target, [self::SOURCE_GRAPH => self::TARGET_GRAPH]);
    $this->assertSame('', file_get_contents($target));
  }

  /**
   * A last line without a newline is rewritten too.
   *
   * @covers ::rewriteNquads
   */
  public function testRewriteNquadsRewritesLastLineWithoutNewline(): void {
    $source = $this->temporaryFile('<https://source.example.org/contents/a> <urn:p> "x" .');
    $target = $this->temporaryFile('');

    SodaScsWisskiUriRewriter::rewriteNquads($source, $target, [self::SOURCE_GRAPH => self::TARGET_GRAPH]);

    $this->assertSame('<https://target.example.org/contents/a> <urn:p> "x" .', file_get_contents($target));
  }

  /**
   * A base that straddles a very long line is still replaced.
   *
   * @covers ::rewriteNquads
   */
  public function testRewriteNquadsRewritesVeryLongLines(): void {
    $literal = str_repeat('ab ', 200000);
    $source = $this->temporaryFile('<https://source.example.org/contents/a> <urn:p> "' . $literal . '" .' . "\n");
    $target = $this->temporaryFile('');

    SodaScsWisskiUriRewriter::rewriteNquads($source, $target, [self::SOURCE_GRAPH => self::TARGET_GRAPH]);

    $this->assertSame('<https://target.example.org/contents/a> <urn:p> "' . $literal . '" .' . "\n", file_get_contents($target));
  }

  /**
   * A missing source file throws and writes nothing.
   *
   * @covers ::rewriteNquads
   */
  public function testRewriteNquadsThrowsWhenTheSourceCannotBeRead(): void {
    $target = $this->temporaryFile('');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Cannot read');

    SodaScsWisskiUriRewriter::rewriteNquads(sys_get_temp_dir() . '/scs-missing-' . bin2hex(random_bytes(4)), $target, [self::SOURCE_GRAPH => self::TARGET_GRAPH]);
  }

  /**
   * An unwritable target throws.
   *
   * @covers ::rewriteNquads
   */
  public function testRewriteNquadsThrowsWhenTheTargetCannotBeWritten(): void {
    $source = $this->temporaryFile("x\n");

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Cannot write');

    SodaScsWisskiUriRewriter::rewriteNquads($source, sys_get_temp_dir() . '/scs-no-such-dir-' . bin2hex(random_bytes(4)) . '/out.nq', [self::SOURCE_GRAPH => self::TARGET_GRAPH]);
  }

  /**
   * The base URIs of the manifest are used without any lookup.
   *
   * @covers ::templateSourceBases
   */
  public function testTemplateSourceBasesComeFromTheManifest(): void {
    $bases = ['graph' => self::SOURCE_GRAPH, 'site' => self::SOURCE_SITE];
    $this->stackStorage->expects($this->never())->method('load');
    $this->containerHelpers->expects($this->never())->method('executeDockerExecCommand');

    $template = $this->template(FALSE, ['uriBases' => $bases, 'sourceStackId' => 8]);
    $this->assertSame($bases, $this->rewriter()->templateSourceBases($template));
  }

  /**
   * An imported version takes its bases from the manifest as well.
   *
   * @covers ::templateSourceBases
   */
  public function testTemplateSourceBasesOfAnImportedVersionComeFromTheManifest(): void {
    $bases = ['graph' => self::SOURCE_GRAPH, 'site' => self::SOURCE_SITE];

    $this->assertSame($bases, $this->rewriter()->templateSourceBases($this->template(TRUE, ['uriBases' => $bases])));
  }

  /**
   * A version built before 4.8 reads the bases from its source WissKI.
   *
   * @covers ::templateSourceBases
   */
  public function testTemplateSourceBasesFallBackToTheSourceWisski(): void {
    $stack = $this->createMock(SodaScsStackInterface::class);
    $this->stackStorage->expects($this->once())->method('load')->with(8)->willReturn($stack);
    $this->projectApps->method('stackComponent')->with($stack, SodaScsDemoProjectApps::WISSKI_COMPONENT_BUNDLE)->willReturn($this->component);
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(SodaScsResult::success(['output' => self::SOURCE_GRAPH . "\nsource.example.org\n"], 'ok'));

    $bases = $this->rewriter()->templateSourceBases($this->template(FALSE, ['sourceStackId' => 8]));

    $this->assertSame(['graph' => self::SOURCE_GRAPH, 'site' => self::SOURCE_SITE], $bases);
  }

  /**
   * A source stack that is gone leaves the bases unknown.
   *
   * @covers ::templateSourceBases
   */
  public function testTemplateSourceBasesAreNullWhenTheSourceStackIsGone(): void {
    $this->stackStorage->method('load')->with(8)->willReturn(NULL);
    $this->containerHelpers->expects($this->never())->method('executeDockerExecCommand');

    $this->assertNull($this->rewriter()->templateSourceBases($this->template(FALSE, ['sourceStackId' => 8])));
  }

  /**
   * A source stack without a WissKI component leaves the bases unknown.
   *
   * @covers ::templateSourceBases
   */
  public function testTemplateSourceBasesAreNullWhenTheSourceStackHasNoWisskiComponent(): void {
    $this->stackStorage->method('load')->willReturn($this->createMock(SodaScsStackInterface::class));
    $this->projectApps->method('stackComponent')->willReturn(NULL);
    $this->containerHelpers->expects($this->never())->method('executeDockerExecCommand');

    $this->assertNull($this->rewriter()->templateSourceBases($this->template(FALSE, ['sourceStackId' => 8])));
  }

  /**
   * A source WissKI that does not answer leaves the bases unknown.
   *
   * @covers ::templateSourceBases
   */
  public function testTemplateSourceBasesAreNullWhenTheSourceWisskiDoesNotAnswer(): void {
    $this->stackStorage->method('load')->willReturn($this->createMock(SodaScsStackInterface::class));
    $this->projectApps->method('stackComponent')->willReturn($this->component);
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(new SodaScsResult(FALSE, NULL, 'container down', 'failed'));

    $this->assertNull($this->rewriter()->templateSourceBases($this->template(FALSE, ['sourceStackId' => 8])));
  }

  /**
   * An imported version never looks at a stack of this site.
   *
   * Its sourceStackId belongs to another site; a stack of the same number
   * here is an unrelated WissKI.
   *
   * @covers ::templateSourceBases
   */
  public function testTemplateSourceBasesOfAnImportedVersionWithoutBasesAreNullWithoutLookup(): void {
    $this->stackStorage->expects($this->never())->method('load');
    $this->containerHelpers->expects($this->never())->method('executeDockerExecCommand');

    $this->assertNull($this->rewriter()->templateSourceBases($this->template(TRUE, ['sourceStackId' => 8])));
  }

  /**
   * A version without a WissKI has no bases.
   *
   * @covers ::templateSourceBases
   */
  public function testTemplateSourceBasesAreNullWithoutWisskiApp(): void {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('getManifest')->willReturn(['apps' => ['jupyter' => ['file' => 'x']]]);
    $this->stackStorage->expects($this->never())->method('load');

    $this->assertNull($this->rewriter()->templateSourceBases($template));
  }

  /**
   * A template double with a WissKI entry in its manifest.
   *
   * @param bool $imported
   *   Whether the version was imported.
   * @param array $wisskiApp
   *   The WissKI entry.
   */
  private function template(bool $imported, array $wisskiApp): SodaScsDemoTemplateInterface&MockObject {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('isImported')->willReturn($imported);
    $template->method('getManifest')->willReturn(['apps' => ['wisski' => $wisskiApp]]);
    return $template;
  }

  /**
   * Builds the rewriter on the Docker double.
   */
  private function rewriter(): SodaScsWisskiUriRewriter {
    return new SodaScsWisskiUriRewriter($this->containerHelpers, $this->entityTypeManager, $this->projectApps);
  }

  /**
   * Writes a temporary file that tearDown() removes.
   */
  private function temporaryFile(string $content): string {
    $file = (string) tempnam(sys_get_temp_dir(), 'scs-rewriter-');
    file_put_contents($file, $content);
    $this->files[] = $file;
    return $file;
  }

}
