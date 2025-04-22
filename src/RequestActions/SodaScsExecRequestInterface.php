<?php

namespace Drupal\soda_scs_manager\RequestActions;

/**
 * Handles the communication with the SCS user manager daemon.
 */
interface SodaScsExecRequestInterface {

  /**
   * Builds the create request for the REST service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The create request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildCreateRequest(array $requestParams): array;

  /**
   * Builds the create request for the REST service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The read request.
   */
  public function buildStartRequest(array $requestParams): array;

  /**
   * Build request to get all stacks.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The read request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildResizeRequest(array $requestParams): array;

  /**
   * Builds health check request.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The health check request.
   */
  public function buildInspectRequest(array $requestParams): array;

  /**
   * Make the API request.
   *
   * @param array $request
   *   The request.
   *
   * @return array{
   *   message: string,
   *   success: bool,
   *   error: string,
   *   data: array,
   *   statusCode: int,
   * }
   *   The response.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsRequestException
   */
  public function makeRequest($request): array;

}
