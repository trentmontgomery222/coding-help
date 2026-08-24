# Managed Content Manager

A **single-site** WordPress plugin (proof of concept) that lets you hand out
narrow, controlled editing access to non‑WordPress users. Editors log in
through a **separate front-end portal** with their **own credentials** (not
WordPress user accounts, not wp-admin) and can only change the specific pieces
of content you assign them, in the format you allow.

> Not multisite / not network aware. Activate it on the individual site only —
> the plugin refuses network activation on purpose.

## What you get

- **Per-page live editing (recommended)** — assign an editor a **page**. They
  open it from the portal and edit its content **in place, on the real page, in
  the exact live layout**. The plugin **auto-detects the page builder** (Beaver
  Builder, Elementor, or the block editor / GenerateBlocks) and edits it the
  right way — no builder is required beyond whatever the site already uses. See
  *Works with your page builder automatically* below.
- **Content Blocks** — named, editable pieces of content (single-line text,
  multi-line text, or *limited* rich text). Each renders on any page/post via a
  shortcode.
- **Beaver Builder editing** — expose a **whole Beaver Builder module** (image,
  text, links, icons, colours — everything in the box, including image
  upload/replace) *or* a single field, as an editable block. Editors change the
  real page content; edits are written straight back into Beaver Builder's
  layout data. See *Beaver Builder support* below.
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

## Works with your page builder automatically

The plugin **detects which builder rendered each page** and edits it the right
way — you don't configure anything:

| Builder | Detected by | Editing style |
|---------|-------------|---------------|
| **Beaver Builder** | `_fl_builder_data` / `_fl_builder_enabled` | Click any module on the page |
| **Elementor** | `_elementor_data` / edit mode | Click any widget on the page |
| **Block editor** (Gutenberg, **GenerateBlocks**, GeneratePress, any block theme) | `has_blocks()` on the content | **Choose a block** from the toolbar list |

Beaver Builder and Elementor mark every unit in the page's HTML (`data-node` /
`data-id`), so editors click the thing on the page. The block editor has no
reliable per-block hook, so those pages use a **Choose a block** button in the
toolbar that lists the editable blocks — still on the real page, in its real
layout. If a builder isn't installed, its provider simply stays dormant; the
block-editor provider is always available, so the plugin works on a plain
WordPress site with no page builder at all.

Adding another builder is just a new class implementing `MCM_Provider`
(`includes/providers/`), registered via the `mcm_providers` filter.

## Per-page live editing

This is the mode most people want: an editor is given a **page**, and can change
anything on it, editing **the real page in its exact layout** — not a list of
form fields.

**Setup**

1. *Content Manager → Editors* → edit an editor → tick pages under **Editable
   pages** (the list is your Beaver Builder pages).
2. Give the editor the portal URL + their login.

**What the editor sees**

1. They log into the portal and see **Pages you can edit** with an *Edit page*
   button for each.
2. Clicking it opens the actual page (with the theme, Beaver Builder styling,
   everything — pixel-identical to what visitors see) with a slim editor toolbar
   on top.
3. On Beaver Builder / Elementor pages, hovering any block outlines it and shows
   an **Edit** button. On block-editor pages, they click **Choose a block** in
   the toolbar and pick from the list. Either opens a side drawer with that
   unit's fields (image upload, text, links, icons, toggles, colours, plus
   *Advanced* where the builder exposes more).
4. **Save** writes the change back into the builder and reloads the page, so
   they immediately see the true result. **Done** leaves edit mode.

**How it works**

The editing layer is injected over the live page only when a valid editor
session is present *and* the page is one they're allowed to edit *and* the URL
carries `?mcm_edit=1`. The plugin picks the provider for that page and (for
Beaver Builder / Elementor) targets the builder's own per-unit DOM markup, so
the page itself is untouched — the toolbar, outlines and Edit buttons are added
in the browser and never saved. Loading a unit's form and saving it go through
`admin-ajax.php`, guarded on every call by the editor session, a per-session
CSRF token, and a check that the page is in the editor's allowed list. Normal
visitors get none of this — the assets don't even load.

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

