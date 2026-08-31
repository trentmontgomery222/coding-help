=== ACPS Sitemap ===
Contributors: acps
Tags: sitemap, xml sitemap, html sitemap, seo, single site
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple, single-site XML and HTML sitemap generator managed entirely from the WordPress admin. No multisite or network install required.

== Description ==

ACPS Sitemap creates a search-engine XML sitemap and an optional visitor-facing HTML sitemap for a single WordPress site. Everything is configured from **Settings → ACPS Sitemap** in the normal (per-site) admin — there is no Network Admin screen and the plugin refuses network-wide activation.

**Features**

* XML sitemap index at `/sitemap.xml` with per-type sub-sitemaps (`/sitemap-pt-post.xml`, etc.).
* Automatic pagination for large content sets (configurable URLs per file).
* "Only include public content" toggle: leaves out password-protected posts/pages and anything marked "noindex" by your SEO plugin (Yoast SEO, Rank Math, All in One SEO, SEOPress).
* Choose exactly which post types and taxonomies to include.
* Exclude specific pages/posts by ID.
* Adds a `Sitemap:` line to `robots.txt`.
* Optionally turns off the built-in WordPress core sitemap to avoid duplicates.
* `[acps_sitemap]` shortcode for a human-readable HTML sitemap, plus a one-click "Create sitemap page" button.
* Output is cached and automatically refreshed whenever content changes.
* Works with pretty permalinks (`/sitemap.xml`) and, as a fallback, with plain permalinks (`/?acps_sitemap=index`).

== Installation ==

1. In the WordPress admin go to **Plugins → Add New → Upload Plugin**.
2. Upload the `acps-sitemap` ZIP file and click **Install Now**.
3. Click **Activate** (on the individual site — do not network activate).
4. Go to **Settings → ACPS Sitemap** to choose what to include.

If you use pretty permalinks and the `/sitemap.xml` URL 404s right after installing, visit **Settings → Permalinks** and click **Save Changes** once to refresh the rewrite rules.

== Frequently Asked Questions ==

= Where is my sitemap? =
The XML sitemap index is at `https://your-site/sitemap.xml`. The exact URL is also shown at the top of the settings page.

= How do I add the HTML sitemap? =
Put the shortcode `[acps_sitemap]` on any page, or use the **Create sitemap page** button on the settings screen.

= Does this work on multisite? =
It runs on individual sites within a multisite network, but it must be activated per-site — it will not activate network-wide.

== Changelog ==

= 1.1.0 =
* Added an "Only include public content" setting (on by default) that excludes password-protected posts/pages and content marked "noindex" by Yoast SEO, Rank Math, All in One SEO, or SEOPress from both the XML and HTML sitemaps.

= 1.0.0 =
* Initial release: XML sitemap index with pagination, HTML shortcode, admin settings page, robots.txt integration, and caching.
