# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- The German translation file is valid gettext again (`msgfmt -c` reported
  34 errors). Three strings of the Keycloak registration form lacked their
  closing quote, so their German text never reached the form; 31 duplicate
  entries are merged, keeping the translation the import used so far.

## [4.3.0] - 2026-11-10

### Changed

- The module ships its own UI styles: `css/soda-scs-manager.css`, built from
  `pcss/` with `npm run build`. The `globalStyling` library no longer depends
  on `soda_scs_manager_theme`, and installing the module no longer needs or
  installs that theme.

### Removed

- The dependency on `soda_scs_manager_theme`. Update 11039 uninstalls the
  theme (with its blocks and settings) unless it is the default or admin
  theme; on sites where its files are already gone it is removed from the
  installed themes. **Remove `web/themes/custom/soda_scs_manager_theme`**
  after the update.
- The site-wide styles that came with the theme bundle: Tailwind's preflight
  reset, the `html`/`body` font (Inter), Olivero/Claro page-frame and
  cookie-banner rules. In the SCS deployment `scs_custom_theme` provides them
  now; **another front theme must bring its own base reset and fonts**.

## [4.2.0] - 2026-11-10

The tag `4.1.1` on git.drupal.org was never released; its changes are part of this release.

### Added

- SCS system check, in the Drupal status report (`/admin/reports/status`)
  and as `drush scs:check` (`--problems` lists only warnings and errors;
  exits with 1 on errors). It checks the required SCS Manager settings,
  reaches MariaDB (root), Portainer (with its token), Keycloak, Nextcloud,
  the Nextcloud mount sidecar, the triplestore, JupyterHub and WebProtégé,
  checks the snapshot directory and its free space, the host timers, the
  demo and WissKI update queues and whether every WissKI container runs.
  The status report reuses a run for five minutes.
- `drush scs:heartbeat <name>`: the systemd timer units call it after each
  run, so the system check can tell a missing or stopped host timer.
  **Re-copy the unit files** from `01_scripts/global/` to
  `/etc/systemd/system/` and run `systemctl daemon-reload`.

- The version card of a WissKI instance names the running build as
  "<commit> from <date>", and for development instances the commit and build
  date of the next image (read from the image registry). The update button
  names its target ("Update to 5.6.1").

### Changed

- WissKI updates run as queued background jobs instead of inside the web
  request, which php-fpm ends after 600 seconds, possibly in the middle of a
  rollback. The update button, "Set package environment" and the automated
  updates form queue the update (queue `soda_scs_manager_wisski_update`) and
  start `drush queue:run` in the background; updates run one after the
  other, and a lock keeps a second update of the same instance from
  starting. The version card shows "Update running" with the current step
  while it runs, reloads when it ends, and shows the outcome for a day. The
  Update button is hidden while an update is queued or running.
  The host timer `scs-wisski-update-queue.timer` (deployment repo,
  `01_scripts/global/`) picks up updates whose background run did not start.

### Fixed

- The onboarding tour now outlines all relevant clickable file, application,
  and subsystem controls using the colours shown in the instructions.
- WissKI updates no longer destroy data when the database update fails. The
  rollback could cut a site's database in half: it ran inside the old Drupal
  container while Portainer was still replacing it, so the restore was
  killed mid-import (seen on a development instance, whose `config` table
  lost every row after `views.view.*`).
  - After the redeploy, the update waits until Portainer has actually
    replaced the Drupal container (up to three minutes) before it checks
    health and runs `drush updatedb`.
  - The pre-update dump is written from the MariaDB container to
    `<snapshot root>/.wisski-update-backups/<instance>/<timestamp>.sql`
    instead of `/opt/drupal/bkp` inside the WissKI container, which did not
    survive the redeploy. The dump is checked for completeness; the three
    newest updates keep their dumps.
  - `drush updatedb` is retried twice before the update counts as failed, so
    a dropped Redis connection right after the redeploy no longer triggers a
    rollback.
  - The rollback stops the WissKI container, dumps the current state to
    `<timestamp>-failed-update.sql`, and restores from inside the MariaDB
    container. The error names the dump files.
