# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.0.0] - 2026-08-12

Development line: branch `3.x`. Major release relative to `2.4.x` (application-centric dashboard).

### Breaking

- Dashboard is **project-centric**: **Central services** (Nextcloud, Jupyter, WebProtégé) above **Your projects** as cards (apps with Online/Offline, member count). Application creation moves to the project page for owners via `(+)` (with `?project=` on create forms); the old flat “add application from dashboard” UX is no longer the primary flow.
- Applications (components and stacks) may belong to **at most one** project (enforced on save + project sync; update `11026` collapses legacy multi-links: stack project → owner default → first listed).
- Breadcrumb root for SCS users is **Dashboard** (route `soda_scs_manager.dashboard`) instead of Home/Startseite (`<front>`). Entity pages append the current title as a non-linked crumb; components/stacks include the related project when set.
- New WissKI stacks use `NEXTCLOUD_MOUNT_MODE=external` and bind only the **project Team Folder** (`NEXTCLOUD_USER_MOUNT_SOURCE=…/<owner>/<project-label>` → `private://nextcloud`); app passwords are no longer injected into new WissKI stacks. Existing stacks need a re-deploy after a final bisync push (see nextcloud_webdav_mount migration notes).

### Added

- Nextcloud sidecar mount lifecycle: `NextcloudMounterClient`, encrypted Drupal storage for app passwords, `NextcloudMountManager` reconciler (cron ~1 min), and Drush `scs:nextcloud-mount|unmount|reconcile|status`.
- Connected Accounts shows Drive mount status (`mounted` / `error:…` / `disconnected`).
- Nextcloud stack entity preview with recent file activity.
- Details link on Nextcloud dashboard cards to the stack entity page.
- Project Team Folders via Nextcloud app `scs_manager_integration` (stable `machineName` = Keycloak group id, editable label as mount point).
- Default project label **Project 1** / **Projekt 1** on first user provisioning, with Keycloak attributes `gid`, `label`, `nextcloudTeamFolder`, and `containedApps` placeholder.
- Follow-up plan: `docs/plans/project-centric-services-followup.md` (Vorhaben, WissKI/DB/Jupyter/WebProtégé).
- Technical backfill guide for existing projects/Team Folders: `docs/technical/project-team-folder-backfill.md`.
- Drush command `soda_scs_manager:backfill-project-team-folders` to bring legacy projects up to Team Folder parity (Keycloak attrs, members, Nextcloud folder; optional `--grant-nc-access`).
- Keycloak `{machineName}-admin` and `{machineName}-user` groups for SQL and Triplestore components (create, delete, project member sync), matching WissKI.
- Restored theme `package.json` with npm `overrides` for locked transitive CSS tooling dependencies.
- Configurable `snapshotHostPath` setting (and `soda_scs_manager.snapshot_host_path` in settings.php) for Portainer host bind mounts.
- Nextcloud settings field for OCC Docker container name (`occContainerName`).
- `LICENSE.txt` (GPL-2.0-or-later) and Drupal.org project page copy (`docs/drupal-org-project-page.md`).

### Changed

- Nextcloud app passwords are stored encrypted in Drupal user data; Keycloak keeps only `nextcloud_login_name` (legacy Keycloak app-password attributes are migrated and cleared on read/connect).
- Nextcloud preview loads SCS-Share activity via `scs_manager_integration` (platform share `externalProjectId=scs-platform-share`) instead of the global Activity API path filter.
- Project create/edit/delete sync Nextcloud Team Folders and Keycloak group labels (group name remains the integer id).
- Snapshot manifests and Keycloak approval emails use the current site URL instead of a hardcoded production domain.
- SQL snapshot/restore Docker exec uses the configured `dbHost` container name.
- Interface translation server pattern points at `modules/contrib/`; composer metadata aligned with module dependencies.
- Settings UI / schema examples use `example.com` placeholders.

### Removed

- Site content export zip, tmp config stubs, xdebug helper scripts, and dummy `assets/options` API drafts from the packaged tree.

### Security

- Nextcloud app passwords no longer remain as plaintext Keycloak user attributes after connect/migration.
- Pin transitive npm packages against Dependabot advisories: `nanoid` 3.3.17, `svgo` 4.0.2, `postcss` 8.5.26, `picomatch` 2.3.2 / 4.0.5.

## [2.4.2] - 2026-07-20

### Fixed

- Error when stack components are missing.
- German translation of “Application” unified.

## [2.4.1] - 2026-07-02

### Added

- Internal WissKI triplestore host for SPARQL environment injection.
- Access checks for project members on delete, edit, and snapshot actions.

### Fixed

- Snapshot PFDP bypass and normalization of private file URIs.
- German translations for coworking intro and registration.

### Changed

- Input validation hardened (“bulletproofness”).

## [2.4.0] - 2026-06-30

### Changed

- UI CSS moved from the module into `soda_scs_manager_theme`.
- Improved project list rendering and tag filtering on projects.
- Package/language updates; imprint and data-policy links; language selector removed from UI where appropriate.

### Fixed

- Nextcloud manual login fallback.
- Wizard naming and translations for wizard and notifications.

## [2.3.0] - 2026-06-17

### Added

- Bearer-token login path.
- Keycloak attribute sync; deleting a user also removes the Nextcloud account and stops/removes the Jupyter user container.
- Additional manager settings (including proxy address).

### Changed

- Snapshot volume paths adjusted to the new layout.

