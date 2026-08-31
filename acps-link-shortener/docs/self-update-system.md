# Self-Update System — Implementation Spec (for AI)

This document has two parts:

1. **PART A** — how to add this WordPress self-update system to *another* plugin.
2. **PART B** — how to build the *update server* (the thing that hosts the
   self-hosted `update.json` + zip), so updates come from a file you control
   instead of GitHub.

The reference implementation lives in
`acps-link-shortener/includes/class-acps-ls-updater.php` (class
`ACPS_LS_Updater`), wired from the main plugin file. Read that file alongside
this spec — this document explains the contract; the file is the canonical code.

---

## PART A — Add self-updating to a WordPress plugin

### A0. What the system does

A WordPress plugin normally only updates if it lives on wordpress.org. This
system makes a *self-hosted* plugin update through the exact same WordPress UI
("Update now" under Plugins, and background auto-updates) by telling WordPress
about a newer version and where to download it.

It supports **two update sources**, both producing the same internal result:

- **`url` (hosted manifest, default):** the plugin fetches a small JSON file at
  a URL you control. The JSON names the latest version and a zip download URL.
- **`github` (GitHub releases):** the plugin queries the GitHub Releases API,
  reads the latest release tag as the version, and downloads an attached zip
  asset (with correct handling for private-repo signed-redirect downloads).

Plus a **secret force-update URL** that triggers an immediate check + install on
demand, and an opt-in **fully-automatic** background install.

### A1. Core idea / WordPress mechanics

WordPress checks for plugin updates by building a transient called
`update_plugins`. If that transient's `->response[ <plugin_basename> ]` contains
an object with a `new_version` greater than the installed version and a
`package` (a downloadable zip URL), WordPress shows "Update now" and can install
it. This system hooks in to inject that object.

Key hooks (all used by the reference class):

| Hook | Type | Purpose |
|---|---|---|
| `pre_set_site_transient_update_plugins` | filter | Inject our `->response[basename]` when a newer version exists (and remove it when up to date). |
| `plugins_api` | filter | Provide data for the "View details" popup (`plugin_information` action, matched by `->slug`). |
| `upgrader_pre_download` | filter | Only for private GitHub assets: resolve the authenticated redirect ourselves, then download the signed URL without the auth header. Return `false` to let WP download normally. |
| `auto_update_plugin` | filter | Return `true`/`false` for THIS plugin only, honoring the "auto" setting, so WordPress core installs it automatically on its cron. |
| `init` | action | Detect the secret force-update URL and run an install now. |
| `upgrader_process_complete` | action | Flush the cached remote lookup after any upgrade. |

**The zip must extract to a folder matching the plugin's directory name**
(e.g. `acps-link-shortener/…`). The build zip already does this. GitHub *source
zipballs* extract to `owner-repo-sha/` and are therefore NOT directly
installable when the plugin lives in a repo subfolder — that is why the GitHub
path prefers an *attached asset* (the real build zip) over the zipball.

### A2. The internal "remote" contract

Both sources normalize to one associative array (or `false` on failure):

```php
array(
  'version'  => '1.14.0',                    // no leading "v"
  'package'  => 'https://…/plugin.zip',      // downloadable zip URL
  'is_asset' => false,                        // true only for GitHub API asset URLs
  'html_url' => 'https://…',                  // release/homepage link (optional)
  'body'     => 'changelog text',             // shown in details popup (optional)
);
```

