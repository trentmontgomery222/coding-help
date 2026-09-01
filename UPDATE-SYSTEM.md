# Self‑Update System — how it works and how to add it to another plugin

This document explains the self‑update / crash‑protection / staged‑rollout system
built into **Cayden Form Manager** (`acps-site-toolkit`), and gives a step‑by‑step
recipe for copying it into a different plugin.

It lets a plugin that does **not** live on wordpress.org still show
**“Update now”** on the Plugins screen (and optionally auto‑update), pulling new
versions from **either** a JSON manifest URL **or** GitHub Releases — while
protecting the site from a bad release.

---

## 1. What it does (feature recap)

| Capability | Where it lives |
|---|---|
| Show “Update now” for a non‑.org plugin | `Updater::inject_update()` (hooks `pre_set_site_transient_update_plugins`) |
| “View details” popup | `Updater::plugin_info()` (hooks `plugins_api`) |
| Two sources: **manifest URL** or **GitHub Releases** | `Updater::remote()` → `fetch_from_url()` / `fetch_from_github()` |
| Private GitHub asset download | `Updater::maybe_resolve_private_download()` (hooks `upgrader_pre_download`) |
| **Keep the plugin active after update** (folder‑name fix) | `Updater::fix_source_dir()` (hooks `upgrader_source_selection`) |
| **Crash‑test the new version, only enable if it loads** | `Updater::verify_after_upgrade()` + `self_test_result()` + `maybe_handle_selftest()` |
| Secret **force‑update URL** (curl/cron/deploy hook) | `Updater::maybe_handle_force_update()` |
| **Staged rollout** dev → production | `update_role`, `/update-status` endpoint, `rollout_allows()` |
| **Fatal‑error “safe mode”** so the plugin can’t white‑screen the site | main plugin file: `boot()`, `shutdown_guard()`, `is_safe_mode()` |
| Settings UI hidden from all menus (typed URL only) | `settings.php` gated on `?acps_updates=1` |

Design rule throughout: **every hook callback is wrapped in `try/catch (\Throwable)`**
and failures are cached briefly, so a broken update source can never take down the
rest of the site.

---

## 2. The moving parts

```
acps-site-toolkit.php            ← bootstrap + crash-protection (safe mode)
includes/class-updater.php       ← the whole updater
includes/class-settings.php      ← update_* settings (defaults + sanitize)
includes/class-activator.php     ← seeds the random force-update secret on activation
includes/admin/views/settings.php← the hidden "Updates" tab UI
uninstall.php                    ← cleans up the update options/transients
```

### Options & transients it uses

| Key | Type | Purpose |
|---|---|---|
| `…_settings[update_enabled]` | setting | master on/off |
| `…_settings[update_auto]` | setting | background auto‑update |
| `…_settings[update_source]` | setting | `url` \| `github` |
| `…_settings[update_manifest]` / `[update_manifest_key]` | setting | manifest URL + optional `?key=` |
| `…_settings[gh_owner]` / `[gh_repo]` / `[gh_asset]` / `[gh_token]` | setting | GitHub source |
| `…_settings[update_trigger]` | setting | secret for the force‑update URL **and** the crash‑test marker |
| `…_settings[update_role]` | setting | `standalone` \| `dev` \| `production` |
| `…_settings[verify_status_url]` / `[verify_status_key]` | setting | production → dev verification |
| `acps_st_update_remote` | transient | cached remote lookup (6 h ok / 15 min on failure) |
| `acps_st_devstatus` | transient | cached dev verification (10 min) |
| `acps_st_verified` | option | `{version,time}` a dev install published after passing its test |
| `acps_st_update_failed` | option | `{when,version}` set when a release was rolled back |
| `acps_st_safe_mode` | option | `{msg,file,line,time}` — plugin is dormant after a caught fatal |

---

## 3. How the tricky bits actually work

### 3a. Why an update used to “disable” the plugin — and the fix
A GitHub release zip unpacks to a folder named after the repo/tag
(e.g. `my-plugin-1.2.3/`), **not** the plugin slug (`my-plugin/`). WordPress then
installs into a *new* directory and the plugin it had marked active
(`my-plugin/my-plugin.php`) no longer exists → it drops to inactive.

`fix_source_dir()` hooks `upgrader_source_selection` and **renames the unpacked
folder back to the plugin slug**, so the update overwrites the *same* directory and
the plugin stays active. This is the single most important piece for GitHub sources.

### 3b. Crash‑test then enable
After our plugin updates (`upgrader_process_complete`):
1. Make sure it’s marked active (silent activation — just sets the option, does **not**
   re‑include the file, which would fatal on “cannot redeclare”).
