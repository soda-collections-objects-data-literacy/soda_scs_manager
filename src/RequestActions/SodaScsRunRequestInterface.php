<?php

namespace Drupal\soda_scs_manager\RequestActions;

/**
 * Interface for Docker run service actions.
 */
interface SodaScsRunRequestInterface {

  /**
   * Makes a request to the Docker API.
   *
   * @param array $request
   *   The request array.
   *
   * @return array
   *   The response array.
   */
  public function makeRequest(array $request): array;

  /**
   * Builds the create container request for the Docker run API.
   *
   * @param array $requestParams
   *   The request params.
   *
   * @return array
   *   The request array.
   */
  public function buildCreateRequest(array $requestParams): array;

  /**
   * Builds the get all containers request for the Docker run API.
   *
   * @param array $requestParams
   *   The request params.
   *
   * @return array
   *   The request array.
   */
  public function buildGetAllRequest(array $requestParams): array;

  /**
   * Builds the start container request for the Docker container API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The start request.
   */
  public function buildStartRequest(array $requestParams): array;

  /**
   * Builds the stop container request for the Docker container API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The stop request.
   */
  public function buildStopRequest(array $requestParams): array;

  /**
   * Builds the inspect container request for the Docker container API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The inspect request.
   */
  public function buildInspectRequest(array $requestParams): array;

  /**
   * Builds the remove container request for the Docker container API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The remove request.
   */
  public function buildRemoveRequest(array $requestParams): array;

}
