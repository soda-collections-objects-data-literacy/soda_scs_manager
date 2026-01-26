# Xdebug Troubleshooting Guide

## Current Configuration

- **Xdebug Version**: 3.5.0
- **Mode**: `debug,develop` ✓
- **Step Debugger**: Enabled ✓
- **Client Host**: `localhost`
- **Client Port**: `9003`
- **IDE Key**: `VSCODE`
- **Start with request**: `yes` (automatic)

## Common Issues & Solutions

### 1. PHP-FPM Not Restarted
If you're debugging web requests, PHP-FPM needs to be restarted to load the new xdebug configuration:

```bash
sudo systemctl restart php8.4-fpm
# Or if using Docker:
docker-compose restart php-fpm
```

### 2. Port Conflict
VS Code PHP Debug extension is listening on port 9003. This is correct - xdebug connects TO this port.

### 3. Path Mappings
The launch.json now includes path mappings for:
- `/opt/drupal` (devcontainer path)
- `/var/deploy/soda_scs_manager_deployment/scs-manager-stack/volumes/drupal` (actual path)

### 4. Enable Xdebug Logging
To see connection attempts, enable logging in `/etc/php/8.4/mods-available/xdebug.ini`:

```ini
xdebug.log=/tmp/xdebug.log
xdebug.log_level=10
```

Then check the log:
```bash
tail -f /tmp/xdebug.log
```

### 5. Try 127.0.0.1 Instead of localhost
Sometimes `localhost` resolves differently. Try changing in xdebug.ini:

```ini
xdebug.client_host=127.0.0.1
```

### 6. Verify VS Code is Listening
Make sure VS Code "Listen for Xdebug" is running (green play button in debug panel).

### 7. Test Connection
Run the test script:
```bash
php test_xdebug.php
```

## Verification Checklist

- [ ] Xdebug is loaded: `php -m | grep xdebug`
- [ ] Step debugger enabled: `php -r "xdebug_info();" | grep "Step Debugger"`
- [ ] VS Code debugger is listening (green icon)
- [ ] PHP-FPM restarted (for web debugging)
- [ ] Breakpoint set in PHP code
- [ ] Path mappings correct in launch.json