2. `self_test_result()` does a **fresh loopback GET** to the home page with a
   secret query var. The new code loads from scratch in that request; if it booted,
   `maybe_handle_selftest()` prints the marker `ACPS_OK` and exits early.
3. Decision:
   * marker present → **`ok`** → stays enabled; publishes `acps_st_verified`.
   * HTTP **5xx** → **`crash`** → `deactivate_plugins()` + record `acps_st_update_failed`.
   * network error / no marker → **`unknown`** → **left enabled** (a blocked/slow
     loopback must never disable a healthy update).

> The one request that first hits a genuine fatal can still error — PHP can’t undo a
> fatal mid‑request — but every request after it is safe.

### 3c. Fatal‑error safe mode (in the main plugin file)
`boot()` runs on `plugins_loaded` instead of constructing the plugin directly:
* it wraps construction in `try/catch (\Throwable)`;
* it `register_shutdown_function('shutdown_guard')`, which — only when the fatal’s
  file is **inside this plugin’s directory** — writes the `…_safe_mode` option;
* on the next request `is_safe_mode()` is true, so the plugin loads **only** a small
  admin notice + a nonce‑protected **“Resume plugin”** button and returns. The theme
  and every other plugin keep working.

### 3d. Staged rollout (dev → production)
* A **dev** install, after it passes its own crash‑test, stores `acps_st_verified`
  and exposes it at `GET /wp-json/<ns>/v1/update-status?key=…` (key‑guarded).
* A **production** install sets `update_role = production`, points `verify_status_url`
  at the dev endpoint and shares `verify_status_key`. Before it will **offer or
  auto‑apply** any version, `rollout_allows()` checks the dev endpoint and only
  passes when the dev site has verified that version (or newer). If the dev status
  can’t be read, production **holds** rather than updating blind. Manual “Update now”
  therefore only appears once dev has verified — i.e. production updates only when told.

### 3e. Hidden settings
The Updates tab and its “check now” card render only when the URL carries
`?acps_updates=1`. There is **no menu entry**; you reach it by typing the specific
URL, so update settings can’t be changed by accident.

---

## 4. Data formats for the two sources

### Manifest (`update_source = url`)
Host a JSON file anywhere that returns HTTP 200. Only `version` + `download_url`
are required:

```json
{
  "version": "1.4.0",
  "download_url": "https://downloads.example.org/my-plugin/my-plugin.zip",
  "homepage": "https://example.org/my-plugin/changelog",
  "changelog": "* Fixed X\n* Added Y",
  "requires_php": "7.4",
  "requires_wp": "6.2"
}
```
If you set a manifest key, it is sent as `?key=<value>` — have your host require it.

### GitHub Releases (`update_source = github`)
Set `gh_owner`, `gh_repo`, and `gh_asset` (the **exact** filename of the release
asset, e.g. `my-plugin.zip`). The tag name (minus a leading `v`) is the version.
For a private repo, set `gh_token` (a PAT); the updater resolves GitHub’s signed
asset redirect itself, because GitHub rejects a forwarded `Authorization` header on
the signed S3 link.

> **Build the zip so it unpacks to `my-plugin/…`** (the plugin slug). GitHub’s
> auto‑generated source zips do **not** — attach a properly‑structured release asset,
> or rely on `fix_source_dir()` which renames it for you.

---

## 5. Porting it to another plugin — step by step

### Step 0 — pick your tokens
Choose these once and use them everywhere (find/replace table below):

| Thing | This plugin | Your plugin (example) |
|---|---|---|
| Namespace | `ACPS\SiteToolkit` | `Acme\Widget` |
| Version constant | `ACPS_ST_VERSION` | `ACME_W_VERSION` |
| Basename constant | `ACPS_ST_BASENAME` | `ACME_W_BASENAME` |
| Path constant | `ACPS_ST_PATH` | `ACME_W_PATH` |
| REST namespace | `acps-st/v1` (`ACPS_ST_REST_NAMESPACE`) | `acme-w/v1` |
| Settings option | `acps_st_settings` (`ACPS_ST_OPT_SETTINGS`) | `acme_w_settings` |
| Option/transient prefix | `acps_st_` | `acme_w_` |
| Crash‑test marker | `ACPS_OK` | `ACME_OK` |
| Force‑update query var | `acps_st_update` (`Updater::QUERY_VAR`) | `acme_w_update` |
| Hidden‑UI query var | `acps_updates` | `acme_updates` |

