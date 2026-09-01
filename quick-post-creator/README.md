# Quick Post Creator

A rough, minimal WordPress plugin that adds one admin page for making a post
fast: Title, Content, a Featured Image, extra Images, full Category/Tag
support, and Yoast SEO fields (when Yoast is active) — skipping the rest of
the full post-editor screen.

This is a **per-site** plugin, not a network/multisite plugin. Install and
activate it on each site individually from that site's own
**Plugins** screen in wp-admin (not the network admin's Plugins screen).

## Install

1. Copy the `quick-post-creator` folder into `wp-content/plugins/`.
2. In wp-admin, go to **Plugins** and activate **Quick Post Creator**.
3. A new **Quick Post** menu item appears in the sidebar.

## What it does

- Adds a "Quick Post" page with:
  - Title field
  - Content field (standard WordPress editor)
  - Featured Image picker (uses the built-in media library)
  - Additional Images picker — selected images are appended to the post as
    a `[gallery]`
  - **Categories** — the same hierarchical checkbox list as the normal post
    editor, plus a text field to create brand-new categories on the fly
    (only shown to users who can `manage_categories`)
  - **Tags** — a comma-separated text field, with clickable "popular tags"
    chips pulled from existing tags
  - **SEO (Yoast)** — only appears if Yoast SEO is active. Sets SEO Title,
    Meta Description, and Focus Keyphrase by writing directly to Yoast's
    post meta (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`,
    `_yoast_wpseo_focuskw`)
  - Publish / Save Draft buttons
- On submit, creates the post with `wp_insert_post()`, assigns categories
  and tags, sets the featured image, saves the Yoast fields, and redirects
  back with a success/error notice and links to edit or view the new post.

## Notes / next steps

This is intentionally bare-bones — a starting framework, not a finished
product. Things you may want to add later:

- Post excerpt field
- Scheduling (future-dated publish)
- Custom post type support (currently posts only)
- Bulk/quick-repeat posting for similar posts
- Permission tweaks (currently anyone who can `publish_posts` sees the page)
- Yoast's readability/SEO score display, or a snippet preview
