<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\ViewBuilder;

use Drupal\soda_scs_manager\Service\SodaScsNextcloudPreview;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;

/**
 * Tests that a demo project offers no links into apps and files while busy.
 *
 * While the demo job provisions or resets the project, its Team Folder and
 * applications are not there yet (#3642169): the project page marks the
 * application cards and the Files section as pending and the templates render
 * no link. Ready and failed demos (a failed one needs the links to clean up)
 * and non-demo projects keep them.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\ViewBuilder\SodaScsProjectViewBuilder
 */
class SodaScsProjectDemoPendingLinksTest extends SodaScsKernelTestBase {

  private const OPEN_URL = 'https://files.example.invalid/apps/files/?dir=/Demo';

  /**
   * The project's owner and the viewer.
   *
   * @var \Drupal\user\UserInterface
   */
  private $owner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The Files section would ask Nextcloud for the viewer's activity.
    $preview = $this->createMock(SodaScsNextcloudPreview::class);
    $preview->method('buildProjectFilesSection')->willReturn([
      '#theme' => 'soda_scs_manager__project_files',
      '#status' => 'ready',
      '#open_url' => self::OPEN_URL,
      '#project_label' => 'Demo project',
    ]);
    $this->container->set('soda_scs_manager.nextcloud.preview', $preview);

    $userRole = Role::load('scs_user') ?? Role::create([
      'id' => 'scs_user',
      'label' => 'scs_user',
    ]);
    $userRole->grantPermission('soda scs manager user');
    $userRole->save();

