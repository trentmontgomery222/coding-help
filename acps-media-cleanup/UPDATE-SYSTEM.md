# Self-hosted updates for ACPS Unused Media Cleanup (FileMedia)

This plugin can update itself from a source **you** control — a GitHub release
or a JSON manifest — and show **“Update now”** on the WordPress Plugins screen
exactly like a wordpress.org plugin, without ever being listed there. A bad
release is crash-tested and rolled back, and a fatal error puts the plugin into
a dormant **safe mode** instead of white-screening the site.

Everything lives in `includes/class-acps-mc-updater.php` (the `ACPS_MC_Updater`
class) plus a crash-safe bootstrap in the main plugin file.

---

## 1. Turn it on

The update settings live on a **hidden page with no menu link** — so they can’t
be changed by accident. Reach it only by typing the URL:

```
/wp-admin/admin.php?page=acps-mc-updates
```

(You still need to be logged in with the `manage_options` capability.)

1. Check **Enable self-updates**.
2. (Optional) Check **install automatically in the background** for hands-off
   auto-updates. They use the same crash-test protection.
3. Pick a source (below) and **Save update settings**.

WordPress checks for plugin updates a few times a day; the new version then
appears on **Plugins**. To check immediately, use the secret force-update URL
(section 4) or just visit **Dashboard → Updates**.

> There is intentionally **no menu entry anywhere** for this page. Bookmark the
> URL above. Nothing about self-updates appears on the normal
> **Media → Media Settings** screen.

---

## 2. Source A — GitHub Releases (recommended)

Set **Update source → GitHub Releases**, then fill in:

| Field | Example | Notes |
|-------|---------|-------|
| Owner / repo | `acps` / `acps-media-cleanup` | Reads the repo’s **latest release**. |
| Release asset filename | `acps-media-cleanup.zip` | The zip attached to the release. |
| Access token | *(blank for public)* | Fine-grained PAT with **Contents: read** for a private repo. |

Publishing a release:

1. Bump the version in `acps-media-cleanup.php` (the `Version:` header **and**
   `ACPS_MC_VERSION`) and in `readme.txt`.
2. Build a zip whose **top folder is `acps-media-cleanup/`** (see section 6).
3. Create a GitHub release whose **tag is the version** (e.g. `v1.15.0` or
   `1.15.0` — a leading `v` is stripped).
4. Attach the zip as an asset named exactly as configured above.

The tag becomes the offered version; the release body becomes the “View
details” changelog.

---

## 3. Source B — JSON manifest

Set **Update source → JSON manifest URL** and point **Manifest URL** at a file
you host. Minimum shape:

```json
{
  "version": "1.15.0",
  "download_url": "https://example.org/downloads/acps-media-cleanup.zip"
}
```

Optional keys: `homepage`, `changelog`, `requires_php`, `requires_wp`. If the
manifest is protected, put a token in **Manifest key** — it’s sent as `?key=…`.

---

## 4. Secret force-update URL

Seeded once at activation, shown on the settings screen when self-updates are
enabled. Hitting it runs a fresh check and installs a newer version right then,
printing a plain-text result — handy from a deploy hook or cron:

```
https://your-site/?acps_mc_update=THE_SECRET
curl -s "https://your-site/?acps_mc_update=THE_SECRET"
```

Keep it secret — anyone with the URL can trigger an update check on the site.
It is compared with a timing-safe `hash_equals()`.

---

## 5. Crash protection

- **After every update** the plugin is re-activated and a fresh loopback request
  loads the new code. It must answer with a secret-guarded marker (`ACPS_MC_OK`,
  emitted early on `init`). Marker present → healthy, stays enabled. A **5xx** →
  real crash → the plugin is **deactivated** and an admin notice explains the
  rollback. A blocked/slow loopback is treated as *inconclusive* and the update
  is **left enabled** (a firewalled loopback never disables a good update).
- **Fatal-error safe mode.** If a fatal ever happens **inside this plugin’s
  files**, a shutdown guard flips a `safe_mode` flag. The next request loads
  only a small **“Resume plugin”** admin notice and returns, so the site stays
  up. Fix the problem (or let an update land) and click **Resume plugin**.

---

## 6. Zip layout

WordPress installs the zip over the existing plugin folder only if the folder
names match. GitHub “Source code” zips unpack to `repo-tag/`, which would orphan
the plugin — so either:

- ship a zip whose top folder is **`acps-media-cleanup/`** (attach it as the
  release asset), **or**
- rely on the built-in `upgrader_source_selection` fix, which renames the
  unpacked folder back to `acps-media-cleanup` so the update overwrites the same
  directory and the plugin stays active.

---

## 7. Staged rollout (optional, two sites)

Run a **dev** site and a **production** site off the same source:

1. On both: set the **same** *Shared status key*.
2. Dev: **This site’s role → Dev**. When dev installs a version and it passes
   the crash-test, dev publishes “verified vX” at
   `…/wp-json/acps-mc/v1/update-status?key=…`.
3. Production: **This site’s role → Production**, and set **Dev status URL** to
   the dev site’s `/update-status` endpoint. Production only offers/applies a
   version once dev reports it verified. If dev can’t be reached, production
   **holds** rather than updating blind.

---

## 8. Operational gotchas

- **OPcache / object cache.** After an update the crash-test loopback loads
  fresh code; on hosts with aggressive OPcache a stale opcode cache can mask a
  problem — flush OPcache on deploy if you can.
- **Blocked loopbacks.** Some hosts block same-server HTTP. That makes the
  crash-test *inconclusive* (update stays enabled), which is intentional.
- **Version compare.** Offers appear only when the source version is strictly
  greater than the installed `ACPS_MC_VERSION` (`version_compare … '>'`).
- **Turning it off** disables the checks **and** the force-update URL.

---

*Reusing this in another plugin:* the whole system is one class plus the
safe-mode bootstrap. Copy `includes/class-acps-mc-updater.php`, find/replace the
`ACPS_MC_*` tokens (version/basename/dir constants, the `acps_mc_update` query
var, the `ACPS_MC_OK` marker, the `acps-mc/v1` REST namespace and the option
prefix), copy the safe-mode functions from the main plugin file, register the
updater in your bootstrap, add the `update_*` settings, and seed the secret on
activation.
