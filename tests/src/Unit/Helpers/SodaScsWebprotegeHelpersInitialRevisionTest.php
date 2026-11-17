<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsWebprotegeServiceActions;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests that linked WebProtégé projects get a first revision.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers
 * @group soda_scs_manager
 */
class SodaScsWebprotegeHelpersInitialRevisionTest extends UnitTestCase {

  private const PROJECT_UUID = '11111111-2222-3333-4444-555555555555';

  /**
   * Request kinds passed to makeRequest(), in call order.
   *
   * @var string[]
   */
  private array $sentRequests = [];

  /**
   * Arguments of every buildAddAxiomsRequest() call.
   *
   * @var array<int, array{0: string, 1: string, 2: string}>
   */
  private array $axiomsCalls = [];

  /**
   * Canned answers of makeRequest(), by request kind.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $answers = [];

  /**
   * Files created by the test.
   *
   * @var string[]
   */
  private array $targetFiles = [];

  /**
   * Container that was set before the test.
   */
  private ?ContainerInterface $previousContainer = NULL;

  /**
   * Logger of the helper under test.
   */
  private LoggerChannelInterface $logger;

  /**
   * Service actions mock.
   */
  private SodaScsWebprotegeServiceActions $actions;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sentRequests = [];
    $this->axiomsCalls = [];
    $this->answers = [];
    $this->logger = $this->createMock(LoggerChannelInterface::class);

