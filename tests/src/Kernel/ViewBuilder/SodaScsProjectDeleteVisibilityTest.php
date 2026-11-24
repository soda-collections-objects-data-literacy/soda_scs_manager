<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\ViewBuilder;

use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Controller\SodaScsManagerController;
use Drupal\soda_scs_manager\Service\SodaScsNextcloudPreview;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;

/**
 * Tests who is offered the Delete action of a project.
 *
 * The owner and an SCS Manager admin may delete, a plain member may not; the
 * project page and the dashboard card carry that as #can_delete.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\ViewBuilder\SodaScsProjectViewBuilder
 */
class SodaScsProjectDeleteVisibilityTest extends SodaScsKernelTestBase {

  /**
   * The project's owner.
   *
   * @var \Drupal\user\UserInterface
   */
  private $owner;

  /**
   * A project member who is not the owner.
   *
   * @var \Drupal\user\UserInterface
   */
  private $member;

  /**
   * An SCS Manager admin who is neither owner nor member.
   *
   * @var \Drupal\user\UserInterface
   */
  private $admin;

  /**
   * The project.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface
   */
  private $project;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The Files section would ask Nextcloud for the viewer's activity.
    $preview = $this->createMock(SodaScsNextcloudPreview::class);
    $preview->method('buildProjectFilesSection')->willReturn([]);
    $this->container->set('soda_scs_manager.nextcloud.preview', $preview);

    $userRole = Role::load('scs_user') ?? Role::create([
      'id' => 'scs_user',
      'label' => 'scs_user',
    ]);
    $userRole->grantPermission('soda scs manager user');
    $userRole->save();

    // Not is_admin: the project's admin permission alone must be enough.
    $adminRole = Role::create([
      'id' => 'scs_admin',
      'label' => 'scs_admin',
    ]);
    $adminRole->grantPermission('soda scs manager user');
    $adminRole->grantPermission('soda scs manager admin');
    $adminRole->save();

    $this->owner = $this->testUser;
    $this->owner->addRole('scs_user');
    $this->owner->save();

    $this->member = $this->createTestUser();
    $this->member->addRole('scs_user');
    $this->member->save();

    $this->admin = $this->createTestUser();
    $this->admin->addRole('scs_admin');
    $this->admin->save();

