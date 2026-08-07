<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ViewBuilder;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Theme\Registry;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Service\SodaScsNextcloudPreview;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The View Builder for the SodaScsStack entity.
 */
class SodaScsStackViewBuilder extends EntityViewBuilder {

  /**
   * Constructs a SodaScsStackViewBuilder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityRepositoryInterface $entity_repository,
    LanguageManagerInterface $language_manager,
    Registry $theme_registry,
    EntityDisplayRepositoryInterface $entity_display_repository,
    protected SodaScsServiceHelpers $sodaScsServiceHelpers,
    protected SodaScsNextcloudPreview $nextcloudPreview,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_type, $entity_repository, $language_manager, $theme_registry, $entity_display_repository);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity.repository'),
      $container->get('language_manager'),
      $container->get('theme.registry'),
      $container->get('entity_display.repository'),
      $container->get('soda_scs_manager.service.helpers'),
      $container->get('soda_scs_manager.nextcloud.preview'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $build) {
    $build = parent::build($build);
    $stackHealthUrl = Url::fromRoute('soda_scs_manager.stack.health_check', [
      'stack_id' => $build['#soda_scs_stack']->id(),
    ])->toString();

    // Hide the flavours field if it exists in the build array.
    if (isset($build['flavours'])) {
      $build['flavours']['#access'] = FALSE;
    }
    $build['#attached']['library'][] = 'soda_scs_manager/entityHelpers';
    $build['#attached']['drupalSettings']['entityInfo']['healthUrl'] = $stackHealthUrl;
    $build['#attached']['drupalSettings']['entityInfo']['bundle'] = $build['#soda_scs_stack']->bundle();
    $entity = $build['#soda_scs_stack'];
    try {
      $serviceUrls = $this->sodaScsServiceHelpers->getStackServiceAndLoginUrls($entity);
      if ($serviceUrls !== NULL && !empty($serviceUrls['loginUrl'])) {
        $build['#attached']['drupalSettings']['entityInfo']['serviceLoginUrl'] = $serviceUrls['loginUrl'];
      }
      if ($serviceUrls !== NULL && !empty($serviceUrls['url'])) {
        $build['#attached']['drupalSettings']['entityInfo']['serviceUrl'] = $serviceUrls['url'];
      }
    }
    catch (MissingDataException $e) {
      // Settings incomplete; health badge will not be linked.
    }

    if ($entity->bundle() === 'soda_scs_nextcloud_stack') {
      $build['nextcloudPreview'] = $this->buildNextcloudPreview($entity);
      $build['nextcloudPreview']['#weight'] = 40;
    }

    // Disable caching.
    $build['#cache'] = [
      'max-age' => 0,
    ];

    return $build;
  }

  /**
   * Builds the Nextcloud drive preview render array.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The Nextcloud stack entity.
   *
   * @return array
   *   Render array for the preview section.
   */
  protected function buildNextcloudPreview(EntityInterface $entity): array {
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    $preview = [
      'status' => 'needs_connect',
      'openUrl' => '',
      'recommendations' => ['status' => 'empty', 'items' => []],
      'activities' => ['status' => 'empty', 'items' => []],
      'favorites' => ['status' => 'empty', 'items' => []],
    ];

    if ($user instanceof UserInterface) {
      $preview = $this->nextcloudPreview->buildPreviewData($user);
    }

    $openUrl = $preview['openUrl'] ?? '';
    try {
      $serviceUrls = $this->sodaScsServiceHelpers->getStackServiceAndLoginUrls($entity);
      if ($serviceUrls !== NULL && !empty($serviceUrls['url'])) {
        $openUrl = $serviceUrls['url'];
      }
    }
    catch (MissingDataException $e) {
      // Keep preview openUrl from settings if stack URL lookup fails.
    }

    $build = [
      '#theme' => 'soda_scs_manager__nextcloud_preview',
      '#status' => $preview['status'],
      '#open_url' => $openUrl,
      '#recommendations' => $preview['recommendations'],
      '#activities' => $preview['activities'],
      '#favorites' => $preview['favorites'],
      '#attached' => [
        'library' => [
          'soda_scs_manager/nextcloudConnect',
        ],
      ],
    ];
    _soda_scs_manager_attach_nextcloud_connect_settings($build);

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode) {
    $build = parent::getBuildDefaults($entity, $view_mode);

    $build['#theme'] = 'soda_scs_entity';
    $build['#soda_scs_stack'] = $entity;
    $build['#entity_type'] = 'stack';
    $build['#view_mode'] = $view_mode;

    return $build;
  }

}
