# External Portal — Extension API Reference

**Audience:** a developer or AI assistant extending the External Portal plugin.
Read this before writing an extension. It documents the *actual* hooks and data
shapes as implemented, so you don't write against outdated assumptions.

- **API version:** `1.0.0` (see [Changelog](#8-changelog--versioning) for the contract history).
- **Registration action:** `exp_register_extensions`
- **Prefix for public functions:** `exp_*`
- **Golden rule:** everything a portal user reaches has *already* passed the
  portal's authentication + session checks. You never re-implement auth.

---

## 1. Overview

External Portal is a **platform**, not a closed system. Your plugin can add:

1. A **dashboard menu item** (a panel portal users can open).
2. A **permission/capability key** that automatically appears on the admin
   Permissions screen — no hand-coded checkboxes.
3. The **content renderer** for your panel.
4. *(Optional)* **Queue integration** so your submissions land in the shared
   Content Update Queue.
5. *(Optional)* **Activity-log integration** so your items appear in "My Activity".

Register everything from a callback hooked to `exp_register_extensions`. That
action fires once, on `init` (priority 20), after auth is available and after core
modules have registered.

```php
add_action( 'exp_register_extensions', function () {
    // Guard so your code doesn't fatal if the portal is deactivated.
    if ( ! function_exists( 'exp_register_menu_item' ) ) {
        return;
    }

    exp_register_capability( array(
        'key'         => 'manage_newsletter_signup',
        'label'       => 'Manage newsletter sign-ups',
        'description' => 'Export and moderate newsletter sign-ups.',
        'target_type' => 'none',
        'source'      => 'My Newsletter Plugin',
    ) );

    exp_register_menu_item( array(
        'slug'       => 'newsletter',
        'label'      => 'Newsletter',
        'icon'       => 'email',                       // A dashicons name (without the "dashicons-" prefix).
        'capability' => 'manage_newsletter_signup',    // Menu hidden unless the user holds this.
        'render'     => 'my_newsletter_render',         // callable( array $ctx ): string
        'handle'     => 'my_newsletter_handle',         // optional callable( array $ctx ): array $notices
        'position'   => 60,
        'source'     => 'My Newsletter Plugin',
    ) );
} );
```

> **Approval gate:** by default a third-party menu item is **hidden from portal
> users until an admin approves it** on *Settings → External Portal → Extensions*.
> This is controlled by the `extensions_require_approval` setting. Core modules are
> never gated. Plan for your item to be invisible until approved.

You can also subscribe to the registry object directly — the action passes it:

```php
add_action( 'exp_register_extensions', function ( $registry ) {
    $registry->register_menu_item( array( /* ... */ ) );
} );
```

---

## 2. Menu registration

`exp_register_menu_item( array $args )` — returns `true` or `WP_Error`.

| Key | Type | Required | Notes |
|-----|------|----------|-------|
| `slug` | string | ✅ | Unique. Lowercased via `sanitize_key`. Identifies the panel (`?view=<slug>`). |
| `label` | string | ✅ | Human label shown in the nav. |
| `render` | callable | ✅ | `function( array $ctx ): string` — returns the panel **body** HTML. |
| `capability` | string | – | If set, the item only shows to users who hold this capability (any target). Empty = visible to every signed-in portal user. |
| `handle` | callable | – | `function( array $ctx ): array` — processes this panel's POST and returns notices. See [§4](#4-content-rendering-contract). |
| `icon` | string | – | A [dashicons](https://developer.wordpress.org/resource/dashicons/) name without the `dashicons-` prefix. Default `admin-generic`. |
| `position` | int | – | Sort order in the nav. Default `50`. Core items use 10–90. |
| `source` | string | – | Your plugin's name — shown on the admin Extensions screen. |

The portal wraps your output in a consistent, accessible `<section>` — you do **not**
add your own panel heading/landmark.

---

## 3. Permission registration

`exp_register_capability( array $args )` — returns `true` or `WP_Error`.

Once registered, the capability appears **automatically** on *Settings → External
Portal → Permissions*, and admins can grant it per user.

| Key | Type | Required | Notes |
|-----|------|----------|-------|
| `key` | string | ✅ | Unique capability key. Lowercased via `sanitize_key`. |
| `label` | string | ✅ | Human label on the grants screen. |
| `description` | string | – | Help text under the label. |
| `target_type` | string | – | `none` \| `page` \| `category` \| `calendar` \| `custom`. Default `none`. |
| `target_options` | callable\|array | – | For non-`none` types: `[ value => label ]`, or a callable returning that. Renders the assignable checkboxes. |
| `source` | string | – | Your plugin's name. |

**Target semantics**

- `none` — a single global grant. Stored with an empty target. Check with
  `exp_user_can( $user_id, 'your_key' )`.
- `page` / `category` — targets are post/term IDs; the portal resolves labels.
- `calendar` — targets are calendar IDs from the admin whitelist.
- `custom` — you supply `target_options` (e.g. `[ '12' => 'Region 12' ]`) and
  interpret the stored target string yourself.

Checking a grant in your render/handle code:

```php
if ( ! exp_user_can( $ctx['user']->id, 'manage_newsletter_signup' ) ) {
    return; // Should not happen if `capability` gates the menu item, but be safe.
}
```

---

## 4. Content-rendering contract

Your `render` callback receives a **context array** and must **return a string**
(do not `echo`).

```php
function my_newsletter_render( array $ctx ) : string {
    // $ctx = [
    //   'user'        => (object) portal user row (id, email, display_name, ...),
    //   'slug'        => 'newsletter',
    //   'csrf'        => '…',   // session CSRF token — put it in every form you POST
    //   'form_action' => 'https://site/portal/?view=newsletter',
    // ];

    $html  = '<p>' . esc_html__( 'Manage your newsletter here.', 'my-textdomain' ) . '</p>';
    $html .= '<form method="post" action="' . esc_url( $ctx['form_action'] ) . '">';
    $html .= exp_module_form_fields( $ctx );  // hidden exp_action/exp_module/exp_csrf
    $html .= '<button type="submit" class="exp-button">' . esc_html__( 'Save', 'my-textdomain' ) . '</button>';
    $html .= '</form>';
    return $html;
}
```

**What is guaranteed for you**

- The callback runs **only after** the portal session is authenticated. No auth
  code needed.
- Your returned HTML is wrapped in the portal's accessible container
  (`<section aria-labelledby>` with a heading built from your `label`).
- The portal's stylesheet is loaded; the classes `exp-button`, `exp-field`,
  `exp-notice`, `exp-table`, `exp-list` are available for consistent styling.

**What you must do**

- **Escape all output** (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`). The
  wrapper does not escape your content.
- Include the CSRF token in every form. The helper
  `exp_module_form_fields( $ctx )` prints the three required hidden fields
  (`exp_action=module`, `exp_module=<slug>`, `exp_csrf=<token>`). If you build the
  form by hand, add all three.
- Meet the [accessibility requirements](#7-accessibility-requirements).

**Handling submissions** — provide a `handle` callback. It runs on
`template_redirect` (before any output) **after** the portal has verified the
session and the CSRF token and confirmed the user holds the menu item's
`capability`. Return an array of notices; the portal redirects back to your panel
and displays them.

```php
function my_newsletter_handle( array $ctx ) : array {
    $email = isset( $_POST['nl_email'] ) ? sanitize_email( wp_unslash( $_POST['nl_email'] ) ) : '';
    if ( ! is_email( $email ) ) {
        return array( array( 'type' => 'error', 'text' => 'Please enter a valid email.' ) );
    }
    // ... do work, or submit to the queue (see §5) ...
    return array( array( 'type' => 'success', 'text' => 'Saved.' ) );
}
```

Notice shape: `array( 'type' => 'error'|'success'|'info'|'warning', 'text' => string )`.

---

## 5. Queue integration (optional)

Register a queue type, then submit to it. Submissions appear on the shared
*Content Queue* admin screen; on approval the portal runs your **applier**.

```php
exp_register_queue_type( array(
    'type'            => 'newsletter_broadcast',       // unique, sanitize_key
    'label'           => 'Newsletter broadcast',
    'review_renderer' => 'my_nl_review',   // callable( $item ): string  — admin preview HTML
    'applier'         => 'my_nl_apply',    // callable( $item ): true|WP_Error — runs on approval
    'source'          => 'My Newsletter Plugin',
) );
```

Submit from your `handle` callback:

```php
$id = exp_queue_submit( array(
    'type'         => 'newsletter_broadcast',
    'submitted_by' => $ctx['user']->id,          // required
    'content_ref'  => 'broadcast',               // optional short reference
    'payload'      => array( 'subject' => $subject, 'body' => $body ), // JSON-encoded for you
) );
if ( is_wp_error( $id ) ) { /* ... */ }
```

The `$item` passed to `review_renderer`/`applier` is the queue row with a decoded
`->payload_data` array. Return `true` from the applier on success, or a `WP_Error`
to block approval and show the message to the admin. If you omit `applier`, the
item is simply marked approved (an admin actions it manually).

---

## 6. Activity-log integration (optional)

Give the "My Activity" panel a one-line summary of your queue items.

```php
exp_register_activity_formatter( 'newsletter_broadcast', function ( $item ) {
    return sprintf( 'Broadcast: %s', esc_html( $item->payload_data['subject'] ?? '' ) );
} );
```

The formatter receives the same `$item` (with `->payload_data`) and returns a
short string. The portal shows status ("Pending review" / "Approved" /
"Rejected — see note") and any admin note alongside it automatically.

---

## 7. Accessibility requirements

Everything rendered in the portal must meet **WCAG 2.2 AA / Section 508**. The
wrapper handles landmarks and headings; your panel content must:

- Give every form control a programmatic label (`<label for>` or, as a last
  resort, `aria-label`). Never rely on placeholder text as the label.
- Never convey meaning by colour alone — use text (e.g. "Rejected — see note").
- Associate errors with their field via `aria-describedby` and mark invalid
  fields with `aria-invalid="true"`. `EXP_UI::field()` (exposed patterns in
  `assets/css/portal.css`) shows the expected markup.
- Keep a logical heading order. Your panel's `<h2>` is provided by the wrapper —
  start your own subheadings at `<h3>`.
- Ensure controls are keyboard operable with a visible focus indicator (the
  portal stylesheet provides `:focus-visible` styling if you use its classes).
- If you add live-updating content, announce it via an ARIA live region.

Reuse the portal's CSS classes so your panel matches and inherits these
guarantees: `exp-field`, `exp-field__label`, `exp-field__input`,
`exp-field__error`, `exp-notice`, `exp-button`, `exp-table`, `exp-list`,
`exp-status`.

---

## 8. Changelog / Versioning

The registration API is versioned independently of the plugin. Write your
extension against a known API version and check this section when upgrading.

### 1.0.0 — initial API
- Action `exp_register_extensions( EXP_Registry $registry )`.
- Functions: `exp_register_menu_item`, `exp_register_capability`,
  `exp_register_queue_type`, `exp_register_activity_formatter`,
  `exp_queue_submit`, `exp_user_can`, `exp_current_portal_user`,
  `exp_is_portal_authenticated`, `exp_wrap_module_output`,
  `exp_module_form_fields`.
- Menu item fields: `slug, label, render, capability, handle, icon, position, source`.
- Capability fields: `key, label, description, target_type, target_options, source`.
  `target_type` ∈ `none|page|category|calendar|custom`.
- Queue type fields: `type, label, review_renderer, applier, source`.
- Render context keys: `user, slug, csrf, form_action`.
- Notice shape: `{ type: error|success|info|warning, text: string }`.
- Governance: third-party menu items are gated behind admin approval by default
  (`extensions_require_approval`).

> **Compatibility policy:** additive changes (new optional fields, new functions)
> bump the **minor** version. Breaking changes (renamed/removed keys, changed
> callback signatures) bump the **major** version and are announced here.
