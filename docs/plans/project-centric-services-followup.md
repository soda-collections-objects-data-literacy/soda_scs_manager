# Follow-up: Project-centric service binding

This document captures work **not** done in the Team Folder MVP (SCS Manager + Keycloak + Nextcloud). Implement in a later change set.

## Context (already done)

- Each user gets a default project labelled **Project 1** / **Projekt 1**.
- Keycloak project group name = stable integer `groupId` (`entityId + 10000`).
- Group attributes include `gid`, `label`, `nextcloudTeamFolder`, `containedApps` (placeholder JSON `[]`).
- Nextcloud Team Folder: mount = editable label; `machineName` = `groupId`.
- Membership in SCS syncs Keycloak; OIDC group provisioning maps users into Nextcloud groups.

## Planned follow-ups

### 1. Vorhaben entity

- Introduce a **Vorhaben** content entity (or bundle) owned by / attached to a project.
- UI: create/list Vorhaben under a project; applications and resources can hang off Vorhaben if needed.
- Clarify wording vs Stack / Component (`Wording.md`).

### 2. WissKI / SQL / Triplestore

- Prefer project-group attributes (`containedApps` / dedicated keys) over ad-hoc Keycloak `-admin`/`-user` groups where possible.
- Ensure new SQL, Triplestore, and WissKI components always attach to the active project (`partOfProjects`).
- Do **not** change Docker images/services in the MVP sense without a dedicated ops plan; focus on Manager + Keycloak metadata and access grants.

### 3. JupyterHub project folders

- Provision a project-scoped directory or share tied to `groupId` / Keycloak group.
- Sync membership when SCS project members change.
- Decide whether personal Jupyter stacks remain “central” or become project-bound.

### 4. WebProtégé projects

- Bind WebProtégé projects to SCS projects (Keycloak group / attribute).
- Today default WebProtégé components are user-owned and not attached to the default project — revisit.

### 5. Migration of existing users

- **Runnable backfill procedure** (validated with `rnsrk`):  
  [`docs/technical/project-team-folder-backfill.md`](../technical/project-team-folder-backfill.md)
- Rename legacy labels (`"{displayname} standard project"` / `… default project`) toward Project 1 / user-chosen names (optional; documented in the backfill page).
- Backfill Nextcloud Team Folders + Keycloak attributes for remaining projects.
- Optionally clean legacy Nextcloud groups prefixed `keycloak-`.

### 6. UI: project-centric navigation

- Dashboard and catalogue emphasize project → members → Team Folder → connected apps.
- Hide or demote purely personal “central” stacks where product policy allows.

## Non-goals (unless explicitly scheduled)

- Reworking Docker Compose / image builds for WissKI, RDF4J, JupyterHub, WebProtégé solely for this redesign.
- Removing personal Nextcloud accounts (Team Folders complement them).