- A failed WissKI update now returns to the previous version, not only the
  previous database. Before the redeploy the manager records the Portainer
  env, the compose stack ref and the running Drupal image by registry digest
  (`main@sha256:…`, so this also works for development instances on a
  rolling tag), plus the entrypoint's package-set marker. Any failure from
  the redeploy on (redeploy error, Drupal not healthy, `drush updatedb`)
  restores the database, resets the marker so the old entrypoint does not
  re-import recipe config, redeploys the recorded state, and leaves
  maintenance mode once Drupal is healthy. If the previous version cannot be
  redeployed, the site stays in maintenance mode on the new image with the
  restored database.
- A failed pre-update dump no longer leaves the site in maintenance mode.
- Operations never left their first status: `updateOperation()` called
  `fields()` once per field, and each call replaced the previous list, so
  only the `updated` time was written. Completed and failed operations now
  say so.
- Progress steps stored their time as single-precision float, which rounds a
  Unix timestamp to about two minutes, so the steps of an operation came
  back in random order. Update 11038 changes `soda_scs_steps.created` to
  double.

## [4.1.0] - 2026-11-09

### Added

- WissKI application pages show an "Admin tasks" panel for SCS
  administrators. "Clear Varnish cache" lives there; its explanation is now
  a tooltip on the button.
- The version card of a WissKI instance has an "Update" button for SCS
  administrators when automated updates are on. Production instances move to
  the latest production version; development instances are redeployed with
  the current development images.
- Production instances show which newer version is available. Development
  instances show the commit of the running image (OCI label
  `org.opencontainers.image.revision`; locally built images have none).

### Changed

- Updating a development instance to "Nightly" keeps its version label
  (for example `5.x`) instead of `nightly-<timestamp>`.

### Removed

- Removed the "WissKI 8.x-4.x Development" option when creating a WissKI.
  Development instances run WissKI 8.x-4.x themselves; update 11037 drops the
  `wisski8x4xDevelopment` field and `WISSKI_8X_4X_DEVELOPMENT` is no longer
  passed to the container.

## [4.0.0] - 2026-11-06

### Added

- Guided onboarding tour across the dashboard, a project and an application
  page, with the overlay centered or placed next to the highlighted elements.
  It starts on a user's first visit and takes them to the dashboard, can be skipped or closed with
  Escape, and can be restarted via "Start onboarding tour" in the
  account menu (temporary development trigger). German translations included.
- Project owners and SCS administrators can set each team member's role per
  WissKI instance (Administrator or User) from a role button in "Edit team
  members". The change goes straight to the instance's Keycloak `-admin` /
  `-user` groups and applies at the member's next login; the owner is always
  administrator.

### Changed

- The login route now redirects directly to the configured Keycloak provider
  instead of displaying an intermediate Drupal login page.
- The registration page is restyled to match the site design: heading, card
  layout, first and last name side by side, and legal checkboxes with their
  text beside the box.

### Removed

- Removed the separate co-working first-login intro; the guided onboarding tour
  now starts instead.

### Security

- Keycloak component groups are matched by exact name, so a member is never
  synced into a similarly named group of another instance.
- Project pages are visible only to the owner, listed members and SCS
  administrators. Any other signed-in user who opens a project URL is denied.

### Changed

- Approved platform users get one demo project automatically on sign-in. The
  project card is there for onboarding; WissKI, JupyterHub and WebProtégé
  fill in the background. Creating an own project stays optional. The
  create-project wizard no longer offers New and Demo tabs.
- While a demo is being set up, the project page lists each application that
  is not ready yet in the sidebar. A line disappears as soon as that
  application is ready: Jupyter once its stack exists, even while the server
  is stopped, WebProtégé once its component exists except while the ontology
  import is the current step, and WissKI once it is running again after the
  restore. Each line shows a spinning throbber next to its status. The
  application cards are rebuilt when an application is created and again when
  that application is ready.
- Demo projects include WissKI, JupyterHub and WebProtégé with sample data.
  Standalone SQL databases and triplestores are no longer created or
  restored. The WissKI stack still has its own database and triplestore.
- Jupyter and WebProtégé cards show the instance name, the same way as WissKI
  and the database. New Jupyter demos are named "<username> Demo-JupyterHub".

### Fixed

- A demo import no longer puts the WissKI login and logout redirects back to
  "home". Those paths stay as they are in the imported template
  ("/wisski_home"). The instance's own SSO client is still written back.
- While a demo is being set up or reset, a WissKI that is stopped only for
  the data restore stays on Starting. It no longer flips to Stopped until
  the setup job has finished.

### Added

