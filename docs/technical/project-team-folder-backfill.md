# Backfill: Project Team Folders for existing users

Existing projects created **before** the Team Folder MVP have a Keycloak group and Drupal project, but usually **no** Nextcloud Team Folder and incomplete Keycloak attributes (`nextcloudTeamFolder`, `containedApps`).

This page records what was required for a successful backfill (validated with user `rnsrk` / project `1` / groupId `10001`) so the same steps can be repeated for other users.

**Preferred path:** Drush command below (Plan 07). The php:eval snippet remains as a manual fallback.

## Prerequisites

- Nextcloud app `scs_manager_integration` enabled (`occ app:list` shows it).
- Nextcloud admin credentials configured in SCS Manager settings (used by `buildCreateProjectFolderRequest` / admin folder list).
- `user_oidc` provider has **group provisioning** enabled:
  - scope includes `groups`
  - `--group-provisioning=1`
  - `--mapping-groups=groups`
- From deployment root, `scs-drush` reaches `scs-manager--drupal`.

## What “done” looks like

For a project with Drupal id `P` and `groupId` `G` (computed: `P + 10000`):

| Check | Expected |
|-------|----------|
| Keycloak group name | `G` (e.g. `10001`) |
| Keycloak attributes | `gid=[G]`, `label=[…]`, `nextcloudTeamFolder=[G]`, `containedApps=["[]"]` |
| Keycloak members | Project owner (+ members) |
| Nextcloud Team Folder mount | Project **label** (visible name) |
| Team Folder ACL group | `G` (plain integer name, **not** `keycloak-G`) |
| `scs_manager_integration` row | `machineName=G`, `externalProjectId=P` |
| Nextcloud user in group `G` | After OIDC re-login, or `--grant-nc-access` / manual `occ group:adduser` |

## Drush batch (preferred)

```bash
cd <deployment-root>

# Inventory (no writes)
scs-drush soda_scs_manager:backfill-project-team-folders --dry-run --all

# Single project
scs-drush soda_scs_manager:backfill-project-team-folders --project=6

# All projects (idempotent: existing Team Folders only refresh attrs/ACL/label)
scs-drush soda_scs_manager:backfill-project-team-folders --all

# Optional: immediate Nextcloud visibility without waiting for OIDC re-login
scs-drush soda_scs_manager:backfill-project-team-folders --all --grant-nc-access
```

Alias: `scs-backfill-project-tf`.

Per project the command runs, in order:

1. **`updateProjectGroupAttributes`** — writes/updates Keycloak attrs without renaming the group (`name` stays `groupId`).
2. **`syncKeycloakGroupMembers`** — ensures owner/members are in the Keycloak project group.
3. **`createProjectTeamFolder`** — creates Team Folder + registration; skips if `externalProjectId` already exists.
4. **`updateProjectTeamFolderLabel`** — re-syncs mount label and group ACL.
5. With **`--grant-nc-access`**: `occ group:adduser G <keycloak-sub>` for owner and members.

Labels are **not** renamed. Errors are logged per project; the batch continues.

## Backfill one project (php:eval fallback)

Replace `PROJECT_ID` (Drupal project entity id). Example for `rnsrk`: `1`.

```bash
scs-drush php:eval '
$projectId = 1; // PROJECT_ID
$ph = \Drupal::service("soda_scs_manager.project.helpers");
$p = \Drupal::entityTypeManager()->getStorage("soda_scs_project")->load($projectId);
if (!$p) { throw new \RuntimeException("project not found"); }
echo "project={$p->id()} label={$p->label()} groupId=".$p->get("groupId")->value."\n";

$r1 = $ph->updateProjectGroupAttributes($p);
echo "updateProjectGroupAttributes: ".($r1->success ? "OK" : $r1->error)."\n";

$r2 = $ph->syncKeycloakGroupMembers($p);
echo "syncKeycloakGroupMembers: ".($r2->success ? "OK" : $r2->error)."\n";

$r3 = $ph->createProjectTeamFolder($p);
echo "createProjectTeamFolder: ".($r3->success ? "OK" : $r3->error)."\n";
echo "  feedId=".($r3->data["folderId"] ?? "?")." machine=".($r3->data["folder"]["machineName"] ?? "?")." label=".($r3->data["folder"]["label"] ?? "?")."\n";

// Idempotent ACL/label refresh (safe if folder already exists).
$r4 = $ph->updateProjectTeamFolderLabel($p);
echo "updateProjectTeamFolderLabel: ".($r4->success ? "OK" : $r4->error)."\n";
'
```

## Immediate Nextcloud access (optional)

OIDC group provisioning adds the user to Nextcloud group `G` on **next login**. Prefer `--grant-nc-access` on the Drush command. Manual equivalent:

```bash
# Resolve Nextcloud UID (often the Keycloak user UUID under user_oidc).
docker exec --user www-data nextcloud--nextcloud php /var/www/html/occ user:list | grep -i DISPLAY_NAME

# Add to project group (G = groupId, e.g. 10001).
docker exec --user www-data nextcloud--nextcloud php /var/www/html/occ group:adduser G NEXTCLOUD_UID
```

Validated for `rnsrk`:

- Nextcloud UID: `6d2b77b1-360b-4f8f-833d-16d4a075ce7f`
- `occ group:adduser 10001 6d2b77b1-360b-4f8f-833d-16d4a075ce7f`

Afterwards the user should see the Team Folder named like the project label (e.g. `rnsrk default project`) in Files.

## Verification commands

```bash
# Team Folders + ACL group
docker exec --user www-data nextcloud--nextcloud php /var/www/html/occ groupfolders:list

# Managed folder registration (via Drupal admin API)
scs-drush php:eval '
$h = \Drupal::service("soda_scs_manager.nextcloud_service.actions");
$res = $h->makeRequest($h->buildAdminProjectFoldersRequest([]));
$body = (string) $res["data"]["nextcloudResponse"]->getBody()->getContents();
foreach (json_decode($body, true)["ocs"]["data"]["folders"] ?? [] as $f) {
  echo ($f["externalProjectId"] ?? "?") . " machine=" . ($f["machineName"] ?? "?")
    . " label=" . ($f["label"] ?? "?") . "\n";
}
'

# Keycloak group attributes (set PROJECT_ID)
scs-drush php:eval '
$p = \Drupal::entityTypeManager()->getStorage("soda_scs_project")->load(1);
$ph = \Drupal::service("soda_scs_manager.project.helpers");
$token = $ph->getKeycloakToken();
$ga = \Drupal::service("soda_scs_manager.keycloak_service.group.actions");
$req = $ga->buildGetRequest([
  "token" => $token,
  "routeParams" => ["groupId" => $p->get("keycloakUuid")->value],
  "queryParams" => ["briefRepresentation" => "false"],
]);
$res = $ga->makeRequest($req);
$g = json_decode((string) $res["data"]["keycloakResponse"]->getBody()->getContents(), true);
echo "name={$g["name"]}\n";
echo json_encode($g["attributes"] ?? [], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), "\n";
'
```

## Notes

- Do **not** use legacy Nextcloud groups named `keycloak-10001`; ACL must use plain `10001`.
- Content migration of private skeleton `SCS-Share` is **out of scope** here (see `docs/technical/scs-share-private-to-teamfolder.md` / Plan 06).
- Adding a user to a project only syncs Keycloak/OIDC membership for folders that **already** exist. Each legacy project still needs the Team Folder backfill once.

### Optional: rename default label to “Projekt 1”

Backfill does **not** rename existing labels (e.g. `rnsrk default project`). To align with new defaults:

```bash
scs-drush php:eval '
$p = \Drupal::entityTypeManager()->getStorage("soda_scs_project")->load(1);
$p->set("label", (string) t("Project 1"));
$p->save();
$ph = \Drupal::service("soda_scs_manager.project.helpers");
$ph->updateProjectGroupAttributes($p);
$ph->updateProjectTeamFolderLabel($p);
'
```

## Reference implementation (rnsrk)

Executed 2026-08-07:

1. Project `1`, groupId `10001`, Keycloak UUID `38d2bf74-2caf-45a5-87f9-135b0e8f5f2a`.
2. Attributes updated to include `nextcloudTeamFolder` + `containedApps`.
3. Team Folder created: groupFolderId `6`, feed id `3`, mount `rnsrk default project`, machine `10001`, ACL group `10001`.
4. Nextcloud user added to group `10001` via `occ group:adduser` for immediate access.

## Reference implementation (robert — member visibility)

Also 2026-08-07: `rnsrk` was already a **member** of Robert’s project `20` (`robert default project`, groupId `10020`), but no Team Folder existed yet — membership alone does not create folders for legacy projects.

1. Same four helper calls for project `20`.
2. Team Folder created: groupFolderId `7`, feed id `4`, mount `robert default project`, machine `10020`, ACL `10020`.
3. Immediate NC group membership: `occ group:adduser 10020` for `rnsrk` and both Robert Nextcloud UIDs; also `10001` for Robert so he sees `rnsrk default project`.

Helpers used (no Docker service changes):

- `\Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers::updateProjectGroupAttributes()`
- `::syncKeycloakGroupMembers()`
- `::createProjectTeamFolder()`
- `::updateProjectTeamFolderLabel()`
- Drush: `soda_scs_manager:backfill-project-team-folders`
