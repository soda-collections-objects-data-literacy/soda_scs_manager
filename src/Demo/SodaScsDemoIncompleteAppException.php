<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

/**
 * A demo application exists but is incomplete; retrying will not help.
 *
 * Left behind when creating the application failed after its entity was
 * saved. The job fails at once instead of waiting for an installation that
 * never runs; the user deletes the application and resets the demo.
 */
class SodaScsDemoIncompleteAppException extends SodaScsDemoException {}
