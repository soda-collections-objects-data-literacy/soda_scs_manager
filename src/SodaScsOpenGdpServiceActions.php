<?php

namespace Drupal\soda_scs_manager;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsOpenGdpServiceActions implements SodaScsServiceRequestInterface {

  /**
   * Builds the create request for the OpenGDB service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildCreateRequest(array $requestParams): array {
    return [];
  }

  /**
   * Builds the create request for the OpenGDB service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildReadRequest(array $requestParams): array {
    return [];
  }

  /**
   * Build request to get all stacks.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildGetRequest($requestParams): array {
    return [];
  }

  /**
   * Builds the update request for the OpenGDB service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildUpdateRequest(array $requestParams): array {
    return [];
  }

  /**
   * Builds the delete request for the OpenGDB service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildDeleteRequest(array $requestParams): array {
    return [];
  }

  /**
   * Make the API request.
   *
   * @param array $request
   *   The request array for the makeRequest function.
   *
   * @return array
   *   The request array for the makeRequest function.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsRequestException
   */
  public function makeRequest($request): array {
    return [];
  }

}
