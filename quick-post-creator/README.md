# Quick Post Creator

A rough, minimal WordPress plugin that adds one admin page for making a post
fast: Title, Content, a Featured Image, extra Images, and full Category/Tag
support — the repetitive parts. After you submit, it hands you off to
WordPress's normal post editor for that post, so Yoast SEO's own box
(snippet preview, SEO/readability analysis, AI title & description
suggestions) works exactly as it always does — nothing about Yoast is
reimplemented here.

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
  - Publish / Save Draft buttons
- On submit, creates the post with `wp_insert_post()`, assigns categories
  and tags, sets the featured image, then redirects straight into
  `post.php?action=edit` for that post — the real WordPress editor — with
  a notice pointing you at the Yoast SEO box if Yoast is active. Finish the
  SEO title, description, and AI suggestions there, same as any other post.

## Notes / next steps

This is intentionally bare-bones — a starting framework, not a finished
product. Things you may want to add later:

- Post excerpt field
- Scheduling (future-dated publish)
- Custom post type support (currently posts only)
- Bulk/quick-repeat posting for similar posts
- Permission tweaks (currently anyone who can `publish_posts` sees the page)