- SCS administrators can clear the Varnish cache of a WissKI instance from
  the instance page and from the component and stack lists. The button asks
  for confirmation, then bans every cached page in that instance's Varnish
  container. The WissKI itself stays online.
- Demo projects: each approved user gets "<username> Demo", a copy of the
  central "Demo Projekt" with its WissKI, shared JupyterLab home, Team Folder
  files and WebProtégé ontology. Apps are named "<username> Demo-WissKI",
  "<username> Demo-JupyterHub" and "<username> Demo-WebProtégé".
- Owners can reset their demo project to the active demo version on the
  project page; the project card and page show the demo state while it is
  set up or reset.
- Admin page `/admin/config/soda-scs-manager/demo-project` (SODa SCS-Manager
  settings): choose the source project (renamed to "Demo Projekt"), build
  demo versions numbered `major.minor.patch` with a required changelog,
  activate or delete them. The page suggests the next patch, minor and major
  version; a new version must be higher than all existing ones. Versions are
  stored under `<snapshot root>/_demo-templates/v<major.minor.patch>/` with a
  checksummed manifest. The WebProtégé ontology is exported as a zip and
  imported into a fresh ontology project; a reset replaces that project,
  because WebProtégé can only add axioms. Needs the SCS WebProtégé endpoint
  `GET /data/projects/{id}/ontology` (image rebuild of the WebProtégé fork).
- Before a reset to a newer demo version, owners see the changelogs of all
  versions in between.
- Demo builds, setups and resets run in the queue `soda_scs_manager_demo`.
  The admin button "Start queued jobs", and creating or resetting a demo,
  start that queue in the background. No systemd timer. Update hook 11036
  installs the template entity and the project fields `demoTemplate` and
  `demoStatus`.

### Fixed

- WissKI public hostnames no longer repeat the internal `wisski-` machine-name
  prefix. A stack stored as `wisski-robert-demo` is reached at
  `robert-demo.wisski…`. Container names, volumes and Keycloak client ids keep
  the prefix. Already deployed stacks still answer on the old host until their
  Portainer env `DRUPAL_DOMAIN` and Drupal trusted hosts are updated.
- A demo that is still waiting for the WissKI installation says so, instead of
  keeping the message "Creating missing applications".
- Deleting an account (by the user or an admin) removes the projects they own
  and the accounts in Keycloak, Nextcloud, MariaDB, the triplestore and
  WebProtégé. Projects they only belong to stay; they are removed from those.
  When an owned project still has other members, the delete form asks before
  those projects go too. Needs a rebuild of the WebProtégé image so
  `DELETE /data/users/{name}` exists.
- Demo export and SQL snapshots talk to MariaDB by its container name
  (`SCS_CONTAINER_DATABASE`). The configured `dbHost` is only the DNS alias
  and Portainer cannot exec into it.
- Stopping a container no longer sends a stop request when the container is
  not running.
- Demo provisioning copies the Team Folder after a new WissKI has finished
  installing. The background queue used to exit while that job was only
  delayed, so the project folder stayed empty. Volume archives of a template
  (Jupyter home, WissKI files) are written into the snapshot directory the
  manager actually reads.

### Security

- Each demo WissKI gets its own password for the local account `api-import`.
  It is stored only in the private notebook home (`~/.wisski-demo-api.env`),
  not in the shared Team Folder and not in the template.

### Changed

- Deleting a project that still has members is blocked until those members
  are removed. The delete dialog says so and offers “Remove members and
  delete”, which drops their access and then deletes the project.
- On a finished demo project, the version sits in the right column with the
  notes and the team. It is a plain section title, without the green status
  pill.
- While a demo project is being set up, the project page shows a shimmer and
  a short progress row (WissKI, JupyterHub, SQL database, triplestore,
  WebProtégé) instead of the template version. Application cards appear as
  soon as each application exists.
- A failed demo version can be built again with the same number. That replaces
  the failed attempt. A finished version keeps its number.
- The version field only hints at the notation `{Major}.{Minor}.{Patch}`,
  without a leading `v`.
- Application configuration dialogs now show a live application preview with
  its documentation link, product name and application type.
- Application cards now show the product name, user-defined instance name,
  and application type as separate strings without added field labels.
- Add-application popup cards now use the same product and application type
  strings as application cards.
- Add-project wizard application choices now use the same translated product
  and application type strings.

## [3.1.0] - 2026-10-22

