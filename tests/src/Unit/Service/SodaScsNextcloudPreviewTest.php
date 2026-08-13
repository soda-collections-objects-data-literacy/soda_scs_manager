<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions;
use Drupal\soda_scs_manager\Service\SodaScsNextcloudPreview;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Service\SodaScsNextcloudPreview
 * @group soda_scs_manager
 */
class SodaScsNextcloudPreviewTest extends UnitTestCase {

  /**
   * Builds a preview service with mocked collaborators.
   */
  private function createPreview(
    SodaScsNextcloudHelpers $helpers,
    SodaScsNextcloudServiceActions $actions,
    SodaScsServiceHelpers $serviceHelpers,
  ): SodaScsNextcloudPreview {
    return new SodaScsNextcloudPreview(
      $helpers,
      $actions,
      $serviceHelpers,
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(TranslationInterface::class),
    );
  }

  /**
   * @covers ::buildProjectFeedData
   */
  public function testBuildProjectFeedDataNeedsConnect(): void {
    $helpers = $this->createMock(SodaScsNextcloudHelpers::class);
    $helpers->method('ensureCredentials')->willReturn(NULL);

    $serviceHelpers = $this->createMock(SodaScsServiceHelpers::class);
    $serviceHelpers->method('initNextcloudSettings')->willReturn([
      'baseUrl' => 'https://drive.example.com/',
    ]);

    $preview = $this->createPreview(
      $helpers,
      $this->createMock(SodaScsNextcloudServiceActions::class),
      $serviceHelpers,
    );

    $result = $preview->buildProjectFeedData($this->createMock(UserInterface::class), '1', 'Projekt 1');

    $this->assertSame('needs_connect', $result['status']);
    $this->assertSame('https://drive.example.com', $result['openUrl']);
    $this->assertSame('empty', $result['activities']['status']);
    $this->assertSame([], $result['activities']['items']);
  }

  /**
   * @covers ::buildProjectFeedData
   */
  public function testBuildProjectFeedDataMapsFolderFeedItems(): void {
    $helpers = $this->createMock(SodaScsNextcloudHelpers::class);
    $helpers->method('ensureCredentials')->willReturn([
      'username' => 'alice',
      'appPassword' => 'secret',
    ]);

    $serviceHelpers = $this->createMock(SodaScsServiceHelpers::class);
    $serviceHelpers->method('initNextcloudSettings')->willReturn([
      'baseUrl' => 'https://drive.example.com',
    ]);

    $actions = $this->createMock(SodaScsNextcloudServiceActions::class);
    $actions->method('buildProjectFoldersRequest')->willReturn(['route' => 'folders']);
    $actions->method('buildProjectFeedRequest')->willReturnCallback(function (array $params): array {
      $this->assertSame(42, $params['folderId']);
      $this->assertSame(6, $params['limit']);
      return ['route' => 'feed'];
    });
    $actions->method('makeRequest')->willReturnCallback(function (array $request): array {
      if (($request['route'] ?? '') === 'folders') {
        return $this->ocsResponse([
          'folders' => [
            [
              'id' => 7,
              'externalProjectId' => 'scs-platform-share',
              'name' => 'SCS-Share',
            ],
            [
              'id' => 42,
              'externalProjectId' => '1',
              'name' => 'Projekt 1',
              'label' => 'Projekt 1',
            ],
          ],
        ]);
      }
      return $this->ocsResponse([
        'items' => [
          [
            'subject' => 'Created notes.md',
            'timestamp' => 1700000000,
            'object' => [
              'name' => 'notes.md',
              'fileId' => 99,
            ],
          ],
        ],
      ]);
    });

    $preview = $this->createPreview($helpers, $actions, $serviceHelpers);
    $result = $preview->buildProjectFeedData($this->createMock(UserInterface::class), '1', 'Projekt 1');

    $this->assertSame('connected', $result['status']);
    $this->assertSame('ok', $result['activities']['status']);
    $this->assertCount(1, $result['activities']['items']);
    $this->assertSame('notes.md', $result['activities']['items'][0]['label']);
    $this->assertSame('https://drive.example.com/f/99', $result['activities']['items'][0]['url']);
    $this->assertStringContainsString('Created notes.md', $result['activities']['items'][0]['subtitle']);
  }

