=== ACPS Sitemap ===
Contributors: acps
Tags: sitemap, xml sitemap, html sitemap, seo, single site
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple, single-site XML and HTML sitemap generator managed entirely from the WordPress admin. No multisite or network install required. Includes self-hosted updates with crash-safe recovery.

== Description ==

ACPS Sitemap creates a search-engine XML sitemap and an optional visitor-facing HTML sitemap for a single WordPress site. Everything is configured from **Settings -> ACPS Sitemap** in the normal (per-site) admin -- there is no Network Admin screen and the plugin refuses network-wide activation.

**Sitemap features**

* XML sitemap index at `/sitemap.xml` with per-type sub-sitemaps (`/sitemap-pt-post.xml`, etc.).
* Automatic pagination for large content sets (configurable URLs per file).
* Choose exactly which post types and taxonomies to include.
* Exclude specific pages/posts by ID.
* Adds a `Sitemap:` line to `robots.txt`.
* Optionally turns off the built-in WordPress core sitemap to avoid duplicates.
* `[acps_sitemap]` shortcode for a human-readable HTML sitemap, plus a one-click "Create sitemap page" button.
* Output is cached and automatically refreshed whenever content changes.
* Works with pretty permalinks (`/sitemap.xml`) and, as a fallback, with plain permalinks (`/?acps_sitemap=index`).

**Self-hosted updates**

Even though this plugin is not on the WordPress.org directory, it can still show "Update now" on the Plugins screen and (optionally) auto-update, pulling new versions from a source you control:

* A **GitHub release** (owner/repo + asset filename; a token for private repos), or
* A **JSON manifest URL** returning at least `{ "version", "download_url" }`.

Protections around updates:

* The unpacked update folder is renamed back to the plugin slug, so an update installs over the same directory and the plugin stays active.
* After installing, the new version is crash-tested with a loopback request; a build that fatals on load is rolled back / kept disabled automatically.
* A "safe mode" catches a fatal in the plugin's own files and parks the plugin behind a "Resume" notice instead of white-screening the site.
* An optional staged rollout lets a production site wait until a paired dev/staging site has installed and verified a version.
* A secret force-update URL (seeded per site on activation) can trigger an immediate check + install from cron, curl, or a deploy hook.

== Installation ==

1. In the WordPress admin go to **Plugins -> Add New -> Upload Plugin**.
2. Upload the `acps-sitemap` ZIP file and click **Install Now**.
3. Click **Activate** (on the individual site -- do not network activate).
4. Go to **Settings -> ACPS Sitemap** to choose what to include and, if you want, configure the update source under **Updates**.

If you use pretty permalinks and the `/sitemap.xml` URL 404s right after installing, visit **Settings -> Permalinks** and click **Save Changes** once to refresh the rewrite rules.

== Frequently Asked Questions ==

= Where is my sitemap? =
The XML sitemap index is at `https://your-site/sitemap.xml`. The exact URL is also shown at the top of the settings page.

= How do I add the HTML sitemap? =
Put the shortcode `[acps_sitemap]` on any page, or use the **Create sitemap page** button on the settings screen.

= Does this work on multisite? =
It runs on individual sites within a multisite network, but it must be activated per-site -- it will not activate network-wide.

= How do updates work if the plugin is not on WordPress.org? =
Point the **Updates** panel at a GitHub release or a JSON manifest you host. When that source reports a newer version, WordPress shows "Update now" as usual. See UPDATE-SYSTEM.md for the full setup.

== Changelog ==

= 1.0.0 =
* Initial release: XML sitemap index with pagination, HTML shortcode, admin settings page, robots.txt integration, caching, and a self-hosted update system with crash-safe recovery.
