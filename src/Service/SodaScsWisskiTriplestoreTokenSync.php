<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes the current triplestore token into the adapter of a WissKI.
 *
 * The WissKI entrypoint writes the token into the Salz adapter only on the
 * first install. OpenGDB issues one token per account, so a new token (the
 * account was created again) or a database imported from another instance
 * (demo templates) leaves the adapter with a token that OpenGDB rejects.
 */
class SodaScsWisskiTriplestoreTokenSync {

  /**
   * Config of the adapter that the WissKI entrypoint creates.
   */
  protected const ADAPTER_CONFIG = 'wisski_salz.wisski_salz_adapter.default';

  /**
   * OpenGDB expects the Django token auth header value.
   */
  protected const TOKEN_PREFIX = 'Token ';

  /**
   * Marks the script result in drush output, which may contain notices.
   */
  protected const OUTPUT_MARKER = 'SCS_TOKEN_SYNC:';

  /**
   * Bundles of the components involved.
   */
  protected const TRIPLESTORE_BUNDLE = 'soda_scs_triplestore_component';
  protected const WISSKI_BUNDLE = 'soda_scs_wisski_component';

  /**
   * Constructs the token sync.
   */
  public function __construct(
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerChannelInterface $logger,
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
    #[Autowire(service: 'soda_scs_manager.service_key.actions')]
    protected SodaScsServiceKeyActionsInterface $serviceKeyActions,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Writes the owner's token of a triplestore into the WissKI adapter.
   *
   * The adapter is only changed when it reads from this triplestore's
   * repository, so an adapter that was pointed elsewhere by hand stays.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent
   *   The running WissKI component.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $triplestoreComponent
   *   The triplestore component of the same stack.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success when the adapter holds the current token or reads another
   *   repository (data 'state': updated, unchanged, other repository).
   */
  public function syncComponents(SodaScsComponentInterface $wisskiComponent, SodaScsComponentInterface $triplestoreComponent): SodaScsResult {
    $tokenKey = $this->serviceKeyActions->getServiceKey([
      'bundle' => self::TRIPLESTORE_BUNDLE,
      'type' => 'token',
      'userId' => $triplestoreComponent->getOwnerId(),
    ]);
    $token = $tokenKey ? (string) $tokenKey->get('servicePassword')->value : '';
    if ($token === '') {
      return SodaScsResult::failure(
        message: 'The triplestore owner has no token.',
        error: sprintf('No triplestore token service key for user %s.', $triplestoreComponent->getOwnerId()),
      );
    }

    // The token travels in the environment of the exec, not in the command.
    $script = '$config = \Drupal::configFactory()->getEditable("' . self::ADAPTER_CONFIG . '");'
      . '$suffix = "/repositories/" . getenv("SCS_TS_REPOSITORY");'
      . 'if (!str_ends_with((string) $config->get("engine.read_url"), $suffix)) { echo "' . self::OUTPUT_MARKER . 'other repository"; return; }'
      . '$token = "' . self::TOKEN_PREFIX . '" . getenv("SCS_TS_TOKEN");'
      . 'if ($config->get("engine.token") === $token) { echo "' . self::OUTPUT_MARKER . 'unchanged"; return; }'
      . '$config->set("engine.token", $token)->set("engine.use_token", TRUE)->save();'
      . 'drupal_flush_all_caches();'
      . 'echo "' . self::OUTPUT_MARKER . 'updated";';
    $result = $this->containerHelpers->executeDockerExecCommand([
      'cmd' => ['drush', 'php:eval', $script],
      'containerName' => $this->containerHelpers->getComponentContainerName($wisskiComponent),
      'env' => [
        'SCS_TS_REPOSITORY=' . $triplestoreComponent->get('machineName')->value,
        'SCS_TS_TOKEN=' . $token,
      ],
      'timeout' => 300,
      'user' => 'www-data',
    ]);
    if (!$result->success) {
      return SodaScsResult::failure(
        message: 'Could not update the triplestore token of the WissKI.',
        error: (string) ($result->error ?? 'drush failed'),
      );
    }
    if (!preg_match('/' . preg_quote(self::OUTPUT_MARKER, '/') . '(updated|unchanged|other repository)/', (string) ($result->data['output'] ?? ''), $matches)) {
      return SodaScsResult::failure(
        message: 'Could not update the triplestore token of the WissKI.',
        error: 'Missing result in drush output.',
      );
    }
    if ($matches[1] === 'updated') {
      $this->logger->notice('Updated the triplestore token in the adapter of WissKI @wisski.', ['@wisski' => $wisskiComponent->id()]);
    }
    return SodaScsResult::success(message: 'Triplestore token checked.', data: ['state' => $matches[1]]);
  }

  /**
   * Updates the WissKI adapters of every triplestore a user owns.
   *
   * Called after a new token was issued for the user. A WissKI that is not
   * running or fails is logged and skipped; the others are still updated.
   *
   * @return int
   *   Number of adapters that now hold the current token.
   */
  public function syncUser(int $userId): int {
    $ids = $this->entityTypeManager->getStorage('soda_scs_component')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', self::TRIPLESTORE_BUNDLE)
      ->condition('owner', $userId)
      ->execute();
    $synced = 0;
    foreach ($this->entityTypeManager->getStorage('soda_scs_component')->loadMultiple($ids) as $triplestoreComponent) {
      if (!$triplestoreComponent instanceof SodaScsComponentInterface) {
        continue;
      }
      $wisskiComponent = $this->stackWisski($triplestoreComponent);
      if ($wisskiComponent === NULL) {
        continue;
      }
      $result = $this->syncComponents($wisskiComponent, $triplestoreComponent);
      if ($result->success) {
        $synced++;
        continue;
      }
      $this->logger->warning('Could not update the triplestore token of WissKI @wisski: @error', [
        '@error' => (string) ($result->error ?? $result->message),
        '@wisski' => $wisskiComponent->id(),
      ]);
    }
    return $synced;
  }

  /**
   * The WissKI component in the stack of a triplestore component.
   */
  protected function stackWisski(SodaScsComponentInterface $triplestoreComponent): ?SodaScsComponentInterface {
    $stack = $triplestoreComponent->getPartOfStack();
    if ($stack === NULL) {
      return NULL;
    }
    foreach ($stack->get('includedComponents')->referencedEntities() as $component) {
      if ($component instanceof SodaScsComponentInterface && $component->bundle() === self::WISSKI_BUNDLE) {
        return $component;
      }
    }
    return NULL;
  }

}
