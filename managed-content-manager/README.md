# Managed Content Manager

A **single-site** WordPress plugin (proof of concept) that lets you hand out
narrow, controlled editing access to non‑WordPress users. Editors log in
through a **separate front-end portal** with their **own credentials** (not
WordPress user accounts, not wp-admin) and can only change the specific pieces
of content you assign them, in the format you allow.

> Not multisite / not network aware. Activate it on the individual site only —
> the plugin refuses network activation on purpose.

## What you get

- **Content Blocks** — named, editable pieces of content (single-line text,
  multi-line text, or *limited* rich text). Each renders on any page/post via a
  shortcode.
- **Editor accounts** — stored in the plugin's own database tables with hashed
  passwords, fully separate from WordPress users. No wp-admin, no dashboard, no
  WordPress capabilities.
- **A locked-down portal** — editors see only the blocks assigned to them, each
  with the correct input type and an enforced max length. They can't add, delete,
  reformat, or reach anything else.
- **Admin control** — everything is configured from **Content Manager** in
  wp-admin (blocks, editors, per-editor block assignments, settings).

## How it works (setup in 5 steps)

1. **Activate** the plugin on your site (single-site).
2. **Create content blocks** under *Content Manager → Content Blocks*. Give each
   a label, pick a field type, and optionally a max length.
3. **Place blocks on your pages** using the shortcode shown for each block, e.g.
   `[managed_content slug="hero-title"]`.
4. **Create the portal page**: add a new WordPress page (e.g. “Editor Login”)
   containing the shortcode `[content_editor_portal]`, then select it under
   *Content Manager → Settings → Portal page*.
5. **Add editor accounts** under *Content Manager → Editors*, set a password,
   and tick which blocks each editor may change. Send them the portal URL and
   their username/password.

Editors visit the portal page, log in, and get a simple form for each block
they're allowed to edit. That's it.

## Shortcodes

| Shortcode | Purpose |
|-----------|---------|
| `[managed_content slug="my-slug"]` | Output a block's current content on the front end. |
| `[content_editor_portal]` | The editor login + editing dashboard. Put it on one page. |

## Security notes (proof-of-concept level)

- Editor passwords are hashed with `wp_hash_password()`.
- Sessions use a random token; only the token's SHA‑256 hash is stored, in a
  dedicated table, with an expiry. The cookie is `HttpOnly` + `SameSite=Lax`
  (and `Secure` on HTTPS).
- Saves are protected by a per-session CSRF token, and the server re-checks that
  the target block is actually assigned to the logged-in editor.
- Content is sanitized on save according to the block's type — rich text is run
  through a small `wp_kses` allow-list (bold, italic, links, lists, H2/H3); no
  scripts, styles, images, or arbitrary attributes.
- Login attempts are rate-limited per IP with a configurable lockout.

For production you'd likely want: HTTPS enforced, stronger password policy /
reset flow, email verification, and an audit log. This build is a working
starting point, not a hardened release.

## File layout

```
managed-content-manager/
├── managed-content-manager.php   # bootstrap, activation, single-site guard
├── includes/
│   ├── class-mcm-db.php          # tables + all queries + content sanitizing
│   ├── class-mcm-auth.php        # editor login / sessions (separate from WP)
│   ├── class-mcm-admin.php       # wp-admin screens (blocks, editors, settings)
│   └── class-mcm-portal.php      # front-end portal + [managed_content]
├── assets/
│   ├── admin.css
│   ├── portal.css
│   └── portal.js                 # live character counters
├── uninstall.php                 # drops tables + options on delete
└── README.md
```

## Uninstall

Deleting the plugin from wp-admin removes its three tables
(`{prefix}mcm_blocks`, `{prefix}mcm_editors`, `{prefix}mcm_sessions`) and its
options.
