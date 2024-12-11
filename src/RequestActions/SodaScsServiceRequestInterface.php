<?php

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
   * Builds the create request for the REST service API.
   *
   * @param array $requestParams
   *   The request parameters.
   *
   * @return array
   *   The read request.
   */
  public function buildGetAllRequest(array $requestParams): array;

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
  public function buildGetRequest($requestParams): array;

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
   *   The request parameters.
   *
   * @return array
   *   The request array for the makeRequest function.
   */
  public function buildTokenRequest(array $requestParams): array;

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
