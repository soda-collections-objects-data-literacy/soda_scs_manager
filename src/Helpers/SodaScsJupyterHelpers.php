<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
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

  use StringTranslationTrait;

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
   * Builds a JupyterLab URL that opens a project's Team Folder.
   *
   * Spawner bind-mounts Team Folders at
   * {@code /home/jovyan/nextcloud/<project label>}.
   *
   * @param string $baseUrl
   *   JupyterHub public base URL, with or without a trailing slash.
   * @param string $accountName
   *   Hub username (Keycloak preferred_username / Drupal account name).
   * @param string $projectLabel
   *   Project display name (Team Folder mount name).
   */
  public static function buildProjectLabUrl(string $baseUrl, string $accountName, string $projectLabel): string {
    $base = rtrim($baseUrl, '/');
    return $base . '/user/' . rawurlencode($accountName)
      . '/lab/tree/nextcloud/' . rawurlencode($projectLabel);
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

  /**
   * Starts a stopped Jupyter notebook container for the given user.
   *
   * Does not spawn a new server. If the container does not exist yet, the user
   * must start it from JupyterHub so bind mounts are applied.
   *
   * @return array{success: bool, started: bool, containerName: string, message: string}
   *   Operation result.
   */
  public function startNotebookContainerForUser(UserInterface $user): array {
    $accountName = $user->getAccountName();
    $containerName = $this->getNotebookContainerName($accountName);
    $logger = $this->loggerFactory->get('soda_scs_manager');

    $inspect = $this->inspectNotebookContainer($containerName);
    if ($inspect['statusCode'] === 404) {
      $message = 'No Jupyter notebook container found. Start it from JupyterHub.';
      $logger->notice('Jupyter notebook start skipped for @user: no container @container.', [
        '@user' => $accountName,
        '@container' => $containerName,
      ]);
      return [
        'success' => FALSE,
        'started' => FALSE,
        'containerName' => $containerName,
        'message' => $message,
      ];
    }
    if (!$inspect['success']) {
      $message = $inspect['error'];
      $logger->error('Jupyter notebook start failed for @user (@container): @error', [
        '@user' => $accountName,
        '@container' => $containerName,
        '@error' => $message,
      ]);
      return [
        'success' => FALSE,
        'started' => FALSE,
        'containerName' => $containerName,
        'message' => $message,
      ];
    }

    if ($inspect['status'] === 'running') {
      $message = 'Jupyter notebook container is already running.';
      $logger->notice('Jupyter notebook start skipped for @user: container @container is already running.', [
        '@user' => $accountName,
        '@container' => $containerName,
      ]);
      return [
        'success' => TRUE,
        'started' => FALSE,
        'containerName' => $containerName,
        'message' => $message,
      ];
    }

    $startResult = $this->containerHelpers->startContainer($containerName);
    if (!$startResult->success) {
      $message = (string) ($startResult->error ?? $startResult->message);
      $logger->error('Jupyter notebook start failed for @user (@container): @error', [
        '@user' => $accountName,
        '@container' => $containerName,
        '@error' => $message,
      ]);
      return [
        'success' => FALSE,
        'started' => FALSE,
        'containerName' => $containerName,
        'message' => $message,
      ];
    }

    $message = 'Jupyter notebook container started.';
    $logger->notice('Started Jupyter notebook container @container for user @user.', [
      '@container' => $containerName,
      '@user' => $accountName,
    ]);
    return [
      'success' => TRUE,
      'started' => TRUE,
      'containerName' => $containerName,
      'message' => $message,
    ];
  }

  /**
   * Restarts a Jupyter notebook container for the given user.
   *
   * @return array{success: bool, restarted: bool, containerName: string, message: string}
   *   Operation result.
   */
  public function restartNotebookContainerForUser(UserInterface $user): array {
    $accountName = $user->getAccountName();
    $containerName = $this->getNotebookContainerName($accountName);
    $logger = $this->loggerFactory->get('soda_scs_manager');

    $inspect = $this->inspectNotebookContainer($containerName);
    if ($inspect['statusCode'] === 404) {
      $message = 'No Jupyter notebook container found. Start it from JupyterHub.';
      $logger->notice('Jupyter notebook restart skipped for @user: no container @container.', [
        '@user' => $accountName,
        '@container' => $containerName,
      ]);
      return [
        'success' => FALSE,
        'restarted' => FALSE,
        'containerName' => $containerName,
        'message' => $message,
      ];
    }
    if (!$inspect['success']) {
      $message = $inspect['error'];
      $logger->error('Jupyter notebook restart failed for @user (@container): @error', [
        '@user' => $accountName,
        '@container' => $containerName,
        '@error' => $message,
      ]);
      return [
        'success' => FALSE,
        'restarted' => FALSE,
        'containerName' => $containerName,
        'message' => $message,
      ];
    }

    $restartResult = $this->containerHelpers->restartContainer($containerName);
    if (!$restartResult->success) {
      $message = (string) ($restartResult->error ?? $restartResult->message);
      $logger->error('Jupyter notebook restart failed for @user (@container): @error', [
        '@user' => $accountName,
        '@container' => $containerName,
        '@error' => $message,
      ]);
      return [
        'success' => FALSE,
        'restarted' => FALSE,
        'containerName' => $containerName,
        'message' => $message,
      ];
    }

    $message = 'Jupyter notebook container restarted.';
    $logger->notice('Restarted Jupyter notebook container @container for user @user.', [
      '@container' => $containerName,
      '@user' => $accountName,
    ]);
    return [
      'success' => TRUE,
      'restarted' => TRUE,
      'containerName' => $containerName,
      'message' => $message,
    ];
  }

  /**
   * Inspects a notebook container by Docker name.
   *
   * @return array{success: bool, statusCode: int, status: string, error: string}
   *   Inspect outcome. status is Docker State.Status when inspect succeeds.
   */
  private function inspectNotebookContainer(string $containerName): array {
    $inspectRequest = $this->dockerRunServiceActions->buildInspectRequest([
      'routeParams' => ['containerId' => $containerName],
    ]);
    $inspectResponse = $this->dockerRunServiceActions->makeRequest($inspectRequest);
    $statusCode = (int) ($inspectResponse['statusCode'] ?? 0);

    if (!$inspectResponse['success']) {
      return [
        'success' => FALSE,
        'statusCode' => $statusCode,
        'status' => '',
        'error' => (string) ($inspectResponse['error'] ?? 'Failed to inspect notebook container.'),
      ];
    }

    $inspectData = json_decode(
      (string) $inspectResponse['data']['portainerResponse']->getBody()->getContents(),
      TRUE
    );

    return [
      'success' => TRUE,
      'statusCode' => $statusCode,
      'status' => (string) ($inspectData['State']['Status'] ?? ''),
      'error' => '',
    ];
  }

  /**
   * Warns that Jupyter must be respawned to pick up new project Team Folders.
   *
   * Mounts are applied only when the notebook container starts. Link goes to
   * the confirm form that stops the server (user must Start again in Hub).
   */
  public function warnRestartNotebookForProjectMounts(MessengerInterface $messenger): void {
    $url = Url::fromRoute('soda_scs_manager.jupyter.restart_notebook')->toString();
    $messenger->addWarning($this->t('JupyterLab mounts project Team Folders only when your notebook server starts. To see this project there, <a href=":url">stop your Jupyter notebook server</a>, then start it again from JupyterHub. Unsaved notebook work will be lost when the server stops.', [
      ':url' => $url,
    ]));
  }

}