### Added

- Every project with JupyterHub has one shared JupyterLab with real-time
  collaboration instead of one notebook per member. Its hub user
  `project-<gid>` owns the server; the hub grants members access by the role
  `scs-project-<gid>` from their Keycloak groups. The Jupyter card opens the
  hub launcher `/hub/scs/projects/<gid>`, which starts the server on demand;
  start, stop and restart on the card act on this server for all members,
  and the card health shows its state. Removing a member stops a running
  server so open sessions end at once. Removing JupyterHub or the project
  stops the server and deletes its hub user and home volume
  (`jupyterhub-user-project-<gid>`); the Team Folder files stay. Needs
  `JUPYTERHUB_SCS_MANAGER_API_TOKEN` (hub service `scs-manager`).
- `drush scs:jupyter-backfill-project-servers [--dry-run]` and
  `scs:jupyter-project-status`.
- Projects with JupyterHub get a project mount: the Nextcloud service account
  `scs-jupyter` mounts the Team Folder through the rclone sidecar at
  `<NEXTCLOUD_MOUNTS_ROOT>/_projects/<gid>/<label>`, independent of the
  members' personal Drives. Adding JupyterHub adds the group
  `scs-project-mounts` to the Team Folder and mounts it; removing JupyterHub
  or the project unmounts it and removes the group. A rename remounts under
  the new label. Drupal cron reconciles the mounts (at most once per minute;
  with `automated_cron` only every few hours, so a host timer should run
  `scs:nextcloud-project-reconcile`). Needs
  `NEXTCLOUD_PROJECT_MOUNT_USER` and `NEXTCLOUD_PROJECT_MOUNT_APP_PASSWORD`
  in the manager container; without them project mounts are skipped.
- `drush scs:nextcloud-project-status`, `scs:nextcloud-project-mount
  --project=`, `scs:nextcloud-project-unmount --project=` and
  `scs:nextcloud-project-reconcile`.
- Project names are unique across the platform. Uniqueness is checked on a
  machine name derived from the label (`machineName` on `soda_scs_project`,
  umlauts transliterated), so "Mein Projekt" and "mein-projekt" collide. The
  create and edit forms check the name while typing
  (`/soda-scs-manager/project-name/check`); `scs-share` is reserved. Update
  `soda_scs_manager_update_11033` fills the machine name for existing
  projects.
- The co-working intro asks for the first project (slide 4) and then offers a
  WissKI inside it (slide 5). Both steps can be skipped.
- `drush soda_scs_manager:applications-without-project` lists applications
  created before projects became mandatory.
- Sidebar **Messages** link shows the number of unread messages and pending
  invitations as a badge, refreshed every 30 seconds via
  `/soda-scs-manager/messages-unread-count`.
- Project page **Files** section shows recent Nextcloud Team Folder activity
  for the viewing owner/member (same `scs_manager_integration` feed as the
  Nextcloud card). Placeable block **Project files (Nextcloud activity)**
  (`soda_scs_manager_project_files`) with project context for Layout Builder
  or block layout.
- `SodaScsDockerDns` resolves shared container DNS from `SCS_CONTAINER_*` /
  `INTERNAL_TS_BASE` / `NEXTCLOUD_MOUNTER_RC_URL` (settings still override),
  so naming migration does not require PHP hardcodes.
- Creating an SCS project also creates a WebProtégé ontology project. The
  project-page WebProtégé card deep-links into it; members receive EDIT.
  Configure the API key in settings (not stored in git). Backfill existing
  projects with `drush soda_scs_manager:backfill-webprotege-projects --all`.
- Hover (or tap on a phone) the application card health icon to start, stop,
  or restart dedicated containers (WissKI, MariaDB, triplestore, Jupyter
  notebook). Shared services (Nextcloud, WebProtégé, JupyterHub) are
  unchanged.
- Project pages show the user’s central services (JupyterHub, Nextcloud,
  WebProtégé). JupyterHub and Nextcloud open the project Team Folder.
  The Nextcloud card lists recent activity in that folder (via
  `scs_manager_integration` `/folders/{id}/feed`), with icons for
  created / edited / deleted (and renamed, moved, restored).

### Changed

- The create-project wizard stores the name, applications and invitations
  until a review step. The project is created only when that review is
  confirmed, so closing the window earlier leaves nothing behind.
- On the last step of the co-working introduction, the Next button becomes
  Close.

