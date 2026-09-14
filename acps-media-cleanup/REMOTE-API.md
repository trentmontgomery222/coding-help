# Hidden remote photo API — ACPS Unused Media Cleanup (FileMedia)

A private, **unadvertised** API for uploading and managing photos from off-site
(a phone shortcut, a script, another server). Like the self-update system it has
**no menu link** and its routes are **hidden from the public REST index**. It is
OFF until you turn it on.

## 1. Turn it on

Reach the settings only by typing the URL (login + `manage_options` required):

```
/wp-admin/admin.php?page=acps-mc-remote
```

1. Check **Enable remote API**.
2. Copy the **Secret key** (or tick “Generate a NEW key” and save).
3. Optionally set the default folder, the per-minute / per-day limits, the max
   upload size, and whether the delete endpoint is allowed. **Save.**

There is deliberately no link to this page anywhere — bookmark the URL.

## 2. Authentication

Every request must carry the secret key, either as a header (preferred) or a
field:

```
X-ACPS-Key: <your key>
```

Requests without a valid key get `401`. After repeated wrong keys an IP is
**locked out for 15 minutes** (`429`).

## 3. Endpoints

Base: `/wp-json/acps-mc/v1/remote`

| Method & path | Body | Purpose |
|---|---|---|
| `GET  /ping` | — | Auth/health check → `{ ok, pong, version }`. |
| `POST /upload` | multipart `file`, **or** JSON `{ filename, content_base64, folder_id? }` | Upload one image. |
| `GET  /list?limit=20` | — | Recent images → `{ items:[{id,url,filename,date,mime,folder}] }`. |
| `POST /move` | `{ id, folder_id }` | Move an item to a FileBird folder (`0` = Uncategorized). |
| `POST /delete` | `{ id }` | Move an item to Trash (reversible; can be disabled). |

All responses are JSON with an `ok` boolean; errors add `code` and `message`.

### Upload examples

Multipart:

```bash
curl -X POST "https://YOUR-SITE/wp-json/acps-mc/v1/remote/upload" \
  -H "X-ACPS-Key: YOUR_KEY" \
  -F "file=@photo.jpg" \
  -F "folder_id=12"
```

Base64 JSON:

```bash
curl -X POST "https://YOUR-SITE/wp-json/acps-mc/v1/remote/upload" \
  -H "X-ACPS-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d '{"filename":"photo.jpg","content_base64":"'"$(base64 -w0 photo.jpg)"'","folder_id":12}'
```

Success returns `{ ok:true, id, url, filename, folder, mime }`.

## 4. Limits & protection (anti-spam)

- **Per-IP rate limit** — configurable requests/minute (default 30) → `429`.
- **Per-day global cap** — configurable total uploads/day (default 500, `0` =
  unlimited) → `429`.
- **Size limit** — configurable max MB (default 20) → `413`.
- **Type enforcement** — only real image files (JPEG/PNG/GIF/WebP/HEIC/HEIF).
  The bytes are checked, so a script renamed `.jpg` is rejected (`415`).
- **Brute-force lockout** — an IP is blocked for 15 minutes after too many
  wrong-key attempts.
- **Delete is Trash-first** — never a hard delete over the API, and the whole
  delete endpoint can be turned off.

## 5. Notes

- Rate-limit counters use WordPress transients; behind a CDN/proxy the client IP
  is taken from `X-Forwarded-For` for throttling only (never for auth), and the
  per-day global cap bounds total abuse regardless.
- Turning the API off disables every endpoint at once.
- The key lives in the plugin settings; regenerate it any time on the hidden
  page (this invalidates the old key immediately).

*Reuse:* the whole API is one class (`includes/class-acps-mc-remote-api.php`)
plus its `remote_api_*` settings and the hidden admin page — mirroring the
self-update system’s structure.
