<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Helper for JupyterHub notebook container lifecycle operations.
 *
 * DockerSpawner names user servers {@code jupyter-{username}} by default and
 * stores data in the named volume {@code jupyterhub-user-{username}}.
 */
class SodaScsJupyterHelpers {

  /**
   * Default DockerSpawner container name prefix.
   */
  public const DEFAULT_CONTAINER_NAME_PREFIX = 'jupyter-';

  /**
   * Constructs a SodaScsJupyterHelpers object.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.docker_run_service.actions')]
    protected SodaScsDockerRunServiceActions $dockerRunServiceActions,
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
    #[Autowire(service: 'config.factory')]
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns the configured Jupyter notebook container name prefix.
   */
  public function getContainerNamePrefix(): string {
    $prefix = $this->configFactory->get('soda_scs_manager.settings')
      ->get('jupyterhub.generalSettings.containerNamePrefix');
    if (!is_string($prefix) || $prefix === '') {
      return self::DEFAULT_CONTAINER_NAME_PREFIX;
    }
    return $prefix;
  }

  /**
   * Builds the Docker container name for a user's Jupyter notebook server.
   *
   * Matches DockerSpawner's default ({@code jupyter-{username}}) where
   * {@code username} is Keycloak {@code preferred_username} / Drupal account
   * name.
   */
  public function getNotebookContainerName(string $accountName): string {
    return $this->getContainerNamePrefix() . $accountName;
  }

  /**
   * Stops a running Jupyter notebook container for the given user.
   *
   * Does not remove the container or its {@code jupyterhub-user-{username}}
   * Docker volume.
   *
   * @return array{success: bool, stopped: bool, containerName: string, message: string}
   *   Operation result. {@code stopped} is FALSE when no running container
   *   was found.
   */
  public function stopNotebookContainerForUser(UserInterface $user): array {
    $accountName = $user->getAccountName();
    $containerName = $this->getNotebookContainerName($accountName);
    $logger = $this->loggerFactory->get('soda_scs_manager');

    $inspectRequest = $this->dockerRunServiceActions->buildInspectRequest([
      'routeParams' => ['containerId' => $containerName],
    ]);
    $inspectResponse = $this->dockerRunServiceActions->makeRequest($inspectRequest);

    if (!$inspectResponse['success']) {
      if (($inspectResponse['statusCode'] ?? 0) === 404) {
        $message = 'No Jupyter notebook container found.';
        $logger->notice('Jupyter notebook stop skipped for @user: no container @container.', [
          '@user' => $accountName,
          '@container' => $containerName,
        ]);
        return [
          'success' => TRUE,
          'stopped' => FALSE,
          'containerName' => $containerName,
          'message' => $message,
        ];
      }

      $message = (string) ($inspectResponse['error'] ?? 'Failed to inspect notebook container.');
      $logger->error('Jupyter notebook stop failed for @user (@container): @error', [
        '@user' => $accountName,
        '@container' => $containerName,
        '@error' => $message,
      ]);
      return [
        'success' => FALSE,
        'stopped' => FALSE,
        'containerName' => $containerName,
        'message' => $message,
      ];
    }

    $inspectData = json_decode(
      (string) $inspectResponse['data']['portainerResponse']->getBody()->getContents(),
      TRUE
    );
    $status = $inspectData['State']['Status'] ?? '';
    if ($status !== 'running') {
      $message = 'Jupyter notebook container is not running.';
      $logger->notice('Jupyter notebook stop skipped for @user: container @container status is @status.', [
        '@user' => $accountName,
        '@container' => $containerName,
        '@status' => $status !== '' ? $status : 'unknown',
      ]);
      return [
        'success' => TRUE,
        'stopped' => FALSE,
        'containerName' => $containerName,
        'message' => $message,
      ];
    }

    $stopResult = $this->containerHelpers->stopContainer($containerName);
    if (!$stopResult->success) {
      $message = (string) ($stopResult->error ?? $stopResult->message);
      $logger->error('Jupyter notebook stop failed for @user (@container): @error', [
        '@user' => $accountName,
        '@container' => $containerName,
        '@error' => $message,
      ]);
      return [
        'success' => FALSE,
        'stopped' => FALSE,
        'containerName' => $containerName,
        'message' => $message,
      ];
    }

    $message = 'Jupyter notebook container stopped.';
    $logger->notice('Stopped Jupyter notebook container @container for user @user.', [
      '@container' => $containerName,
      '@user' => $accountName,
    ]);
    return [
      'success' => TRUE,
      'stopped' => TRUE,
      'containerName' => $containerName,
      'message' => $message,
    ];
  }

}
