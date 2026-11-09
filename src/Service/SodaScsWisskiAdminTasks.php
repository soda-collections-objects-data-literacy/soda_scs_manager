<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Access\SodaScsManagerAdminAccess;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Form\SodaScsWisskiClearVarnishCacheConfirmForm;
use Drupal\soda_scs_manager\Form\SodaScsWisskiUpdateConfirmForm;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prepares the admin tasks and version details of a WissKI application page.
 *
 * Used by the component and the stack view builder, so both pages show the
 * same "Admin tasks" panel and version card.
 */
class SodaScsWisskiAdminTasks {

  /**
   * Characters of the image commit hash shown on the page.
   */
  public const SHORT_COMMIT_LENGTH = 7;

  /**
   * OCI image label that carries the source commit of the image build.
   */
  private const REVISION_LABEL = 'org.opencontainers.image.revision';

  /**
   * Bundle of WissKI components.
   */
  private const WISSKI_COMPONENT_BUNDLE = 'soda_scs_wisski_component';

  /**
   * Constructs a SodaScsWisskiAdminTasks.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerChannelInterface $logger,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    protected SodaScsServiceHelpers $serviceHelpers,
  ) {}

  /**
   * Builds the template variables for a WissKI component or stack page.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The displayed component or stack.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewing user.
   *
   * @return array{
   *   availableVersion: string,
   *   clearVarnishCacheUrl: string,
   *   commitHash: string,
   *   commitHashShort: string,
   *   isDevelopment: bool,
   *   updateUrl: string,
   * }
   *   Empty strings for everything that does not apply.
   */
  public function build(ContentEntityInterface $entity, AccountInterface $account): array {
    $build = [
      'availableVersion'     => '',
      'clearVarnishCacheUrl' => '',
      'commitHash'           => '',
      'commitHashShort'      => '',
      'isDevelopment'        => FALSE,
      'updateUrl'            => '',
    ];

    $component = $this->wisskiComponent($entity);
    if ($component === NULL) {
      return $build;
    }

    $destination = $entity->toUrl()->toString();
    $isAdmin = $account->hasPermission(SodaScsManagerAdminAccess::SCS_MANAGER_ADMIN_PERMISSION);
    $isDevelopment = $this->booleanField($entity, 'developmentInstance');
    $build['isDevelopment'] = $isDevelopment;

    if ($isAdmin) {
      $build['clearVarnishCacheUrl'] = SodaScsWisskiClearVarnishCacheConfirmForm::confirmUrl($component, $destination)->toString();
      if ($this->booleanField($entity, 'automatedUpdates')) {
        $build['updateUrl'] = SodaScsWisskiUpdateConfirmForm::confirmUrl($component, $destination)->toString();
      }
    }

    if ($isDevelopment) {
      $build['commitHash'] = $this->imageCommit($component);
      $build['commitHashShort'] = substr($build['commitHash'], 0, self::SHORT_COMMIT_LENGTH);
    }
    elseif ($entity->hasField('version')) {
      $build['availableVersion'] = $this->availableVersion((string) ($entity->get('version')->value ?? ''));
    }

    return $build;
  }

  /**
   * Newer production version than the installed one, or an empty string.
   *
   * @param string $installedVersion
   *   Version label stored on the displayed entity.
   */
  protected function availableVersion(string $installedVersion): string {
    try {
      $defaultVersion = (string) ($this->serviceHelpers->initWisskiInstanceSettings()['defaultVersion'] ?? '');
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not read the WissKI default version: @message', ['@message' => $e->getMessage()]);
      return '';
    }

    if ($defaultVersion === '' || $installedVersion === '') {
      return '';
    }
    return version_compare($defaultVersion, $installedVersion, '>') ? $defaultVersion : '';
  }

  /**
   * Whether a boolean field exists on the entity and is set.
   */
  protected function booleanField(ContentEntityInterface $entity, string $fieldName): bool {
    return $entity->hasField($fieldName) && (bool) $entity->get($fieldName)->value;
  }

  /**
   * Source commit of the image the WissKI Drupal container runs.
   *
   * Images from the registry carry the OCI revision label; locally built
   * images do not, so the result can be empty.
   */
  protected function imageCommit(SodaScsComponentInterface $component): string {
    $lookup = $this->containerHelpers->findLiveContainerByName(
      $this->containerHelpers->getComponentContainerName($component),
    );
    if (!$lookup->success) {
      return '';
    }

    $revision = (string) ($lookup->data['labels'][self::REVISION_LABEL] ?? '');
    return preg_match('/^[0-9a-f]{7,64}$/', $revision) ? $revision : '';
  }

  /**
   * The WissKI component of a component or stack page, or NULL.
   */
  protected function wisskiComponent(ContentEntityInterface $entity): ?SodaScsComponentInterface {
    if ($entity instanceof SodaScsComponentInterface) {
      return $entity->bundle() === self::WISSKI_COMPONENT_BUNDLE ? $entity : NULL;
    }
    if (!$entity instanceof SodaScsStackInterface
      || $entity->bundle() !== 'soda_scs_wisski_stack'
      || !$entity->hasField('includedComponents')) {
      return NULL;
    }

    foreach ($entity->get('includedComponents')->referencedEntities() as $component) {
      if ($component instanceof SodaScsComponentInterface && $component->bundle() === self::WISSKI_COMPONENT_BUNDLE) {
        return $component;
      }
    }
    return NULL;
  }

}
