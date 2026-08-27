# Google Drive → WordPress drip uploader

Bring a big batch of photos into your media library gradually from a Google
Drive folder — slow during the day, faster at night — so it never slows the
site down. There are two ways to wire it up. You can use either or both.

**HEIC/HEIF photos are skipped by this importer** (there is no browser here to
convert them). Convert those by uploading them through **FileMedia** instead,
or export them as JPEG before dropping them in Drive.

---

## Option A — Apps Script push (easiest; no Google Cloud project)

A tiny script runs in *your* Google account and sends files to your site.

1. In WordPress, go to **Media ▸ FileMedia ▸ (⋯) ▸ Settings ▸ "Google Drive
   import"**. Copy the **Push token** and the **Endpoint URL**.
2. Open <https://script.google.com> → **New project**. Delete the sample code
   and paste in the contents of **`Code.gs`** (in this folder).
3. Edit the `CONFIG` block at the top:
   - `WP_INGEST_URL` → the Endpoint URL from step 1.
   - `PUSH_TOKEN` → the Push token from step 1.
   - `SOURCE_FOLDER_ID` → the long ID in your Drive folder's URL, the part
     after `/folders/`.
   - Optionally adjust `DAY_RATE`, `NIGHT_RATE` and the day/night hours.
4. Run the `installTrigger` function once. Approve the permissions prompt
   (it needs to read your Drive and reach your site).
5. Drop photos into the Drive folder. Every 5 minutes the script sends a few
   (more at night). Uploaded files move to an **"Imported to WordPress"**
   sub-folder; skipped ones to **"Skipped (not imported)"**.

To pause, run `removeTriggers`. To send a burst right now, run `runImport`.
Progress also shows in WordPress on the same settings page.

---

## Option B — WordPress pulls (Google service account)

WordPress downloads from the Drive folder on its own schedule — nothing runs in
Drive. This needs a one-time Google Cloud setup.

1. Go to <https://console.cloud.google.com> → create (or pick) a project.
2. **APIs & Services ▸ Library ▸ Google Drive API ▸ Enable.**
3. **APIs & Services ▸ Credentials ▸ Create credentials ▸ Service account.**
   Give it a name and create it (no roles needed).
4. Open the service account ▸ **Keys ▸ Add key ▸ Create new key ▸ JSON.**
   A `.json` file downloads. Open it and copy its entire contents.
5. In WordPress ▸ FileMedia Settings ▸ Google Drive import ▸ **Option B**:
   - Tick **Enable pulling**.
   - Paste the folder URL/ID into **Drive source folder**.
   - Paste the JSON into **Service-account JSON key**.
   - Set the daytime/night throttle if you like. Save.
6. In Google Drive, **share the source folder** with the service account's
   email address (the `client_email` in the JSON, ends in
   `…iam.gserviceaccount.com`) as **Editor**.

WordPress checks the folder every 5 minutes and imports up to the current
rate's worth of files, moving each into "Imported to WordPress" (or "Skipped
(not imported)") so it is never processed twice. Last-run status and a short
log appear on the settings page.

> Note: WordPress must be able to make outbound HTTPS calls to
> `googleapis.com` (most hosts, including WP Engine, allow this).
