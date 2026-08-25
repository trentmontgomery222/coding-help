=== ACPS Unused Media Cleanup ===
Contributors: acps
Tags: media, cleanup, unused media, filebird, beaver builder
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.0
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

= 1.0.0 =
* Initial release.
