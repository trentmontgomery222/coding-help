=== Cayden Link Shortener ===
Contributors: caydenriddle
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.14.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted, branded URL shortener with click tracking, an accessible admin UI,
and a password-gated front-end shortcode so staff can make links without Bitly.

== Description ==

A custom single-site shortener built for the operator (WP Engine behind
Cloudflare / Global Edge Security). It stores every short link in one database
table and serves links from the site (or a custom short domain).

Features:

* Single link table on the active site.
* URL format example.com/{slug} (the path prefix is a single constant; set it to
  a value like "link" for example.com/link/{slug} if you prefer).
* Optional custom short-link domain (e.g. go.example.com) set in Settings.
* 301/302 redirects. Permanent (301) is disabled by default so edits take effect
  instantly; re-enable with the `acps_ls_allow_permanent` filter.
* Locked destinations: once a short link exists, its slug and destination cannot
  be changed, so a given short link always points to exactly one place.
* Click counter + last-clicked timestamp.
* Front-end shortcode `[acps_link_shortener]`: a nice, password-gated form for
  staff to create links from any page. Each person has their own name + password
  (set in Settings). Passwords are stored hashed; sign-in uses a signed, expiring
  cookie.
* Accessible admin (WCAG 2.2 AA / Section 508 targeted): core WP_List_Table and
  standard form fields, labelled inputs, field-associated validation errors,
  visible focus, copy-to-clipboard announced via an ARIA live region, and status
  shown with text + icon (never color alone).
* Security: capability checks, nonces, input sanitization, output escaping, and
  $wpdb->prepare() on every query with variables.

== Setup ==

1. Install and activate the plugin (Plugins -> Add New -> Upload Plugin).
2. If short links 404, visit Settings -> Permalinks and click Save once.
3. Go to Settings -> Link Shortener to (optionally) set a custom short domain and
   to add front-end users (name + password).
4. Add the shortcode `[acps_link_shortener]` to any page. Staff sign in with the
   name + password you set and can create links there.
5. Manage all links under the top-level "Link Shortener" menu.

== Custom short domain ==

Settings -> Link Shortener -> Custom domain controls "the first part" of short
URLs. A custom domain only works if it actually resolves to this WordPress
install (DNS + WP Engine domain mapping). Leaving it blank uses the site's own
address.

== Edge caching (WP Engine Global Edge Security / Cloudflare) ==

Cloudflare sits in front of the site and can cache redirects. The redirect
handler sends `Cache-Control: private, no-store, no-cache, max-age=0,
must-revalidate` (plus Pragma: no-cache) so the edge does not store the redirect,
keeping edits effective immediately. As belt-and-suspenders, add an edge
cache-bypass rule for the short-link path.

Known limitation: if a redirect is ever served straight from edge cache without
reaching PHP, that hit is not counted, so click totals can undercount.

== Frequently Asked Questions ==

= Which capability gates management? =
`manage_options` by default. Filter `acps_ls_manage_capability` to change it.

= Can I re-enable permanent (301) redirects? =
Yes: add_filter( 'acps_ls_allow_permanent', '__return_true' );

= Does uninstalling delete my links? =
No. Data is preserved by default. To drop the table on uninstall, define
`ACPS_LS_DROP_DATA_ON_UNINSTALL` as true in wp-config.php.

= How do I add reserved slugs? =
Filter `acps_ls_reserved_slugs`.

== Changelog ==

= 1.14.0 =
* Self-updating: the plugin can now update itself from a source you control, so
  you don't have to re-upload the zip on every site by hand. Two sources:
  - "A file I host": point it at a small JSON manifest URL you host anywhere over
    HTTPS ({"version":"…","download_url":"https://…/acps-link-shortener.zip"}).
    To ship an update, upload the new zip and bump the version in the JSON.
  - "GitHub releases": point it at owner/repo and attach the built zip to each
    release as an asset (public repo needs no token; private repo takes a
    fine-scoped personal access token, stored in Settings).
  When a newer version is published, WordPress shows the normal "Update now"
  button. Turn on "Install automatically" to have sites self-update silently in
  the background.
* Secret force-update URL: visiting a private URL word (default
  /protcol_U999_update, configurable in Settings) forces an immediate check and
  install and prints a short status page. Also available as
  /?acps_ls_update=<word>. Keep the word secret; change it to rotate it.
* All update code is wrapped so a bad manifest, an unreachable host, or a failed
  download can never take the site down. Configure under
  Settings → Link Shortener → Automatic updates.

= 1.13.0 =
* Link Manager now shows how long each broken link has been broken. Every row in
  the "Broken" state displays "Broken for <duration>" (e.g. "Broken for 3 days")
  under its status, measured from when the checker first saw it fail; hover to
  see the exact first-failure date/time. The duration resets automatically if a
  link recovers and later breaks again.

