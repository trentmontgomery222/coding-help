=== ACPS Site Toolkit ===
Contributors: acps
Tags: feedback, analytics, forms, accessibility, journey
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

First-party page-journey analytics, an accessible feedback system, and a
Google-Forms-replacement form builder — one engine, WCAG 2.2 AA / Section 508
throughout, built to run behind aggressive edge caching.

== Description ==

Three connected features that share one infrastructure:

1. **Journey tracking** — records the ordered sequence of pages each visitor
   sees in a session, using a cache-safe client-side beacon (works behind WP
   Engine Global Edge Security).
2. **Feedback system** — an inviting, thin feedback form with the page
   pre-selected from the visitor's journey. It is implemented as a template of
   the form engine, so it inherits the engine's accessibility.
3. **Form builder** — a general-purpose form system (fields, conditional logic,
   multi-page, notifications, spam prevention) to replace Google Forms.

Journey data is the connective tissue: it pre-fills the feedback page picker
and attaches a path to every feedback and form submission.

= Designed for a specific environment =

* Single site (no multisite code paths anywhere).
* WP Engine + Global Edge Security aggressive full-page caching.
* Beaver Builder page builder and child theme.

Two failure modes this plugin deliberately avoids:

* **Server-side tracking on a cached site.** Visits are recorded ONLY via a
  client-side beacon to an uncached REST endpoint. A PHP write during render
  never fires for cached pages.
* **Nonces baked into cached HTML.** The submission nonce and time-trap token
  are fetched after page load from an uncached endpoint, never printed into
  cached markup.

= Accessibility =

WCAG 2.1 AA and Section 508 are the floor; WCAG 2.2 AA is the target — on both
the front end and every admin screen. Real labels, fieldset/legend for groups,
an error summary that receives focus, live-region announcements, autocomplete
attributes, focus-trapped modal, 24px+ targets, keyboard-only form builder with
Up/Down reordering (no drag required), and accessible table equivalents for all
charts.

= Spam prevention (no third-party CAPTCHA) =

Honeypot, time trap, nonce/CSRF, rate limiting, keyword blocklist, and an
optional screen-reader-friendly plain-text challenge.

== Installation ==

1. Upload the `acps-site-toolkit` folder to `/wp-content/plugins/` (file
   manager is fine — the main file has a complete plugin header).
2. Activate the plugin through the **Plugins** screen. Activation creates the
   database tables and the built-in feedback form. Presence on disk is not
   enough — you must activate.
3. Configure under **Site Toolkit → Settings**.

Schema upgrades apply automatically on load via a stored schema version, so no
deactivate/reactivate cycle is needed after an update.

== Frequently Asked Questions ==

= Will journey tracking work behind full-page caching? =

Yes. Tracking is a client-side beacon to an uncached REST route, which is the
only reliable approach behind Global Edge Security.

= Does deactivating the plugin delete my data? =

No. Deactivation never removes data. Even deleting the plugin preserves data
unless you turn off "Preserve data on uninstall" in Settings.

== Changelog ==

= 1.0.0 =
* Initial release: journey tracking, feedback system, form builder, analytics,
  integrations (shortcode, block, Beaver Builder module), GDPR export/erase.
