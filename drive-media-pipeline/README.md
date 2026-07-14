# Drive → Sheet → WordPress Media Library Pipeline

Staff select an image in Google Drive, fill in **alt text (required)**,
location, and destination subsite in an add-on card; within a few minutes a
WordPress plugin imports the image into the right multisite media library and
writes the resulting URL back to the queue sheet.

```
┌────────────┐  card submit   ┌──────────────┐   poll (outbound   ┌────────────────┐
│ Google     │ ─────────────▶ │ Google Sheet │ ◀─ HTTPS only) ──▶ │ WordPress      │
│ Drive      │  Apps Script   │ (job queue + │   Apps Script      │ multisite      │
│ add-on     │  appends row   │  audit log)  │   Web App doPost   │ plugin (cron)  │
└────────────┘                └──────────────┘                    └────────────────┘
```

**Why this shape:** WordPress never authenticates to Google (no service
account, no OAuth in PHP, no key file), and WordPress exposes **no inbound
endpoint** — all traffic is outbound, so WP Engine's Global Edge Security WAF
never sees an inbound POST to block.

## Contents

| Path | What it is |
|---|---|
| `apps-script/` | One Apps Script project: the Drive add-on card **and** the `doPost` Web App |
| `wordpress-plugin/drive-media-importer/` | Standard WordPress plugin (activate from the normal Plugins screen): cron poller + importer + Settings → Drive Media Importer screen |
| `docs/SETUP.md` | Step-by-step deployment and verification guide |

## Data flow

1. **Queue** — the card validates alt text (WCAG 1.1.1, required) and appends a
   sheet row: `status = pending`, UUID `row_id`, bare Drive `file_id`.
2. **Claim** — the plugin POSTs `action:"pending"`; the script (under a
   LockService lock, after sweeping stale `processing` rows back to `pending`)
   flips claimed rows to `processing` *before* returning them.
3. **Fetch** — per row, `action:"file"` returns base64 bytes. The script only
   serves files referenced by a row currently in `processing`, so a leaked
   token can't crawl Drive. Files over the size cap are rejected (Option A).
4. **Import** — the plugin sniffs the real MIME type from the bytes
   (JPEG/PNG/GIF/WebP allowlist), then `switch_to_blog(target_site)` →
   `wp_upload_bits` → `wp_insert_attachment` → thumbnail metadata → alt text
   meta → `restore_current_blog()` (in `finally`).
5. **Ack** — one batched `action:"ack"` writes `done`/`error`, `wp_url`,
   `wp_attachment_id`, and a human-actionable `error_message` per row. The
   sheet is the uploader-facing status/notification surface.

## Built-in guards

- Shared token travels in the **POST body** (headers are stripped across the
  Apps Script 302 redirect); the plugin re-POSTs across redirects itself so
  the body is never dropped, and converts HTML error pages into structured
  errors instead of JSON-parse crashes.
- LockService on every sheet write; transient lock against overlapping polls;
  activate the plugin on **one site only** so there is a single poller.
- Stale-`processing` sweep re-queues rows if WordPress dies mid-batch.
- Working-hours gate uses `wp_timezone()`, never the server's UTC clock.
- One bad file fails only its own row, never the batch.

See `docs/SETUP.md` for the ordered build/deploy steps, curl smoke tests, and
the verification checklist.
