<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerExecServiceActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LogLevel;

/**
 * Helper class for Soda SCS container operations.
 *
 * @service soda_scs_manager.container.helpers
 */
class SodaScsContainerHelpers {

  use MessengerTrait;
  use StringTranslationTrait;


  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The Soda SCS docker run service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions
   */
  protected SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions;

  /**
   * The Soda SCS docker exec service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsDockerExecServiceActions
   */
  protected SodaScsDockerExecServiceActions $sodaScsDockerExecServiceActions;

  /**
   * The Soda SCS helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsHelpers
   */
  protected SodaScsHelpers $sodaScsHelpers;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions
   *   The Soda SCS docker run service actions.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsDockerExecServiceActions $sodaScsDockerExecServiceActions
   *   The Soda SCS docker exec service actions.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsHelpers $sodaScsHelpers
   *   The Soda SCS helpers.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'soda_scs_manager.docker_run_service.actions')]
    SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions,
    #[Autowire(service: 'soda_scs_manager.docker_exec_service.actions')]
    SodaScsDockerExecServiceActions $sodaScsDockerExecServiceActions,
    #[Autowire(service: 'soda_scs_manager.helpers')]
    SodaScsHelpers $sodaScsHelpers,
  ) {
    $this->loggerFactory = $loggerFactory;
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
    $this->sodaScsDockerExecServiceActions = $sodaScsDockerExecServiceActions;
    $this->sodaScsHelpers = $sodaScsHelpers;
  }

  /**
   * Wait for container to finnish.
   *
   * We loop through the containers for given
   * attempts. We inspect them until they
   * exited or we reach the max attempts. If $deleteContainers is TRUE,
   * we delete them after they are exited.
   *
   * @param array $containers
   *   The containers.
   * @param bool $deleteContainers
   *   Whether to delete the containers.
   * @param string $context
   *   The context.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function waitContainersToFinish(array $containers, bool $deleteContainers, $context) {
    $containerIsRunning = TRUE;
    $attempts = 0;
    $maxAttempts = $this->sodaScsHelpers->adjustMaxAttempts();

    // Max attempts can not be calculated, return a failure result.
    if ($maxAttempts === FALSE) {
      Error::logException(
      $this->loggerFactory->get('soda_scs_manager'),
      new \Exception(''),
      (string) $this->t('The PHP request timeout is less than the required sleep interval of 5 seconds. Please increase your max_execution_time setting.'),
      [],
      LogLevel::ERROR
      );
      $this->messenger()->addError($this->t('Could not wait for containers to finish. See logs for more details.'));
      return SodaScsResult::failure(
        error: 'PHP request timeout error',
        message: 'Failed to wait for containers to finish. The PHP request timeout is less than the required sleep interval of 5 seconds. Please increase your max_execution_time setting.',
      );
    }
    // Loop through the containers.
    /** @var \Drupal\soda_scs_manager\ValueObject\SodaScsSnapshotData $containerData */
    foreach ($containers as $type => $containerData) {
      $containerId = $containerData->snapshotContainerId;
      // Begin loop to check if the containers are running.
      while ($containerIsRunning && $attempts < $maxAttempts) {

        if (!$containerId) {
          Error::logException(
            $this->loggerFactory->get('soda_scs_manager'),
            new \Exception('Container ID not found'),
            (string) $this->t('Failed to create @context. Container ID not found.', ['@context' => $context]),
            [],
            LogLevel::ERROR
          );
          $this->messenger()->addError($this->t('Failed to create @context. See logs for more details.', ['@context' => $context]));
          return SodaScsResult::failure(
            error: 'Container ID not found',
            message: (string) $this->t('Failed to create @context. Container ID not found.', ['@context' => $context]),
          );
        }
        // Inspect the container to check if it is running.
        // Build the inspect container request.
        $containerInspectRequestParams = [
          'routeParams' => [
            'containerId' => $containerId,
          ],
        ];

        // Build and make the inspect container request.
        $containerInspectRequest = $this->sodaScsDockerRunServiceActions->buildInspectRequest($containerInspectRequestParams);
        $containerInspectResponse = $this->sodaScsDockerRunServiceActions->makeRequest($containerInspectRequest);

        // If the container is not found, it may be already removed.
        if ($containerInspectResponse['statusCode'] === 404) {
          $containers[$type]->snapshotContainerRemoved = TRUE;
          $containerIsRunning = FALSE;
          continue;
        }

        // If the inspect container request failed, return a failure result.
        if ($containerInspectResponse['success'] === FALSE) {
          Error::logException(
            $this->loggerFactory->get('soda_scs_manager'),
            new \Exception('Container inspection failed'),
            (string) $this->t('Failed to create containers for @context. Could not inspect container: @error', [
              '@context' => $context,
              '@error' => $containerInspectResponse['error'],
            ]),
            [],
            LogLevel::ERROR
          );
          $this->messenger()->addError($this->t('Failed to create @context. See logs for more details.', ['@context' => $context]));
          return SodaScsResult::failure(
            error: 'Container inspection failed',
            message: (string) $this->t('Failed to create @context. Could not inspect container: @error', [
              '@context' => $context,
              '@error' => $containerInspectResponse['error'],
            ]),
          );
        }

        // @todo Handle error code 404, because the container
        // is not running/ already removed OR handle deletion
        // over status of container.
        $responseCode = $containerInspectResponse['data']['portainerResponse']->getStatusCode();
        if ($responseCode !== 200) {
          Error::logException(
            $this->loggerFactory->get('soda_scs_manager'),
            new \Exception('HTTP error'),
            (string) $this->t('Failed to create @context. Response code is not 200, but: @responseCode', [
              '@context' => $context,
              '@responseCode' => $responseCode,
            ]),
            [],
            LogLevel::ERROR
          );
          $this->messenger()->addError($this->t('Failed to create @context. See logs for more details.', ['@context' => $context]));
          return SodaScsResult::failure(
            error: 'HTTP error',
            message: (string) $this->t('Failed to create @context. Response code is not 200, but: @responseCode', [
              '@context' => $context,
              '@responseCode' => $responseCode,
            ]),
          );
        }
        // Get the container status.
        $containerStatus = json_decode($containerInspectResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
        $containerIsRunning = $containerStatus['State']['Running'];
        if ($containerIsRunning) {
          // If the container is running, wait for 5 seconds and try again.
          sleep(5);
          $attempts++;
        }
        else {
          // Delete the temporarily created backup container
          // if it is not running, but have a error return failure result.
          if ($containerStatus['State']['ExitCode'] !== 0) {
            $containerName = $containerData->snapshotContainerName ?? $containerId;
            $exitCode = $containerStatus['State']['ExitCode'];
            Error::logException(
              $this->loggerFactory->get('soda_scs_manager'),
              new \Exception('Temporarily created backup container exited unexpectedly.'),
              (string) $this->t('Failed to create @context. Backup container @name exited with code @exitCode. Check container logs (e.g. docker logs @name) for the underlying error (e.g. volume mount, permissions, or path not available on node).', [
                '@context' => $context,
                '@name' => $containerName,
                '@exitCode' => $exitCode,
              ]),
              [],
              LogLevel::ERROR
            );
            $this->messenger()->addError($this->t('Failed to create @context. Backup container @name exited with code @exitCode. See logs or run "docker logs @name" for details.', [
              '@context' => $context,
              '@name' => $containerName,
              '@exitCode' => $exitCode,
            ]));
            if ($deleteContainers) {
              $this->deleteContainers([$containers[$type]], $context);
            }
            return SodaScsResult::failure(
              error: 'Temporarily created backup container exited unexpectedly',
              message: (string) $this->t('Failed to create @context. Backup container @name exited with exit code: @exitCode. Check container logs for the underlying error (e.g. volume mount, permissions, or backup path not available on the node where the container ran).', [
                '@context' => $context,
                '@name' => $containerName,
                '@exitCode' => $exitCode,
              ]),
            );
          }
          // Append the container status to the containers array
          // if deleted successfully.
          $containers[$type]->snapshotContainerStatus = $containerStatus;
          if ($deleteContainers) {
            $this->deleteContainers([$containers[$type]], $context);
            $containers[$type]->snapshotContainerRemoved = TRUE;
          }
        }
      }
    }

    // If the containers are still running after max attempts, return an error.
    if ($attempts === $maxAttempts) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        new \Exception('Container timeout'),
        (string) $this->t('Failed to create @context. Maximum number of attempts to check if the container is running reached. Container is still running.', ['@context' => $context]),
        [],
        LogLevel::ERROR
      );
      $this->messenger()->addError($this->t('Failed to create @context. See logs for more details.', ['@context' => $context]));
      if ($deleteContainers) {
        $this->deleteContainers($containers, $context);
      }
      return SodaScsResult::failure(
        error: 'Container timeout',
        message: (string) $this->t('Failed to create @context. Maximum number of attempts to check if the container is running reached. Container is still running.', [
          '@context' => $context,
        ]),
      );
    }
    // If the containers are finished, return a success result.
    return SodaScsResult::success(
      message: (string) $this->t('Containers finished successfully for @context.', [
        '@context' => $context,
      ]),
      data: $containers,
    );
  }

  /**
   * Wait until a container reaches a specific state.
   *
   * @param string $containerId
   *   The container ID.
   * @param string $desiredState
   *   The desired state value from Docker inspect's State.Status
   *   (e.g. "exited", "running").
   * @param int $sleepSeconds
   *   Seconds between polls.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success when desired state is reached;
   *   failure on timeout or inspect error.
   *
   * @todo Merge with waitContainersToFinish if duplication increases.
   */
  public function waitForContainerState(
    string $containerId,
    string $desiredState,
    int $sleepSeconds = 5,
  ): SodaScsResult {
    $maxAttempts = $this->sodaScsHelpers->adjustMaxAttempts($sleepSeconds);
    if ($maxAttempts === FALSE) {
      return SodaScsResult::failure(
        error: 'PHP request timeout error',
        message: (string) $this->t('Failed to wait for container state. The PHP request timeout is too low.'),
      );
    }

    $attempts = 0;
    while ($attempts < $maxAttempts) {
      $inspectRequest = $this->sodaScsDockerRunServiceActions->buildInspectRequest([
        'routeParams' => ['containerId' => $containerId],
      ]);
      $inspectResponse = $this->sodaScsDockerRunServiceActions->makeRequest($inspectRequest);

      // If there is no container, it is already removed.
      if ($inspectResponse['statusCode'] === 404) {
        return SodaScsResult::success(
          message: (string) $this->t('Container already removed.'),
          data: [
            $containerId => [
              'state' => 'removed',
              'responseStatusCode' => $inspectResponse['statusCode'],
              'responseMessage' => $inspectResponse['error'],
            ],
          ],
        );
      }

      // If the inspect container request failed, return a failure result.
      if (!$inspectResponse['success']) {
        return SodaScsResult::failure(
          message: (string) $this->t('Failed to inspect container.'),
          error: (string) $inspectResponse['error'],
        );
      }

      $inspect = json_decode(
        $inspectResponse['data']['portainerResponse']
          ->getBody()
          ->getContents(),
        TRUE
      );
      // Prefer State.Status (Docker) but fall back
      // to a plain string if present.
      $currentState = $inspect['State']['Status'] ?? ($inspect['State'] ?? NULL);

      if ($currentState === $desiredState) {
        return SodaScsResult::success(
          message: (string) $this->t('Container reached desired state: @state.', [
            '@state' => $desiredState,
          ]),
          data: [
            'state' => $currentState,
            'inspect' => $inspect,
          ],
        );
      }

      $attempts++;
      sleep($sleepSeconds);
    }

    return SodaScsResult::failure(
      message: (string) $this->t('Timed out waiting for container to reach state: @state.', ['@state' => $desiredState]),
      error: 'Container state timeout',
    );
  }

  /**
   * Wait for a container exec to finish.
   *
   * @param string $execId
   *   The exec ID.
   * @param string $desiredState
   *   The desired state value from Docker inspect's State.Status
   *   (e.g. "exited", "running").
   *
   * @param int|null $maxAttempts
   *   Optional poll limit; when NULL, derived from PHP max_execution_time.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success when desired state is reached;
   *   failure on timeout or inspect error.
   */
  public function waitForContainerExecState(string $execId, int $desiredState, ?int $maxAttempts = NULL): SodaScsResult {
    $attempts = 0;
    if ($maxAttempts === NULL) {
      $maxAttempts = $this->sodaScsHelpers->adjustMaxAttempts();
    }

    // If max attempts can not be calculated, return a failure result.
    if ($maxAttempts === FALSE) {
      return SodaScsResult::failure(
        error: 'PHP request timeout error',
        message: (string) $this->t('Failed to wait for container exec state. The PHP request timeout is too low.'),
      );
    }

    // While the attempts are less than the max attempts,
    // inspect the container exec.
    while ($attempts < $maxAttempts) {
      // Construct the inspect request params.
      $inspectRequestParams = [
        'routeParams' => [
          'execId' => $execId,
        ],
      ];

      // Build and make the inspect container exec request.
      $inspectRequest = $this->sodaScsDockerExecServiceActions->buildInspectRequest($inspectRequestParams);
      $inspectResponse = $this->sodaScsDockerExecServiceActions->makeRequest($inspectRequest);

      // If the container exec is already removed, return a success result.
      if ($inspectResponse['statusCode'] === 404) {
        return SodaScsResult::success(
          message: (string) $this->t('Container exec already removed.'),
          data: [
            'state' => 'removed',
            'responseStatusCode' => $inspectResponse['statusCode'],
            'responseMessage' => $inspectResponse['error'],
          ],
        );
      }

      // If the inspect container exec request failed, return a failure result.
      if (!$inspectResponse['success']) {
        return SodaScsResult::failure(
          message: (string) $this->t('Failed to inspect container exec.'),
          error: (string) $inspectResponse['error'],
        );
      }

      // Get the inspect data.
      $inspect = json_decode(
        $inspectResponse['data']['portainerResponse']
          ->getBody()
          ->getContents(),
        TRUE
      );
      $currentState = $inspect['ExitCode'] ?? NULL;

      if ($inspect['Running'] === FALSE || $currentState === $desiredState) {
        return SodaScsResult::success(
          message: (string) $this->t('Container exec reached desired state: @state.', ['@state' => $desiredState]),
          data: [
            'state' => $currentState,
            'inspect' => $inspect,
          ],
        );
      }

      // Increment the attempts and sleep for 5 seconds.
      $attempts++;
      sleep(5);
    }

    return SodaScsResult::failure(
      message: (string) $this->t('Timed out waiting for container exec to reach state: @state.', ['@state' => $desiredState]),
      error: 'Container exec state timeout',
    );
  }

  /**
   * Delete the containers.
   *
   * @param array $containers
   *   The containers.
   * @param string $context
   *   The context.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function deleteContainers(array $containers, $context) {
    /** @var \Drupal\soda_scs_manager\ValueObject\SodaScsSnapshotData $containerData */
    foreach ($containers as $containerData) {
      $containerId = $containerData->snapshotContainerId;

      // Build the delete container request.
      // Construct the request parameters.
      $deleteContainerRequestParams = [
        'routeParams' => [
          'containerId' => $containerId,
        ],
      ];

      // Build and make the delete container request.
      $deleteContainerRequest = $this->sodaScsDockerRunServiceActions->buildRemoveRequest($deleteContainerRequestParams);
      $deleteContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($deleteContainerRequest);

      // If the delete container request failed, return a failure result.
      if (!$deleteContainerResponse['success']) {
        Error::logException(
          $this->loggerFactory->get('soda_scs_manager'),
          new \Exception('Container deletion failed'),
          (string) $this->t('Failed to delete @context. Could not delete container: @error', [
            '@context' => $context,
            '@error' => $deleteContainerResponse['error'],
          ]),
          [],
          LogLevel::ERROR
        );
        return SodaScsResult::failure(
          error: 'Container deletion failed',
          message: (string) $this->t('Failed to delete @context. Could not delete container: @error', [
            '@context' => $context,
            '@error' => $deleteContainerResponse['error'],
          ]),
        );
      }
    }
    // If the containers are deleted successfully, return a success result.
    return SodaScsResult::success(
      message: (string) $this->t('Containers deleted successfully for @context.', [
        '@context' => $context,
      ]),
      data: $containers,
    );
  }

  /**
   * Execute a command in a container and capture both output and exit code.
   *
   * @param array $requestParams
   *   The request parameters:
   *   - 'cmd': The command to run as an array.
   *   - 'containerName': The container name.
   *   - 'user': The user to run the command as.
   *   - 'workingDir': Optional working directory.
   *   - 'env': Optional environment variables.
   *   - 'privileged': Optional privileged mode.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function executeDockerExecCommand(array $requestParams): SodaScsResult {
    $timeout = (int) ($requestParams['timeout'] ?? 600);
    $detach = (bool) ($requestParams['detach'] ?? FALSE);

    // Create the exec command.
    $createRequest = $this->sodaScsDockerExecServiceActions->buildCreateRequest($requestParams);
    $createRequest['timeout'] = $timeout;
    $createResponse = $this->sodaScsDockerExecServiceActions->makeRequest($createRequest);

    if (!$createResponse['success']) {
      return SodaScsResult::failure(
        error: $createResponse['error'],
        message: (string) $this->t('Failed to create exec command.'),
      );
    }

    // Extract exec ID.
    $createResponseData = json_decode(
      $createResponse['data']['portainerResponse']->getBody()->getContents(),
      TRUE
    );
    $execId = $createResponseData['Id'];

    // Start the exec command.
    $startRequest = $this->sodaScsDockerExecServiceActions->buildStartRequest([
      'execId' => $execId,
      'detach' => $detach,
    ]);
    // Detached starts return immediately; the HTTP client only needs to wait for
    // long-running commands when output is streamed on the start response.
    $startRequest['timeout'] = $detach ? 60 : $timeout;
    $startResponse = $this->sodaScsDockerExecServiceActions->makeRequest($startRequest);

    if (!$startResponse['success']) {
      return SodaScsResult::failure(
        error: $startResponse['error'],
        message: (string) $this->t('Failed to start exec command.'),
      );
    }

    // Capture and parse the command output from the start response.
    if ($detach) {
      $commandOutput = '';
    }
    else {
      $rawCommandOutput = $startResponse['data']['portainerResponse']->getBody()->getContents();
      $commandOutput = $this->sodaScsHelpers->parseDockerExecOutput($rawCommandOutput);
    }

    // Wait for the command to complete.
    $maxAttempts = $detach ? max(1, (int) floor($timeout / 5)) : NULL;
    $waitForContainerExecStateResponse = $this->waitForContainerExecState($execId, 0, $maxAttempts);
    if (!$waitForContainerExecStateResponse->success) {
      return SodaScsResult::failure(
        error: $waitForContainerExecStateResponse->error,
        message: 'Failed to wait for container exec state.',
      );
    }

    $inspectRequest = $this->sodaScsDockerExecServiceActions->buildInspectRequest([
      'routeParams' => ['execId' => $execId],
    ]);
    $inspectResponse = $this->sodaScsDockerExecServiceActions->makeRequest($inspectRequest);

    if (!$inspectResponse['success']) {
      return SodaScsResult::failure(
        error: $inspectResponse['error'],
        message: 'Failed to inspect exec command.',
      );
    }

    $inspectData = json_decode(
      $inspectResponse['data']['portainerResponse']->getBody()->getContents(),
      TRUE
    );

    $exitCode = $inspectData['ExitCode'] ?? NULL;
    if ($exitCode === 0) {
      // Return the result.
      return SodaScsResult::success(
        message: 'Command executed successfully.',
        data: [
          'exitCode' => $exitCode,
          'output' => $commandOutput,
          'execId' => $execId,
          'inspect' => $inspectData,
        ],
      );
    }
    else {
      $errorDetail = $commandOutput !== ''
        ? $commandOutput
        : "Docker exec exited with code {$exitCode} (no captured output; detached or silent command).";
      return SodaScsResult::failure(
        message: "Command failed with exit code: {$exitCode}",
        error: $errorDetail,
      );
    }
  }

  /**
   * Resolves the Docker container name for a WissKI component.
   */
  public function getComponentContainerName(SodaScsComponentInterface $component): string {
    $containerName = (string) ($component->get('containerName')->value ?? '');
    if ($containerName === '') {
      $containerName = (string) $component->get('machineName')->value . '--drupal';
    }
    return $containerName;
  }

  /**
   * Looks up a live Docker container by exact name.
   *
   * @param string $containerName
   *   Container name without a leading slash.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success data contains containerId, containerName, and state.
   */
  public function findLiveContainerByName(string $containerName): SodaScsResult {
    $dockerGetAllContainersRequest = $this->sodaScsDockerRunServiceActions->buildGetAllRequest([
      'queryParams' => [
        'all' => TRUE,
        'filters' => json_encode(['name' => [$containerName]]),
      ],
    ]);
    $dockerGetAllContainersResponse = $this->sodaScsDockerRunServiceActions->makeRequest($dockerGetAllContainersRequest);
    if (!$dockerGetAllContainersResponse['success']) {
      return SodaScsResult::failure(
        error: (string) ($dockerGetAllContainersResponse['error'] ?? 'Docker request failed.'),
        message: (string) $this->t('Failed to resolve container.'),
        log: FALSE,
      );
    }

    $containers = json_decode(
      $dockerGetAllContainersResponse['data']['portainerResponse']->getBody()->getContents(),
      TRUE,
    );
    $matchedContainer = $this->findContainerByExactName(is_array($containers) ? $containers : [], $containerName);
    if ($matchedContainer === NULL) {
      return SodaScsResult::failure(
        error: 'Container not found for name: ' . $containerName,
        message: (string) $this->t('Container not found.'),
        log: FALSE,
      );
    }

    return SodaScsResult::success(
      message: (string) $this->t('Container resolved.'),
      data: [
        'containerId' => (string) ($matchedContainer['Id'] ?? ''),
        'containerName' => $containerName,
        'state' => (string) ($matchedContainer['State'] ?? ''),
      ],
    );
  }

  /**
   * Refreshes the stored container ID from the live Docker container name.
   *
   * After a stack redeploy, Docker assigns a new container ID. This looks up the
   * current container by name and persists it on the component entity.
   */
  public function syncComponentContainerId(SodaScsComponentInterface $component): SodaScsResult {
    $containerName = $this->getComponentContainerName($component);
    $storedId = (string) ($component->get('containerId')->value ?? '');

    $liveLookup = $this->findLiveContainerByName($containerName);
    if (!$liveLookup->success) {
      return $liveLookup;
    }
    $liveId = (string) ($liveLookup->data['containerId'] ?? '');

    if ($liveId === '') {
      return SodaScsResult::failure(
        error: 'Container not found for name: ' . $containerName,
        message: (string) $this->t('WissKI container not found.'),
        log: FALSE,
      );
    }

    if ($storedId !== $liveId) {
      $component->set('containerId', $liveId);
      $component->set('containerName', $containerName);
      $component->save();
    }

    return SodaScsResult::success(
      message: (string) $this->t('WissKI container metadata refreshed.'),
      data: [
        'containerId' => $liveId,
        'containerName' => $containerName,
        'refreshed' => $storedId !== $liveId,
      ],
    );
  }

  /**
   * Inspects a component container, refreshing stale IDs when needed.
   */
  public function inspectComponentContainer(SodaScsComponentInterface $component): SodaScsResult {
    $containerId = (string) ($component->get('containerId')->value ?? '');
    if ($containerId === '') {
      $syncResult = $this->syncComponentContainerId($component);
      if (!$syncResult->success) {
        return $syncResult;
      }
      $containerId = (string) ($syncResult->data['containerId'] ?? '');
    }

    $inspectResult = $this->inspectContainer($containerId);
    if ($inspectResult->success) {
      return $inspectResult;
    }

    $syncResult = $this->syncComponentContainerId($component);
    if ($syncResult->success) {
      $newId = (string) ($syncResult->data['containerId'] ?? '');
      if ($newId !== '' && $newId !== $containerId) {
        return $this->inspectContainer($newId);
      }
    }

    return $inspectResult;
  }

  /**
   * Finds a container whose normalized name matches exactly.
   *
   * Docker's name filter is substring-based; e.g. "wisski-test--drupal" also
   * matches "wisski-jc-wisski-test--drupal". Always verify an exact name.
   *
   * @param array $containers
   *   Docker list containers API response items.
   * @param string $containerName
   *   Expected container name without a leading slash.
   *
   * @return array|null
   *   Matching container payload or NULL.
   */
  private function findContainerByExactName(array $containers, string $containerName): ?array {
    foreach ($containers as $container) {
      if (!is_array($container)) {
        continue;
      }
      foreach ($container['Names'] ?? [] as $name) {
        if (ltrim((string) $name, '/') === $containerName) {
          return $container;
        }
      }
    }
    return NULL;
  }

  /**
   * Inspect a container.
   *
   * @param string $containerId
   *   The container ID.
   * @param array|null $additionalRequestParams
   *   The request parameters.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function inspectContainer(string $containerId, ?array $additionalRequestParams = NULL): SodaScsResult {

    $requestParams = [
      'routeParams' => [
        'containerId' => $containerId,
      ],
    ];
    if ($additionalRequestParams) {
      $requestParams = array_merge($requestParams, $additionalRequestParams);
    }

    $inspectContainerRequest = $this->sodaScsDockerRunServiceActions->buildInspectRequest($requestParams);
    $inspectContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($inspectContainerRequest);
    if (!$inspectContainerResponse['success']) {
      $statusCode = (int) ($inspectContainerResponse['statusCode'] ?? 0);
      $isMissingContainer = $statusCode === 404;
      return SodaScsResult::failure(
        message: 'Failed to inspect container.',
        error: (string) $inspectContainerResponse['error'],
        log: !$isMissingContainer,
      );
    }
    return SodaScsResult::success(
      message: 'Container inspected successfully.',
      data: [
        $containerId => json_decode($inspectContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE),
      ],
    );
  }

  /**
   * Restart a container.
   *
   * Inspect the container state, if it is running, stop it gracefully,
   * start the container again.
   *
   * @param string $containerId
   *   The container ID.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function restartContainer(string $containerId): SodaScsResult {
    // Inspect the container state.
    $inspectContainerResponse = $this->inspectContainer($containerId);

    if (!$inspectContainerResponse->success) {
      return $inspectContainerResponse;
    }

    $containerState = $inspectContainerResponse->data[$containerId]['State'];

    // If the container is running, stop it gracefully.
    if ($containerState['Status'] === 'running') {
      $stopContainerResponse = $this->stopContainer($containerId);
      if (!$stopContainerResponse->success) {
        return $stopContainerResponse;
      }
    }

    // Start the container again.
    $startContainerResponse = $this->startContainer($containerId);
    if (!$startContainerResponse->success) {
      return $startContainerResponse;
    }

    return SodaScsResult::success(
      message: 'Container restarted successfully.',
      data: [
        $containerId => $inspectContainerResponse->data[$containerId],
      ],
    );

  }

  /**
   * Start a container.
   *
   * If the container is already running, return a success result.
   * If the container is not running, start it.
   * Wait for it to be started.
   *
   * @param string $containerId
   *   The container ID.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function startContainer(string $containerId): SodaScsResult {

    $inspectContainerResponse = $this->inspectContainer($containerId);

    if (!$inspectContainerResponse->success) {
      return $inspectContainerResponse;
    }

    $containerState = $inspectContainerResponse->data[$containerId]['State'];
    if ($containerState['Status'] === 'running') {
      return SodaScsResult::success(
        message: 'Container already running.',
        data: [
          $containerId => $inspectContainerResponse->data[$containerId],
        ],
      );
    }

    $startContainerRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
      'routeParams' => ['containerId' => $containerId],
    ]);
    $startContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startContainerRequest);
    if (!$startContainerResponse['success']) {
      return SodaScsResult::failure(
        message: 'Failed to start container.',
        error: (string) $startContainerResponse['error'],
      );
    }

    // Wait for the container to be started.
    $waitForContainerStateResponse = $this->waitForContainerState($containerId, 'running');
    if (!$waitForContainerStateResponse->success) {
      return $waitForContainerStateResponse;
    }

    return SodaScsResult::success(
      message: 'Container started successfully.',
      data: [
        $containerId => json_decode($startContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE),
      ],
    );
  }

  /**
   * Stop a container.
   *
   * Inspect the container state,
   * if it is not running, return a success result.
   * if it is running, stop it gracefully.
   * Wait for it to be stopped.
   *
   * @param string $containerId
   *   The container ID.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function stopContainer(string $containerId): SodaScsResult {
    $inspectContainerResponse = $this->inspectContainer($containerId);

    if (!$inspectContainerResponse->success) {
      return $inspectContainerResponse;
    }

    $containerState = $inspectContainerResponse->data[$containerId]['State'];
    if (!$containerState['Status'] === 'running') {
      return SodaScsResult::success(
        message: 'Container already stopped.',
        data: [
          $containerId => $inspectContainerResponse->data[$containerId],
        ],
      );
    }
    $stopContainerRequest = $this->sodaScsDockerRunServiceActions->buildStopRequest([
      'routeParams' => ['containerId' => $containerId],
    ]);
    $stopContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($stopContainerRequest);
    if (!$stopContainerResponse['success']) {
      return SodaScsResult::failure(
        message: 'Failed to stop container.',
        error: (string) $stopContainerResponse['error'],
      );
    }

    // Wait for the container to be stopped.
    $waitForContainerStateResponse = $this->waitForContainerState($containerId, 'exited');
    if (!$waitForContainerStateResponse->success) {
      return $waitForContainerStateResponse;
    }

    return SodaScsResult::success(
      message: 'Container stopped successfully.',
      data: [
        $containerId => $inspectContainerResponse->data[$containerId],
      ],
    );
  }

  /**
   * Ensures a directory exists inside the Drupal component container.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component whose container should be used.
   * @param string $directoryPath
   *   Absolute directory path inside the container.
   * @param string $user
   *   User that should execute the command.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result of the operation.
   */
  public function ensureContainerDirectory(SodaScsComponentInterface $component, string $directoryPath, string $user = 'www-data'): SodaScsResult {
    $directoryPath = rtrim($directoryPath, '/');
    if ($directoryPath === '') {
      return SodaScsResult::failure(
        error: 'Directory path is empty.',
        message: (string) $this->t('Directory path can not be empty.'),
      );
    }

    $containerId = $component->getContainerId();
    if ($containerId === NULL) {
      return SodaScsResult::failure(
        error: 'Component container ID not found.',
        message: (string) $this->t('Component container ID not found.'),
      );
    }

    // Create directory as root, then set ownership and permissions.
    $response = $this->executeDockerExecCommand([
      'cmd' => [
        'sh',
        '-c',
        "mkdir -p {$directoryPath} && chown www-data:www-data {$directoryPath} && chmod 775 {$directoryPath}",
      ],
      'containerName' => $containerId,
      'user' => 'root',
    ]);

    if (!$response->success) {
      $errorDetail = 'Failed to create directory inside the component container: ' . ($response->error ?? '');
      return SodaScsResult::failure(
        error: $errorDetail,
        message: (string) $this->t('Failed to create directory inside the component container.'),
      );
    }

    return SodaScsResult::success(
      data: [
        'path' => $directoryPath,
        'exec' => $response->data,
      ],
      message: (string) $this->t('Directory ensured successfully.'),
    );
  }

}
