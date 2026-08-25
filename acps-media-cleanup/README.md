# ACPS Unused Media Cleanup

A **single-site** WordPress plugin that safely finds and removes media library
files (images, PDFs, documents, videos, etc.) that are **not used anywhere** on
your site — and lets you clean them up **folder by folder** so you can deal with
large libraries in bulk.

Built for the ACPS website (WordPress + **FileBird** media folders +
**Beaver Builder**), but works on any single-site install.

> This is **not** a multisite/network plugin. Everything is managed from the
> normal wp-admin of the site it is activated on, under **Media Cleanup**.

## Two tools in one plugin

1. **Unused Media Cleanup** (the *Media Cleanup* menu) — scans the whole site,
   reports which files are unused and *where* the used ones are used, and lets
   you trash them safely, folder by folder.
2. **Media Manager** (the *Media Manager* screen, and optionally the default
   *Media* screen) — a better day-to-day media library:
   - FileBird folder sidebar with **collapsible** sub-folders and counts, plus
     **All / Unused / Used / Unfiled** views (cleanup is built right in).
   - **250×250 square previews**, all shown at once (no "load more"), each
     **colour-coded**: green = used, red = unused, grey = not yet scanned.
   - Real **PDF thumbnails** (when the server generates previews).
   - Search / type / sort; a detail drawer with inline editing, copy-URL,
     move-to-folder, and on-demand "where used".
   - **Drag-and-drop upload** anywhere on the page with a per-file **progress**
     panel; after each upload a popup files it (recent-folder chips) and hands
     you its URL.
   - **Bulk organize**: move / set alt / trash on many files at once.
   - Built-in **HEIC → JPEG** conversion (auto on upload + a manual button),
     when the server's Imagick supports HEIC.
   - A **nightly automatic scan** (~2am) keeps the used/unused colours current.

   It also adds Copy-URL, folder and "where used" controls **into** the normal
   media modal without replacing it, so Beaver Builder and FileBird keep working.

---

## Why it is safe

Deleting the wrong media file is the thing everyone is afraid of. This plugin is
designed so that is very hard to do:

1. **It errs toward "used."** A file is only ever reported as *unused* when the
   scanner cannot find a single reference to it anywhere it looked. Any doubt →
   the file is kept.

2. **It looks far beyond page content.** The common cause of a media-cleanup
   plugin deleting a live image is that it only checked `post_content`. Modern
   sites keep images in page-builder data, custom fields, widgets, and theme
   options. This scanner checks **all** of it:
   - Page & post content (classic editor **and** the block editor)
   - **Every** post-meta value — this covers **Beaver Builder** (`_fl_builder_data`),
     featured images, ACF and other custom fields, Yoast OG images, nav-menu
     items, Robo Gallery, and any other page-builder data
   - **Every** option — site logo, site icon, theme mods, widgets, plugin settings
   - Term meta and user meta
   - Featured images, gallery shortcodes, `wp-image-###` classes, site logo & icon
     (explicitly)
   - *(optional)* Active + child **theme files** (PHP/CSS/JS) — catches images
     hard-coded into templates
   - *(optional)* The **Beaver Builder CSS cache** in `/uploads/bb-plugin/cache`

   Matching is done by **filename** (page builders store the file URL, so this
   catches them even inside serialized data) **and** by **attachment ID**.
   Resized thumbnails, `-scaled`, and edited copies all resolve to the same file.

3. **Nothing is deleted by scanning.** Scanning only produces a report.

4. **Deletion defaults to reversible Trash.** Files are moved to Trash
   (`wp_trash_post`) — the files stay on disk and can be **restored** from the
   *Trash & Log* tab. Permanent deletion is a separate, opt-in mode.

5. **Multiple guard rails**, all enforced again on the server at delete time:
   - Protect files uploaded within the last *N* days (default **30**)
   - Never-delete list for individual files (**Protect** button)
   - Protected folders (and their sub-folders)
   - Excluded file types (e.g. never delete `pdf`)
   - "I have a backup" confirmation before any deletion
   - Only files the latest scan explicitly marked **unused** can be deleted

6. **Full audit log** of every trash / restore / permanent delete.

---

## Installing

1. Copy the `acps-media-cleanup` folder into `wp-content/plugins/`.
   (Or zip the folder and upload it via **Plugins → Add New → Upload Plugin**.)
2. Activate **ACPS Unused Media Cleanup**.
3. Go to **Media Cleanup** in the admin menu.

