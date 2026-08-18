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
- **Beaver Builder fields** — expose individual module fields from any
  Beaver Builder page (headings, rich-text, callouts, buttons, etc.) as editable
  blocks. Editors change the real page content; edits are written straight back
  into Beaver Builder's layout data. See *Beaver Builder support* below.
- **Editor accounts** — stored in the plugin's own database tables with hashed
  passwords, fully separate from WordPress users. No wp-admin, no dashboard, no
  WordPress capabilities.
- **A locked-down portal** — editors see only the blocks assigned to them, each
  with the correct input type and an enforced max length. They can't add, delete,
  reformat, or reach anything else.
- **Admin control** — everything is configured from **Content Manager** in
  wp-admin (blocks, Beaver fields, editors, per-editor assignments, settings).

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

## Beaver Builder support

This plugin can hand editors control of content that already lives inside
Beaver Builder modules — without giving them the Beaver Builder editor or
wp-admin.

**How it works**

Beaver Builder stores each page's layout as a node tree in the
`_fl_builder_data` post meta. Every module node carries a `settings` object
whose `type` is the module slug (`heading`, `rich-text`, `callout`, …) and whose
text lives in specific keys. This plugin reads that tree, lets you pick
individual text fields, and writes edits back through Beaver Builder's own API
(`FLBuilderModel::get_layout_data()` / `update_layout_data()`), falling back to
the post meta directly. After a save it clears Beaver Builder's asset cache so
the change shows immediately.

**Setup**

1. Go to *Content Manager → Beaver Builder*.
2. Pick a page that's built with Beaver Builder.
3. The screen lists every editable text field it found (module · field, with a
   preview of the current value). Click **Add** on the ones you want editors to
   control, choosing a label, field type, and optional max length.
4. Assign those blocks to editors under *Content Manager → Editors*, exactly
   like custom blocks.

Editors then edit those fields from the portal; their changes update the live
Beaver Builder page. Both the published layout and the working draft are updated.

**Recognised modules** (others fall back to a generic content-field scan):
`heading`, `rich-text`, `callout` (title / text / CTA text), `icon`, `button`,
`button-group`, `photo` (caption), `testimonial`, `cta`, `html`, and more. The
field map lives in `includes/class-mcm-beaver.php` (`field_map()`) and is easy to
extend.

**Field types & sanitising for Beaver fields**

- A module's rich-text field (e.g. `rich-text → text`) is treated as **rich
  text** and sanitised with `wp_kses_post` on save, so existing markup survives
  but scripts/unsafe attributes are stripped.
- Set a field to **single-line text** to force plain text (good for headings and
  labels) — HTML the editor types is reduced to text.

> Note: rich-text fields are edited as raw HTML in a textarea in this proof.
> Dropping in a WYSIWYG editor (`wp_editor()` on the front end) is a natural
> next step.

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
│   ├── class-mcm-beaver.php      # Beaver Builder read/scan/write integration
│   ├── class-mcm-auth.php        # editor login / sessions (separate from WP)
│   ├── class-mcm-admin.php       # wp-admin screens (blocks, Beaver, editors, settings)
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
