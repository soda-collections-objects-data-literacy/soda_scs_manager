# SODa SCS Manager

Drupal.org: [drupal.org/project/soda_scs_manager](https://www.drupal.org/project/soda_scs_manager)

SODa SCS Manager turns Drupal into a **self-service control panel** for the [SODa](https://sammlungen.io) [Semantic Co-Working Space (SCS)](https://zenodo.org/records/14627710). Researchers and collection staff can create projects, invite collaborators, and spin up research apps—WissKI environments, Nextcloud file spaces, JupyterHub notebooks, SQL databases, OpenGDB triplestores, and WebProtégé—without installing software locally or learning Docker.

If you are new to Drupal: you mainly use a dashboard and wizards. Drupal hosts the UI, users, and configuration; the module talks to Keycloak (login), Portainer/Docker (containers), MariaDB, Nextcloud, and related services in the background.

**What solution does this module provide?** A project-centric platform to provision, share, monitor, and tear down SCS applications and resources with SSO, membership sync, snapshots, and connected-account management—so cultural-heritage and research teams get FAIR-oriented tooling without a dedicated ops person for every instance.

**Try it / run it:** Production/reference deployment at [scs.sammlungen.io](https://scs.sammlungen.io). Full stack (Drupal + Keycloak, Nextcloud, JupyterHub, OpenGDB, Traefik, etc.): [soda_scs_manager_deployment](https://github.com/soda-collections-objects-data-literacy/soda_scs_manager_deployment). For most adopters, that Compose environment is the supported way to run SODa SCS Manager—not a plain core Drupal site alone.

## Features

**Basic functionality**

- **Project-centric dashboard** — central services (e.g. Nextcloud, Jupyter, WebProtégé) plus “Your projects” as cards (status, members); create apps from the project page.
- **Stacks (environments)** — e.g. WissKI Environment (Drupal/WissKI + DB + triplestore), JupyterHub Environment, Nextcloud Environment (files, OnlyOffice, SCS-Share).
- **Components (resources/accounts/instances)** — SQL databases (phpMyAdmin), OpenGDB triplestores, WebProtégé accounts, plain WissKI instances, etc.
- **Identity & access** — OpenID Connect / Keycloak SSO; project membership sync to groups; service keys and connected accounts.
- **Lifecycle** — create/delete provisioning, health/online status, snapshots for backup/restore, secure logging (credential redaction).
- **Collaboration** — projects with owners/members; Nextcloud Team Folders tied to projects; shared access across apps.

**When / why use it**

- You run (or plan) a multi-tenant research/coworking platform for collections, digital humanities, or museum data.
- Users need self-service WissKI / Jupyter / Nextcloud / SPARQL / SQL without ticket-driven VM setup.
- You want one Drupal-facing admin UX wired to Keycloak and container orchestration.

**Typical use cases**

- A research group creates a project, adds a WissKI Environment with the default data model, and shares the project Team Folder via Nextcloud.
- A curator gets a SQL database and phpMyAdmin access via SSO for structured imports.
- A data scientist starts a JupyterHub environment that can use files from SCS-Share / Nextcloud.
- An ontology editor uses a WebProtégé account managed through the same dashboard and login.

## Requirements

This module is meant to run inside the **SODa SCS Manager deployment** (or an equivalent stack), not as a drop-in on a bare Drupal site.

- **Drupal** 10 or 11 (`^10 | ^11`).
- **Drupal dependencies** (declared): Language (core), [OpenID Connect](https://www.drupal.org/project/openid_connect), [SMTP](https://www.drupal.org/project/smtp), Field Group, and the **soda_scs_manager_theme** theme.
- **Composer-related** (module `composer.json`): e.g. `drupal/openid_connect`, `drupal/smtp`, Tailwind-related packages for the theme stack.
- **Infrastructure (required for real use):** Keycloak (SSO/groups/attributes), Docker + Portainer (or equivalent) for container lifecycle, MariaDB, Traefik (or similar reverse proxy), Nextcloud (+ integration app where used), JupyterHub, OpenGDB/triplestore services, WebProtégé as configured in the deployment. The reference Compose wiring is in [soda_scs_manager_deployment](https://github.com/soda-collections-objects-data-literacy/soda_scs_manager_deployment).
- **Host tools for operators:** Docker, Git (submodules), `jq`, `curl`; Node/npm on the host for theme builds.

### Recommended modules / libraries

- **soda_scs_manager_theme** — required companion theme (Tailwind/PostCSS UI); styling lives there, not in the module.
- **OpenID Connect** + Keycloak realm/clients as documented in the deployment MkDocs.
- **SMTP** — reliable outbound mail for invitations/notifications.
- Platform companions from the SODa stacks (Nextcloud `scs_manager_integration`, WissKI base images/packages, default WissKI data model) as used by the deployment—these extend what the manager can provision.

## Installation

Supported install path: the [soda_scs_manager_deployment](https://github.com/soda-collections-objects-data-literacy/soda_scs_manager_deployment) Compose stack (this module ships with that deployment under `scs-manager-stack`).

1. **Deploy the stack** — follow [soda_scs_manager_deployment](https://github.com/soda-collections-objects-data-literacy/soda_scs_manager_deployment) (clone, submodules, `.env` from `example-env`, `./start.sh`, `docker compose up -d`, then MkDocs post-configuration for Keycloak, Nextcloud, DBMS SSO, etc.).
2. **Enable the module and theme** — enable `soda_scs_manager` and its companion theme `soda_scs_manager_theme`; rebuild theme assets (`npm install` / `npm run build` in the theme) if you maintain CSS.
3. **Composer (standalone reference only)** — on Drupal.org the package is `drupal/soda_scs_manager`. A plain `composer require 'drupal/soda_scs_manager:^3.0'` without the deployment stack will not provide working provisioning; use the Compose environment above.

## Configuration (post-installation)

1. **Configure settings** — go to *Administration → Configuration → SODa SCS Manager* (`/admin/config/soda-scs-manager` or the route from the module’s configure link). Set integration endpoints/secrets (Keycloak, Portainer/Docker, Nextcloud, mail/SMTP, security/logging, etc.).
2. **OpenID Connect** — configure the OpenID Connect client so users log in via Keycloak (no separate “local-only” SCS workflow).
3. **Permissions & roles** — grant SCS users permissions such as managing own stacks/components/projects and “Manage own connected accounts”; keep admin permissions restricted.
4. **User-facing entry** — after login, users land on the **Dashboard** (not classic Drupal content types). Create projects there; add applications with “(+)” on a project. Optional: Connected Accounts for Nextcloud mount status and related links.
5. **Translations** — German UI strings ship as `translations/soda_scs_manager.de.po`; import via Locale/Drush if needed (see [Translations](#translations)).

**Special considerations:** Applications belong to at most one project. Provisioning needs working Docker/Portainer and service APIs. Nextcloud Team Folders and Keycloak groups are synced on project create/update/delete. Post-install hooks in companion services (e.g. Nextcloud) may need one-time `occ`/manual steps on existing installs—see the deployment docs.

### Permissions (quick note)

- SCS users need **Manage own connected accounts** (and typically permissions to manage their own stacks/components/projects).

### Security settings

Navigate to **Administration → SODa SCS Manager → Settings → Security Settings** to configure log sanitization and minimum log level (see [Security Features](#security-features)).

## Theming (development)

Tailwind and component styles live in the **soda_scs_manager_theme** subtheme (`web/themes/custom/soda_scs_manager_theme/pcss`), including `pcss/manager/` (migrated from this module). Install [Node](https://nodejs.org/) or [nvm](https://github.com/nvm-sh/nvm), then from that theme directory run `npm install` and `npm run build` (or `npm run watch` while developing).

## Content sync

### Export

```bash
# Navigate to web-root
cd /opt/drupal/web
drush content:export node modules/custom/soda_scs_manager/content/sync --all-content --translate --assets
mv modules/custom/soda_scs_manager/content/sync/content-bulk-export* modules/custom/soda_scs_manager/content/sync/content-bulk-export.zip
```

### Import

```bash
drush content:import modules/custom/soda_scs_manager/content/sync/content-bulk-export.zip
```

## Translations

If translations are outdated, reimport (*Administration → Configuration → Regional and language → User interface translation*, or Drush):

```bash
drush locale:import de modules/custom/soda_scs_manager/translations/soda_scs_manager.de.po --type=customized --override=all
```

## Hooks

This module implements (among others):

- **hook_bundle_info()** — additional bundles for `soda_scs_component`.
- **hook_entity_bundle_info()** — custom bundle information for SODa SCS entities.
- **hook_entity_field_storage_info()** — storage for bundle fields.
- **hook_ENTITY_TYPE_delete() / insert() / presave() / update()** — project lifecycle (e.g. Keycloak / Nextcloud sync).
- **hook_ENTITY_TYPE_view()** — overview content for `soda_scs_component` and `soda_scs_stack`.
- **hook_help()** — module help page.
- **hook_options_list_alter()** — options for the `connectedComponents` field.
- **hook_preprocess() / hook_theme()** — libraries and theme hooks for entities and pages.
- **hook_user_delete() / hook_user_insert()** — Keycloak/DB cleanup and default role assignment.

## Security Features

### Secure Logging System

The SODa SCS Manager includes a secure logging system to prevent sensitive data exposure in log files.

#### Key security features

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

#### Usage in custom code

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

#### What gets sanitized

- **MySQL Commands**: `-p<password>` → `-p[REDACTED]`
- **Connection Strings**: `mysql://user:pass@host` → `mysql://user:[REDACTED]@host`
- **Environment Variables**: `DB_PASSWORD=secret` → `DB_PASSWORD=[REDACTED]`
- **JSON Fields**: `{"password": "secret"}` → `{"password": "[REDACTED]"}`
- **API Keys & Tokens**: Long alphanumeric strings are automatically detected

#### Security best practices

1. **Always use secureLog()** instead of direct logger calls for potentially sensitive data
2. **Mark sensitive context keys** when calling secureLog()
3. **Keep sanitization enabled** in production environments
4. **Review logs regularly** to ensure no sensitive data is exposed
5. **Secure log files** with appropriate file permissions

⚠️ **Important:** Data marked as `raw_password`, `private_key`, `secret_key`, `api_secret`, or `client_secret` is completely removed from logs rather than sanitized.

For detailed security documentation, see `SECURITY.md`.

## Community documentation

- **Live platform:** [scs.sammlungen.io](https://scs.sammlungen.io)
- **Drupal.org project page:** [drupal.org/project/soda_scs_manager](https://www.drupal.org/project/soda_scs_manager)
- **Deployment + operator docs (MkDocs):** [soda_scs_manager_deployment](https://github.com/soda-collections-objects-data-literacy/soda_scs_manager_deployment) — clone and run MkDocs Material as described in that repo’s README.
- **SODa / SCS background:** [sammlungen.io](https://sammlungen.io) · [Zenodo record](https://zenodo.org/records/14627710)
- Issues and contributions: [soda-collections-objects-data-literacy](https://github.com/soda-collections-objects-data-literacy)

## Roadmap

- [x] Harmonise Entity/Bundle Definitions ([the modern way](https://www.drupal.org/docs/create-custom-content-types-with-bundle-classes))
- [x] Health checks for running components
- [ ] Flavour 3D
- [ ] Flavour conservation and restauration
- [ ] Documentation

## License

[GNU General Public License 2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html) (see `LICENSE.txt`) — GPL-2.0-or-later, matching Drupal core.
