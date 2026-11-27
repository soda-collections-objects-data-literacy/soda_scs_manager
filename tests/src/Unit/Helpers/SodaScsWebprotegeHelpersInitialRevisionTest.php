<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that linked WebProtégé projects get a first revision.
 */
#[CoversMethod(SodaScsWebprotegeHelpers::class, 'ensureInitialRevision')]
#[CoversMethod(SodaScsWebprotegeHelpers::class, 'downloadOntologyArchive')]
#[CoversMethod(SodaScsWebprotegeHelpers::class, 'createOntologyProject')]
#[CoversMethod(SodaScsWebprotegeHelpers::class, 'syncOntologyProject')]
#[CoversMethod(SodaScsWebprotegeHelpers::class, 'isOntologyProjectUsed')]
#[Group('soda_scs_manager')]
class SodaScsWebprotegeHelpersInitialRevisionTest extends SodaScsWebprotegeHelpersTestBase {

  /**
   * Tests ensureInitialRevision().
   *
   * Posts initial document when project has no revisions.
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
   * Tests that ensureInitialRevision() leaves project with revisions untouched.
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
   * Tests ensureInitialRevision().
   *
   * Returns failure and posts nothing when revisions cannot be read.
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
   * Tests ensureInitialRevision().
   *
   * Posts nothing when revisions body is malformed.
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
   * Tests that ensureInitialRevision() sends nothing when no project is linked.
   */
  public function testEnsureInitialRevisionSendsNothingWhenNoProjectIsLinked(): void {
    $values = [];

    $result = $this->createHelper()->ensureInitialRevision($this->createProject($values));

    $this->assertFalse($result->success);
    $this->assertSame([], $this->sentRequests);
    $this->assertSame([], $this->axiomsCalls);
  }

  /**
   * Tests ensureInitialRevision().
   *
   * Returns failure and logs when axioms request fails.
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
   * Tests downloadOntologyArchive().
   *
   * Returns failure without download request when initial revision fails.
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
   * Tests downloadOntologyArchive().
   *
   * Writes initial revision before downloading.
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
   * Tests that createOntologyProject() writes initial revision of new project.
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
   * Tests createOntologyProject().
   *
   * Stays linked and warns when initial revision fails.
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
   * Tests createOntologyProject().
   *
   * Skips initial revision when project is already linked.
   */
  public function testCreateOntologyProjectSkipsInitialRevisionWhenProjectIsAlreadyLinked(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];

    $result = $this->createHelper()->createOntologyProject($this->createProject($values));

    $this->assertTrue($result->success);
    $this->assertSame([], $this->sentRequests);
  }

  /**
   * Tests syncOntologyProject().
   *
   * Writes initial revision of already linked project.
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
   * Tests syncOntologyProject().
   *
   * Returns failure when initial revision of linked project fails.
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
   * Tests syncOntologyProject().
   *
   * Syncs members even when initial revision fails.
   */
  public function testSyncOntologyProjectSyncsMembersEvenWhenInitialRevisionFails(): void {
    $values = ['webprotegeProjectId' => self::PROJECT_UUID];
    $this->answers['revisions'] = ['success' => FALSE, 'error' => 'HTTP 502 from WebProtégé'];
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->logger);
    $helper = new class($this->actions, $loggerFactory, $this->inspector) extends SodaScsWebprotegeHelpers {

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
   * Tests isOntologyProjectUsed().
   */
  #[DataProvider('providerOntologyProjectUse')]
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
   * Tests syncOntologyProject().
   *
   * Checks revisions only once for new project.
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

}
