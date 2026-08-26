=== ACPS Unused Media Cleanup ===
Contributors: acps
Tags: media, cleanup, unused media, filebird, beaver builder
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.6.0
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
