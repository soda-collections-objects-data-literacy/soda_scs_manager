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

1. **Use as demo project** – choose the source project. It is renamed to "Demo Projekt" (Keycloak group label and Team Folder label follow) and handed to the service account `scs_demo` in the background (see [Source owner](#source-owner-scs_demo)); the source WissKI restarts once. Projects that are themselves user demos cannot be chosen.
2. **Build new version** – queues a template build with a version and a changelog (both required). During the export the source WissKI container and the project Jupyter server are stopped for a few minutes. A source WissKI that has not finished its installation (no `sites/.wisski-install-complete`, or `sites/.wisski-install-in-progress` present) fails the build before anything is stopped. After the WissKI container starts again its Varnish container is restarted, because Varnish resolves the backend host name only when it loads the VCL (`SodaScsWisskiVarnishCache::restart()`; the demo restore and WissKI snapshot create and restore do the same). The Team Folder copy skips `.ipynb_checkpoints` in both directions.
3. **Activate** – a finished version becomes the one new demos and resets use. The first finished version is activated automatically.
4. **Delete** – removes a version and its files. The active version and versions still referenced by demo projects cannot be deleted.

## Source owner: scs_demo

The source project and its applications belong to the service account `scs_demo`, not to the admin who chose it, so deleting a personal account never deletes the demo source.

- **Account.** `drush soda_scs_manager:demo-account` (`SodaScsDemoServiceAccount::ensure()`) creates or updates the Drupal account, a Keycloak user with the password from `SCS_DEMO_ACCOUNT_PASSWORD` (optional e-mail: `SCS_DEMO_ACCOUNT_EMAIL`, both in the manager container environment), the OpenID Connect link, and a Nextcloud account via `occ` with the Keycloak id as user id plus an app password for the Team Folder mount. Admins can sign in as `scs_demo`. The e-mail address in Keycloak and Drupal is set to `SCS_DEMO_ACCOUNT_EMAIL` on every run (empty = none). An existing Keycloak user `scs_demo` is only used when the Drupal account is already linked to it; otherwise the command fails and the Keycloak user has to be deleted or renamed. `scs_demo` and `scs-demo` are reserved user names. Update 11043 only creates the Drupal account; the transfer job runs `ensure()` before it starts.
- **Protection.** `SodaScsProtectedAccounts::isProtected()` covers uid 1 and `scs_demo`: `hook_user_access()` forbids `delete` (admin cancel form, bulk cancel action), the cancel forms and the profile delete form refuse it, offboarding skips it, and `SodaScsProtectedAccountHooks` throws in `hook_user_cancel()` and `hook_user_predelete()`, ordered first, for paths without an access check (`drush user:cancel`): openid_connect would otherwise remove the SSO link before the account is blocked. No automatic demo project is created for it.
- **Before the handover.** Until the transfer job has worked, the source still belongs to the admin who chose it. The demo page then shows a warning with the last step of the job (`SodaScsDemoManager::sourceOwnerNotice()`; the operation id is stored like a project job's). Deleting that admin is refused: the cancel forms (`soda_scs_manager_user_cancel_validate_demo_source()`) and the profile delete form check `SodaScsUserOffboarding::ownsDemoSource()`, `ownedProjectIds()` leaves the source out of the delete batch, and `purge()` throws in `hook_user_predelete()`, which rolls the delete back.
- **Transfer.** `SodaScsProjectOwnershipTransfer::transfer()` moves a project with the stacks and components its owner owns (applications a member added keep their owner; an application shared with another project stops the transfer). Under the project lock it
  1. gives the new owner access: MariaDB account and grants (`SodaScsProjectDbAccessHelpers::ensureProjectDbAccess()`), OpenGDB account, token and repository rights (added, never replaced), WebProtégé edit right, the project's Nextcloud group;
  2. per WissKI, holding its update lock (`soda_scs_manager.wisski_update.<id>`, the lock of `SodaScsWisskiUpdateQueueWorker`; a running update stops the transfer before any change): records the deployment, replaces the database account in `settings.php` (`SodaScsWisskiDatabaseCredentials`, the entrypoint writes it only on install), redeploys the stack with `DB_*`, `TS_*` and `NEXTCLOUD_USER_MOUNT_SOURCE` of the new owner (`SodaScsWisskiComponentActions::redeployWithEnvironment()`), and writes the new token into the Salz adapter. A failed step or an exception undoes the earlier ones of that and of the previous WissKIs (old `settings.php`, `restoreDeployment()`, old token);
  3. in one transaction: owner of components, stacks and their snapshots, the service key references (the WissKI admin password key is copied to the new owner; when the new owner already has a different one, the WissKI keeps its password and the transfer returns a warning), owner and members of the project. The previous owner becomes a member with the admin role on the WissKIs, so Keycloak keeps them in the `-admin` group;
  4. syncs the Keycloak groups.

  Nothing of the previous owner is deleted. The demo queue runs it as operation `transfer_source` under the template build lock; `drush soda_scs_manager:project-transfer <project> <account>` runs it for any project.

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
  manifest.json            # also on the soda_scs_demo_template entity; formatVersion 1
  wisski/                  # wisski-database.sql, wisski-triplestore.nq, wisski-volumes.tar.gz
  jupyter-home.tar.gz
  team-folder/
  webprotege-ontology.zip   # OWL functional syntax, root ontology plus imports
```

The manifest lists the exported apps, the Team Folder file count, the base URIs of the source WissKI (`apps.wisski.uriBases`) and SHA-256 checksums of every file except `manifest.json` itself. Instance ids in it (`sourceProjectId`, `apps.*.sourceStackId`, `apps.*.sourceComponentId`) are for information only. Provisioning verifies all checksums before it touches the target (`SodaScsDemoTemplateArchive::damagedFiles()`).

## Export and import

A ready version can be moved to another site (`SodaScsDemoTemplateArchive`, in the manager container):

```bash
# On the source site: writes _demo-templates/_exports/demo-v1.2.5.tar.gz and .sha256
docker exec -u www-data manager--drupal--app drush soda_scs_manager:demo-export 1.2.5
# On the target site, after copying both files to <snapshot root>/_demo-templates/_incoming/
docker exec -u www-data manager--drupal--app drush soda_scs_manager:demo-import \
  /var/scs-manager/snapshots/_demo-templates/_incoming/demo-v1.2.5.tar.gz --activate
docker exec -u www-data manager--drupal--app drush soda_scs_manager:demo-versions
```

The archive holds `v{version}/` with `manifest.json`, rewritten on every export. Versions built before 4.8 do not list the base URIs of their source WissKI; the export reads them from that WissKI (`SodaScsWisskiUriRewriter::templateSourceBases()`) and refuses when it is gone, because demos on the other site would keep this site's URIs. The import refuses, before anything is written:

- a name other than `demo-v{major.minor.patch}.tar.gz`, a missing `.sha256` file or a checksum that does not match;
- a version that is not higher than every existing one (the build rule, `SodaScsDemoManager::validateNewVersion()`);
- an entry outside `v{version}/` (absolute, `..`) or one that is not a plain file or directory (links, devices).

It unpacks into `_demo-templates/_import-{random}/` (`--no-same-owner`), removes a failed build of the same version (as a new build does), checks `formatVersion`, the version in the manifest, every checksum, that no file is unlisted, that every app entry points at listed files and that `teamFolder.directory` is `team-folder`, gives the files to www-data, moves the directory to `v{version}/` and creates the version as ready with `origin` = `imported` and no source project (update 11044 added the field; existing versions are `built`). Any failure removes the temporary and the moved directory. The demo page marks imported versions; activate and delete work as for built ones. A site without its own demo source project runs demos from imported versions only. Demo WissKIs from an imported version get their own URIs from `uriBases` in the manifest; for versions built before 4.8 without it, the restore reads the bases from the source WissKI while that still exists on the building site, and otherwise keeps the source URIs (notice in the log).

## How a demo is created or reset

Sign-in of an `scs_user` without a demo calls the same creation as `POST /soda-scs-manager/demo-project/create`. It runs in `hook_openid_connect_post_authorize()`, not `hook_user_login()`: only then is the OIDC access token in the session, and with Nextcloud Bearer mode `SodaScsAutomaticDemoProject` uses it to store the user's Nextcloud app password first, but only when a demo template is active and the user has no demo yet. Linking an existing account to Keycloak fires the same hook without saving a token; then no demo is queued. The demo job runs in a background `drush queue:run` without the user's session, so the WissKI step can only use stored credentials. A sign-in without OpenID Connect (local account) gets no automatic demo. The project shell (name, Keycloak group, Team Folder) exists before the dashboard is rendered, so onboarding can point at the card. Filling the apps is queued. The reset form lives at `/soda-scs-manager/project/{id}/demo-reset` (owner or admin). Both only queue a job and set `demoStatus` (`provisioning`, `resetting`); the page polls `/soda-scs-manager/project/{id}/demo-status` and reloads when the job is done. While `demoStatus` is busy, `SodaScsProjectViewBuilder` marks the application cards and the Files section `#is_pending`: the cards lose their link to the app, the Files section shows a note instead of the button, and the onboarding tour swaps its click hints for a wait note. Its `jobLine` comes from the job's progress operation (`soda_scs_operations`: status, `created`, `updated`) and its steps: queued since `created`, the current step while running, the first wait step and `updated` + 60 s while waiting (left out once that is a full delay overdue, i.e. the queue runner stopped), and the reason when the job fails. The worker stores steps as source strings, so the status translates them for each viewer (the queue runs in the site default language). On failure the last step is a `REASON_*` constant of `SodaScsDemoStatusBuilder`, or the step the job failed at, re-recorded if an earlier attempt already had it; the exception stays in the log. A job whose template version was deleted marks the project failed too. The worker writes each step once per job, so retries do not repeat them. There is no second demo per account. A missing active demo version does not block sign-in; the next sign-in tries again.

The queue worker (`soda_scs_manager_demo`) then:

1. Creates missing demo apps (WissKI, Jupyter, WebProtégé). A new WissKI needs a few minutes to install; until its install marker exists the job is requeued (up to one hour) before any data is overwritten. A failed `createStack()` removes what it created: its components in reverse order through their component actions (database, repository, Portainer stack, Keycloak client and groups with them), then the stack; the SQL and triplestore `createComponent()` remove their own database or repository when a later step of theirs fails. A component whose delete reports a failure stays in the stack, and so does the stack. Such a stack, or one left by an older release, has no WissKI component and is not waited for: the job fails at once with `SodaScsDemoStatusBuilder::REASON_WISSKI_INCOMPLETE`, and the user deletes that stack before resetting again. `deleteStack()` then also deletes the Keycloak client `wisski-{name}` and its groups, unless a WissKI component or another WissKI stack uses the name, or the client carries another label (a raw machine name may itself start with `stack-`, so the name without it is only used for a client with the stack's label).
2. Copies the Team Folder (purge, then copy through the rclone mount).
3. Stops the project Jupyter server and replaces its home volume.
4. Imports the WebProtégé ontology. On a reset the ontology project is trashed and created empty first, then every document of the zip is posted as axioms.
5. Restores the WissKI target-agnostically: it captures the instance-specific config of the target (Salz adapter, OpenID Connect, SSO bouncer, Nextcloud WebDAV mount, IIIF and 3D viewer settings), keeps the target's `settings*.php`/`services*.yml` and its install state in `sites/` (`.wisski-install-*`, `.wisski-packages-version`), imports volumes, database and triplestore (with the base URIs rewritten, see [URIs](#uris-of-the-copied-wisski)), deletes the OpenID Connect clients the template brought along that the target did not have (otherwise the login page shows a second SSO button for the source's Keycloak client), writes the captured config back (the login and logout redirects of `openid_connect.settings` stay as imported from the template, e.g. `/wisski_home`), writes the current triplestore token of the owner into the Salz adapter (`SodaScsWisskiTriplestoreTokenSync`; OpenGDB issues one token per account, and a new token makes the captured one invalid), sets the site name to the WissKI label, then runs `updatedb` and `cache:rebuild`.
6. Sets a new password on the local account `api-import` (created when the template lacks it, with a warning in the log; the roles `wisski_user` and `wisski_api_import` are added in both cases, and the job fails when the WissKI lacks one of them) and writes `~/.wisski-demo-api.env` (`WISSKI_USER`, `WISSKI_PASSWORD`, `WISSKI_BASE_URL`) into the project notebook home, mode 0600. The template export leaves that file out, so the source password is not copied, and also the Jupyter runtime files and secrets, the IPython history, the collaboration state, the lab workspaces and `~/.cache` (`SodaScsDemoTemplateBuilder::JUPYTER_HOME_EXCLUDES`).
7. Sets `demoTemplate` to the version and `demoStatus` to `ready`.

Every step is idempotent, so a failed job can simply be reset. Failures set `demoStatus` to `failed` and are logged on channel `soda_scs_manager`; the item is not retried endlessly.

Template builds share one lock (`soda_scs_manager.demo.template_build`); each project has its own lock. A busy lock requeues the item after 60 seconds.

Deleting a project (`SodaScsProjectDeleteForm`, demo or not) runs as a batch: one request per application (`SodaScsProjectApplicationRemover::removeNextApplication()`), so a project with many WissKI instances does not run into the request timeout, then a last step that runs `SodaScsProjectApplicationRemover::removeApplications()` again (for an application added meanwhile; it also drops the components detached in earlier requests from `connectedComponents`) and deletes the rest. The applications are removed in this order: the WissKI stacks of the project owner (a failed demo run can leave more than one) are deleted through `SodaScsStackActions::deleteStack()`, with their database and triplestore, then the loose SQL and triplestore components (no `partOfStack`, or one whose stack is gone) under the same rule through `SodaScsComponentActions::deleteComponent()`. A WissKI, database or triplestore of another member stays and only loses this project in `partOfProjects` (a kept stack takes its components along); the remover also drops it from the project's `connectedComponents`, because `_soda_scs_manager_sync_project_component_relationship()` links every listed component to the project again when the project is saved or deleted. An application belongs to at most one project (`soda_scs_manager_entity_presave()`). `deleteStack()` keeps the stack and returns `success` FALSE when one of its components cannot be deleted. The first failure, or a failed lookup of the applications, stops there: members, JupyterHub, the Team Folder, the Keycloak group and the project stay; members are detached only after the applications are gone. The account delete uses the same remover but goes on after a failure: `soda_scs_manager_batch_alter()` replaces every `_user_cancel` operation that deletes an account (cancel forms, confirmation link, profile delete form through `user_cancel()`, drush) by `SodaScsUserOffboardingBatch::removeApplications()` and `SodaScsUserOffboardingBatch::deleteAccount()`. The first takes the project lock of every owned project through `lock.persistent` (`SodaScsUserOffboarding::lockProjectsForAccountDelete()`), removes one application per request (`SodaScsUserOffboarding::removeNextProjectApplication()`) and moves on to the next project when one fails. When a lock is busy (the confirmation link and drush do not run the form validators), it removes nothing and `deleteAccount()` refuses with a message. Otherwise `deleteAccount()` runs `_user_cancel()` with the locks still held (`SodaScsUserOffboarding::holdProjectLocks()`, so `purge()` does not take them again) and releases them afterwards. The locks expire after an hour and are not renewed: an account delete batch that runs longer loses them. `hook_user_predelete` (`SodaScsUserOffboarding::deleteOwnedProject()`) then runs the remover once more for what is left, including the failed ones; an application of the user that still fails is retried by `purgeLeftovers()`. The delete of a project takes the project lock first (`SodaScsDemoManager::PROJECT_LOCK_PREFIX`, for a demo project the lock of the queue job; for any project it keeps a second delete out) through `lock.persistent`, so it lasts across the batch requests, and releases it in the batch finish callback; an abandoned batch lets it expire after an hour. While the lock of an owned project is held, the profile delete and the account cancel forms refuse with "being set up or deleted" (`SodaScsUserOffboarding::lockedProjects()`, for every owned project, demo or not). It holds the lock until the project is gone; with the lock held it reloads the project, so fields a job attempt saved just before (e.g. `webprotegeProjectId`) are deleted too. While a job attempt runs, the delete is refused; a job that starts meanwhile is requeued and then drops itself, because its project no longer exists. A job that waits between attempts (WissKI still installing) does not hold the lock; its stack is already part of the project and is deleted with it.

Deleting an account (`hook_user_predelete`, `SodaScsUserOffboarding::purge()`) deletes its demo project under the same lock and reloads the project unchanged with the lock held, as the project delete does. The profile delete form and the admin account cancel forms refuse while it is held (`SodaScsUserOffboarding::lockedProjects()`) and name every locked project in one message. The hook itself cannot refuse: when the lock is still busy there (e.g. `drush user:cancel`), it logs a warning with the project and deletes anyway, so apps the running attempt creates afterwards stay behind. The hook runs inside the transaction of the account delete, so its lock row is not committed until the account is gone: a job that starts meanwhile is not requeued at once but waits on that row (MariaDB `innodb_lock_wait_timeout`, 50 s by default, twice with the retry of `acquire()`), and with it the queue runner. Afterwards it finds the project gone and drops itself, or is requeued after a timeout.

## URIs of the copied WissKI

A WissKI mints entity URIs and its graphs (`{base}originatesFrom`, `{base}baseFields`) under its default graph (`WISSKI_DEFAULT_GRAPH`, `https://{machine name}.{SCS host}/contents/`) and links each entity to its page under the site domain (`owl:sameAs <https://{DRUPAL_DOMAIN}/wisski/navigate/{id}/view>` in `{default graph}originatesFrom`). `Sparql11Engine::getDrupalIdForUri()` reads the entity id from such a navigate link in its own `originatesFrom` graph. `SodaScsWisskiUriRewriter` maps the two bases of the source to the target on create and reset:

- **Bases.** The template build reads `WISSKI_DEFAULT_GRAPH` and `DRUPAL_DOMAIN` of the source WissKI into the manifest (`apps.wisski.uriBases`, `graph` and `site`); invalid bases are dropped on restore. Versions built before 4.8 have none: the restore reads them from the source WissKI (`apps.wisski.sourceStackId`) while it still exists on the building site, and otherwise keeps the URIs (notice in the log).
- **Only the source's own bases.** Data copied into the source from another WissKI carries that WissKI's bases, and its `originatesFrom` graph holds navigate links with *that* site's entity ids. Rewritten into the target's `originatesFrom` graph, they made navigate links point at two entities (44 on dev) and could resolve wrong ids, so such URIs stay as foreign identifiers.
- **Triplestore.** A rewritten copy of `wisski-triplestore.nq` is written to the temp directory line by line (`strtr()`, in IRIs and in literals such as file URLs), imported and deleted. The template file stays unchanged.
- **Database.** After the import, a `drush php:eval` script in the target rewrites `wisski_salz_id2uri.uri`, `wisski_datatable.wisski_uri` and `wisski_entity_field_properties` (`ident`, and `properties` unserialized without classes and serialized again: a text replacement in the SQL dump would break the string lengths of serialized values), then every config object (e.g. the IIIF server and 3D viewer URLs); the closing `cache:rebuild` of the restore makes it visible. The instance config and the triplestore token are written back afterwards and win where both touch the same keys; they are written back also when waiting for drush or the rewrite fails, because the imported database holds the Salz adapter and SSO config of the source (`SodaScsDemoWisskiTransfer::restoreInstance()`).

Check on a demo WissKI (in its container): `SELECT (COUNT(*) AS ?n) WHERE { GRAPH ?g { ?s ?p ?o } FILTER(CONTAINS(CONCAT(STR(?s), STR(?o), STR(?g)), "<source host>")) }` returns 0.

## Queue runner

Demo jobs take minutes and do not run during Drupal cron. Building a version, creating a demo and resetting a demo spawn a background loop inside `manager--drupal--app` and return at once. The loop runs `drush queue:run soda_scs_manager_demo` and, while items are only delayed (a new WissKI is still installing), sleeps and tries again for up to two hours. A job that is already waiting is started with **Start queued jobs** on the demo admin page.

`SodaScsDemoQueueRunner::start()` counts the start as done when the `queue:run` loop is in the process list or the queue already holds fewer items than before the spawn: a job refused at once finishes before the process check, and the loop has exited by then. The page refreshes the status every 5 seconds while a version is building and reloads when the version becomes ready or failed. The runner's own output, including errors, is `/tmp/scs-demo-queue.log` in the manager container:

```bash
docker exec manager--drupal--app tail -f /tmp/scs-demo-queue.log
```

That file belongs to `www-data` (web requests and the queue timer). A run that another user starts, for example drush as root, writes `/tmp/scs-demo-queue-{user}.log` instead (`/tmp/scs-demo-queue-root.log`): `/tmp` is sticky and `fs.protected_regular` keeps even root from opening another user's file there, so a shared file would stop the run before it starts. A uid without a passwd entry (`docker exec -u 1234 …`) writes `/tmp/scs-demo-queue-1234.log`. The error message names the file that was used; the system check's stale-queue warning points to `/tmp/scs-demo-queue*.log`.

The same run from a shell:

```bash
scs-drush queue:list
scs-drush queue:run soda_scs_manager_demo --time-limit=900 --lease-time=7200
```

## Known limits

- The URI rewrite (above) covers the two bases of the source; URIs minted by WissKIs the source was copied from stay. URIs typed by hand into other fields, the title n-grams (`wisski_title_n_grams`, rebuilt when an entity is saved), revision logs and the watchdog keep the source domain.
- WebProtégé keeps the ontology in its own project store. Forms, perspectives and the revision history of the source are not copied; the demo gets the axioms of the head revision.
- WebProtégé cannot export a project without revisions. Before the download, `SodaScsWebprotegeHelpers::ensureInitialRevision()` gives such a source project a first revision ("Initial revision created by the SCS Manager", it declares `rdfs:label`), so the export also writes into an otherwise empty source project.
- The ontology download needs the SCS endpoint `GET /data/projects/{id}/ontology` of the WebProtégé fork. Rebuild and recreate `webprotege--webprotege--app` after pulling that change.
- The source WissKI and the source Jupyter server are briefly offline while a version is built.
- The demo notebook reads `WISSKI_BASE_URL` from `~/.wisski-demo-api.env`. The central demo project needs that line in its own notebook home as well; user demos get a fresh file on every create and reset. The account `api-import` belongs in the source WissKI (roles `wisski_api_import` and `wisski_user`). Without it every demo creates the account itself and logs a warning.
