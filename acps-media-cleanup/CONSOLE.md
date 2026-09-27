# External control console — ACPS Unused Media Cleanup (FileMedia)

A plain-text, **no-styling** control panel that lives **outside wp-admin**, so you
can manage the plugin and push updates without waiting on the slow Beaver
Builder / wp-admin UI. It is unadvertised (no menu link), password-protected,
IP-filterable, and — crucially — it keeps working **even when the plugin is
paused (safe mode)**, so a broken site can always be fixed from it.

Everything here is configured on the hidden Updates tab:
`/wp-admin/admin.php?page=acps-mc-updates`

---

## 1. Open it

```
https://YOUR-SITE/?acpsupdater=<console_key>
```

- `console_key` is shown (and regenerated) on the hidden Updates tab.
- You then enter the **console password** (also on that tab). Wrong passwords are
  rate-limited; after 10 bad tries your IP is locked out for ~15 minutes.
- With the wrong key (or none), the URL is invisible — the site renders normally.

The console prints your installed version, the latest available version, whether
an update is available, whether the plugin is paused, and your IP. Buttons:
**Update to latest**, **Reinstall latest (fix broken files)**, **Resume** (only
when paused), **View / edit settings**.

## 2. No update notices anywhere

With **Silent** mode on (default), the plugin shows **no** update notice in
wp-admin — no "Update now" on the Plugins screen, no banners. Updates happen
**only** from this console URL or the hidden Updates page. (Turn Silent off on
the Updates tab if you also want the normal Plugins-screen update.)

## 3. Drive it from a Python script

The password is the only thing to "type", and every action works as a single
GET or POST. No JavaScript, no styling, predictable fields.

```python
import requests

BASE = "https://YOUR-SITE/"
KEY  = "the_console_key"
PW   = "the_console_password"

def call(do=None):
    params = {"acpsupdater": KEY, "pw": PW}
    if do: params["do"] = do
    return requests.get(BASE, params=params, timeout=120)

print(call("raw").text)        # machine-readable JSON status
print(call("update").text)     # update if a newer version exists
print(call("reinstall").text)  # re-download + overwrite (fixes edited files)
print(call("resume").text)     # clear paused (safe) mode
```

`do=raw` returns JSON: `installed_version`, `latest_version`,
`update_available`, `paused_safe_mode`, `site_url`, `your_ip`.

**Edit settings from a script:** POST `do=savesettings` with a `json` field
containing the settings object (any keys you include are written; others are
left alone). GET the console home → "View / edit settings" shows the current
JSON.

## 4. IP filtering

On the Updates tab:

- **Allow only these IPs** — one IP or prefix per line (or comma-separated). A
  prefix like `168.1` matches `168.1.*` (on the dot boundary — `168.10` is not
  matched). Blank = allow any IP.
- **Block these IPs** — always blocked, even if also in the allow list.

Filtering applies to the console URL. (The client IP is read from
`X-Forwarded-For` behind a proxy/CDN.)

## 5. Custom links

On the Updates tab, **Extra links on the console** takes one link per line:

```
Media Manager | https://YOUR-SITE/wp-admin/upload.php?page=acps-media-manager
Site home     | https://YOUR-SITE/
```

They appear as clickable links on the console home page, alongside the built-in
links to the FileMedia manager, Settings, and the hidden Updates / Remote-API
pages.

## 6. Self-healing failsafe

- The console runs on `init` and is registered **before** the safe-mode
  short-circuit, so it loads even when the plugin is paused after a fatal.
- **Reinstall latest** downloads the configured release/manifest package
  directly (not via the Plugins-screen transient), overwrites the plugin folder,
  re-activates it, and clears paused mode — so a wrongly-edited or half-uploaded
  file is fixed in one click, even from a dead-looking site.
- If the console itself ever hits an error, it prints the error plus a reminder
  that `&do=reinstall` will redownload and apply the latest version.

## 7. Conditional shortcode (Beaver Builder & any content)

```
[acps_when condition="plugin_disabled"]The media tools are briefly paused.[/acps_when]
[acps_when condition="plugin_active"]…normal content…[/acps_when]
[acps_when condition="update_available"]An update is ready.[/acps_when]
```

Conditions: `plugin_disabled` / `paused` / `safe_mode`, `plugin_active` /
`enabled`, `update_available` / `has_update`, `up_to_date`. Prefix any with `!`
to negate (e.g. `condition="!plugin_active"`). The shortcode is registered even
while the plugin is paused, so `plugin_disabled` still renders its content then.

---

*Security note:* this console can update code and change every setting from a
URL. It is protected by the secret key **and** the password **and** optional IP
filtering, all timing-safe. Keep the key and password secret; set an allow-list
of IPs if you can.
