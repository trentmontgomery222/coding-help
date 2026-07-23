# Template overrides

The form renderer looks for overrides in your (child) theme before falling back
to its built-in markup (spec §10). To customise field markup or the feedback
modal without editing plugin files, copy a template into your theme:

```
wp-content/themes/your-child-theme/acps-site-toolkit/form.php
wp-content/themes/your-child-theme/acps-site-toolkit/feedback-modal.php
```

## Available variables

### `form.php`
- `$acps_form`   — the `ACPS\SiteToolkit\Form` object
- `$acps_fields` — the normalized field list (array)
- `$acps_args`   — render context (e.g. `post_id`)

Echo the full `<form>` markup. **If you override this, you own the
accessibility of the result** — keep the real `<label>`s, the `<fieldset>`/
`<legend>` for choice groups, the error summary, the empty token slots
(`acps_nonce`, `acps_ts`, `acps_session`), and the honeypot container. The
`assets/js/forms.js` runtime depends on those hooks.

### `feedback-modal.php`
Rendered in the footer. Available: `$form` (feedback Form), `$form_html`
(pre-rendered form), `$label`, `$position`, `$post_id`, `$title`.

## Reference

The file `form.php` in this directory is a faithful copy of the built-in markup
you can start from. It is **not loaded** from here — only a copy inside your
theme's `acps-site-toolkit/` directory is used.

## Hooks

Prefer hooks over template overrides where possible:

- `acps_st_field_types` (filter) — register custom field types
- `acps_st_before_submission` (action)
- `acps_st_validation_errors` (filter)
- `acps_st_after_submission` (action)
- `acps_st_admin_email_body` (filter)
- `acps_st_allowed_upload_mimes` / `acps_st_max_upload_bytes` (filters)
