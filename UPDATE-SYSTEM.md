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
directly — the settings page with `&updates=1` appended:

```
/wp-admin/options-general.php?page=acps-sitemap&updates=1
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
| `includes/class-acps-sitemap-updater.php` | The whole updater (`run_update()` installs on demand) |
| `includes/class-acps-sitemap-remote.php` | The secret out-of-band control panel (IP + password + rate limit) |
| `includes/class-acps-sitemap.php` | Settings defaults, the shared `apply_settings()` sanitizer, the issue log, activation seeding of the secret |
| `includes/class-acps-sitemap-admin.php` | The hidden **Updates** panel UI, “Check for updates now”, remote-password setter |
| `uninstall.php` | Removes the update/remote options + transients |

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
| `update_trigger` | Access key for the control-panel URL (`?acpsupdater=`); seeded on activation, editable in the hidden panel |
| `remote_enabled` | Master switch for the secret control panel |
| `remote_param` | The URL query key (default `acpsupdater`); rename to avoid a clash with another plugin |
| `remote_ip_allow` / `remote_ip_deny` | Advanced IP filtering — allow + deny lists (exact / prefix / wildcard / CIDR); deny wins |
| `remote_ip_source` | `remote_addr` or `x_forwarded_for` |
| `remote_rate_max` | Max control-panel requests per 5 min per IP |
| `remote_links` | Custom quick links shown on the panel (`[{label,url}]`) |

Options/transients outside the settings array:
`acps_sitemap_update_remote` (cached lookup, 6 h ok / 15 min fail),
`acps_sitemap_devstatus` (cached dev status, 10 min),
`acps_sitemap_verified` (`{version,time}` a dev install published),
`acps_sitemap_update_failed` (`{when,version}` after a rollback),
`acps_sitemap_safe_mode` (`{...}` — plugin parked after a fatal / missing files),
`acps_sitemap_issues` (capped ring buffer shown on the panel),
`acps_sitemap_remote_pw` (hashed control-panel password; set only in wp-admin),
`acps_sitemap_remote_last_edit` (once-a-day edit stamp),
`acps_sitemap_selfheal` (`{last,attempts}` auto-heal state),
`acps_sitemap_remote_{sess,rl,fail}_*` transients (session / rate limit / lockout).

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

The request URL the plugin builds is **manifest URL + this site + the key**:

```
<manifest_url>?site=<home_url>&plugin=<basename>&version=<installed>&key=<manifest_key>
```

so the manifest host can identify and authorize the requesting site. The
endpoint is expected to be **public** (no WordPress login), because the check
also runs from WP-Cron and logged-out contexts.

## Crash protection

* **After every update**, `verify_after_upgrade()` makes a fresh loopback
  request that loads the new code and looks for the marker `ACPS_SITEMAP_OK`
  (emitted early on `init`). Marker present → healthy, stays enabled; an HTTP
  5xx → real crash, the plugin is deactivated and the failure recorded; a
  network error / no marker → inconclusive, left enabled (a blocked loopback
  never disables a good update).
* **Safe mode**: if the plugin fatals in one of its own files, it parks itself
  and the theme and other plugins keep working. The plugin **never** shows a
  notice on top of other admin pages — recovery lives only on **Settings ->
  ACPS Sitemap** (a “Resume plugin” button) and on the control-panel URL. Fix
  the problem, then Resume (or let auto-heal do it).
* **File integrity**: before loading anything, the bootstrap checks all plugin
  files exist. `verify_after_upgrade()` re-checks after an update — a release
  that shipped incomplete (missing files) is rolled back like any other crash,
  and if the update secret went missing it is reseeded so the recovery URL keeps
  working.
* **Recovery URL in safe mode**: even while parked in safe mode, the bootstrap
  brings up ONLY the secret control panel (in reduced mode) if its files are
  intact, so a bad release cannot lock you out of the recovery URL. From there
  you can clear safe mode or force an update.

## No update notices

The plugin does **not** register the `pre_set_site_transient_update_plugins`,
`plugins_api`, or `auto_update_plugin` hooks, so there is **no "Update now" row,
no update-count badge, and no update notice** anywhere in WordPress. Updates
happen **only** when you press a button in the hidden admin panel (`&updates=1`)
or the control-panel URL — both call `Updater::run_install()`, which injects the
update transient just for the moment of its own upgrade and clears it afterward.

## Secret control panel (the update URL)

Activation seeds a random access key (`update_trigger`, editable in the hidden
Updates panel). The URL

```
https://your-site/?acpsupdater=<key>
```

where the query key (`acpsupdater`) is itself renameable via `remote_param` — if
another plugin already reads `?acpsupdater=`, change it to anything unique and the
plugin will only ever act on that exact key. The plugin does nothing for any other
parameter or value (no output, no 404, no side effects) — it only runs when the
configured parameter is present AND its value is an exact, constant-time match to
the key. The URL opens a self-contained, logged-out, **plain-text (no CSS/JS)** control panel
(`class-acps-sitemap-remote.php`). It is **not** linked anywhere. It can do
**everything the wp-admin screen can** — diagnostics, updates, and editing every
setting. Before it shows anything it passes, in order:

1. **`remote_enabled`** master switch.
2. **Advanced IP filtering** — independent **allow** (`remote_ip_allow`) and
   **deny** (`remote_ip_deny`) lists; deny wins, and an empty allow list means
   "allow all (except denied)". Each rule is an exact IP, a prefix/wildcard
   (`168.1.*`), or an IPv4 CIDR (`10.0.0.0/8`). The client IP is read from
   `remote_ip_source` (`remote_addr`, or `x_forwarded_for` behind a proxy/CDN).
   Default: allow only `167.102.110.1`. A blocked IP gets a plain 404.
3. **Rate limit** — `remote_rate_max` requests per 5 minutes per IP (429 over).
4. **Password** — stored **hashed** in `acps_sitemap_remote_pw`, set ONLY from
   wp-admin (hidden Updates panel). 5 wrong tries per IP = 15-minute lockout.

Auth works two ways: a browser gets a short-lived, IP-bound session cookie (with
a CSRF token on state-changing posts); a **script** may instead send the password
on every POST, which skips the cookie/token dance entirely (see `acps-remote.py`).

Actions: **check / install / reinstall** (reinstall re-downloads and overwrites
the current version to repair a mis-edited file), **create the HTML sitemap
page**, **clear safe mode**, **clear the issue log**, and **edit every setting**
— the last is limited to **once per 24 hours** (`acps_sitemap_remote_last_edit`).
Operator-defined **custom links** (`remote_links`, "Label|URL" lines) appear on
the panel for quick navigation.

### Driving it from Python

`acps-remote.py` (repo root, stdlib only) is a ready example:

```
export ACPS_URL=https://your-site/ ACPS_KEY=<key> ACPS_PW=<password>
python3 acps-remote.py status
python3 acps-remote.py reinstall
python3 acps-remote.py set enable_xml=1 max_per_sitemap=2000
```

## Conditional shortcode for page builders

`[acps_if]` shows/hides content by condition, for BeaverBuilder/Gutenberg modules:

```
[acps_if condition="update_available"]An update is ready[/acps_if]
[acps_if condition="!xml_enabled"]Sitemap is off[/acps_if]
[acps_if plugin_inactive="bb-plugin/fl-builder.php"]BeaverBuilder is off[/acps_if]
```

Conditions: `safe_mode`, `not_safe_mode`, `xml_enabled`, `xml_disabled`,
`remote_enabled`, `update_available`, `no_update`, `update_failed` (prefix `!`
to negate); plus `plugin_active="basename"` / `plugin_inactive="basename"` for
any plugin. (While THIS plugin is itself in safe mode its shortcodes don't run,
so use a builder-level fallback for the "totally down" case.)

## Failsafe auto-heal

If the plugin ends up broken — safe mode or missing files — it tries to
**re-download and reapply the latest version automatically** (`Updater::maybe_self_heal()`,
on `admin_init` and an hourly cron). It is rate-limited to one attempt per hour
and capped at 5 total (tracked in `acps_sitemap_selfheal`) so it can never loop;
on a successful repair it clears safe mode. The control-panel URL also keeps
working in safe mode (reduced recovery mode) so you can always intervene by hand.

> Security note: this is a real attack surface — a logged-out endpoint that edits
> settings and installs code. Keep the allow list tight, use a long password,
> and choose `x_forwarded_for` only when you trust the proxy in front of the site.

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
