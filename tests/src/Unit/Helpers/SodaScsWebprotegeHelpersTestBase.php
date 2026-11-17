<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsOntologyImportInspector;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsWebprotegeServiceActions;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared fixtures for the SodaScsWebprotegeHelpers unit tests.
 *
 * Service calls go to a mocked SodaScsWebprotegeServiceActions that answers
 * from $answers and records what was sent.
 */
abstract class SodaScsWebprotegeHelpersTestBase extends UnitTestCase {

  protected const PROJECT_UUID = '11111111-2222-3333-4444-555555555555';

  /**
   * Request kinds passed to makeRequest(), in call order.
   *
   * @var string[]
   */
  protected array $sentRequests = [];

  /**
   * Arguments of every buildAddAxiomsRequest() call.
   *
   * @var array<int, array{0: string, 1: string, 2: string, 3: string|null, 4: int|null}>
   */
  protected array $axiomsCalls = [];

  /**
   * Canned answers of makeRequest(), by request kind.
   *
   * @var array<string, array<string, mixed>>
   */
  protected array $answers = [];

  /**
   * Files created by the test.
   *
   * @var string[]
   */
  protected array $targetFiles = [];

  /**
   * Container that was set before the test.
   */
  protected ?ContainerInterface $previousContainer = NULL;

  /**
   * Logger of the helper under test.
   */
  protected LoggerChannelInterface $logger;

  /**
   * Import inspector whose host lookups come from $resolvedHosts.
   */
  protected SodaScsOntologyImportInspector $inspector;

  /**
   * Addresses the inspector resolves, by host; other hosts get a public one.
   *
   * @var array<string, list<string>>
   */
  protected array $resolvedHosts = [];

  /**
   * Service actions mock.
   */
  protected SodaScsWebprotegeServiceActions $actions;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sentRequests = [];
    $this->axiomsCalls = [];
    $this->answers = [];
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->resolvedHosts = [];
    $this->inspector = new class($this->resolvedHosts) extends SodaScsOntologyImportInspector {

      /**
       * @param array<string, list<string>> $resolvedHosts
       *   Addresses by host, shared with the test.
       */
      public function __construct(private array &$resolvedHosts) {}

      /**
       * {@inheritdoc}
       */
      protected function resolveHost(string $host): array {
        return $this->resolvedHosts[$host] ?? ['93.184.215.14'];
      }

    };

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
      function (string $message, string $document, string $projectId, ?string $mimeType = NULL, ?int $timeout = NULL): array {
        $this->axiomsCalls[] = [$message, $document, $projectId, $mimeType, $timeout];
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
  protected function createHelper(): SodaScsWebprotegeHelpers {
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->with('soda_scs_manager')->willReturn($this->logger);
    $helper = new SodaScsWebprotegeHelpers($this->actions, $loggerFactory, $this->inspector);
    $helper->setStringTranslation($this->getStringTranslationStub());
    return $helper;
  }

  /**
   * Builds a project whose field values live in the given array.
   *
   * @param array<string, string> $values
   *   Field values by field name; set() writes into it.
   */
  protected function createProject(array &$values): SodaScsProjectInterface {
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
  protected function answerRevisions(string $body): void {
    $this->answers['revisions'] = [
      'success' => TRUE,
      'data' => ['webprotegeResponse' => new Response(200, [], $body)],
    ];
  }

  /**
   * Canned successful answer of the axioms endpoint.
   */
  protected function answerAxiomsAccepted(): void {
    $this->answers['axioms'] = [
      'success' => TRUE,
      'data' => ['webprotegeResponse' => new Response(200, [], '{}')],
    ];
  }

  /**
   * Canned failed answer of the axioms endpoint.
   *
   * @param int $statusCode
   *   HTTP status of the answer.
   * @param string $body
   *   Body of the answer.
   */
  protected function answerAxiomsRejected(int $statusCode, string $body): void {
    $this->answers['axioms'] = [
      'success' => FALSE,
      'error' => 'HTTP ' . $statusCode . ' from WebProtégé',
      'statusCode' => $statusCode,
      'data' => ['webprotegeResponse' => new Response($statusCode, [], $body)],
    ];
  }

  /**
   * Creates an empty target file that is removed after the test.
   */
  protected function createTargetFile(): string {
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
