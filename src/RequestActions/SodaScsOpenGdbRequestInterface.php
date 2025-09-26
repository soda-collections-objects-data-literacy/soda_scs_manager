<?php

namespace Drupal\soda_scs_manager\RequestActions;

/**
 * Handles the communication with the SCS user manager daemon.
 */
interface SodaScsOpenGdbRequestInterface extends SodaScsServiceRequestInterface {

/**
 * Builds the dump request for the OpenGDB service API.
 *
 * @param array $requestParams
 *   The request parameters.
 *
 * @return array
 *   The dump request.
 */
public function buildDumpRequest(array $requestParams): array;

/**
 * Builds the replace repository request.
 *
 * @param array $requestParams
 *   The request parameters.
 *
 * @return array
 *   The replace repository request.
 */
public function buildReplaceRepositoryRequest(array $requestParams): array;

}