### Step 1 — copy the class
Copy `includes/class-updater.php` into your plugin and run the find/replace from the
table. It assumes your plugin defines the constants above and a `Settings` class with
static `get()` — see steps 3–4.

### Step 2 — crash protection in your main plugin file
Replace your bootstrap (the part that does `new Plugin()` on `plugins_loaded`) with
the `boot()` / `is_safe_mode()` / `arm_safe_mode()` / `shutdown_guard()` /
`safe_mode_notice()` / `resume_from_safe_mode()` functions from
`acps-site-toolkit.php` (the “Bootstrap” section). Key points to keep:
* `define( 'ACME_W_SAFE_MODE_OPT', 'acme_w_safe_mode' );`
* `add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );`
* the shutdown guard only arms when `strpos($err['file'], ACME_W_PATH) === 0`.

### Step 3 — register the updater + crash‑test hooks
In your plugin class, instantiate and register the updater **defensively**, and wire
the self‑test + verify hooks (already inside `Updater::register()` once copied):

```php
// In your Plugin bootstrap:
$this->updater = class_exists( __NAMESPACE__ . '\\Updater' ) ? new Updater() : null;
// …later, where you register hooks:
if ( $this->updater ) {
    $this->updater->register();
    add_action( 'update_option_' . ACME_W_OPT_SETTINGS, array( __NAMESPACE__ . '\\Updater', 'flush_cache' ) );
}
```

### Step 4 — settings
Add these keys to your `Settings::defaults()` and sanitize them (copy the
`update_*`, `verify_status_*`, `update_role` blocks from `class-settings.php`):

```php
'update_enabled'      => 1,
'update_auto'         => 0,
'update_source'       => 'url',      // 'url' | 'github'
'update_manifest'     => '',
'update_manifest_key' => '',
'gh_owner'            => '',
'gh_repo'             => '',
'gh_asset'            => 'my-plugin.zip',
'gh_token'            => '',
'update_trigger'      => '',         // seeded on activation
'update_role'         => 'standalone',
'verify_status_url'   => '',
'verify_status_key'   => '',
```

### Step 5 — seed the secret on activation
In your activation hook (`Activator::activate()`):

```php
$settings = get_option( ACME_W_OPT_SETTINGS );
if ( is_array( $settings ) && empty( $settings['update_trigger'] ) ) {
    $settings['update_trigger'] = sanitize_title( wp_generate_password( 24, false, false ) );
    update_option( ACME_W_OPT_SETTINGS, $settings );
}
delete_option( 'acme_w_update_failed' ); // clean activation clears any rollback flag
```

### Step 6 — settings UI (optional but recommended)
Copy the Updates‑tab markup from `settings.php`. Keep it gated on `?acme_updates=1`
if you also want it hidden from menus.

### Step 7 — uninstall cleanup
```php
delete_transient( 'acme_w_update_remote' );
delete_transient( 'acme_w_devstatus' );
delete_option( 'acme_w_update_failed' );
delete_option( 'acme_w_verified' );
delete_option( 'acme_w_safe_mode' );
```

### Step 8 — ship a build that unzips to your slug
Make your release zip contain a top folder named exactly `my-plugin/`. (Or rely on
`fix_source_dir()`, which renames it — but a correct zip is cleaner.)

---

## 6. Two‑site staged rollout — setup

1. **Dev site:** hidden Updates URL → role **Dev/staging**, set a **Status key**,
   copy the shown **status URL** (`…/wp-json/acme-w/v1/update-status`).
2. **Production site:** role **Production**, paste the **Dev status URL**, set the
   **same Status key**.
3. Release a new version → dev updates and self‑tests → on pass it publishes
   `verified: <version>` → production then offers it → you click **Update** (or it
   auto‑updates if you enabled that).

---

## 7. Gotchas / operations

* **OPcache (WP Engine etc.):** uploading PHP does not recompile bytecode. After a
  manual deploy, *Clear all caches + Restart PHP*.
* **Loopback blocked:** some hosts block a site calling itself. That’s why an
  inconclusive crash‑test **never** disables the plugin — only a real 5xx does.
* **Force‑update URL:** the value of `update_trigger` in the URL forces an immediate
  check+install; keep it secret (it’s the only guard). It’s also the crash‑test marker
  secret.
* **Parse error in the *main* plugin file** can’t be caught mid‑request (safe mode
  can’t arm), but the crash‑test sees the 5xx and deactivates → site recovers.
* **Version numbers** are compared with `version_compare`; keep the header version and
  `*_VERSION` constant in sync, and make the source’s version strictly greater to
  offer an update.
