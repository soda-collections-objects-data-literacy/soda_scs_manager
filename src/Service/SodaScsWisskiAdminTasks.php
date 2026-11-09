<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
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

  use StringTranslationTrait;

  /**
   * Date format of image build dates.
   */
  private const BUILD_DATE_FORMAT = 'd.m.Y';

  /**
   * Characters of the image commit hash shown on the page.
   */
  public const SHORT_COMMIT_LENGTH = 7;

  /**
   * Image repository development instances are redeployed from.
   *
   * Used when the running image name does not reveal it (locally built or
   * referenced by ID).
   */
  private const DEVELOPMENT_IMAGE_REPOSITORY = 'ghcr.io/soda-collections-objects-data-literacy/wisski-base-image-development';

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
    #[Autowire(service: 'date.formatter')]
    protected DateFormatterInterface $dateFormatter,
    #[Autowire(service: 'soda_scs_manager.image_registry_client')]
    protected SodaScsImageRegistryClient $imageRegistryClient,
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
   *   availableCommit: string,
   *   availableLabel: string,
   *   clearVarnishCacheUrl: string,
   *   commitHash: string,
   *   commitLabel: string,
   *   isDevelopment: bool,
   *   updateUrl: string,
   * }
   *   Empty strings for everything that does not apply. availableLabel is the
   *   update target ("5.6.1" or "main (4c498db from 09.11.2026)"),
   *   commitLabel the running build ("4c498db from 08.11.2026").
   */
  public function build(ContentEntityInterface $entity, AccountInterface $account): array {
    $build = [
      'availableCommit'      => '',
      'availableLabel'       => '',
      'clearVarnishCacheUrl' => '',
      'commitHash'           => '',
      'commitLabel'          => '',
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

    $settings = $this->wisskiInstanceSettings();
    if ($isDevelopment) {
      $running = $this->runningImage($component);
      $build['commitHash'] = $running['revision'];
      $build['commitLabel'] = $this->commitLabel($running);

      $available = $this->availableDevelopmentBuild($running, (string) ($settings['imageDevelopmentVersion'] ?? ''));
      $build['availableCommit'] = $available['revision'];
      if ($available['tag'] !== '') {
        $availableCommitLabel = $this->commitLabel($available);
        $build['availableLabel'] = $availableCommitLabel !== ''
          ? $available['tag'] . ' (' . $availableCommitLabel . ')'
          : $available['tag'];
      }
    }
    elseif ($entity->hasField('version')) {
      $build['availableLabel'] = $this->availableProductionVersion(
        (string) ($entity->get('version')->value ?? ''),
        (string) ($settings['defaultVersion'] ?? ''),
      );
    }

    return $build;
  }

  /**
   * Newer development build than the running one.
   *
   * A development update redeploys from the configured development tag.
   * There is an update when the instance runs another tag, or when the
   * registry's build of that tag has another commit than the running image.
   *
   * @param array{created: int, image: string, revision: string} $running
   *   The running image, see runningImage().
   * @param string $targetTag
   *   Configured development image tag, for example main.
   *
   * @return array{created: int, revision: string, tag: string}
   *   Empty values when no update is known.
   */
  protected function availableDevelopmentBuild(array $running, string $targetTag): array {
    $none = ['created' => 0, 'revision' => '', 'tag' => ''];
    if ($targetTag === '') {
      return $none;
    }

    [$runningRepository, $runningTag] = $this->splitImageReference($running['image']);
    $repository = str_ends_with($runningRepository, '/wisski-base-image-development')
      ? $runningRepository
      : self::DEVELOPMENT_IMAGE_REPOSITORY;
    $registryBuild = $this->imageRegistryClient->build($repository, $targetTag);

    $otherTag = $runningTag !== '' && $runningTag !== $targetTag;
    $otherCommit = $registryBuild['revision'] !== ''
      && $running['revision'] !== ''
      && $registryBuild['revision'] !== $running['revision'];
    if (!$otherTag && !$otherCommit) {
      return $none;
    }
    return $registryBuild + ['tag' => $targetTag];
  }

  /**
   * Newer production version than the installed one, or an empty string.
   *
   * @param string $installedVersion
   *   Version label stored on the displayed entity.
   * @param string $defaultVersion
   *   Version new production instances get.
   */
  protected function availableProductionVersion(string $installedVersion, string $defaultVersion): string {
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
   * Short commit hash with build date, e.g. "4c498db from 09.11.2026".
   *
   * @param array{created: int, revision: string} $build
   *   Image build, see SodaScsImageRegistryClient::buildFromLabels().
   *
   * @return string
   *   Empty when the commit is unknown.
   */
  protected function commitLabel(array $build): string {
    if ($build['revision'] === '') {
      return '';
    }
    $shortCommit = substr($build['revision'], 0, self::SHORT_COMMIT_LENGTH);
    if ($build['created'] <= 0) {
      return $shortCommit;
    }
    return (string) $this->t('@commit from @date', [
      '@commit' => $shortCommit,
      '@date'   => $this->dateFormatter->format($build['created'], 'custom', self::BUILD_DATE_FORMAT),
    ]);
  }

  /**
   * Image name, source commit and build time of the WissKI Drupal container.
   *
   * Images from the registry carry the OCI labels; locally built images do
   * not, so commit and build time can be empty.
   *
   * @return array{created: int, image: string, revision: string}
   *   Empty values when the container is not found.
   */
  protected function runningImage(SodaScsComponentInterface $component): array {
    $lookup = $this->containerHelpers->findLiveContainerByName(
      $this->containerHelpers->getComponentContainerName($component),
    );
    if (!$lookup->success) {
      return ['created' => 0, 'image' => '', 'revision' => ''];
    }

    $labels = $lookup->data['labels'] ?? [];
    return SodaScsImageRegistryClient::buildFromLabels(is_array($labels) ? $labels : [])
      + ['image' => (string) ($lookup->data['image'] ?? '')];
  }

  /**
   * Splits an image reference into repository and tag.
   *
   * @return array{0: string, 1: string}
   *   Repository and tag; the tag is empty for digests and bare image IDs.
   */
  protected function splitImageReference(string $image): array {
    $image = explode('@', $image, 2)[0];
    $slash = strrpos($image, '/');
    $colon = strrpos($image, ':');
    if ($colon === FALSE || ($slash !== FALSE && $colon < $slash)) {
      return [$image, ''];
    }
    return [substr($image, 0, $colon), substr($image, $colon + 1)];
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

  /**
   * WissKI instance settings, or an empty array when they are incomplete.
   *
   * @return array<string, mixed>
   *   See SodaScsServiceHelpers::initWisskiInstanceSettings().
   */
  protected function wisskiInstanceSettings(): array {
    try {
      return $this->serviceHelpers->initWisskiInstanceSettings();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not read the WissKI instance settings: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

}