- If creating an application in the "Add application" popup fails, the popup
  stays open and shows the error with "Try again" and "Cancel" instead of
  jumping to the form page.
- A standalone WissKI component card shows only the WissKI icon; the stack
  card keeps the WissKI, triplestore and SQL icons.
- Notification close buttons and the project card's Edit/Delete menu use the
  theme buttons.
- The user mount reconcile leaves mounts under `_projects/` alone, and the
  sidecar mount lookup matches the mount point exactly instead of by suffix.
- New users start without a project: no default project is created on
  registration any more.
- Applications (WissKI, SQL, triplestore, Jupyter, WebProtégé) always belong
  to a project. Create forms ask for one of the user's projects (or take
  `?project=`); without a project they link to project creation. Stack and
  component actions reject requests without a project.
- Project creation runs through `SodaScsProjectProvisioner` (form and intro).
  It deletes the project again when the Keycloak group fails or Nextcloud
  already has a folder with that name.
- Failed application creation (WissKI and other stacks/components) keeps the
  user on the create form with the error message instead of redirecting.
- JupyterHub and WebProtégé are project applications: a project page shows
  them only when they were added to that project, at most once per project,
  owned by the project owner. Members open the project's shared JupyterLab
  and may start/stop it from the card. Adding WebProtégé creates
  (or reuses) the project's ontology project; deleting it moves the ontology
  project to trash. The add-application popup shows these tiles as
  "Already in this project" when present.
- New users and new projects no longer get JupyterHub, WebProtégé or a
  WebProtégé ontology project automatically. `backfill-webprotege-projects`
  only handles projects with a WebProtégé component. Run
  `drush soda_scs_manager:reconcile-webprotege-projects --dry-run --all` after
  `updb` to link used ontology projects and trash unused ones.
- Profile page: the username is shown as plain text instead of a disabled
  field, the picture dialog opens from an **Upload picture** button, and the
  duplicate name heading under the picture is gone.
- After deleting a stack (including force delete), redirect back to the
  related project page instead of the dashboard.
- Application health is always one of **running**, **starting**, **stopped**,
  **problem**, or **unknown**. Container states, HTTP probes, and legacy
  labels (healthy, unavailable, …) are mapped onto that set in PHP; Twig and
  JS only render those five. **Starting** replaces **unknown** while a
  container is booting (Docker health `starting`, restarting, or HTTP
  502/503 from a running container); starting or restarting from the health
  icon shows it immediately.
- The **Add Application** popup lists JupyterHub and WebProtégé as well as
  WissKI, MariaDB, and triplestore, and loads the create form in the dialog
  instead of navigating away.
- The former **Notifications** page is a general **Messages** inbox
  (sender, subject, date). Opening a message shows its body and
  type-specific actions. Project invitations are one message type
  (accept / decline); other types (private or group) can be added later
  without changing the inbox. Existing invitations are migrated on
  `update 11029`. The old `/soda-scs-manager/notifications` URL redirects
  to `/soda-scs-manager/messages`.
- Central application cards are titled **JupyterHub**, **Nextcloud**, and
  **WebProtégé** (not the per-user instance name). WissKI, database, and
  triplestore cards keep the instance title.
- Removed the **Restart Jupyter** button from the JupyterHub card; notebook
  stop and restart are on the health icon.
- Dashboard lists only **Your projects**. JupyterHub, Nextcloud, and WebProtégé
  are opened from the project page, not from a separate **Central services**
  block.
- The main-menu **Projects** tab is removed. Projects are opened from the
  dashboard. On the project page, owners (and admins) add members with **+**
  (email or username, no autocomplete). Members can remove themselves with
  **×**; admins and owners can remove other members. Members cannot remove
  other members.
- The project team editor lists pending invitations and keeps declined ones
  visible as **Declined**. A pending invitation can be withdrawn. A declined
  one can be deleted or resent. The same person cannot be invited again while
  that invitation is still pending or declined.

### Removed

- Personal Jupyter notebooks: the restart warning after creating or joining a
  project, stopping the notebook container on user deletion, and the
  notebook container prefix setting. Update 11035 deletes Jupyter stacks
  without a project.
- User field `default_project` and the default project fallbacks
  (`soda_scs_manager_update_11034`). Former default projects stay as normal
  projects.