## Using it

1. **Scan** tab → *Scan now*. A progress bar runs the scan in small batches so
   it will not time out, even on large libraries.
2. **Unused by Folder** tab → your FileBird folder tree appears on the left with
   an unused count and reclaimable size per folder. Click a folder to list its
   unused files (with thumbnails). Tick files (or *select all*), then
   **Move selected to Trash**.
3. Check your site still looks right. Anything you need back → **Trash & Log**
   tab → *Restore*.
4. When you are confident, empty the trash (*Delete forever*), or switch
   **Settings → Deletion mode** to *permanent*.

### Recommended first run

- Keep **Deletion mode = Trash** (the default).
- Keep **Protect recent uploads** on.
- **Take a backup first** anyway — see the limitation below.
- Trash a folder, load the affected pages, confirm, then empty the trash.

---

## Known limitations (please read)

No tool can detect a reference that lives **outside** this WordPress database —
for example an image URL hard-coded on a *different* website, in an email
template stored elsewhere, or pulled by a third-party service. Filenames used
only inside minified/combined CSS from a caching plugin can also be missed.

This is exactly why the plugin **trashes first and keeps a restore path**, and
why you should keep a recent backup before permanently deleting. Trash → verify
→ empty trash is the safe workflow.

---

## How it works (for developers)

```
acps-media-cleanup/
├── acps-media-cleanup.php          Bootstrap, constants, activation/uninstall hooks
├── uninstall.php                   Removes options + log table on delete
├── includes/
│   ├── class-acps-mc-settings.php  Settings + safe defaults + sanitisation
│   ├── class-acps-mc-logger.php    Audit-log custom table
│   ├── class-acps-mc-folders.php   FileBird (table/taxonomy) + date-folder fallback
│   ├── class-acps-mc-scanner.php   Batched usage index + classification (the core)
│   ├── class-acps-mc-deleter.php   Server-side re-validated trash/delete/restore
│   ├── class-acps-mc-admin.php     Menu, tabs, settings screen
│   └── class-acps-mc-ajax.php      Nonce-guarded AJAX endpoints
└── assets/
    ├── admin.css
    └── admin.js
```

- **Scan** runs as phases (`index_posts`, `index_postmeta`, `index_options`,
  `index_termmeta`, `index_usermeta`, `index_extras`, `classify`), each paged via
  AJAX. Indexing extracts referenced filenames/IDs — **together with the source
  they came from** (page, option, widget, theme file, …) — into the
  `{prefix}acps_mc_index` table. The `classify` phase looks up every attachment
  against that table and records **where** it is used.
- **Accuracy:** attachment posts and their own bookkeeping meta
  (`_wp_attached_file`, `_wp_attachment_metadata`, backup sizes, alt text,
  optimiser data) are **never** indexed, so a file can never "match itself." This
  was the cause of the earlier "everything looks used" behaviour.
- **Resumable:** the index lives in a table and the scan position is saved to
  `acps_media_cleanup_scan_meta['cursor']` after every batch, and results are
  written incrementally to `acps_media_cleanup_results`. If a scan is
  interrupted, the Scan tab offers **Resume** to continue from where it stopped.
- Every deletion is re-validated by `ACPS_MC_Deleter::can_delete()` regardless of
  what the browser sends, so a stale page can never delete a now-used file.

### Requirements

- WordPress 5.6+
- PHP 7.2+
- Capabilities: `upload_files` to use the Media Manager; `manage_options`
  (administrators) for scanning, Trash and Settings.
- HEIC conversion additionally needs the server's **Imagick** to be built with
  HEIC/libheif support (the Settings page shows whether it is).

## Roadmap — Google Drive sync (not built yet)

Syncing files from Google Drive is planned but **not implemented**, because it
needs credentials that only you can create and authorize:

1. A **Google Cloud project** with the **Drive API** enabled.
2. An **OAuth 2.0 client** (client ID + secret) with this site's domain as an
   authorized redirect URI.
3. A one-time **"Connect Google Drive"** consent flow, after which the plugin
   stores a refresh token and can list/pull files from a chosen Drive folder
   into the WordPress media library (with the same folder-placement and
   HEIC-conversion steps as a normal upload).

Once you have the Google Cloud OAuth client, this can be added as a dedicated
"Google Drive" tab in Settings. It is intentionally kept separate from the rest
of the plugin so nothing here depends on external credentials to work.

## License

GPL-2.0-or-later.
