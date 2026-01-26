# XDebug Configuration for Dev Container

## What Was Fixed

### 1. Dev Container Extensions (`devcontainer.json`)
- ✅ Moved extensions to the newer `customizations.vscode.extensions` format
- ✅ Changed workspace folder from `/opt/drupal` to `/opt/drupal/web/modules/custom/soda_scs_manager`
- ✅ Extensions will now **automatically install on container rebuild**

### 2. XDebug PHP Configuration (`/usr/local/etc/php/conf.d/zz-xdebug-custom.ini`)
```ini
xdebug.mode=debug,develop
xdebug.client_host=localhost
xdebug.start_with_request=yes
xdebug.client_port=9003
xdebug.log=/var/log/xdebug/xdebug.log
xdebug.log_level=7
error_reporting=E_ALL
```

**Important:** This file is **NOT** preserved on container rebuild. You need to:
- Add it to your Dockerfile, OR
- Add a script to copy it in `postCreateCommand`, OR  
- Mount it as a volume in docker-compose.yml

### 3. Launch Configuration (`.vscode/launch.json`)
- ✅ Path mappings: `/opt/drupal` → `/opt/drupal`
- ✅ Module mapping: `/opt/drupal/web/modules/custom/soda_scs_manager` → `${workspaceFolder}`

## To Make XDebug Config Persistent

### Option A: Add to Dockerfile
Add this to your PHP Dockerfile:
```dockerfile
COPY xdebug-custom.ini /usr/local/etc/php/conf.d/zz-xdebug-custom.ini
```

### Option B: Use postCreateCommand
In `devcontainer.json`, change:
```json
"postCreateCommand": "cp xdebug-clean.ini /usr/local/etc/php/conf.d/zz-xdebug-custom.ini && pkill -USR2 php-fpm && echo 'Dev container ready'"
```

### Option C: Volume Mount in docker-compose.yml
```yaml
volumes:
  - ./xdebug-custom.ini:/usr/local/etc/php/conf.d/zz-xdebug-custom.ini:ro
```

## How to Use XDebug

1. **Start the debugger** in Cursor: Press F5 → Select "Listen for Xdebug"
2. **Set breakpoints** in your PHP files (click left of line numbers)
3. **Visit a Drupal page** or run a Drush command
4. **Breakpoints will hit automatically** (no browser extension needed!)

## Troubleshooting

### Check if XDebug is loaded:
```bash
php -v
# Should show "with Xdebug v3.5.0"
```

### Check configuration:
```bash
php -i | grep xdebug
```

### Monitor connections:
```bash
tail -f /var/log/xdebug/xdebug.log
```

### If breakpoints don't hit:
1. Make sure debugger is listening (F5)
2. Check that port 9003 is open: `ss -tuln | grep 9003`
3. Verify XDebug log shows connection attempts
4. Ensure the file you're debugging actually gets loaded by PHP
