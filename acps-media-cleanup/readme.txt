=== ACPS Unused Media Cleanup ===
Contributors: acps
Tags: media, cleanup, unused media, filebird, beaver builder
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.2.0
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
