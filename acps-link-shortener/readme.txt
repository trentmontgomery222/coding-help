=== ACPS Link Shortener ===
Contributors: acps
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted, branded URL shortener. Creates /link/{slug} redirects with click
tracking, an accessible admin management UI, and optional Google Sheet sync.

== Description ==

A custom single-site shortener built for the ACPS environment (WP Engine behind
Cloudflare / Global Edge Security). It stores every short link in one database
table and serves all links from the site under the /link/ prefix.

Features:

* Single link table on the active site.
* URL format acpsmd.org/link/{slug} — the prefix is a single constant.
* Per-link 301 (permanent) or 302 (temporary) redirects, default 301.
* Click counter + last-clicked timestamp.
* Accessible admin (WCAG 2.2 AA / Section 508 targeted): core WP_List_Table and
  Settings-API-style forms, labelled fields, field-associated validation errors,
  visible focus, copy-to-clipboard announced via an ARIA live region, and status
  shown with text + icon (never color alone).
* Security: capability checks, nonces, input sanitization, output escaping, and
  $wpdb->prepare() on every query with variables.
* Optional Google Sheet sync (WP-Cron, every 3 minutes) that creates a short
  link for each NEW sheet row, with the slug/name defined per row in the sheet.

== Edge caching (WP Engine Global Edge Security / Cloudflare) ==

Cloudflare sits in front of the site and can cache redirects. Two things matter:

1. The redirect handler sends `Cache-Control: private, no-store, no-cache,
   max-age=0, must-revalidate` (plus Pragma: no-cache) so the edge does not
   store the redirect. This keeps link edits effective immediately.

2. As belt-and-suspenders, add an edge cache-bypass rule for the `/link/*` path
   in WP Engine / Cloudflare, and confirm `/link/*` does not collide with any
   existing WP Engine redirect rules.

Known limitation: if a redirect is ever served straight from edge cache without
reaching PHP, that hit is not counted, so click totals can undercount. This is
accepted by design rather than worked around.

== Google Sheet sync ==

Deploy the bundled google-apps-script/Code.gs as a Google Apps Script web app
(it reads your sheet and returns rows as JSON), then enter its /exec URL under
the admin menu Link Shortener -> Settings. The plugin polls it every 3 minutes
and creates a short link for each new row. Slugs (shortened link names) are read
from the sheet, so staff control them per row. An optional shared secret ensures
only this site can read the feed.

The sync only PULLS data. It does not write to any Google Doc and does not run
remote commands; all validation and link creation happen inside WordPress under
the same rules as the manual add form.

== Frequently Asked Questions ==

= Which capability gates management? =
`manage_options` by default. Filter `acps_ls_manage_capability` to change it.

= Does uninstalling delete my links? =
No. Data is preserved by default. To drop the table on uninstall, define
`ACPS_LS_DROP_DATA_ON_UNINSTALL` as true in wp-config.php.

= How do I add reserved slugs? =
Filter `acps_ls_reserved_slugs`.

== Changelog ==

= 1.0.0 =
* Initial release.
