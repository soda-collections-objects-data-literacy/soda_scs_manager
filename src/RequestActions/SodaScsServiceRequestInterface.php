<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\RequestActions;

/**
 * Handles the communication with the SCS user manager daemon.
 */
interface SodaScsServiceRequestInterface {

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
   * Builds the get all request for the REST service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The get all request.
   */
  public function buildGetAllRequest(array $requestParams): array;

  /**
   * Build request to get one entity.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The get one request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildGetRequest(array $requestParams): array;

  /**
   * Builds health check request.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The health check request.
   */
  public function buildHealthCheckRequest(array $requestParams): array;

  /**
   * Builds the update request for the REST service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The update request.
   */
  public function buildUpdateRequest(array $requestParams): array;

  /**
   * Builds the delete request for the REST service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The delete request.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildDeleteRequest(array $requestParams): array;

  /**
   * Build token request.
   *
   * @param array $requestParams
   *   Optional request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildTokenRequest(array $requestParams = []): array;

  /**
   * Make the API request.
   *
   * @param array $request
   *   The request.
   *
   * @return array
   *   The response.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsRequestException
   */
  public function makeRequest($request): array;

}
