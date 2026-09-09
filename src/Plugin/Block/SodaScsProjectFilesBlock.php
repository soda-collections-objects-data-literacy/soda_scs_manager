<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\Service\SodaScsNextcloudPreview;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows recent Nextcloud Team Folder activity for the current project.
 *
 * Place on project pages (or Layout Builder). The block resolves the project
 * from context and loads the feed with the viewing user's Drive credentials,
 * so owners and members each see activity for folders they can access.
 */
#[Block(
  id: 'soda_scs_manager_project_files',
  admin_label: new TranslatableMarkup('Project files (Nextcloud activity)'),
  category: new TranslatableMarkup('SODa SCS Manager'),
  context_definitions: [
    'soda_scs_project' => new EntityContextDefinition(
      data_type: 'entity:soda_scs_project',
      label: new TranslatableMarkup('SODa SCS Project'),
      required: TRUE,
    ),
  ]
)]
final class SodaScsProjectFilesBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a SodaScsProjectFilesBlock.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SodaScsNextcloudPreview $nextcloudPreview,
    protected SodaScsProjectMembershipHelpers $projectMembershipHelpers,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.nextcloud.preview'),
      $container->get('soda_scs_manager.project_membership.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $project = $this->getContextValue('soda_scs_project');
    if (!$project instanceof SodaScsProjectInterface) {
      return [];
    }

    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if (!$user instanceof UserInterface) {
      return [];
    }

    return $this->nextcloudPreview->buildProjectFilesSection($user, $project);
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    $project = $this->getContextValue('soda_scs_project');
    if (!$project instanceof SodaScsProjectInterface) {
      return AccessResult::forbidden()->addCacheContexts(['route']);
    }

    $entityAccess = $project->access('view', $account, TRUE);
    if (!$entityAccess->isAllowed()) {
      return $entityAccess;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$user instanceof UserInterface) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $isMember = $this->projectMembershipHelpers->isMemberOfProject($project, $user);
    return AccessResult::allowedIf($isMember)
      ->addCacheableDependency($project)
      ->cachePerUser();
  }

}
