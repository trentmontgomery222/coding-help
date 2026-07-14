# Setup Guide — Drive → Sheet → WordPress Media Pipeline

Follow these steps **in order** (they mirror the build brief's §8 build order).
Each step is verifiable on its own before moving to the next.

## 1. Create the spreadsheet

1. Create a new Google Sheet. Rename the first tab to `queue`.
2. Add this exact header row (the Apps Script also auto-creates it on an empty sheet):

   ```
   row_id | timestamp | file_id | filename | alt_text | location | uploader | target_site | status | wp_url | wp_attachment_id | error_message
   ```

3. Note the spreadsheet ID (the long string in the sheet URL between `/d/` and `/edit`).

## 2. Create the Apps Script project

1. Go to [script.google.com](https://script.google.com) → New project.
2. Enable "Show `appsscript.json` manifest file" in Project Settings.
3. Copy in the four files from `apps-script/`:
   - `appsscript.json` (replaces the default manifest)
   - `Config.gs`
   - `Card.gs`
   - `WebApp.gs`
4. In **Project Settings → Script Properties**, add:

   | Property | Value |
   |---|---|
   | `SHARED_TOKEN` | A long random secret. Generate with `openssl rand -hex 32`. |
   | `QUEUE_SHEET_ID` | The spreadsheet ID from step 1. |
   | `TARGET_SITES` | JSON map of blog IDs to labels, e.g. `{"1":"Main site","3":"Athletics"}`. Optional — omit for a single-site default of blog 1. |
   | `MAX_FILE_BYTES` | Optional, default 20971520 (20 MB). |
   | `STALE_MINUTES` | Optional, default 30. Minutes before a stuck `processing` row is reset to `pending`. |

## 3. Install the Drive add-on (test deployment)

1. Deploy → **Test deployments** → Install (Drive add-on).
2. In Drive, select an image file; the "Send to Media Library" card should appear
   in the right-hand panel showing the filename.
3. Fill in alt text (required — the form re-renders with an error if it's empty
   or under 3 characters), location, and target site, then submit.
4. Verify the sheet gained a row with `status = pending`.

When it works, publish the add-on to your Workspace domain via internal
Marketplace publishing so other staff can use it.

## 4. Deploy the Web App

1. Deploy → **New deployment** → type: **Web app**.
2. **Execute as: Me** (the script owner).
3. **Who has access: Anyone.** (Yes, genuinely public — the shared token in the
   request body is the auth barrier. "Anyone with a Google account" would force
   WordPress into a Google OAuth flow.)
4. Copy the `/exec` URL.

> **Redeployment rule:** to ship script changes later, use
> **Deploy → Manage deployments → ✏️ edit → Version: New version**.
> Creating a *new* deployment mints a *new* URL and silently breaks the plugin.

## 5. Test the API manually (before touching WordPress)

```bash
URL="https://script.google.com/macros/s/DEPLOYMENT_ID/exec"
TOKEN="your-shared-token"

# Claim pending rows (should flip them to "processing" in the sheet)
curl -sL -X POST "$URL" -H 'Content-Type: application/json' \
  -d "{\"action\":\"pending\",\"token\":\"$TOKEN\",\"limit\":5}"

# Fetch a claimed file's bytes
curl -sL -X POST "$URL" -H 'Content-Type: application/json' \
  -d "{\"action\":\"file\",\"token\":\"$TOKEN\",\"row_id\":\"ROW_UUID\",\"file_id\":\"DRIVE_FILE_ID\"}"

# Ack the result
curl -sL -X POST "$URL" -H 'Content-Type: application/json' \
  -d "{\"action\":\"ack\",\"token\":\"$TOKEN\",\"results\":[{\"row_id\":\"ROW_UUID\",\"status\":\"done\",\"wp_url\":\"https://example.org/x.jpg\",\"wp_attachment_id\":\"123\",\"error_message\":\"\"}]}"
```

Note `-L` (follow redirects): Apps Script answers with a 302 to
`script.googleusercontent.com`. Confirm every call returns JSON, `pending`
flips rows to `processing`, `file` refuses file IDs that are *not* in a
`processing` row, and `ack` writes the outcome columns.

## 6. Install the WordPress plugin

1. Copy `wordpress-plugin/drive-media-importer/` into `wp-content/plugins/`.
2. **Network Activate** it (Network Admin → Plugins).
3. Define the token in `wp-config.php` (preferred over storing it in the DB):

   ```php
   define( 'DMI_SHARED_TOKEN', 'your-shared-token' );
   ```

4. Network Admin → Settings → **Drive Media Importer**: set the Web App URL,
   batch size, and max file size. Leave "Enable polling" OFF for now.
5. Queue an image from Drive, then click **Run one poll cycle now** (or run
   `wp dmi poll` with WP-CLI). Verify: the image appears in the target
   subsite's media library **with alt text populated** (Media → edit the
   attachment → Alternative Text), and the sheet row shows `done` plus the URL.

## 7. Real cron on WP Engine

WP-Cron fires on page loads and silently drifts on low-traffic sites.

1. In `wp-config.php`:

   ```php
   define( 'DISABLE_WP_CRON', true );
   ```

2. In the WP Engine dashboard (or a support ticket), add a server cron hitting
   the **main site's** `wp-cron.php` every 5 minutes:

   ```
   */5 * * * * wget -q -O /dev/null https://your-main-site.example/wp-cron.php?doing_wp_cron
   ```

3. Turn on **Enable polling** in the plugin settings. Optionally enable the
   working-hours window (evaluated in the site timezone via `wp_timezone()`).
   Consider leaving it off — polling is nearly free, and a Saturday upload
   sitting in limbo until Monday may be worse than running 24/7.

## 8. Verification checklist

- [ ] Card refuses empty/too-short alt text with an inline error.
- [ ] Queued row lands with `status = pending` and a UUID `row_id`.
- [ ] `pending` claims rows and flips them to `processing` before returning.
- [ ] Two rapid polls never claim the same row (LockService + claim-first).
- [ ] `file` refuses a valid file ID whose row is not in `processing`.
- [ ] A wrong token gets `unauthorized` on every action.
- [ ] Import lands on the correct subsite with thumbnails generated.
- [ ] Attachment carries `_wp_attachment_image_alt` (WCAG 1.1.1).
- [ ] A corrupt/oversized/non-image file marks only its own row `error`
      with an actionable message; the rest of the batch completes.
- [ ] Kill the poll mid-batch: rows stuck in `processing` return to `pending`
      after `STALE_MINUTES`.
- [ ] Overlapping manual + cron runs: the second bails on the transient lock.
- [ ] Settings screen is keyboard navigable with labeled controls.

## Security notes

- The Web App URL is public; the token is the only barrier. 32+ random bytes,
  rotate it if the Apps Script project is ever shared with another editor
  (update both the Script Property and `DMI_SHARED_TOKEN`).
- The `file` action only serves files referenced by an in-flight
  (`processing`) row, so a leaked token cannot enumerate the owner's Drive.
- WordPress sniffs MIME from the decoded bytes and allowlists
  JPEG/PNG/GIF/WebP; filenames are sanitized and the extension is forced to
  match the detected type.
- Drive files are never made public; the Web App executes as the script owner.
