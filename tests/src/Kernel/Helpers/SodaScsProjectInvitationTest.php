<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Helpers;

use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface;
use Drupal\user\UserInterface;

/**
 * A person can have only one open project invitation.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers
 */
class SodaScsProjectInvitationTest extends SodaScsKernelTestBase {

  /**
   * Membership helper under test.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers
   */
  private $membershipHelpers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('soda_scs_project_membership');
    $this->installEntitySchema('soda_scs_message');
    $this->membershipHelpers = $this->container->get('soda_scs_manager.project_membership.helpers');
  }

  /**
   * Pending and declined invitations are not created a second time.
   *
   * @covers ::inviteUsers
   * @covers ::resendInvitation
   * @covers ::withdrawInvitation
   * @covers ::deleteDeclinedInvitation
   */
  public function testOneOpenInvitationPerPerson(): void {
    $owner = $this->testUser;
    $recipient = $this->createTestUser();
    $project = $this->createTestProject(['owner' => $owner->id()]);

    $created = $this->membershipHelpers->inviteUsers($project, $owner, [$recipient]);
    $this->assertTrue($created->success);
    $this->assertSame(1, $this->countInvitations($project->id(), $recipient));

    $duplicate = $this->membershipHelpers->inviteUsers($project, $owner, [$recipient]);
    $this->assertFalse($duplicate->success);
    $this->assertSame(1, $this->countInvitations($project->id(), $recipient));

    $invitation = $this->loadInvitation($project->id(), $recipient);
    $this->assertInstanceOf(SodaScsProjectMembershipInterface::class, $invitation);
    $rejected = $this->membershipHelpers->rejectRequest($invitation, $recipient);
    $this->assertTrue($rejected->success);

    $afterDecline = $this->membershipHelpers->inviteUsers($project, $owner, [$recipient]);
    $this->assertFalse($afterDecline->success);
    $this->assertSame(1, $this->countInvitations($project->id(), $recipient));

    $invitation = $this->loadInvitation($project->id(), $recipient);
    $this->assertSame(SodaScsProjectMembershipInterface::STATUS_REJECTED, $invitation->getStatus());

    $resent = $this->membershipHelpers->resendInvitation($project, $invitation, $owner);
    $this->assertTrue($resent->success);
    $invitation = $this->reloadInvitation($invitation);
    $this->assertSame(SodaScsProjectMembershipInterface::STATUS_PENDING, $invitation->getStatus());
    $this->assertSame(1, $this->countInvitations($project->id(), $recipient));
    $this->assertSame(1, $this->countMessages((int) $invitation->id()));

    $outsider = $this->createTestUser();
    $forbidden = $this->membershipHelpers->withdrawInvitation($project, $invitation, $outsider);
    $this->assertFalse($forbidden->success);
    $this->assertSame(1, $this->countInvitations($project->id(), $recipient));

    $withdrawn = $this->membershipHelpers->withdrawInvitation($project, $invitation, $owner);
    $this->assertTrue($withdrawn->success);
    $this->assertSame(0, $this->countInvitations($project->id(), $recipient));
    $this->assertSame(0, $this->countMessages((int) $invitation->id()));

    $created = $this->membershipHelpers->inviteUsers($project, $owner, [$recipient]);
    $this->assertTrue($created->success);
    $invitation = $this->loadInvitation($project->id(), $recipient);
    $this->membershipHelpers->rejectRequest($invitation, $recipient);
    $invitation = $this->reloadInvitation($invitation);

    $removed = $this->membershipHelpers->deleteDeclinedInvitation($project, $invitation, $owner);
    $this->assertTrue($removed->success);
    $this->assertSame(0, $this->countInvitations($project->id(), $recipient));
  }

  /**
   * Count membership rows for one project and recipient.
   */
  private function countInvitations(int|string $projectId, UserInterface $recipient): int {
    $ids = $this->entityTypeManager->getStorage('soda_scs_project_membership')->getQuery()
      ->condition('project', $projectId)
      ->condition('recipient', $recipient->id())
      ->accessCheck(FALSE)
      ->execute();
    return count($ids);
  }

  /**
   * Count inbox copies that point at one invitation.
   */
  private function countMessages(int $invitationId): int {
    $ids = $this->entityTypeManager->getStorage('soda_scs_message')->getQuery()
      ->condition('relatedEntityType', 'soda_scs_project_membership')
      ->condition('relatedEntityId', $invitationId)
      ->accessCheck(FALSE)
      ->execute();
    return count($ids);
  }

  /**
   * Load the single invitation for a recipient.
   */
  private function loadInvitation(int|string $projectId, UserInterface $recipient): SodaScsProjectMembershipInterface {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project_membership');
    $ids = $storage->getQuery()
      ->condition('project', $projectId)
      ->condition('recipient', $recipient->id())
      ->accessCheck(FALSE)
      ->execute();
    $invitation = $storage->load(reset($ids));
    $this->assertInstanceOf(SodaScsProjectMembershipInterface::class, $invitation);
    return $invitation;
  }

  /**
   * Reload an invitation after it was saved by a helper.
   */
  private function reloadInvitation(SodaScsProjectMembershipInterface $invitation): SodaScsProjectMembershipInterface {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project_membership');
    $storage->resetCache([$invitation->id()]);
    $reloaded = $storage->load($invitation->id());
    $this->assertInstanceOf(SodaScsProjectMembershipInterface::class, $reloaded);
    return $reloaded;
  }

}