    // SodaScsResult::failure() logs through \Drupal::logger() by default.
    $this->previousContainer = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);

    $this->actions = $this->createMock(SodaScsWebprotegeServiceActions::class);
    $this->actions->method('buildGetRequest')->willReturn(['kind' => 'revisions']);
    $this->actions->method('buildCreateRequest')->willReturn(['kind' => 'create']);
    $this->actions->method('buildDownloadOntologyRequest')->willReturn(['kind' => 'download']);
    $this->actions->method('buildAddAxiomsRequest')->willReturnCallback(
      function (string $message, string $document, string $projectId): array {
        $this->axiomsCalls[] = [$message, $document, $projectId];
        return ['kind' => 'axioms'];
      }
    );
    $this->actions->method('makeRequest')->willReturnCallback(
      function (array $request): array {
        $kind = $request['kind'];
        $this->sentRequests[] = $kind;
        return $this->answers[$kind] ?? ['success' => FALSE, 'error' => 'No canned answer for ' . $kind];
      }
    );
  }

  /**
   * Builds the helper around the mocked actions.
   */
  private function createHelper(): SodaScsWebprotegeHelpers {
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->with('soda_scs_manager')->willReturn($this->logger);
    $helper = new SodaScsWebprotegeHelpers($this->actions, $loggerFactory);
    $helper->setStringTranslation($this->getStringTranslationStub());
    return $helper;
  }

  /**
   * Builds a project whose field values live in the given array.
   *
   * @param array<string, string> $values
   *   Field values by field name; set() writes into it.
   */
  private function createProject(array &$values): SodaScsProjectInterface {
    $owner = $this->createMock(UserInterface::class);
    $owner->method('getAccountName')->willReturn('alice');

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('id')->willReturn(7);
    $project->method('label')->willReturn('demo project');
    $project->method('getOwner')->willReturn($owner);
    $project->method('language')->willReturn($language);
    $project->method('hasField')->willReturn(FALSE);
    $project->method('get')->willReturnCallback(
      static function (string $name) use (&$values): object {
        return (object) ['value' => $values[$name] ?? NULL];
      }
    );
    $project->method('set')->willReturnCallback(
      static function (string $name, $value) use (&$values, $project) {
        $values[$name] = $value;
        return $project;
      }
    );
    return $project;
  }

  /**
   * Canned answer of the revisions endpoint.
   */
  private function answerRevisions(string $body): void {
    $this->answers['revisions'] = [
      'success' => TRUE,
      'data' => ['webprotegeResponse' => new Response(200, [], $body)],
    ];
  }

  /**
   * Canned successful answer of the axioms endpoint.
   */
  private function answerAxiomsAccepted(): void {
    $this->answers['axioms'] = [
      'success' => TRUE,
      'data' => ['webprotegeResponse' => new Response(200, [], '{}')],
    ];
  }

  /**
   * @covers ::ensureInitialRevision
   */
  public function testEnsureInitialRevisionPostsInitialDocumentWhenProjectHasNoRevisions(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerRevisions('[]');
    $this->answerAxiomsAccepted();

    $result = $this->createHelper()->ensureInitialRevision($this->createProject($values));

    $this->assertTrue($result->success);
    $this->assertTrue($result->data['created']);
    $this->assertSame(self::PROJECT_UUID, $result->data['webprotegeProjectId']);
    $this->assertSame(['revisions', 'axioms'], $this->sentRequests);
    $this->assertCount(1, $this->axiomsCalls);
    [$message, $document, $projectId] = $this->axiomsCalls[0];
    $this->assertSame('Initial revision created by the SCS Manager', $message);
    $this->assertSame(SodaScsWebprotegeHelpers::INITIAL_REVISION_MESSAGE, $message);
    $this->assertSame(self::PROJECT_UUID, $projectId);
    $this->assertStringContainsString('Declaration(AnnotationProperty(rdfs:label))', $document);
    $this->assertStringContainsString('Prefix(rdfs:=<http://www.w3.org/2000/01/rdf-schema#>)', $document);
    $this->assertStringContainsString('Ontology(', $document);
  }

  /**
   * @covers ::ensureInitialRevision
   */
  public function testEnsureInitialRevisionLeavesProjectWithRevisionsUntouched(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerRevisions('[{"revisionNumber":1}]');

    $result = $this->createHelper()->ensureInitialRevision($this->createProject($values));

    $this->assertTrue($result->success);
    $this->assertFalse($result->data['created']);
    $this->assertSame(self::PROJECT_UUID, $result->data['webprotegeProjectId']);
    $this->assertSame(['revisions'], $this->sentRequests);
    $this->assertSame([], $this->axiomsCalls);
  }

  /**
   * @covers ::ensureInitialRevision
   */
  public function testEnsureInitialRevisionReturnsFailureAndPostsNothingWhenRevisionsCannotBeRead(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answers['revisions'] = ['success' => FALSE, 'error' => 'HTTP 502 from WebProtégé'];

    $result = $this->createHelper()->ensureInitialRevision($this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame('HTTP 502 from WebProtégé', $result->error);
    $this->assertSame(['revisions'], $this->sentRequests);
    $this->assertSame([], $this->axiomsCalls);
  }

  /**
   * @covers ::ensureInitialRevision
   */
  public function testEnsureInitialRevisionPostsNothingWhenRevisionsBodyIsMalformed(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerRevisions('<html>gateway timeout</html>');

    $result = $this->createHelper()->ensureInitialRevision($this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame(['revisions'], $this->sentRequests);
    $this->assertSame([], $this->axiomsCalls);
  }

  /**
   * @covers ::ensureInitialRevision
   */
  public function testEnsureInitialRevisionSendsNothingWhenNoProjectIsLinked(): void {
    $values = [];

    $result = $this->createHelper()->ensureInitialRevision($this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame([], $this->sentRequests);
    $this->assertSame([], $this->axiomsCalls);
  }

  /**
   * @covers ::ensureInitialRevision
   */
  public function testEnsureInitialRevisionReturnsFailureAndLogsWhenAxiomsRequestFails(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerRevisions('[]');
    $this->answers['axioms'] = ['success' => FALSE, 'error' => 'HTTP 500 from WebProtégé'];
    $this->logger->expects($this->once())->method('error');

    $result = $this->createHelper()->ensureInitialRevision($this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame('HTTP 500 from WebProtégé', $result->error);
    $this->assertSame(['revisions', 'axioms'], $this->sentRequests);
  }

  /**
   * @covers ::downloadOntologyArchive
   */
  public function testDownloadOntologyArchiveReturnsFailureWithoutDownloadRequestWhenInitialRevisionFails(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answers['revisions'] = ['success' => FALSE, 'error' => 'HTTP 502 from WebProtégé'];
    $this->answers['download'] = [
      'success' => TRUE,
      'data' => ['webprotegeResponse' => new Response(200, [], 'zip-bytes')],
    ];
    $targetFile = $this->createTargetFile();

    $result = $this->createHelper()->downloadOntologyArchive($this->createProject($values), $targetFile);

    $this->assertFalse($result->success);
    $this->assertSame('HTTP 502 from WebProtégé', $result->error);
    $this->assertNotContains('download', $this->sentRequests);
    $this->assertSame('', (string) file_get_contents($targetFile));
  }

  /**
   * @covers ::downloadOntologyArchive
   */
  public function testDownloadOntologyArchiveWritesInitialRevisionBeforeDownloading(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerRevisions('[]');
    $this->answerAxiomsAccepted();
    $this->answers['download'] = [
      'success' => TRUE,
      'data' => ['webprotegeResponse' => new Response(200, [], 'zip-bytes')],
    ];
    $targetFile = $this->createTargetFile();

    $result = $this->createHelper()->downloadOntologyArchive($this->createProject($values), $targetFile);

    $this->assertTrue($result->success);
    $this->assertSame(['revisions', 'axioms', 'download'], $this->sentRequests);
    $this->assertSame('zip-bytes', file_get_contents($targetFile));
  }

  /**
   * @covers ::createOntologyProject
   */
  public function testCreateOntologyProjectWritesInitialRevisionOfNewProject(): void {
    $values = [];
    $this->answers['create'] = [
      'success' => TRUE,
      'data' => ['webprotegeResponse' => new Response(201, [], '{"projectId":"' . self::PROJECT_UUID . '"}')],
    ];
    $this->answerRevisions('[]');
    $this->answerAxiomsAccepted();

    $result = $this->createHelper()->createOntologyProject($this->createProject($values));

    $this->assertTrue($result->success);
    $this->assertSame(self::PROJECT_UUID, $values['webprotegeProjectId']);
    $this->assertSame(['create', 'revisions', 'axioms'], $this->sentRequests);
    $this->assertSame(self::PROJECT_UUID, $this->axiomsCalls[0][2]);
  }

  /**
   * @covers ::createOntologyProject
   */
  public function testCreateOntologyProjectStaysLinkedAndWarnsWhenInitialRevisionFails(): void {
    $values = [];
    $this->answers['create'] = [
      'success' => TRUE,
      'data' => ['webprotegeResponse' => new Response(201, [], '{"projectId":"' . self::PROJECT_UUID . '"}')],
    ];
    $this->answers['revisions'] = ['success' => FALSE, 'error' => 'HTTP 502 from WebProtégé'];
    $this->logger->expects($this->once())->method('warning');

    $result = $this->createHelper()->createOntologyProject($this->createProject($values));

    $this->assertTrue($result->success);
    $this->assertSame(self::PROJECT_UUID, $result->data['webprotegeProjectId']);
    $this->assertSame(self::PROJECT_UUID, $values['webprotegeProjectId']);
    $this->assertSame([], $this->axiomsCalls);
  }

  /**
   * @covers ::createOntologyProject
   */
  public function testCreateOntologyProjectSkipsInitialRevisionWhenProjectIsAlreadyLinked(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];

    $result = $this->createHelper()->createOntologyProject($this->createProject($values));

    $this->assertTrue($result->success);
    $this->assertSame([], $this->sentRequests);
  }

  /**
   * @covers ::syncOntologyProject
   */
  public function testSyncOntologyProjectWritesInitialRevisionOfAlreadyLinkedProject(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answerRevisions('[]');
    $this->answerAxiomsAccepted();

    $result = $this->createHelper()->syncOntologyProject($this->createProject($values));

    $this->assertTrue($result->success);
    $this->assertSame(['revisions', 'axioms'], $this->sentRequests);
  }

  /**
   * @covers ::syncOntologyProject
   */
  public function testSyncOntologyProjectReturnsFailureWhenInitialRevisionOfLinkedProjectFails(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answers['revisions'] = ['success' => FALSE, 'error' => 'HTTP 502 from WebProtégé'];

    $result = $this->createHelper()->syncOntologyProject($this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame('HTTP 502 from WebProtégé', $result->error);
    $this->assertSame([], $this->axiomsCalls);
  }

  /**
   * @covers ::syncOntologyProject
   */
  public function testSyncOntologyProjectSyncsMembersEvenWhenInitialRevisionFails(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answers['revisions'] = ['success' => FALSE, 'error' => 'HTTP 502 from WebProtégé'];
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->logger);
    $helper = new class($this->actions, $loggerFactory) extends SodaScsWebprotegeHelpers {

      /**
       * Number of member syncs.
       */
      public int $memberSyncs = 0;

      /**
       * {@inheritdoc}
       */
      public function syncMemberEditAccess(SodaScsProjectInterface $project): array {
        $this->memberSyncs++;
        return [];
      }

    };
    $helper->setStringTranslation($this->getStringTranslationStub());

    $result = $helper->syncOntologyProject($this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame('HTTP 502 from WebProtégé', $result->error);
    $this->assertSame(1, $helper->memberSyncs);
  }

  /**
   * @covers ::isOntologyProjectUsed
   * @dataProvider providerOntologyProjectUse
   */
  public function testIsOntologyProjectUsed(bool $expected, int $count, ?array $latest, string $createdAt, string $modifiedAt): void {
    $this->assertSame($expected, $this->createHelper()->isOntologyProjectUsed($count, $latest, $createdAt, $modifiedAt));
  }

  /**
   * Revision lists and timestamps with the expected use.
   */
  public static function providerOntologyProjectUse(): array {
    $created = '2026-11-08T19:24:47.650Z';
    $initialAt = '2026-11-17T20:41:56.684Z';
    $later = '2026-11-18T08:00:00.000Z';
    $initial = [
      'rev'       => 1,
      'timestamp' => $initialAt,
      'msg'       => SodaScsWebprotegeHelpers::INITIAL_REVISION_MESSAGE,
    ];
    $translatedInitial = ['msg' => 'Erste Revision, angelegt vom SCS Manager'] + $initial;
    $userEdit = ['rev' => 2, 'timestamp' => $later, 'msg' => 'Added class Person'];
    $userFirstEdit = ['rev' => 1] + $userEdit;
    return [
      'untouched, no revision' => [FALSE, 0, NULL, $created, $created],
      'only the initial revision' => [FALSE, 1, $initial, $created, $initialAt],
      'only the initial revision, renamed later' => [TRUE, 1, $initial, $created, $later],
      'initial revision and a user edit' => [TRUE, 2, $userEdit, $created, $later],
      'one revision by a user' => [TRUE, 1, $userFirstEdit, $created, $later],
      'one translated initial revision' => [TRUE, 1, $translatedInitial, $created, $initialAt],
      'modified without revision' => [TRUE, 0, NULL, $created, $later],
      'details missing' => [TRUE, 0, NULL, '', ''],
      'details missing, only the initial revision' => [TRUE, 1, $initial, '', ''],
    ];
  }

  /**
   * @covers ::syncOntologyProject
   */
  public function testSyncOntologyProjectChecksRevisionsOnlyOnceForNewProject(): void {
    $values = [];
    $this->answers['create'] = [
      'success' => TRUE,
      'data' => ['webprotegeResponse' => new Response(201, [], '{"projectId":"' . self::PROJECT_UUID . '"}')],
    ];
    $this->answerRevisions('[]');
    $this->answerAxiomsAccepted();

    $result = $this->createHelper()->syncOntologyProject($this->createProject($values));

    $this->assertTrue($result->success);
    $this->assertSame(['create', 'revisions', 'axioms'], $this->sentRequests);
  }

  /**
   * Creates an empty target file that is removed after the test.
   */
  private function createTargetFile(): string {
    $file = tempnam(sys_get_temp_dir(), 'scs-webprotege-');
    $this->assertIsString($file);
    $this->targetFiles[] = $file;
    return $file;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->targetFiles as $file) {
      if (is_file($file)) {
        unlink($file);
      }
    }
    $this->targetFiles = [];
    if ($this->previousContainer !== NULL) {
      \Drupal::setContainer($this->previousContainer);
    }
    else {
      \Drupal::unsetContainer();
    }
    parent::tearDown();
  }

}
