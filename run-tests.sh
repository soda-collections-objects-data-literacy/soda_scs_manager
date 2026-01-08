#!/bin/bash

# Run PHPUnit tests for soda_scs_manager module.
# This script dynamically reads database credentials from Drupal settings.

set -e

# Get the script directory.
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
DRUPAL_ROOT="$SCRIPT_DIR/../../../.."

# Extract database credentials from settings.php.
cd "$DRUPAL_ROOT/web"

# Use PHP to extract database credentials.
DB_INFO=$(php -r "
  \$app_root = '$DRUPAL_ROOT/web';
  \$site_path = 'sites/default';
  include 'sites/default/settings.php';
  \$db = \$databases['default']['default'];
  echo \$db['driver'] . '://' . \$db['username'] . ':' . \$db['password'] . '@' . \$db['host'] . '/' . \$db['database'];
")

# Set environment variables.
export SIMPLETEST_BASE_URL="${SIMPLETEST_BASE_URL:-http://localhost}"
export SIMPLETEST_DB="$DB_INFO"
export BROWSERTEST_OUTPUT_DIRECTORY="${BROWSERTEST_OUTPUT_DIRECTORY:-/tmp/browser_output}"

# Create browser output directory if it doesn't exist.
mkdir -p "$BROWSERTEST_OUTPUT_DIRECTORY"

# Default to running all tests if no arguments provided.
if [ $# -eq 0 ]; then
  ARGS="--testdox"
else
  ARGS="$@"
fi

# Run PHPUnit from the Drupal root.
cd "$DRUPAL_ROOT/web"
../vendor/bin/phpunit -c modules/custom/soda_scs_manager/phpunit.xml $ARGS
