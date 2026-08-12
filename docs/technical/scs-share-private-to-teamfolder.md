# Migrate private Skeleton SCS-Share into the Team Folder

Existing users created before the platform Team Folder often have a **private**
home folder named `SCS-Share` (from the Nextcloud skeleton). The Manager preview
and shared tooling use the **Team Folder** `SCS-Share`
(`externalProjectId=scs-platform-share`).

This page documents the one-time, programmatic content migration run on the
deployment host.

## Policy

- Destination: `SCS-Share/<nextcloud-uid>/…` inside the Team Folder
- Never overwrite on name conflict — append `.<timestamp>`
- After success: rename private folder to `SCS-Share-legacy-YYYYMMDD`
- **No deletes** in the migration job
- Always `--dry-run` before the first live run

## Command

Nextcloud app `scs_manager_integration`:

```bash
docker exec --user www-data nextcloud--nextcloud \
  php /var/www/html/occ scs_manager_integration:migrate-private-scs-share --help
```

| Option | Meaning |
|--------|---------|
| `--dry-run` | Report only |
| `--user=<uid>` | Single user |
| `--all` | All users with a private `…/files/SCS-Share` on disk |
| `--limit=N` | Cap users with `--all` |
| `--group=` | ACL group (default `keycloak-scs_user`) |

## Host runbook (executed)

Deployment root: `<deployment-root>`

1. Selective backup → `backups/scs-share-private-YYYYMMDD.tar.gz`
2. `occ scs_manager_integration:ensure-platform-share --admin=admin -g admin -g keycloak-scs_user`
3. Dry-run `--all`, pilot user, then `--all` live
4. Skeleton private folder disabled: `scs-nextcloud-stack/skeleton/_disabled_SCS-Share`
5. `Welcome.md` updated to describe the Team Folder

## Related

- Project Team Folder **registration** backfill (no file moves):
  [project-team-folder-backfill.md](project-team-folder-backfill.md)
- Deployment plan copy:
  `<deployment-root>/plans/06-scs-share-private-to-teamfolder.md`
