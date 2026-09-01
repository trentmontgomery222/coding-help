# Quick Post Creator

A rough, minimal WordPress plugin that adds one admin page for making a post
fast: Title, Content, a Featured Image, and a handful of Additional Images —
skipping the rest of the full post-editor screen.

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
  - Category dropdown
  - Publish / Save Draft buttons
- On submit, creates the post with `wp_insert_post()`, sets the featured
  image, and redirects back with a success/error notice and links to edit
  or view the new post.

## Notes / next steps

This is intentionally bare-bones — a starting framework, not a finished
product. Things you may want to add later:

- Tags field
- Post excerpt field
- Scheduling (future-dated publish)
- Custom post type support (currently posts only)
- Bulk/quick-repeat posting for similar posts
- Permission tweaks (currently anyone who can `publish_posts` sees the page)
