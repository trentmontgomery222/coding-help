=== ACPS Unused Media Cleanup ===
Contributors: acps
Tags: media, cleanup, unused media, filebird, beaver builder
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.12.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely find and delete media library files that are not used anywhere on your
single-site WordPress install. Folder-based bulk cleanup. Trash first, restore
anytime.

== Description ==

ACPS Unused Media Cleanup scans your entire site to find media files (images,
PDFs, documents, videos, etc.) that are not referenced anywhere, then lets you
clean them up folder by folder so large libraries are manageable.

It is built to be **very safe**:

* Scanning changes nothing — it only produces a report.
* Detection errs toward "used": any doubt keeps the file.
* It checks far more than page content — every post-meta value (so Beaver
  Builder, ACF and other page builders/custom fields are covered), every option
  (site logo, icon, widgets, theme mods), term meta, user meta, featured images,
  galleries, and optionally your theme files.
* Deletion defaults to reversible Trash. Files stay on disk and can be restored.
* Guard rails: protect recent uploads, protected files/folders/file-types, a
  backup confirmation, and server-side re-validation so a used file can never be
  deleted.
* Full audit log of every action.

This is a single-site plugin. It does not add any network/multisite screens.

In-browser HEIC → JPEG conversion is powered by the bundled heic2any library
(MIT licensed, https://github.com/alexcorvi/heic2any), which wraps a WebAssembly
build of libheif. It runs entirely in the browser; no media is sent anywhere.

= Folder support =

FileBird folders (both the modern custom-table and older taxonomy storage) are
supported. With no folder plugin present, files are grouped by upload date.

== Installation ==

1. Upload the `acps-media-cleanup` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Open "Media Cleanup" in the admin menu and click "Scan now".

== Frequently Asked Questions ==

= Is it safe to run on a live site? =

Scanning is completely read-only. For deletion, keep the default Trash mode,
verify your site, and only then empty the trash. Always keep a recent backup.

= Can it miss a file that is actually in use? =

It cannot see references that live outside your WordPress database (for example
an image URL hard-coded on another website). That is why it trashes first and
lets you restore, and why a backup is recommended before permanent deletion.

= Does it work with page builders? =

Yes. It scans all post meta, which is where Beaver Builder and similar builders
store their image references (both the file URL and the attachment ID).

== Changelog ==

= 1.12.2 =
* The folder sidebar now stays on screen while you scroll the media grid, and
  the folder list scrolls inside it (New folder, the All media/Uncategorized
  views, and the folder search stay pinned at the top). No more one giant page.
* Opening a folder no longer jumps you back to the top of the page.
* Classic view polish to better match the native FileBird library: the
  "New folder" button is FileBird blue regardless of your wp-admin colour
  scheme, and folders use a clean outline folder icon instead of the chunkier
  dashboard icon.

= 1.12.1 =
* Classic view now closely matches the native WordPress + FileBird media
  library: the folder sidebar shows the folder icons, a count "pill" on each
  folder (including "All media" and "Uncategorized"), a light-blue highlight on
  the active folder, and a "New folder" button pinned at the top; the grid shows
  the filename below each square thumbnail instead of only on hover. (Refined
  view is unchanged — it keeps the colour-coded card borders.) A small
  used/unused dot stays in each thumbnail's corner so you don't lose the usage
  indicator.

= 1.12.0 =
* Uploads now TRULY resume after a refresh or crash — automatically, with no
  re-selecting files. Each pending file's data is saved to your browser's
  IndexedDB as it's queued, so if you leave the page or it reloads mid-upload,
  the unfinished files upload themselves the next time you open FileMedia. A
  per-file idempotency id means a file that had actually finished (but whose
  confirmation was lost to the interruption) is recognised and never uploaded
  twice. If a file can't be saved (private-browsing mode or full storage), it
  falls back to the old "drag it in again" prompt for just those files.
  Persistent-storage is requested so the browser is less likely to evict the
  saved data. (Verified in a real browser: file bytes survive a reload intact.)

= 1.11.0 =
* New: Google Drive drip-importer. Drop a big batch of photos into a Drive
  folder and have them trickle into the media library over time — slower during
  the day, faster at night — so a large import never slows the site down. Two
  ways to wire it up (use either or both), set up under FileMedia ▸ Settings ▸
  "Google Drive import":
  - Apps Script push: a small script (in your own Google account, provided in
    the plugin's google-apps-script/ folder) posts files to a token-protected
    REST endpoint. No Google Cloud project needed.
  - WordPress pull: WordPress downloads from a shared Drive folder on a schedule
    using a Google service-account key.
  Both paths file imports into a folder you choose, skip byte-for-byte
  duplicates, and move each processed Drive file into an "Imported to WordPress"
  (or "Skipped (not imported)") sub-folder so nothing is ever imported twice.
  HEIC/HEIF files are skipped by the importer (there's no browser to convert
  them) — import those through FileMedia instead. See google-apps-script/README.md
  for step-by-step setup.

= 1.10.0 =
* Renamed the screen to **FileMedia** (menu, headings and settings). Nothing
  else changes — same page, same URL.
* New in-house uploader (replaces the built-in WordPress uploader inside
  FileMedia) so uploads finally behave the way you'd expect:
  - HEIC really converts now. The previous approach relied on the WordPress
    uploader handing the file to the browser converter, which didn't reliably
    happen; FileMedia now controls the whole upload, converts each HEIC to JPEG
    in the browser first, and uploads the JPEG.
  - Uploads go into the folder you're currently viewing (a real folder), and
    the popup still lets you move them somewhere else.
  - Generic camera names (IMG_1234, DSC_, "image 3", "test", screenshots, …)
    must be renamed — even in bulk uploads, where they used to slip through.
  - Each progress row shows the time it took and when it finished on hover; a
    failed upload shows the reason on hover.
  - Single-file uploads copy the file's link to your clipboard when you finish.
  - If you leave or refresh mid-upload, FileMedia tells you which files didn't
    finish when you come back so you can drop them in again. (A browser can't
    silently resume files it can no longer read — the planned Google Drive
    drip-uploader is the true unattended path for very large batches.)
* Folder search box at the top of the folder list — type to filter folders.
* "Select" is now a mode: turn it on and click photos to highlight them (Shift-
  click selects a range), instead of ticking one checkbox at a time. Added a
  "Select all shown" button.
* Cleaner preview tiles: the select checkbox (top-left), copy-link button
  (top-right) and used/unused dot (bottom-right) now sit in their own corners
  so they're easy to see and never overlap.

= 1.9.0 =
* HEIC conversion now happens in your browser. Some servers (including some
  managed hosts) run a hardened image library that refuses modern iPhone HEICs
  with "too many auxiliary image references," which no PHP-side change can work
  around. The Media Manager now converts HEIC/HEIF photos to JPEG right in the
  browser — using a bundled WebAssembly build of libheif (heic2any) — *before*
  the file is uploaded. Benefits:
  - Works regardless of what the server's image library supports.
  - Nothing is sent to any third-party service; the photo is converted on your
    own computer, so it's safe for sensitive images.
  - The converter shows a "Converting HEIC…" row, then uploads the JPEG with a
    normal progress bar and the usual place-in-folder / rename popup.
  If a photo can't be converted in the browser for any reason, the original is
  still uploaded so nothing is lost. Server-side conversion remains as a
  fallback for uploads made outside the Media Manager. Controlled by the
  existing "Convert HEIC/HEIF uploads to JPEG automatically" setting.

= 1.8.3 =
* Fixed the upload progress bars and, more importantly, uploads that never
  appeared in the grid until a manual page reload. Root cause: on some
  WordPress builds the uploader never fired the "file added" / "progress"
  callbacks the Media Manager was relying on, so the progress rows were never
  created and — because the whole post-upload step (including the grid refresh)
  keyed off a counter set in that callback — nothing ran after the files
  finished. The manager now listens to the uploader's underlying events
  directly, which always fire, so progress shows and new files appear right
  away. Added a grid re-sync when the upload queue drains as a safety net.

= 1.8.2 =
* Fixed new uploads landing in a hidden sub-folder. FileBird auto-files every
  new upload into whichever folder is currently selected, so files were ending
  up somewhere you couldn't see without turning on "Include sub-folders." The
  Media Manager now files every upload into Uncategorized (top level) unless you
  explicitly place it in a folder — including the bulk "Just upload" path, which
  previously skipped that step.
* HEIC: another attempt at "Too many auxiliary image references" on modern
  iPhone photos. The plugin now raises ImageMagick's "list-length" resource
  limit before decoding (that limit is what this specific error trips) and reads
  only the primary image. If your host's ImageMagick policy hard-caps that
  limit, conversion can still be blocked at the server level — in that case the
  file still uploads and the error now explains the exact cause and the fixes
  (ask the host to raise the ImageMagick "list-length" policy or update libheif,
  or set the iPhone to shoot "Most Compatible" so photos arrive as JPEG).

= 1.8.1 =
* Fixed uploads that stopped reporting progress and quit after a few files: the
  Media Manager now uses the uploader's standard per-file callbacks again, so
  progress bars update, the whole batch uploads, and cancelling a file marks the
  right row.
* HEIC: fixed "Too many auxiliary image references" on modern iPhone photos
  (which bundle HDR/depth auxiliary images) by converting from the primary image
  only. Auto-conversion now runs on upload again (inline) rather than waiting on
  WP-Cron, so uploaded HEICs are JPEGs right away; the manual button is unchanged.
* Restore now returns a file to the folder it was in before it was trashed
  (FileBird can drop the folder mapping on trash; the plugin remembers it and
  re-files the file on restore).
* Removed a duplicate "Uncategorized" entry in the folder sidebar — there is now
  a single Uncategorized view at the top that consistently lists unfiled files.
* Made the "Select" control and the toolbar filters a proper, comfortable size.

= 1.8.0 =
* New: automatic duplicate-file detection. Every file is content-hashed (not
  just compared by name), so an exact re-upload of an existing file is caught
  even if it was renamed or WordPress auto-suffixed it (photo-1.jpg,
  photo-2.jpg, etc.):
  - Uploading a duplicate now shows a warning right in the upload popup, with
    the existing file to compare against and one-click actions: view the
    existing file, delete this copy, or keep both.
  - A new "Find duplicates" button reviews the whole library (hashing any
    older files that predate this feature as it goes) and groups matching
    files together so you can pick which one to keep and trash the rest, with
    the same server-side re-validation and trash-first safety as the rest of
    the plugin.

= 1.7.3 =
* Faster uploads when "Convert HEIC/HEIF uploads to JPEG automatically" is on:
  conversion used to run inline as part of each file's upload response — real
  work (often 1-3+ seconds per photo) that, since uploads process one file at
  a time, compounded across a batch of iPhone photos. It's now handed to
  WP-Cron instead, so each upload finishes immediately and the photo converts
  moments later in the background (the same mechanism this plugin already
  uses for its nightly scan). The manual "Convert to JPEG" button still
  converts immediately if you don't want to wait.

= 1.7.2 =
* Fixed unreliable upload progress/cancel in the Media Manager: canceling one
  file during a bulk upload could sometimes mark a *different*, still-uploading
  file as failed (or done). Each row is now tied to the exact file it belongs
  to via the uploader's own per-file events, not "whichever row happens to be
  first," so progress, cancel and errors always land on the right row.
* Bulk uploads now ask, once every file has finished, whether to edit each one
  individually (rename / place in a folder, one at a time — the existing flow)
  or just leave them uploaded (Uncategorized, edit later). Uploading a single
  file still always opens the edit step, same as before.
* The post-upload edit popup (rename / copy URL / place in folder) now opens
  instantly using the data the upload itself already returned, instead of
  waiting on an extra server round trip first. The recent-folder chips and the
  "add to grid" step fill in a moment later in the background without holding
  up the popup or blocking you from moving to the next file.

= 1.7.1 =
* Fixed HEIC/HEIF uploads: WordPress core was rejecting .heic/.heif files
  outright ("this file type is not permitted") before the plugin's converter
  ever ran, because the plugin never told WordPress those extensions were
  allowed. The upload allow-list and the file-type check are now both
  patched so HEIC photos (e.g. straight from an iPhone) actually reach the
  server and get converted to JPEG as intended.

= 1.7.0 =
* Folder browsing now works like a file explorer. Opening a folder shows only the
  files directly in it, plus clickable tiles for its sub-folders (with a cover
  image and file count). A new "Include sub-folders" toggle (off by default)
  switches to showing every file from the folder and all its sub-folders at once.

= 1.6.0 =
* Menu: the Media Manager no longer has its own top-level tab — it lives entirely
  under the WordPress "Media" menu (with Media Trash and Media Settings beside it).
* Instant delete & rename: deleting hides the item immediately and trashes it in
  the background; renaming updates the name on screen instantly and renames the
  files (no re-encoding) in the background. The used/in-use warning now uses the
  last scan result, so it never adds a slow lookup.
* Uploads no longer reload the whole grid — the new file is added straight to the
  top of the list. The upload popup gained a Cancel button, and each in-progress
  upload has its own cancel (×).
* The manager reopens where you left off: the last folder, your scroll position,
  and the file whose panel was open are all restored.
* The Classic / Refined switch now restyles the whole page (in Classic the folder
  list drops its icons to match the plain look), and the view / size controls moved
  to the top-right corner instead of sitting above the thumbnails.

= 1.5.0 =
* View switcher in the Media Manager toolbar: "Classic" (default) looks like the
  normal WordPress media grid — plain thumbnail tiles, filename on hover — while
  "Refined" is the bordered, colour-coded card view. Every extra feature (copy
  link, selection, the detail drawer, used/unused indicator) works in both. Your
  choice is remembered per browser.

= 1.4.0 =
* Cleanup fully merged into the Media Manager: it is now the single top-level
  menu, with Trash and Settings as sub-pages (no separate "Media Cleanup" tab).
* File renaming (with a warning if the file is in use, since renaming breaks
  hard-coded links) and folder renaming / deleting (folder delete keeps every
  file — contents move up a level).
* Upload guard: uploading a file with a generic camera name (IMG_1234, DSC_,
  PXL_, Screenshot…) now requires a descriptive rename before you can continue.
* Folders are collapsed by default and expand on demand; the folder sidebar is
  a fixed 300px, never clips names (they wrap) and never hides content behind a
  scrollbar. "All media" and "Uncategorized" sit at the top.
* Adjustable preview size (Small / Medium / Large) with a smaller default, and a
  copy-link button in the top-right of every thumbnail.
* "Used on page" filter: pick a page and see just the media used on it.
* Faster grid: the file list is cached and only rebuilt when something changes,
  so repeat loads are near-instant.

= 1.3.0 =
* Media Manager grid: fixed 250×250 square previews, every file shown at once
  (no "load more"), and a colour indicator per file — green = used, red =
  unused, grey = not yet scanned — plus a matching legend.
* Cleanup is now built into the Media Manager: "Unused" and "Used" smart views
  in the sidebar, a "Scan usage now" button with a progress bar, and the last
  scan time / unused count shown in the toolbar.
* Collapsible folder tree: sub-folders can be expanded/collapsed (state
  remembered per browser).
* PDF (and other preview-able files) now show real thumbnails, not a generic
  icon, when the server has generated previews.
* Drag-and-drop upload: drop files anywhere on the page; a per-file progress
  panel shows upload status.
* Built-in HEIC/HEIF → JPEG converter: automatic on upload (when the server
  supports it) and a manual "Convert to JPEG" button on HEIC files.
* Nightly automatic usage scan (around 2am) via WP-Cron, so used/unused stays
  current without running a scan by hand. Runs in bounded slices and resumes
  itself so large libraries never time out.

= 1.2.0 =
* New Media Manager: a full-screen replacement for the Media Library screen with
  a FileBird folder sidebar, search/type/sort filters, a fast thumbnail grid, and
  a detail drawer for inline editing (title/alt/caption/description), copy-URL
  (per size), move-to-folder, and on-demand "where is this used?".
* Bulk organize: select many files and move them to a folder, set alt text, or
  send to Trash in one action (with a safety warning if any look used).
* Upload flow: after each upload the manager shows a popup to place the file in a
  folder (recent/common folders as one-click chips) and copy its URL.
* Core media modal enhanced (not replaced): the attachment details panel now has
  a Copy-URL button, a folder selector, and a "Where is this used?" button, so
  Beaver Builder / FileBird keep working unchanged.
* Optional: make the Media Manager the default screen for the "Media" menu (the
  classic library stays one click away).

= 1.1.0 =
* Fixed a major accuracy bug where nearly every file was reported as "used":
  the scanner was reading each attachment's own metadata (which contains its own
  filename and thumbnail sizes) and matching the file against itself. Attachment-
  owned data is now excluded from the index.
* Added "Used in" details: each used file now lists the exact pages, settings,
  widgets, theme files, etc. where it was found.
* The scan now writes results incrementally to a database table and saves its
  position after every batch, so an interrupted scan can be resumed where it
  left off.

= 1.0.0 =
* Initial release.
