# Demo projects from versioned templates

Every approved platform user (`scs_user`) gets one demo project, a copy of one central admin project, **"Demo Projekt"**. It is created on sign-in, including the first sign-in while onboarding runs. The admin freezes that project into numbered versions (templates); user demos are created from and reset to the active version. An own project stays optional and is created from the project wizard.

## Naming

| Item | Label | Machine name base |
|------|-------|-------------------|
| Project | `<username> Demo` (falls back to `… Demo 2` … `… Demo 10` on conflicts) | derived from the label |
| WissKI stack | `<username> Demo-WissKI` | `<user>-demo` |
| Jupyter stack | `<username> Demo-JupyterHub` | `<user>-demo-jupyter` |
| WebProtégé | `<username> Demo-WebProtégé` | `<user>-demo-ontology` |

Machine name bases stay within 30 characters; the stack and component actions add their prefixes (`stack-`, `sql-`, `ts-`, …). On collisions a counter is appended.

## Scope

Copied: the WissKI stack (sites and private-files volumes, Drupal database, triplestore repository), the project's shared JupyterLab home volume, the project Team Folder files and the WebProtégé ontology (root ontology and its imports, merged into the demo's root ontology).

Not copied: standalone SQL databases and triplestores, project members, and apps the user added to their demo. A reset overwrites the data of the demo apps only. The WebProtégé project is trashed and created again on reset, because the API can add axioms but not replace them.

## Admin workflow

`/admin/config/soda-scs-manager/demo-project` (menu: Configuration → SODa SCS-Manager → Demo project):

1. **Use as demo project** – choose the source project. It is renamed to "Demo Projekt" (Keycloak group label and Team Folder label follow). Projects that are themselves user demos cannot be chosen.
2. **Build new version** – queues a template build with a version and a changelog (both required). During the export the source WissKI container and the project Jupyter server are stopped for a few minutes.
3. **Activate** – a finished version becomes the one new demos and resets use. The first finished version is activated automatically.
4. **Delete** – removes a version and its files. The active version and versions still referenced by demo projects cannot be deleted.

## Versions and changelog

Versions follow `major.minor.patch` (`SodaScsDemoVersion`): three numbers without leading zeros and without a `v` prefix or pre-release suffix. A new version must be higher than every existing one. Building a failed version again with the same number replaces that attempt; a finished version keeps its number. When the newest version failed, the form prefills that number and its changelog. Otherwise it prefills the next minor bump:

- **patch** – small fixes in the demo data,
- **minor** – new content, e.g. more objects or notebooks,
- **major** – a new structure, e.g. other or additional apps.

Versions are compared numerically (`1.10.0` is newer than `1.9.0`), so lists are sorted in PHP rather than by the database.

The changelog is plain text; it is stored on the template entity and in `manifest.json`. When an owner resets a demo to a newer active version, the reset form lists the changelogs of all ready versions between the demo's version and the active one, newest first.

## Storage

Versions live under the snapshot filesystem root:

```
<snapshot root>/_demo-templates/v<major.minor.patch>/
  manifest.json            # also stored on the soda_scs_demo_template entity
  wisski/                  # wisski-database.sql, wisski-triplestore.nq, wisski-volumes.tar.gz
  jupyter-home.tar.gz
  team-folder/
  webprotege-ontology.zip   # OWL functional syntax, root ontology plus imports
```

The manifest lists the exported apps, the Team Folder file count and SHA-256 checksums. Provisioning verifies all checksums before it touches the target.

## How a demo is created or reset

Sign-in of an `scs_user` without a demo calls the same creation as `POST /soda-scs-manager/demo-project/create`. It runs in `hook_openid_connect_post_authorize()`, not `hook_user_login()`: only then is the OIDC access token in the session, and with Nextcloud Bearer mode `SodaScsAutomaticDemoProject` uses it to store the user's Nextcloud app password first, but only when a demo template is active and the user has no demo yet. Linking an existing account to Keycloak fires the same hook without saving a token; then no demo is queued. The demo job runs in a background `drush queue:run` without the user's session, so the WissKI step can only use stored credentials. A sign-in without OpenID Connect (local account) gets no automatic demo. The project shell (name, Keycloak group, Team Folder) exists before the dashboard is rendered, so onboarding can point at the card. Filling the apps is queued. The reset form lives at `/soda-scs-manager/project/{id}/demo-reset` (owner or admin). Both only queue a job and set `demoStatus` (`provisioning`, `resetting`); the page polls `/soda-scs-manager/project/{id}/demo-status` and reloads when the job is done. Its `jobLine` comes from the job's progress operation (`soda_scs_operations`: status, `created`, `updated`) and its steps: queued since `created`, the current step while running, the first wait step and `updated` + 60 s while waiting (left out once that is a full delay overdue, i.e. the queue runner stopped), and the reason when the job fails. The worker stores steps as source strings, so the status translates them for each viewer (the queue runs in the site default language). On failure the last step is a `REASON_*` constant of `SodaScsDemoStatusBuilder`, or the step the job failed at, re-recorded if an earlier attempt already had it; the exception stays in the log. A job whose template version was deleted marks the project failed too. The worker writes each step once per job, so retries do not repeat them. There is no second demo per account. A missing active demo version does not block sign-in; the next sign-in tries again.