## [2.2.0] - 2026-05-05

### Added

- Split WissKI project owner vs member permissions; only owners get admin.
- Project members added as WissKI users when creating new WissKIs.
- Project notes field.
- Email for project invitations.
- Wizard videos and sync-related media.

### Fixed

- Orphaned projects handling.
- Typed property initialization bug in project membership form.
- Hardened name/input fields.

### Removed

- Unnecessary field on projects.

## [2.1.0] - 2026-04-29

### Added

- Onboarding wizard on dashboard and front page, with validation and animations.
- WissKI ready popup on component and stack entity views.
- Projects entry points and richer start-page content.

### Changed

- Health status display on cards (shorter, clickable “available”).
- Redirects after project edit/delete/cancel go to the projects page.
- Unified wording (e.g. OpenGDB), stack/component descriptions, and translations.
- Default project labeling (“standard project”).
- Design/assets moved toward the theme; card icons and image sizing.

### Fixed

- Page attachments and container TLS logging noise.
- Snapshot paths after access-proxy replacement; Drupal container name for snapshots.

## [2.0.0] - 2026-03-18

Development line: branch `2.x`. Major release relative to `1.3.x` (projects on stacks/components; filesystem component).

### Added

- Nextcloud connection (browser login for devices by default).
- Triplestore read access, credentials, and endpoints.
- Keycloak password/user management for databases.
- Docs/menus content, translations, descriptive throbbers.

### Changed

- **Breaking:** Project management removed from stacks and components; projects simplified and removed from those forms.
- Dashboard sorting, filters, and naming polish.
- Environment variables improved (including Nextcloud).

### Removed

- **Breaking:** `file_system_component` removed.
- Shared folder approach discontinued.
- Triplestore access restricted for normal users.

### Security

- Resolved SVGO high-severity vulnerability (GHSA-xpqw-6gx7-v673).

## [1.3.0] - 2026-02-10

### Added

- Health status for WebProtégé, Nextcloud, and Jupyter on the dashboard.
- Direct links to services; WissKI version entity update.
- Issue report form (title in email subject/body; copy to reporter).

### Changed

- Landing/navigation and dashboard health/links polish.
- Documentation book navigation highlighting.

### Fixed

- Catalogue reference on empty dashboard results; list-style CSS on menus.

## [1.2.0] - 2026-01-09

### Added

- Component/stack version entities and recipe versioning.
- Automated updates for single WissKI/Drupal instances (update routine and progress).
- Terms and conditions; reset-password flow; app counting on UI.
- Snapshot permission/folder hardening.

### Changed

- Devcontainer and Varnish/Redis host/port abstraction.
- Health checks and role handling hardened.

### Fixed

- Redirect and CSS optimization bugs.

## [1.1.0] - 2025-11-26

### Added

- In-app notifications and project member notifications.
- Stack/image and recipe version display foundations.
- Improved health status on entities and list builders.

### Changed

- Project membership listing and owner-only edit/delete permissions.
- Entity create UX and admin styling.

### Fixed

- Broken project membership display; list builder cache/spacing issues.

## [1.0.0] - 2025-09-28

### Added

- Full snapshot create and restore (SQL, WissKI, bags, related cleanup).
- Project access control handler and improved member management.
- Health status on WissKI stacks.

### Changed

- Filesystem naming/permissions for snapshot workflows.
- Default project assignment and Keycloak group sync for projects.

### Fixed

- Snapshot reference deletion and restore robustness.

## [0.4.0] - 2025-08-22

### Added

- Default project for new users; project–Keycloak group syncing.
- Snapshot prototypes (SQL/filesystem/bag) toward production readiness.
- Keycloak groups and related project membership wiring.

### Changed

- Nextcloud stack imagery; machine name removed from project entity.

## [0.3.0] - 2025-06-25

### Added

- Jupyter and Nextcloud stack integration (and OnlyOffice branding).
- Keycloak registration/user settings and project member sync to Keycloak.
- Early SQL/file snapshot work and project filters/action links.
- Health checks (initial).

### Changed

- Keycloak service implementation renewed; project connect UI improvements.

### Fixed

- Keycloak user/admin group deletion and missing-user cases.

## [0.2.0] - 2024-12-18

### Added

- Stack and component entity structure with create/delete flows.
- OpenGDB repository create/delete, tokens, and bundle field definitions.
- Service keys/permissions and triplestore field configuration.

### Changed

- Module restructure and Drupal coding standards pass.
- Better error handling when OpenGDB users are missing.

### Fixed

- Bundle field mismatches; stack save/delete edge cases; settings bugs.

## [0.1.0] - 2024-08-06

### Added

- Portainer-based component provisioning and REST helpers.
- Database credential management and dynamic configuration.
- Early SODa naming, menus, and status/config pages (from prior prototypes).

### Changed

- Move toward fully Portainer-driven lifecycle for managed services.

[unreleased]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/3.0.0...HEAD
[3.0.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/2.4.2...3.0.0
[2.4.2]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/2.4.1...2.4.2
[2.4.1]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/2.4.0...2.4.1
[2.4.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/2.3.0...2.4.0
[2.3.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/2.2.0...2.3.0
[2.2.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/2.1.0...2.2.0
[2.1.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/2.0.0...2.1.0
[2.0.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/1.3.0...2.0.0
[1.3.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/1.2.0...1.3.0
[1.2.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/0.4.0...1.0.0
[0.4.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/releases/tag/0.1.0