    $this->project = $this->createTestProject([
      'owner' => $this->owner->id(),
      'members' => [$this->member->id()],
    ]);
  }

  /**
   * An admin who does not own the project is offered Delete on the page.
   *
   * @covers ::build
   */
  public function testProjectPageOffersDeleteToAdminWhoIsNotOwner(): void {
    $build = $this->projectPageBuildFor($this->admin);

    $this->assertTrue($build['#can_delete']);
    $this->assertFalse($build['#is_owner']);
  }

  /**
   * The owner is offered Delete on the page.
   *
   * @covers ::build
   */
  public function testProjectPageOffersDeleteToOwner(): void {
    $build = $this->projectPageBuildFor($this->owner);

    $this->assertTrue($build['#can_delete']);
    $this->assertTrue($build['#is_owner']);
  }

  /**
   * A plain member is not offered Delete on the page.
   *
   * @covers ::build
   */
  public function testProjectPageOmitsDeleteForPlainMember(): void {
    $build = $this->projectPageBuildFor($this->member);

    $this->assertFalse($build['#can_delete']);
    $this->assertFalse($build['#is_owner']);
  }

  /**
   * An admin who does not own the project gets Delete on the dashboard card.
   *
   * @covers \Drupal\soda_scs_manager\Controller\SodaScsManagerController::dashboardPage
   */
  public function testDashboardCardOffersDeleteToAdminWhoIsNotOwner(): void {
    $card = $this->dashboardCardFor($this->admin);

    $this->assertTrue($card['#can_delete']);
    $this->assertFalse($card['#is_owner']);
  }

  /**
   * The owner gets Delete on the dashboard card.
   *
   * @covers \Drupal\soda_scs_manager\Controller\SodaScsManagerController::dashboardPage
   */
  public function testDashboardCardOffersDeleteToOwner(): void {
    $card = $this->dashboardCardFor($this->owner);

    $this->assertTrue($card['#can_delete']);
    $this->assertTrue($card['#is_owner']);
  }

  /**
   * A plain member gets no Delete on the dashboard card.
   *
   * @covers \Drupal\soda_scs_manager\Controller\SodaScsManagerController::dashboardPage
   */
  public function testDashboardCardOmitsDeleteForPlainMember(): void {
    $card = $this->dashboardCardFor($this->member);

    $this->assertFalse($card['#can_delete']);
    $this->assertFalse($card['#is_owner']);
  }

  /**
   * The card shows the Delete entry and the confirmation dialog under delete.
   */
  public function testProjectCardRendersDeleteEntryAndDialogWhenAllowed(): void {
    $html = $this->renderProjectCard(TRUE);

    $this->assertStringContainsString('project-card__delete-trigger', $html);
    $this->assertStringContainsString('project-card__delete-confirmation', $html);
  }

  /**
   * The card shows neither the Delete entry nor the dialog without delete.
   */
  public function testProjectCardRendersNoDeleteEntryOrDialogWhenDenied(): void {
    $html = $this->renderProjectCard(FALSE);

    $this->assertStringNotContainsString('project-card__delete-trigger', $html);
    $this->assertStringNotContainsString('project-card__delete-confirmation', $html);
    $this->assertStringContainsString('project-card__actions', $html);
  }

  /**
   * The card defaults to no delete when the variable is not passed.
   */
  public function testProjectCardRendersNoDeleteEntryByDefault(): void {
    $html = $this->renderProjectCard(NULL);

    $this->assertStringNotContainsString('project-card__delete-trigger', $html);
    $this->assertStringNotContainsString('project-card__delete-confirmation', $html);
  }

  /**
   * The project page shows Delete to a non-owner who may delete.
   */
  public function testProjectPageTemplateRendersDeleteForNonOwnerWhenAllowed(): void {
    $html = $this->renderProjectPage(TRUE, FALSE);

    $this->assertStringContainsString('project__delete', $html);
  }

  /**
   * The project page hides Delete from an owner flag without delete access.
   */
  public function testProjectPageTemplateRendersNoDeleteWhenDeniedEvenForOwnerFlag(): void {
    $html = $this->renderProjectPage(FALSE, TRUE);

    $this->assertStringNotContainsString('project__delete', $html);
  }

  /**
   * Switches the current user.
   */
  private function actAs(AccountInterface $account): void {
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * Project page build array as the view builder produces it for the user.
   *
   * @return array<string, mixed>
   *   The built render array.
   */
  private function projectPageBuildFor(AccountInterface $account): array {
    $this->actAs($account);
    $viewBuilder = $this->entityTypeManager->getViewBuilder('soda_scs_project');
    return $viewBuilder->build($viewBuilder->view($this->project));
  }

  /**
   * The project's dashboard card as the controller builds it for the user.
   *
   * @return array<string, mixed>
   *   The card render array.
   */
  private function dashboardCardFor(AccountInterface $account): array {
    $this->actAs($account);
    $controller = SodaScsManagerController::create($this->container);
    $cards = $controller->dashboardPage()['#project_cards'];
    $this->assertCount(1, $cards);
    return $cards[0];
  }

  /**
   * Renders the project card with the given #can_delete value.
   *
   * @param bool|null $canDelete
   *   Value of #can_delete; NULL leaves it to the theme hook default.
   */
  private function renderProjectCard(?bool $canDelete): string {
    $card = [
      '#theme' => 'soda_scs_manager__project_card',
      '#project_id' => (int) $this->project->id(),
      '#label' => 'Card project',
      '#url' => '/project/1',
      '#is_owner' => TRUE,
      '#owner_id' => (int) $this->owner->id(),
    ];
    if ($canDelete !== NULL) {
      $card['#can_delete'] = $canDelete;
    }
    return (string) $this->container->get('renderer')->renderInIsolation($card);
  }

  /**
   * Renders the project page template with the given flags.
   */
  private function renderProjectPage(bool $canDelete, bool $isOwner): string {
    $page = [
      '#theme' => 'soda_scs_project',
      '#label' => 'Page project',
      '#project_id' => (int) $this->project->id(),
      '#can_delete' => $canDelete,
      '#is_owner' => $isOwner,
    ];
    return (string) $this->container->get('renderer')->renderInIsolation($page);
  }

}