  /**
   * @covers ::buildPreviewData
   */
  public function testBuildPreviewDataUsesPlatformShareFolder(): void {
    $helpers = $this->createMock(SodaScsNextcloudHelpers::class);
    $helpers->method('ensureCredentials')->willReturn([
      'username' => 'alice',
      'appPassword' => 'secret',
    ]);

    $serviceHelpers = $this->createMock(SodaScsServiceHelpers::class);
    $serviceHelpers->method('initNextcloudSettings')->willReturn([
      'baseUrl' => 'https://drive.example.com',
    ]);

    $actions = $this->createMock(SodaScsNextcloudServiceActions::class);
    $actions->method('buildProjectFoldersRequest')->willReturn(['route' => 'folders']);
    $actions->method('buildProjectFeedRequest')->willReturnCallback(function (array $params): array {
      $this->assertSame(7, $params['folderId']);
      return ['route' => 'feed'];
    });
    $actions->method('makeRequest')->willReturnCallback(function (array $request): array {
      if (($request['route'] ?? '') === 'folders') {
        return $this->ocsResponse([
          'folders' => [
            [
              'id' => 7,
              'externalProjectId' => 'scs-platform-share',
              'name' => 'SCS-Share',
            ],
          ],
        ]);
      }
      return $this->ocsResponse(['items' => []]);
    });

    $preview = $this->createPreview($helpers, $actions, $serviceHelpers);
    $result = $preview->buildPreviewData($this->createMock(UserInterface::class));

    $this->assertSame('connected', $result['status']);
    $this->assertSame('empty', $result['activities']['status']);
  }

  /**
   * @covers ::buildProjectFeedData
   */
  public function testBuildProjectFeedDataFallsBackToFolderName(): void {
    $helpers = $this->createMock(SodaScsNextcloudHelpers::class);
    $helpers->method('ensureCredentials')->willReturn([
      'username' => 'alice',
      'appPassword' => 'secret',
    ]);

    $serviceHelpers = $this->createMock(SodaScsServiceHelpers::class);
    $serviceHelpers->method('initNextcloudSettings')->willReturn([
      'baseUrl' => 'https://drive.example.com',
    ]);

    $actions = $this->createMock(SodaScsNextcloudServiceActions::class);
    $actions->method('buildProjectFoldersRequest')->willReturn(['route' => 'folders']);
    $actions->method('buildProjectFeedRequest')->willReturnCallback(function (array $params): array {
      $this->assertSame(11, $params['folderId']);
      return ['route' => 'feed'];
    });
    $actions->method('makeRequest')->willReturnCallback(function (array $request): array {
      if (($request['route'] ?? '') === 'folders') {
        return $this->ocsResponse([
          'folders' => [
            [
              'id' => 11,
              'externalProjectId' => '',
              'name' => 'rnsrk default project',
              'label' => 'rnsrk default project',
            ],
          ],
        ]);
      }
      return $this->ocsResponse(['items' => []]);
    });

    $preview = $this->createPreview($helpers, $actions, $serviceHelpers);
    $result = $preview->buildProjectFeedData(
      $this->createMock(UserInterface::class),
      '99',
      'rnsrk default project',
    );

    $this->assertSame('connected', $result['status']);
    $this->assertSame('empty', $result['activities']['status']);
  }

  /**
   * Wraps OCS data in the makeRequest() response shape.
   *
   * @param array<string, mixed> $data
   *   Payload for ocs.data.
   *
   * @return array<string, mixed>
   *   Normalised Nextcloud response.
   */
  private function ocsResponse(array $data): array {
    $json = json_encode(['ocs' => ['data' => $data]], JSON_THROW_ON_ERROR);
    return [
      'success' => TRUE,
      'statusCode' => 200,
      'data' => [
        'nextcloudResponse' => new class($json) {

          public function __construct(private string $body) {}

          public function getBody(): string {
            return $this->body;
          }

        },
      ],
    ];
  }

}
