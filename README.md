# Cayden Form Manager

A single WordPress plugin combining three connected features, built to the spec
in this repository:

1. **Journey tracking** — cache-safe, first-party recording of each visitor's
   ordered page sequence.
2. **Feedback system** — a thin, inviting feedback form (built as a template of
   the form engine) with the page pre-selected from the visitor's journey.
3. **Form builder** — a general-purpose, accessible form system to replace
   Google Forms.

The plugin lives in [`acps-site-toolkit/`](acps-site-toolkit/).

## Environment it targets

- WordPress **single site** (no multisite code paths anywhere)
- WP Engine + **Global Edge Security** (aggressive full-page edge caching)
- Beaver Builder page builder and child theme
- WCAG 2.1 AA / Section 508 floor, **WCAG 2.2 AA** target

## The two failure modes it avoids

1. **Server-side tracking on a cached site** → visits are recorded only via a
   client-side beacon (`assets/js/tracking.js`) to an uncached REST route.
2. **Nonces baked into cached HTML** → the submission nonce + time-trap token
   are fetched after load from `/wp-json/acps-st/v1/token` and injected into
   the form.

## Install

1. Upload the `acps-site-toolkit` folder to `wp-content/plugins/` (file manager
   is fine — the main file has a complete plugin header).
2. Activate it on the **Plugins** screen. Activation creates the tables and the
   built-in feedback form.
3. Configure under **Cayden Form Manager → Settings**.

## Structure

```
acps-site-toolkit/
├── acps-site-toolkit.php     Main file: header, constants, autoloader, bootstrap
├── uninstall.php             Drops tables only if "preserve data" is off
├── includes/
│   ├── class-plugin.php      Wires the pillars together, registers hooks
│   ├── class-schema.php      Table DDL + version-checked upgrades
│   ├── class-activator.php / class-deactivator.php
│   ├── class-settings.php    Settings model + Settings API
│   ├── class-session.php     Session tokens, IP anonymization, UA parsing
│   ├── class-tracking.php    Visit writes + recent-pages reads (beacon only)
│   ├── class-form.php        Form CRUD (fields/settings as JSON)
│   ├── class-field-types.php Field-type registry
│   ├── class-form-renderer.php  Accessible markup (labels, fieldsets, errors)
│   ├── class-spam.php        Honeypot, time trap, nonce, rate limit, blocklist
│   ├── class-submission.php  Validation → storage → notifications
│   ├── class-entries.php     Entry/value/note data layer
│   ├── class-notifications.php  Admin email + auto-reply + merge tags
│   ├── class-feedback.php    Feedback form template + modal + page pre-fill
│   ├── class-analytics.php   Metrics, path analysis, feedback/traffic overlay
│   ├── class-privacy.php     GDPR export/erase + scheduled retention purge
│   ├── class-rest-controller.php  /beacon /unload /token /recent-pages /submit
│   ├── class-integrations.php     Shortcode, Gutenberg block, Beaver module
│   ├── admin/                Menu + five screens + CSV exporter
│   └── beaver/              Beaver Builder module
├── assets/js/                tracking, forms, feedback, admin-builder, block
├── assets/css/               frontend, admin
└── templates/                Child-theme override reference + hooks list
```

See [`acps-site-toolkit/readme.txt`](acps-site-toolkit/readme.txt) for the
WordPress-format readme and [`acps-site-toolkit/templates/README.md`](acps-site-toolkit/templates/README.md)
for template overrides and hooks.

---

_The original site-export XML files remain in the repository root for
reference._
