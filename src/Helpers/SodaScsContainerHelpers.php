<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerExecServiceActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LogLevel;

/**
 * Helper class for Soda SCS container operations.
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
    SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions,
    SodaScsDockerExecServiceActions $sodaScsDockerExecServiceActions,
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
      $this->t('The PHP request timeout is less than the required sleep interval of 5 seconds. Please increase your max_execution_time setting.'),
      [],
      LogLevel::ERROR
      );
      $this->messenger()->addError($this->t('Could not wait for containers to finish. See logs for more details.'));
      return SodaScsResult::failure(
        error: 'PHP request timeout error',
        message: 'Failed to wait for containers to finish. The PHP request timeout is less than the required sleep interval of 5 seconds. Please increase your max_execution_time setting.',
      );
    }
    // Begin loop to check if the containers are running.
    while ($containerIsRunning && $attempts < $maxAttempts) {
      // Loop through the containers.
      /** @var \Drupal\soda_scs_manager\ValueObject\SodaScsSnapshotData $containerData */
      foreach ($containers as $type => $containerData) {
        $containerId = $containerData->snapshotContainerId;

        if (!$containerId) {
          Error::logException(
            $this->loggerFactory->get('soda_scs_manager'),
            new \Exception('Container ID not found'),
            $this->t('Failed to create @context. Container ID not found.', ['@context' => $context]),
            [],
            LogLevel::ERROR
          );
          $this->messenger()->addError($this->t('Failed to create @context. See logs for more details.', ['@context' => $context]));
          return SodaScsResult::failure(
            error: 'Container ID not found',
            message: $this->t('Failed to create @context. Container ID not found.', ['@context' => $context]),
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

        // If the inspect container request failed, return a failure result.
        if ($containerInspectResponse['success'] === FALSE) {
          Error::logException(
            $this->loggerFactory->get('soda_scs_manager'),
            new \Exception('Container inspection failed'),
            $this->t('Failed to create containers for @context. Could not inspect container: @error', [
              '@context' => $context,
              '@error' => $containerInspectResponse['error'],
            ]),
            [],
            LogLevel::ERROR
          );
          $this->messenger()->addError($this->t('Failed to create @context. See logs for more details.', ['@context' => $context]));
          return SodaScsResult::failure(
            error: 'Container inspection failed',
            message: $this->t('Failed to create @context. Could not inspect container: @error', [
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
            $this->t('Failed to create @context. Response code is not 200, but: @responseCode', [
              '@context' => $context,
              '@responseCode' => $responseCode,
            ]),
            [],
            LogLevel::ERROR
          );
          $this->messenger()->addError($this->t('Failed to create @context. See logs for more details.', ['@context' => $context]));
          return SodaScsResult::failure(
            error: 'HTTP error',
            message: $this->t('Failed to create @context. Response code is not 200, but: @responseCode', [
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
            Error::logException(
              $this->loggerFactory->get('soda_scs_manager'),
              new \Exception('Temporarily created backup container exited unexpectedly.'),
              $this->t('Failed to create @context. Temporarily created backup container exited with exit code: @exitCode', [
                '@context' => $context,
                '@exitCode' => $containerStatus['State']['ExitCode'],
              ]),
              [],
              LogLevel::ERROR
            );
            $this->messenger()->addError($this->t('Failed to @context. See logs for more details.', ['@context' => $context]));
            if ($deleteContainers) {
              $this->deleteContainers([$containers[$type]], $context);
            }
            return SodaScsResult::failure(
              error: 'Temporarily created backup container exited unexpectedly',
              message: $this->t('Failed to create @context. Temporarily created backup container exited with exit code: @exitCode', [
                '@context' => $context,
                '@exitCode' => $containerStatus['State']['ExitCode'],
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
        $this->t('Failed to create @context. Maximum number of attempts to check if the container is running reached. Container is still running.', ['@context' => $context]),
        [],
        LogLevel::ERROR
      );
      $this->messenger()->addError($this->t('Failed to create @context. See logs for more details.', ['@context' => $context]));
      if ($deleteContainers) {
        $this->deleteContainers($containers, $context);
      }
      return SodaScsResult::failure(
        error: 'Container timeout',
        message: $this->t('Failed to create @context. Maximum number of attempts to check if the container is running reached. Container is still running.', [
          '@context' => $context,
        ]),
      );
    }
    // If the containers are finished, return a success result.
    return SodaScsResult::success(
      message: $this->t('Containers finished successfully for @context.', [
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
        message: $this->t('Failed to wait for container state. The PHP request timeout is too low.'),
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
          message: $this->t('Container already removed.'),
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
          message: $this->t('Failed to inspect container.'),
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
          message: $this->t('Container reached desired state: @state.', [
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
      message: $this->t('Timed out waiting for container to reach state: @state.', ['@state' => $desiredState]),
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
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success when desired state is reached;
   *   failure on timeout or inspect error.
   */
  public function waitForContainerExecState(string $execId, string $desiredState): SodaScsResult {
    $attempts = 0;
    $maxAttempts = $this->sodaScsHelpers->adjustMaxAttempts();

    // If max attempts can not be calculated, return a failure result.
    if ($maxAttempts === FALSE) {
      return SodaScsResult::failure(
        error: 'PHP request timeout error',
        message: $this->t('Failed to wait for container exec state. The PHP request timeout is too low.'),
      );
    }

    // While the attempts are less than the max attempts, inspect the container exec.
    while ($attempts < $maxAttempts) {
      $inspectRequest = $this->sodaScsDockerExecServiceActions->buildInspectRequest(['execId' => $execId]);
      $inspectResponse = $this->sodaScsDockerExecServiceActions->makeRequest($inspectRequest);

      // If the container exec is already removed, return a success result.
      if ($inspectResponse['statusCode'] === 404) {
        return SodaScsResult::success(
          message: $this->t('Container exec already removed.'),
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
          message: $this->t('Failed to inspect container exec.'),
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
      $currentState = $inspect['State']['Status'] ?? ($inspect['State'] ?? NULL);

      // If the current state is the desired state, return a success result.
      if ($currentState === $desiredState) {
        return SodaScsResult::success(
          message: $this->t('Container exec reached desired state: @state.', ['@state' => $desiredState]),
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
      message: $this->t('Timed out waiting for container exec to reach state: @state.', ['@state' => $desiredState]),
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
          $this->t('Failed to delete @context. Could not delete container: @error', [
            '@context' => $context,
            '@error' => $deleteContainerResponse['error'],
          ]),
          [],
          LogLevel::ERROR
        );
        return SodaScsResult::failure(
          error: 'Container deletion failed',
          message: $this->t('Failed to delete @context. Could not delete container: @error', [
            '@context' => $context,
            '@error' => $deleteContainerResponse['error'],
          ]),
        );
      }
    }
    // If the containers are deleted successfully, return a success result.
    return SodaScsResult::success(
      message: $this->t('Containers deleted successfully for @context.', [
        '@context' => $context,
      ]),
      data: $containers,
    );
  }

}
