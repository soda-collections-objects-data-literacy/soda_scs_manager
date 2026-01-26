#!/bin/bash

# Setup script to configure xdebug for step debugging.
# Run this script with sudo: sudo ./setup-xdebug.sh

set -e

XDEBUG_INI="/etc/php/8.4/mods-available/xdebug.ini"

if [ ! -f "$XDEBUG_INI" ]; then
  echo "Error: xdebug.ini not found at $XDEBUG_INI"
  exit 1
fi

# Backup original configuration.
if [ ! -f "${XDEBUG_INI}.backup" ]; then
  cp "$XDEBUG_INI" "${XDEBUG_INI}.backup"
  echo "✓ Created backup at ${XDEBUG_INI}.backup"
fi

# Write new configuration.
cat > "$XDEBUG_INI" << 'EOF'
zend_extension=xdebug

; Enable step debugging and development helpers.
xdebug.mode=debug,develop

; Configure client connection.
xdebug.client_host=localhost
xdebug.client_port=9003

; Start debugging automatically (set to "trigger" if you prefer manual start via XDEBUG_SESSION cookie/GET param).
xdebug.start_with_request=yes

; Set IDE key for VS Code.
xdebug.idekey=VSCODE

; Increase limits for better debugging experience.
xdebug.var_display_max_depth=10
xdebug.var_display_max_children=256
xdebug.var_display_max_data=1024
EOF

echo "✓ Updated xdebug configuration at $XDEBUG_INI"
echo ""
echo "Configuration applied. You may need to restart PHP-FPM for web requests:"
echo "  sudo systemctl restart php8.4-fpm"
echo ""
echo "Or restart your Docker container if using Docker."
echo ""
echo "To verify, run: php test_xdebug.php"