- Nextcloud stack entities (`soda_scs_nextcloud_stack`) and the project-page
  Nextcloud card. Project files are reached through the files block; update
  11032 deletes the entities (no remote resources belong to them).

### Fixed

- Saving a user no longer fails with a TypeError when no Keycloak token can
  be fetched (Keycloak unreachable or not configured); the Keycloak field sync
  is skipped instead.
- Pages no longer fail with "Source path … has to start with a slash" on
  Drupal 11.4 while the documentation book does not exist yet: the
  Documentation menu link keeps the leading slash of its unrouted path.
- Installing the module on a fresh site works again. The companion theme was
  listed as a module dependency, which Drupal cannot resolve, so `drush en`
  aborted; the install requirements now check that `soda_scs_manager_theme`
  is present and `hook_install()` installs it. The Documentation body field
  ships its own `field.storage.node.body` as optional config, because Drupal
  11.3+ no longer creates that storage on install; sites that already have it
  keep theirs.
- The start page (`/soda-scs-manager/start`) no longer fails with a TypeError
  for users without a first name (e.g. admin or fresh SSO accounts); it greets
  them by account name.
- Deleting an application, project, or profile shows the same progress overlay
  as creating an application, instead of only relabeling the confirm button.
- The co-working intro Skip control stays inside the panel and wraps on a narrow
  screen. Close on the last step marks the introduction complete and dismisses
  it. Next no longer stays clickable on the project and WissKI steps.
- Drive connects automatically again after Keycloak login. Creating the app
  password via `occ` used the old container name `nextcloud--nextcloud`; the
  default is now `nextcloud--nextcloud--app`, so new users no longer get the
  "Connect Drive manually" prompt.
- A new project with the same label as an existing Team Folder no longer takes
  over that folder's groups. `scs_manager_integration` answers 409, and the
  manager asks for a different name (or keeps the old name on rename).
- Creating a WissKI right after its project no longer fails with "Nextcloud
  Team Folder … is not available under the owner mount yet". Nextcloud adds
  Keycloak groups only at Drive login, so the new Team Folder was missing from
  the owner's Drive. The manager now adds the owner to the Team Folder group
  when it creates the project, adds members when they accept an invitation,
  and removes them when they leave (Nextcloud provisioning API with the
  admin credentials). Before mounting, the WissKI creation repeats the step
  for its owner and clears the rclone directory cache, so the folder shows
  up at once. `scs-backfill-project-tf --grant-nc-access` uses the same API
  instead of `occ`.
- WebProtégé card links open the project's ontology even for signed-out
  users: they go through `/webprotege/oidc/login?return_to=…`, which keeps
  the target across the Keycloak sign-in (needs the matching WebProtégé
  build).
- Project pages no longer show the viewer's personal JupyterHub, Nextcloud and
  WebProtégé on every project.
- Application cards on a stack detail page (e.g. WissKI with database and
  triplestore) stayed on **Unknown** because the health polling script was not
  loaded there; they now show the live status.
- Stack detail pages crashed with “Call to undefined method
  SodaScsStackViewBuilder::resolveDeleteDestination()”. The delete-destination
  helper that already existed on the component view builder is now also on
  the stack view builder.
- Settings form save failed with browser error “invalid form control … is not
  focusable” when `accessProxy[generalSettings][containerName]` was empty and
  required inside an inactive horizontal tab. Empty config now falls back to
  `SodaScsDockerDns::accessProxy()`, and the form uses `novalidate` so Drupal
  (not HTML5) validates required fields across tabs.
- AJAX file uploads on the user edit form (and other managed-file widgets)
  failed with HTTP 500 because `WsodCatcherSubscriber` treated Drupal’s
  intentional `FormAjaxException` as an unhandled error before
  `FormAjaxSubscriber` could build the AJAX response.
- Project Nextcloud card activity feed failed with HTTP 500 when the
  `scs_manager_integration` bind mount pointed at a deleted directory.
  Recreate `nextcloud--nextcloud` after replacing that app tree so the
  OCS `/folders` API can load.

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

[Unreleased]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/4.3.0...HEAD
[4.3.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/4.2.0...4.3.0
[4.2.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/4.1.0...4.2.0
[4.1.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/4.0.0...4.1.0
[4.0.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/3.1.0...4.0.0
[3.1.0]: https://github.com/soda-collections-objects-data-literacy/soda_scs_manager/compare/3.0.0...3.1.0
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
