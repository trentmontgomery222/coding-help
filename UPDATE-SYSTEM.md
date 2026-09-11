# ACPS Sitemap — self-hosted update system

This plugin can show **“Update now”** on the Plugins screen (and optionally
auto-update) even though it is not on the WordPress.org directory, pulling new
versions from a source you control — while protecting the site from a bad
release. It is adapted from the same mechanism used in the ACPS Site Toolkit
(Cayden Form Manager); this file documents it as it exists **in this plugin**,
with this plugin’s option names and URLs.

> **This is a private developer note.** It lives at the repository root and is
> deliberately kept OUT of the shipped plugin zip, so the distributed plugin
> carries no reference to the update system.

## The controls are hidden

There is **no visible link, tab, menu item, or mention** of the update settings
anywhere in the WordPress admin. The panel renders only when you type its URL
directly — the settings page with `&acps_updates=1` appended:

```
/wp-admin/options-general.php?page=acps-sitemap&acps_updates=1
```

Without that query flag, **Settings → ACPS Sitemap** shows only the sitemap
options. Saving the update settings, or using “Check for updates now”, keeps you
on that hidden URL. The normal Plugins-screen “Update now” row still appears when
an update is available (that is the point of the feature); everything else about
it is out of sight.

## The pieces

| File | Role |
|---|---|
| `acps-sitemap.php` | Bootstrap + crash-safe “safe mode” (`acps_sitemap_boot()`, `acps_sitemap_shutdown_guard()`) |
| `includes/class-acps-sitemap-updater.php` | The whole updater |
| `includes/class-acps-sitemap.php` | `update_*` settings defaults + activation seeding of the force-update secret |
| `includes/class-acps-sitemap-admin.php` | The **Updates** panel UI + “Check for updates now” |
| `uninstall.php` | Removes the update options/transients |

## Settings (stored inside `acps_sitemap_settings`)

| Key | Purpose |
|---|---|
| `update_enabled` | Master switch (also gates the force-update URL) |
| `update_auto` | Install updates in the background |
| `update_source` | `github` or `url` |
| `gh_owner` / `gh_repo` / `gh_asset` / `gh_token` | GitHub Releases source (token only for private repos) |
| `update_manifest` / `update_manifest_key` | JSON manifest URL + optional `?key=` |
| `update_role` | `standalone` \| `dev` \| `production` (staged rollout) |
| `verify_status_url` / `verify_status_key` | Production → dev verification link |
| `update_trigger` | Secret for the force-update URL **and** the crash-test marker (seeded on activation) |

Options/transients outside the settings array:
`acps_sitemap_update_remote` (cached lookup, 6 h ok / 15 min fail),
`acps_sitemap_devstatus` (cached dev status, 10 min),
`acps_sitemap_verified` (`{version,time}` a dev install published),
`acps_sitemap_update_failed` (`{when,version}` after a rollback),
`acps_sitemap_safe_mode` (`{msg,file,line,time}` — plugin parked after a fatal).

## Two sources

### GitHub Releases (`update_source = github`)
Set **owner**, **repo**, and the exact **asset filename** (default
`acps-sitemap.zip`). The release tag (minus a leading `v`) is the version. For
a private repo add a personal access token; the updater resolves GitHub’s
signed asset redirect itself.

Build the release asset so it unzips to a top folder named `acps-sitemap/`. If
it doesn’t, `fix_source_dir()` renames the unpacked folder back to the slug so
the update overwrites the same directory and the plugin stays active.

### JSON manifest (`update_source = url`)
Host a file that returns HTTP 200. Only `version` + `download_url` are required:

```json
{
  "version": "1.1.0",
  "download_url": "https://downloads.example.org/acps-sitemap/acps-sitemap.zip",
  "homepage": "https://example.org/acps-sitemap/changelog",
  "changelog": "* Fixed X\n* Added Y",
  "requires_php": "7.0",
  "requires_wp": "5.0"
}
```

If you set a manifest key it is sent as `?key=<value>` — have your host require it.

## Crash protection

* **After every update**, `verify_after_upgrade()` makes a fresh loopback
  request that loads the new code and looks for the marker `ACPS_SITEMAP_OK`
  (emitted early on `init`). Marker present → healthy, stays enabled; an HTTP
  5xx → real crash, the plugin is deactivated and the failure recorded; a
  network error / no marker → inconclusive, left enabled (a blocked loopback
  never disables a good update).
* **Safe mode**: if the plugin fatals in one of its own files, the next request
  loads only a “Resume plugin” admin notice and returns, so the theme and other
  plugins keep working. Fix the problem, then click **Resume**.

## Force-update URL

Activation seeds a random secret (`update_trigger`). The **Updates** panel shows
a URL of the form `https://your-site/?acps_sitemap_update=<secret>`. Loading it
(from curl, cron, or a deploy hook) forces an immediate check + install. Keep it
secret — it is the only guard.

## Staged rollout (optional)

1. **Dev/staging site:** role **Dev**, set a **status key**. After it installs
   and self-tests a version, it publishes that at
   `…/wp-json/acps-sitemap/v1/update-status?key=…`.
2. **Production site:** role **Production**, paste the dev **status URL** and the
   same **status key**. Production only offers/auto-applies a version once the
   dev site has verified that version (or newer); if the dev status can’t be
   read, production holds rather than updating blind.

## Operational notes

* **OPcache** (WP Engine etc.): uploading PHP doesn’t recompile bytecode — after
  a manual deploy, clear caches / restart PHP.
* **Loopback blocked:** some hosts block a site calling itself; that’s why an
  inconclusive crash-test never disables the plugin.
* **Version compare:** the source’s version must be strictly greater than the
  installed `Version:` header to offer an update; keep the header and any release
  tag in sync.