    $this->owner = $this->testUser;
    $this->owner->addRole('scs_user');
    $this->owner->save();
    $this->container->get('current_user')->setAccount($this->owner);
  }

  /**
   * Application cards of a provisioning demo are marked pending.
   *
   * @covers ::build
   */
  public function testCardsArePendingWhileDemoIsProvisioning(): void {
    $build = $this->buildProjectPage('provisioning');

    $this->assertNotEmpty($build['#application_cards']);
    foreach ($build['#application_cards'] as $card) {
      $this->assertTrue($card['#is_pending']);
    }
  }

  /**
   * Application cards of a resetting demo are marked pending.
   *
   * @covers ::build
   */
  public function testCardsArePendingWhileDemoIsResetting(): void {
    $build = $this->buildProjectPage('resetting');

    $this->assertNotEmpty($build['#application_cards']);
    foreach ($build['#application_cards'] as $card) {
      $this->assertTrue($card['#is_pending']);
    }
  }

  /**
   * The Files section of a provisioning demo is marked pending.
   *
   * @covers ::build
   */
  public function testFilesArePendingWhileDemoIsProvisioning(): void {
    $build = $this->buildProjectPage('provisioning');

    $this->assertTrue($build['#files']['#is_pending']);
  }

  /**
   * The Files section of a resetting demo is marked pending.
   *
   * @covers ::build
   */
  public function testFilesArePendingWhileDemoIsResetting(): void {
    $build = $this->buildProjectPage('resetting');

    $this->assertTrue($build['#files']['#is_pending']);
  }

  /**
   * A ready demo keeps its links.
   *
   * @covers ::build
   */
  public function testNothingIsPendingWhenDemoIsReady(): void {
    $build = $this->buildProjectPage('ready');

    $this->assertNotEmpty($build['#application_cards']);
    foreach ($build['#application_cards'] as $card) {
      $this->assertArrayNotHasKey('#is_pending', $card);
    }
    $this->assertArrayNotHasKey('#is_pending', $build['#files']);
  }

  /**
   * A failed demo keeps its links: the owner needs them to clean up.
   *
   * @covers ::build
   */
  public function testNothingIsPendingWhenDemoFailed(): void {
    $build = $this->buildProjectPage('failed');

    $this->assertNotEmpty($build['#application_cards']);
    foreach ($build['#application_cards'] as $card) {
      $this->assertArrayNotHasKey('#is_pending', $card);
    }
    $this->assertArrayNotHasKey('#is_pending', $build['#files']);
  }

  /**
   * A project that is not a demo is unchanged.
   *
   * @covers ::build
   */
  public function testNothingIsPendingForNonDemoProject(): void {
    $build = $this->buildProjectPage(NULL);

    $this->assertNull($build['#demo_status']);
    $this->assertNotEmpty($build['#application_cards']);
    foreach ($build['#application_cards'] as $card) {
      $this->assertArrayNotHasKey('#is_pending', $card);
    }
    $this->assertArrayNotHasKey('#is_pending', $build['#files']);
  }

  /**
   * A pending card renders without a link into the application.
   */
  public function testPendingCardRendersNoLinkAndShowsNote(): void {
    $card = $this->buildProjectPage('provisioning')['#application_cards'][0];

    $html = $this->renderToHtml($card);

    $this->assertStringNotContainsString('<a href', $this->coreMarkup($html));
    $this->assertStringContainsString('<div class="application__core">', $html);
    $this->assertStringContainsString('application--card--pending', $html);
    $this->assertStringContainsString('Available once the demo project is ready.', $html);
    $this->assertStringNotContainsString('application__arrow', $html);
  }

  /**
   * A card that is not pending renders the link and the arrow.
   */
  public function testReadyCardRendersLinkAndNoNote(): void {
    $card = $this->buildProjectPage('ready')['#application_cards'][0];

    $html = $this->renderToHtml($card);

    $this->assertMatchesRegularExpression('/<a href="[^"]+" class="application__core"/', $html);
    $this->assertStringContainsString('application__arrow', $html);
    $this->assertStringNotContainsString('application--card--pending', $html);
    $this->assertStringNotContainsString('application__pending-note', $html);
  }

  /**
   * A pending Files section renders the note instead of the open button.
   */
  public function testPendingFilesRenderNoteInsteadOfOpenButton(): void {
    $files = $this->buildProjectPage('resetting')['#files'];

    $html = $this->renderToHtml($files);

    $this->assertStringContainsString('project__files__pending', $html);
    $this->assertStringNotContainsString('project__files__button', $html);
    $this->assertStringNotContainsString(self::OPEN_URL, $html);
  }

  /**
   * A Files section that is not pending renders the open button.
   */
  public function testReadyFilesRenderOpenButton(): void {
    $files = $this->buildProjectPage('ready')['#files'];

    $html = $this->renderToHtml($files);

    $this->assertStringContainsString('project__files__button', $html);
    $this->assertStringContainsString(self::OPEN_URL, $html);
    $this->assertStringNotContainsString('project__files__pending', $html);
  }

  /**
   * Builds the project page for the owner.
   *
   * @param string|null $demoStatus
   *   Value of demoStatus; NULL for a project that is not a demo.
   *
   * @return array<string, mixed>
   *   The built render array.
   */
  private function buildProjectPage(?string $demoStatus): array {
    $component = $this->createTestComponent('soda_scs_sql_component', [
      'owner' => $this->owner->id(),
    ]);
    $values = [
      'owner' => $this->owner->id(),
      'connectedComponents' => [$component->id()],
    ];
    if ($demoStatus !== NULL) {
      $values['demoStatus'] = $demoStatus;
    }
    $project = $this->createTestProject($values);

    $viewBuilder = $this->entityTypeManager->getViewBuilder('soda_scs_project');
    return $viewBuilder->build($viewBuilder->view($project));
  }

  /**
   * Renders a render array in isolation.
   */
  private function renderToHtml(array $element): string {
    return (string) $this->container->get('renderer')->renderInIsolation($element);
  }

  /**
   * The markup of the card between the meta block and the name block.
   */
  private function coreMarkup(string $html): string {
    $start = strpos($html, 'application__core');
    $end = strpos($html, 'application__name');
    $this->assertNotFalse($start);
    $this->assertNotFalse($end);
    return substr($html, $start - 20, $end - $start + 20);
  }

}