The queue worker (`soda_scs_manager_demo`) then:

1. Creates missing demo apps (WissKI, Jupyter, WebProtégé). A new WissKI needs a few minutes to install; until its install marker exists the job is requeued (up to one hour) before any data is overwritten. A WissKI stack without a WissKI component (left by a failed `createStack()`) is not waited for: the job fails at once with `SodaScsDemoStatusBuilder::REASON_WISSKI_INCOMPLETE`, and the user deletes that stack before resetting again. Deleting it removes only the stack entity: the SQL, triplestore and possibly WissKI components the failed creation made were never attached to the stack, so they stay in the project. `createStack()` does not roll them back yet; that needs its own issue.
2. Copies the Team Folder (purge, then copy through the rclone mount).
3. Stops the project Jupyter server and replaces its home volume.
4. Imports the WebProtégé ontology. On a reset the ontology project is trashed and created empty first, then every document of the zip is posted as axioms.
5. Restores the WissKI target-agnostically: it captures the instance-specific config of the target (Salz adapter, OpenID Connect, SSO bouncer, Nextcloud WebDAV mount, IIIF and 3D viewer settings), keeps the target's `settings*.php`/`services*.yml`, imports volumes, database and triplestore, writes the captured config back, sets the site name to the WissKI label, then runs `updatedb` and `cache:rebuild`.
6. Sets a new password on the local account `api-import` (created with the roles `wisski_user` and `wisski_api_import` when the template lacks it; a warning is logged then) and writes `~/.wisski-demo-api.env` (`WISSKI_USER`, `WISSKI_PASSWORD`, `WISSKI_BASE_URL`) into the project notebook home, mode 0600. The template export leaves that file out, so the source password is not copied.
7. Sets `demoTemplate` to the version and `demoStatus` to `ready`.

Every step is idempotent, so a failed job can simply be reset. Failures set `demoStatus` to `failed` and are logged on channel `soda_scs_manager`; the item is not retried endlessly.

Template builds share one lock (`soda_scs_manager.demo.template_build`); each project has its own lock. A busy lock requeues the item after 60 seconds.

## Queue runner

Demo jobs take minutes and do not run during Drupal cron. Building a version, creating a demo and resetting a demo spawn a background loop inside `manager--drupal--app` and return at once. The loop runs `drush queue:run soda_scs_manager_demo` and, while items are only delayed (a new WissKI is still installing), sleeps and tries again for up to two hours. A job that is already waiting is started with **Start queued jobs** on the demo admin page.

The page refreshes the status every 5 seconds while a version is building and reloads when the version becomes ready or failed. The runner's own output, including errors, is `/tmp/scs-demo-queue.log` in the manager container:

```bash
docker exec manager--drupal--app tail -f /tmp/scs-demo-queue.log
```

The same run from a shell:

```bash
scs-drush queue:list
scs-drush queue:run soda_scs_manager_demo --time-limit=900 --lease-time=7200
```

## Known limits

- Triplestore data keeps the URIs of the source WissKI domain; the Salz adapter of the demo WissKI points to its own repository, so the data is readable, but entity URIs show the source domain.
- WebProtégé keeps the ontology in its own project store. Forms, perspectives and the revision history of the source are not copied; the demo gets the axioms of the head revision.
- The ontology download needs the SCS endpoint `GET /data/projects/{id}/ontology` of the WebProtégé fork. Rebuild and recreate `webprotege--webprotege--app` after pulling that change.
- The source WissKI and the source Jupyter server are briefly offline while a version is built.
- The demo notebook reads `WISSKI_BASE_URL` from `~/.wisski-demo-api.env`. The central demo project needs that line in its own notebook home as well; user demos get a fresh file on every create and reset. The account `api-import` belongs in the source WissKI (roles `wisski_api_import` and `wisski_user`). Without it every demo creates the account itself and logs a warning.
