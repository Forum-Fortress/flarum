# Plugin Deploy Process (Locked)

This is the canonical process to build and publish forum plugins.

## Source of Truth

- Repo artifacts are built from `plugins/*/build_release.py`.
- Invision is **tar-only** for install and distribution.
- Flarum is delivered via Composer on Packagist and is managed from the
  `plugins/flarum/` extension source; it is not part of this command's artifact build.
- Control ships only `app/releases/ff-plugin-releases.json` in deploy tarballs (no plugin binaries); the app resolves download URLs to the marketing site and can fetch that manifest over HTTPS if needed. Legacy `/plugin-download/*` routes redirect to `marketing_base_url/downloads/...` when configured.
- Marketing web host serves static downloads at `/opt/forumfortress-site/public/downloads` (including `ff-plugin-releases.json`) and the MkDocs site at `/opt/forumfortress-site/public-docs` (see `deploy/Caddyfile`). Older versioned artifacts are moved to `/opt/forumfortress-site/plugin-archive` (outside the docroot) when you run `app/scripts/publish_plugins.sh`.

## One Command

From the repo root:

```bash
cd app
./scripts/publish_plugins.sh
```

## What it does

1. Builds XenForo/phpBB/Invision/SMF artifacts.
2. Publishes versioned archives under `/downloads/` on the web host plus `ff-plugin-releases.json` (same directory).
3. Removes any Invision `.zip` artifacts from local and web.
4. Verifies `invision.tar` metadata on the web host.

## Required Access

- Local SSH to the static web host (`WEB_SSH`, default `root@web.forumfortress.com`).

## Environment Overrides

- `WEB_SSH` (default: `root@web.forumfortress.com`)
- `WEB_DOWNLOADS_DIR` (default: `/opt/forumfortress-site/public/downloads`)

## Per-platform notes

- **Invision (IPB)** has additional packaging and ownership constraints. See
  `app/docs/PLUGIN_DEPLOY_PROCESS_IPB.md` for the locked IPB-specific process,
  including the USTAR archive requirement, the `chown` step needed for
  upgrade-time chmod to succeed, and the verification queries.

## Notes

- If plugin names/copy on the static website UI are manually managed, update those separately on web after file publish.
- Do not upload Invision ZIP files anywhere.
- Invision tar **must** be built with `tarfile.USTAR_FORMAT`. GNU/PAX magic strings
  (`ustar  \0` or extended `PaxHeader/` entries) are rejected by `PharData` on
  stricter PHP builds and surface as `1C133/9` in the IPB ACP installer. Verify
  with `od -An -c -j 257 -N 8 <tar>` - must read `u s t a r \0 0 0`.
- Invision `1C133/9` on **upgrade** is almost always an ownership mismatch on
  the customer's server, not the archive. `PharData::extractTo()` chmods every
  extracted file; if the destination file already exists and is owned by a
  different user than the PHP process, chmod returns `EPERM` and PharException
  bubbles up as 1C133/9. Customer fix:
  `chown -R <php-user>:<php-group> /path/to/applications/forumfortress`. This
  guidance is documented in `plugins/invision/README.md`.