The lookup is cached in a transient (`CACHE_TTL = 6h`; a failed lookup is cached
for 15 min so the source isn't hammered). `remote(true)` forces a fresh fetch.
Version comparison uses `version_compare($remote, INSTALLED, '>')`.

### A3. Settings keys (stored in the plugin's settings option)

The reference reads these from the plugin's single settings option array. Rename
the option to the target plugin's own; keep the key names or adapt consistently.

| Key | Meaning | Default |
|---|---|---|
| `update_enabled` | Master on/off for the whole updater | `true` |
| `update_auto` | Install automatically in the background | `false` |
| `update_source` | `'url'` or `'github'` | `'url'` |
| `update_manifest` | Manifest URL (for `url` source) | `''` |
| `update_manifest_key` | Optional shared secret, sent as `?key=…` | `''` |
| `gh_owner` / `gh_repo` | GitHub owner/repo (for `github` source) | — |
| `gh_asset` | Release asset filename to download | `plugin.zip` |
| `gh_token` | PAT for private repos (blank = public) | `''` |
| `update_trigger` | Secret word for the force-update URL | e.g. `protcol_U999_update` |

### A4. The manifest contract (`url` source)

The plugin does `wp_remote_get( manifest_url )` (adding `?key=<secret>` if
`update_manifest_key` is set) and expects **HTTP 200 with a JSON body**:

```json
{
  "version": "1.14.0",
  "download_url": "https://updates.example.org/plugin/plugin.zip",
  "changelog": "Human-readable notes (optional)",
  "requires_php": "7.4",
  "homepage": "https://example.org/plugin (optional)"
}
```

Required: `version`, `download_url`. Everything else optional. `version` may
have a leading `v` (stripped). `download_url` must be a publicly reachable HTTPS
zip (or reachable with whatever auth is baked into the URL). See PART B for how
to serve this.

### A5. The GitHub source

- Endpoint: `GET https://api.github.com/repos/{owner}/{repo}/releases/latest`
  with headers `Accept: application/vnd.github+json`,
  `X-GitHub-Api-Version: 2022-11-28`, a `User-Agent`, and
  `Authorization: Bearer <token>` when a token is set.
- `version` = `ltrim(tag_name, 'vV')`.
- `package`: find the asset whose `name` equals `gh_asset`. If a token is set,
  use the asset's **API `url`** (`…/releases/assets/{id}`); otherwise use
  `browser_download_url`.
- **Private download fix** (`upgrader_pre_download`): when the package is a
  GitHub asset API URL and a token is set, do a `wp_remote_get` with
  `redirection => 0`, `Accept: application/octet-stream`, and the bearer token;
  read the `Location` header; then `download_url($location)` with **no** auth
  header (GitHub's signed S3 redirect rejects a forwarded Authorization header).
  Return the temp file path. For public downloads, return the filter's original
  value so WP downloads normally.

### A6. The secret force-update URL

On `init`, the updater checks whether the request matches the secret:

- `?<query>=<secret>` (reference uses `?acps_ls_update=<secret>`), or
- the request **path** equals the secret (`/protcol_U999_update`).

Compared with `hash_equals()`. On a match it: flushes the cache, does a forced
`remote(true)`, and if a newer version exists, loads
`wp-admin/includes/{plugin,file,misc,class-wp-upgrader}.php`, refreshes the
`update_plugins` transient (`delete_site_transient` + `wp_update_plugins()`),
then runs:

```php
$skin     = new Automatic_Upgrader_Skin();
$upgrader = new Plugin_Upgrader( $skin );
$result   = $upgrader->upgrade( PLUGIN_BASENAME );
```

It prints a `text/plain` status page (installed vs latest, upgrader messages,
SUCCESS/FAILED) and `exit`s. The secret word is the only guard, so it must be
non-trivial and kept private; it can only ever install the configured latest
release, nothing arbitrary. Note self-update cannot bootstrap the very first
install of the updater itself — that first version is installed manually.

### A7. Crash-proofing conventions (keep these)

- Every hook callback body is wrapped in `try { … } catch ( Throwable $e ) { log; return safe; }`.
- The whole class file is only loaded through the plugin's safe file loader; if
  the file is missing the plugin pauses instead of fataling.
- Failures cache a short "nothing" and return `false`; they never surface to
  visitors. Errors are logged only when `WP_DEBUG` is on.

### A8. Bootstrap wiring

1. Add the updater file to the plugin's file loader list.
2. In the plugin's `plugins_loaded` bootstrap, after other components:
   ```php
   if ( class_exists( 'TARGET_Updater' ) ) {
       ( new TARGET_Updater() )->register();
   }
   ```
3. Add the settings keys to the settings save handler (sanitize:
   `esc_url_raw` for URLs, `sanitize_text_field` for tokens/keys,
   `sanitize_title` for the trigger word, checkboxes to 0/1) and a settings UI
   section. After saving, `delete_transient( '<plugin>_update_remote' )` so a
   changed source takes effect immediately.

### A9. Per-plugin rename checklist (porting to a new plugin)

Replace all of these when copying the class into `NewPlugin`:

- Class name `ACPS_LS_Updater` → `NewPlugin_Updater`.
- `CACHE_KEY` transient string (must be globally unique).
- `ACPS_LS_VERSION` → the new plugin's version constant.
- `ACPS_LS_BASENAME` → the new plugin's basename constant (`plugin_basename(__FILE__)`).
- `ACPS_LS_OPT_SETTINGS` → the new plugin's settings option name.
- `acps_ls_log_error()` → the new plugin's logger (or inline the try/catch log).
- Default `gh_owner`/`gh_repo`/`gh_asset`/`update_trigger` values.
- The `?acps_ls_update=` query-var name and the `delete_transient` key in the
  settings save handler.
- User-facing strings + text domain.

Nothing else is WordPress-version-specific; it relies only on long-stable core
hooks and `WP_Upgrader`.

---

## PART B — Build the update server (self-hosted, no GitHub)

The `url` source needs a host that serves two things over HTTPS:

1. **`update.json`** — the manifest (schema in A4).
2. **the plugin zip** — named/pathed to match `download_url` in the manifest.

It can be as simple as two static files, or a small app/plugin that manages them.

### B0. Minimum viable server (static)

Any HTTPS host works: an object store (S3/R2 + public bucket), a static site
host, or a folder on an existing web server. Upload `plugin.zip` and
`update.json`. To release: replace the zip, bump `version` (and `changelog`) in
the JSON. That's the entire "server." No code required.

Requirements:
- Serve `update.json` with `Content-Type: application/json` (or any type; the
  client parses the body regardless) and **HTTP 200**.
- Serve the zip as a normal file download (any content type; must be the real
  zip bytes, 200, following redirects is fine).
- Prefer **no/short caching** on `update.json` (e.g. `Cache-Control: max-age=60`)
  so new versions propagate quickly. The client itself caches results for 6h, so
  ultra-low TTL isn't required.
- If using the optional secret: the client appends `?key=<secret>`. A static
  host can't check it, so treat the secret as "unguessable URL" only. For real
  gating, use the managed server below.

### B1. Managed update server — functional spec (for AI to build)

Build a small web app (or a companion WordPress plugin) that manages releases
and serves the manifest + zip. Target: a non-technical operator uploads a new
zip and clicks publish; all subscribed sites then update.

**Data model — one "product" (the plugin being distributed):**
- `slug` (string, e.g. `acps-link-shortener`)
- `latest_version` (semver string)
- `changelog` (text/markdown)
- `requires_php`, `requires_wp` (optional)
- `zip` (the current release binary + its stored path/URL)
- `history[]` (optional: prior versions + zips for rollback)
- `manifest_key` (optional shared secret required as `?key=`)

**Endpoints:**

- `GET /update.json` (or `/{slug}/update.json`)
  → returns the manifest exactly matching schema A4:
  ```json
  { "version": "<latest_version>",
    "download_url": "<absolute URL to the current zip>",
    "changelog": "<changelog>",
    "requires_php": "<x.y>" }
  ```
  - If `manifest_key` is set, require `?key=<secret>` and return `403` otherwise.
  - `download_url` must be absolute and directly downloadable.

- `GET /download/{slug}/{version}.zip` (or a signed/temporary URL)
  → streams the zip bytes. `200`, real zip, redirects OK. If gated, accept the
  same `?key=` or a per-download signed token.

- **Admin UI (auth-protected):**
  - Upload a new zip (validate it is a zip whose top-level folder == `slug`).
  - Auto-read the version from the zip's plugin header
    (`Version:` line in the main PHP file) OR let the operator type it; validate
    it is a higher semver than `latest_version` (use PHP `version_compare` or an
    equivalent). Warn on non-increasing versions.
  - Edit changelog / requires_php.
  - "Publish" = make the uploaded zip the current release and update the
    manifest atomically (write zip first, then flip the manifest, so a site
    never sees a version pointing at a missing/old zip).
  - Optional: keep the previous release for one-click rollback (publish an older
    version as latest again).
  - Show/rotate the `manifest_key`.

**Publish ordering (critical):** always upload+verify the new zip, THEN update
`update.json`. If reversed, a site can read the new version number and download
the old/absent zip mid-publish.

**Version rule:** clients only update when
`version_compare(manifest.version, installed, '>')` is true. So the manifest
version MUST strictly increase and MUST equal the version in the zip's plugin
header (otherwise WordPress reinstalls or shows "update available" forever).
Validate this equality at publish time.

**Security notes:**
- The admin UI must be authenticated (it can push code to every subscriber).
- Serve everything over HTTPS.
- Never trust the uploaded zip blindly beyond distribution; at minimum confirm
  it is a valid zip with the expected top-level folder and a readable plugin
  header. Optionally sign releases and verify on the client (an enhancement not
  in the reference client today).
- The optional `manifest_key` is a coarse read-gate, not strong auth; combine
  with an unguessable path for practical privacy.

**If built as a WordPress plugin (companion "release server"):**
- Register a public REST route (e.g. `GET /wp-json/updates/v1/{slug}`) that
  returns the manifest JSON, and a route (or a redirect to an uploaded media
  file) that serves the zip.
- Store the zip as a private upload or a protected path; if using the media
  library, restrict direct access and stream via the route with the `?key=`
  check.
- Provide an admin screen for upload/publish/changelog/key as above.
- This lets a single WordPress site act as the update origin for the same
  plugin installed on many other sites.

### B2. End-to-end release flow (what "shipping an update" looks like)

1. Build the plugin zip (top-level folder == plugin slug), bump the version in
   the plugin header so it matches the intended manifest version.
2. Upload the zip to the server (static: replace the file; managed: upload +
   publish, which validates header==manifest version and flips atomically).
3. Set `update.json.version` to the new version and `download_url` to the new
   zip (managed server does this automatically).
4. Subscribed sites: within their cache window WordPress shows "Update now", or
   auto-installs if enabled, or an operator hits the secret force-update URL for
   an immediate install.

---

## Quick reference — files in the reference implementation

- `includes/class-acps-ls-updater.php` — the whole updater (PART A).
- `acps-link-shortener.php` — loader list + `plugins_loaded` wiring.
- `includes/class-acps-ls-admin.php` — settings save keys + "Automatic updates"
  settings UI, and `delete_transient` cache flush on save.
