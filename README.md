# SODa SCS Manager

## Introduction
The SODa SCS Manager module is part of the [SODa Semantic Co-Working Space](https://zenodo.org/records/14627710) of the [SODa project](https://sammlungen.io).
It extends the Drupal framework with an administration panel for the SODa Semantic Co-Working Space.

## Prerequisites
### Development
#### Theming
- Install [node and nvm](https://github.com/nvm-sh/nvm).
- Run `nvm install`
- You can now style with tailwind in pcss and run `npm run dev` or `npm run build` for pcss compiling and `npm run build:map`or `npm run dev:map` for css mapping.

## Install


## Hooks

This file contains implementations of the following hooks:

- **hook_bundle_info()**: Add the additional bundles for the soda_scs_component.
- **hook_entity_bundle_info()**: Provides custom bundle information for SODa SCS entities.
- **hook_entity_field_storage_info()**: Defines storage for all bundle fields.
- **hook_entity_field_storage_info()**: Defines storage for all bundle fields.
- **hook_ENTITY_TYPE_delete()**: Implements delete functionality for soda_scs_project entities.
- **hook_ENTITY_TYPE_insert()**: Implements insert functionality for soda_scs_project entities.
- **hook_ENTITY_TYPE_presave()**: Implements presave functionality for soda_scs_project entities.
- **hook_ENTITY_TYPE_update()**: Implements update functionality for soda_scs_project entities.
- **hook_ENTITY_TYPE_view()**: Implements the content of the overview page for soda_scs_component and soda_scs_stack entities.
- **hook_help()**: Implements the user help page for the module.
- **hook_options_list_alter()**: Alters the options list for the connectedComponents field.
- **hook_preprocess()**: Implements the preprocess function to add JavaScript libraries.
- **hook_theme()**: Implements the theme for several entities and pages.
- **hook_user_delete()**: Implements the functionality that cleans up Keycloak and database users when a user is deleted.
- **hook_user_insert()**: Implements the functionality that assigns a role to a newly created user.

## Security Features

### Secure Logging System

The SODa SCS Manager includes a comprehensive secure logging system to prevent sensitive data exposure in log files.

#### Key Security Features

- **Automatic Password Sanitization**: MySQL commands with `-p` flags are automatically sanitized
- **Connection String Protection**: Database connection strings with embedded credentials are sanitized
- **API Key & Token Detection**: Long tokens and API keys are automatically detected and redacted
- **JSON Field Sanitization**: JSON objects with sensitive field names are sanitized
- **Environment Variable Protection**: Common environment variables containing secrets are sanitized
- **Configurable Logging**: Set minimum log level and enable/disable sanitization

#### Configuration

Navigate to **Administration → SODa SCS Manager → Settings → Security Settings** tab to configure:

- **Sanitize sensitive data in logs**: Enable/disable automatic sanitization (enabled by default)
- **Minimum log level**: Set the minimum log level (Info by default)

#### Usage in Custom Code

```php
use Drupal\soda_scs_manager\Traits\SecureLoggingTrait;

class MyService {
  use SecureLoggingTrait;

  public function someMethod() {
    // Use secureLog() instead of regular logging
    $this->secureLog(
      LogLevel::INFO,
      'Database operation completed: @command',
      ['@command' => $sqlCommand],
      ['@command'] // Mark @command as sensitive
    );
  }
}
```

#### What Gets Sanitized

- **MySQL Commands**: `-p<password>` → `-p[REDACTED]`
- **Connection Strings**: `mysql://user:pass@host` → `mysql://user:[REDACTED]@host`
- **Environment Variables**: `DB_PASSWORD=secret` → `DB_PASSWORD=[REDACTED]`
- **JSON Fields**: `{"password": "secret"}` → `{"password": "[REDACTED]"}`
- **API Keys & Tokens**: Long alphanumeric strings are automatically detected

#### Security Best Practices

1. **Always use secureLog()** instead of direct logger calls for potentially sensitive data
2. **Mark sensitive context keys** when calling secureLog()
3. **Keep sanitization enabled** in production environments
4. **Review logs regularly** to ensure no sensitive data is exposed
5. **Secure log files** with appropriate file permissions

⚠️ **Important**: Data marked as `raw_password`, `private_key`, `secret_key`, `api_secret`, or `client_secret` is completely removed from logs rather than sanitized.

For detailed security documentation, see `SECURITY.md`.

## Roadmap
- [x] harmonise Entity/Bundle Definitions ["the modern way"](https://www.drupal.org/docs/create-custom-content-types-with-bundle-classes)
- [x] Healtchecks for running components
- [ ] Flavour 3D
- [ ] Flavour conservation and restauration
- [ ] Documentation

## License
[GNU General Public Licence 3](https://www.gnu.org/licenses/gpl-3.0.html)
