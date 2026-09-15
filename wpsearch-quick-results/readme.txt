=== WPSearch Quick Results ===
Contributors: acpsmd
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later

Serves popular searches from a cache instead of re-running the search engine,
and filters what appears in the results.

== Description ==

A search is slow because the search engine scores every indexed row against
the term on every request. But the same handful of terms get typed over and
over. Scoring them again each time is the waste this removes.

The first time a term is searched the result IDs are stored. Every search
after that is a primary-key lookup, with no index scoring at all. A cron job
keeps the most popular terms warm.

This is a caching layer, not a search engine. It does not make an uncached
search faster. It is worth having because real search traffic is top-heavy —
and the Dashboard tells you whether that is true on your site rather than
asking you to assume it.

== Installation ==

1. Upload the folder to `/wp-content/plugins/`.
2. Activate. Two tables are created.
3. Put `[wpsqr_results]` where the SearchWP results module is now.
4. Set the hide-plugin meta key under Quick Results → Settings.

The shortcode is deliberate rather than an automatic takeover: keeping both
available is the only way to compare them honestly.