**Two ways to expose Beaver content**

*Content Manager → Beaver Builder* → pick a page. You then get two lists:

1. **Whole modules — edit everything in the box.** Click **Edit whole module**
   on any module and editors get a generated form covering the *entire* module:
   image (with upload/replace), text, links, icons, captions, toggles, colours,
   and — under an **Advanced** section — every remaining setting the module has.
   This is the "edit any Beaver Builder box" mode.
2. **Single fields — fine-grained.** Expose just one field (e.g. a heading) with
   a chosen type and max length, when you want to lock editors down to one thing.

Either way, assign the resulting blocks to editors under
*Content Manager → Editors*. Editors edit from the portal; changes update the
live Beaver Builder page (published layout **and** working draft), and the
plugin clears Beaver Builder's asset cache so the change shows immediately.

**How whole-module editing works**

For a module block, the plugin reads the module's live settings and renders a
widget per setting, inferred from the value/key:

| Setting looks like… | Editor gets… | Sanitised with |
|---------------------|--------------|----------------|
| an image (`photo`)  | image upload + preview | image-only upload → new attachment |
| a link / URL        | URL field    | `esc_url_raw` |
| an icon class       | text field   | class-safe filter |
| `yes`/`no` or `0`/`1` | on/off toggle | constrained to the field's tokens |
| a hex colour        | colour field | `sanitize_hex_color` |
| a number            | number field | numeric filter |
| HTML / long text    | rich text / textarea | `wp_kses_post` / `sanitize_textarea_field` |
| anything else       | text field   | `sanitize_text_field` |

On save, only settings the plugin actually rendered are written back — array/
object settings (typography, borders, responsive maps) are **preserved
untouched**, so editing text or an image can't corrupt the layout. Image uploads
are restricted to jpg/png/gif/webp, capped at 8 MB, and become real media-library
attachments.

**Curated content fields** (shown first, before Advanced) are defined per module
in `includes/class-mcm-beaver.php` (`content_schema()`): heading, rich-text,
callout, icon, button, photo/image, html, and more — easy to extend.

**Recognised modules for single-field mode** (others fall back to a generic
content-field scan): `heading`, `rich-text`, `callout` (title / text / CTA text),
`icon`, `button`, `button-group`, `photo` (caption), `testimonial`, `cta`,
`html`, and more. That map lives in `includes/class-mcm-beaver.php`
(`field_map()`).

> Note: rich-text is edited as HTML in a textarea in this proof. Dropping in a
> WYSIWYG editor (`wp_editor()` on the front end) is a natural next step.

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
│   ├── class-mcm-beaver.php      # Beaver Builder read/scan/write engine
│   ├── class-mcm-providers.php   # builder auto-detection + registry
│   ├── providers/
│   │   ├── abstract-mcm-provider.php
│   │   ├── class-mcm-provider-beaver.php     # Beaver Builder (click-in-place)
│   │   ├── class-mcm-provider-elementor.php  # Elementor (click-in-place)
│   │   └── class-mcm-provider-gutenberg.php  # block editor / GenerateBlocks (list)
│   ├── class-mcm-auth.php        # editor login / sessions (separate from WP)
│   ├── class-mcm-admin.php       # wp-admin screens (blocks, Beaver, editors, settings)
│   ├── class-mcm-portal.php      # front-end portal + [managed_content]
│   └── class-mcm-editmode.php    # in-place per-page live editing + AJAX (provider-driven)
├── assets/
│   ├── admin.css
│   ├── portal.css
│   ├── portal.js                 # live character counters
│   ├── editmode.css              # in-place editor toolbar + drawer
│   └── editmode.js               # injects Edit buttons, drives the drawer
├── uninstall.php                 # drops tables + options on delete
└── README.md
```

## Uninstall

Deleting the plugin from wp-admin removes its three tables
(`{prefix}mcm_blocks`, `{prefix}mcm_editors`, `{prefix}mcm_sessions`) and its
options.
