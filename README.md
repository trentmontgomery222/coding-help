# wordpress-help

## ACPS Sitemap plugin

A single-site WordPress sitemap plugin, managed entirely from the WordPress admin
(**Settings → ACPS Sitemap**). No multisite or network install is required — the
plugin refuses network-wide activation on purpose.

### What it does

- **XML sitemap** for search engines at `/sitemap.xml`, with per-type sub-sitemaps
  and automatic pagination for large content sets.
- **HTML sitemap** for visitors via the `[acps_sitemap]` shortcode (plus a one-click
  "Create sitemap page" button in the settings screen).
- Pick exactly which **post types and taxonomies** to include, and exclude specific
  pages/posts by ID.
- Adds a `Sitemap:` line to `robots.txt` and can turn off WordPress's built-in
  `wp-sitemap.xml` to avoid duplicates.
- Output is cached and refreshed automatically whenever content changes.

### Install

1. Download **`acps-sitemap.zip`** from this repo.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the ZIP, **Install Now**.
3. **Activate** it on the site (not network-wide).
4. Configure it under **Settings → ACPS Sitemap**.

If you use pretty permalinks and `/sitemap.xml` returns a 404 right after activating,
open **Settings → Permalinks** and click **Save Changes** once to refresh the rewrite
rules.

The plugin source lives in [`acps-sitemap/`](acps-sitemap/).