= 1.12.0 =
* Link Manager "Found in" links now auto-detect the page builder for each item
  and open it in the right editor — no configuration needed:
  - Beaver Builder pages open in the Beaver Builder editor (front-end + ?fl_builder).
  - Elementor pages open in the Elementor editor (action=elementor).
  - Divi pages open in the Divi Builder (front-end + ?et_fb=1).
  - Everything else (GeneratePress/GenerateBlocks, the block editor, classic
    posts) opens in the normal WordPress editor.
  Detection is per item, so a mixed site (some Beaver, some Elementor, some plain)
  is handled automatically. Filter `acps_ls_edit_url` overrides the chosen URL.

= 1.11.0 =
* Link Manager "Found in" links: pages now open in the Beaver Builder editor
  (front-end URL + ?fl_builder) when Beaver Builder is active; posts keep opening
  in the normal WordPress editor. Falls back to the WordPress editor for pages
  when Beaver Builder is not active.

= 1.10.0 =
* Renamed the "Link Checker" admin menu to "Link Manager".
* Idle scan cadence: outside the checking window the plugin no longer scans on
  every 10-minute cron tick — it scans at most once per "scan when not checking"
  interval (default 60 minutes, configurable). During the checking window it
  still scans every run so checks have a fresh queue.

= 1.9.0 =
* Night-only link checking: the outbound HTTP checks now run only inside a
  configurable window (default 12 AM–6 AM, site timezone) so the site isn't
  loaded during the day. New links are still discovered any time, keeping the
  queue ready; the manual "Check now" / "Recheck" buttons ignore the window.

= 1.8.0 =
* Rebrand to "Cayden Link Shortener" (first name).
* Crash-proofing so the plugin can never white-screen the whole site:
  - Missing class files (e.g. an incomplete/failed upload) no longer fatal —
    the plugin pauses itself, shows an admin notice naming the missing file(s),
    and WordPress keeps running.
  - Every runtime entry point (front-end redirect handler, shortcode render +
    submission, WP-Cron sync and link checker, admin action router, dashboard
    widget) is wrapped in try/catch: any unexpected error is logged (when
    WP_DEBUG is on) and swallowed instead of taking the site down.
  - Activation/deactivation guarded too, so a hiccup there fails gracefully.

= 1.7.0 =
* Quiet hours for automatic broken-link e-mails: hold notifications overnight
  and send anything found in the first check after quiet hours end. Default
  window 8 PM–8 AM in the site timezone; configurable start/end. The manual
  "Force notify" button ignores quiet hours.

= 1.6.0 =
* Add a "Force notify" button on the Link Checker screen that immediately
  e-mails a report of every currently broken link (ignores the once-an-hour
  throttle and the per-link notified flag).
* Rebrand: plugin name and author are now "Cayden Link Shortener" by
  Cayden Riddle. Internal identifiers and stored data are unchanged.

= 1.5.0 =
* Link checker settings expanded: status panel (broken count, queue, unique URLs,
  PHP/MySQL/cURL versions, last e-mail); e-mail notifications to admin and to
  post authors; warnings vs broken; request timeout; exclusion list; link types
  (HTML links, images, plain-text URLs); which post types, post statuses, and
  comments to scan; a dashboard "Broken links" widget.
* New per-link actions on the checker screen: Dismiss / Restore, Not broken
  (false positive), Unlink (remove the link, keep the text), Fix redirect
  (repoint to the final URL), plus a "Forced recheck" that clears and rescans.
* Deliberately not included (host-specific or very heavy): server-load limiter,
  continuous in-browser monitor, custom log file, embedded-video/ACF parsers,
  and in-content broken-link CSS styling.

= 1.4.0 =
* Link checker: verifies that links are alive (HEAD then GET; broken / redirect /
  OK states) and flags problems on a new "Link Checker" admin screen. Checks the
  shortener's destinations plus, optionally, all links in posts, pages, and
  comments. Each unique URL is checked once (deduped) and work runs in small
  WP-Cron batches every 10 minutes.
* Replacement rules (Settings): match a URL by contains / exact / regex and
  either rewrite it or flag it. Rewrite rules auto-apply to every saved
  destination and can be swept across existing short links; you can also replace
  a broken URL everywhere it appears (destinations + content) from the checker.

= 1.3.0 =

= 1.2.0 =
* Front-end: separate sign-in from creating. Signing in opens a dashboard where
  staff create links AND manage/delete the links they made.
* Per-user options: a link limit (shortcode-created links only) and a URL
  namespace that forces the first path segment (acpsmd.org/katherine/name).
* Two-way Google Sheet sync (WordPress -> Google): WordPress mirrors its links
  into the sheet and applies the sheet's adds/edits/deletes. Only sheet-made
  links are ever auto-deleted. Includes a "Test connection" button.
* Multi-segment slugs supported so namespaced links resolve.

= 1.1.0 =
* Add password-gated front-end shortcode [acps_link_shortener] with per-person
  name + password (hashed).
* Move settings to Settings -> Link Shortener; keep the top-level Link Shortener
  menu for managing links.
* Add optional custom short-link domain.
* Lock slug + destination after creation (a short link can't be repointed).
* Disable permanent (301) redirects by default.
* Remove the Google Sheet / Apps Script sync.

= 1.0.0 =
* Initial release.
