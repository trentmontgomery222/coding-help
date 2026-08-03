# External Portal (WordPress plugin)

A self-contained front-end portal that runs **alongside** WordPress with its own
users, authentication, sessions and permissions — sharing nothing with WordPress's
login/user system. Portal users are **not** WordPress users, have no WP role or
capability, and never touch `wp-login.php` or `wp-admin`.

This plugin implements the architecture described in
[`../external-portal-plugin-spec.md`](../external-portal-plugin-spec.md).

> **Status:** initial implementation (v0.1.0). Single-site only — it refuses
> network activation by design.

---

## What it does

- **Independent auth** — portal users log in on a front-end page via a one-time
  emailed code (OTP), or a password with OTP fallback. Their own cookie/session,
  never `is_user_logged_in()`.
- **Per-user permissions** — nothing is granted by default; every ability is an
  explicit `(user, capability, target)` grant. The capability list is dynamic.
- **Unified review queue** — every content change portal users submit lands in one
  Content Update Queue for admin approval (calendar changes optionally excepted).
- **Dashboard modules** — page editing, category-scoped posts, Google Calendar
  sharing, a general request form, and a "My Activity" history — each shown only if
  the user has been granted it.
- **Extension platform** — other plugins register their own menu items,
  capabilities, queue types and activity formatters without touching core. See
  [`docs/EXTENSION-API.md`](docs/EXTENSION-API.md).
- **Accessibility** — all custom UI targets WCAG 2.2 AA / Section 508.

---

## Install & set up

1. Copy the `external-portal/` folder into `wp-content/plugins/` and activate it on
   the site (not Network Admin).
2. Create two normal WordPress pages:
   - a **Login** page containing the shortcode `[external_portal_login]`
   - a **Dashboard** page containing `[external_portal_dashboard]`
3. Go to **Settings → External Portal → Settings** and select those two pages in
   *Portal pages*. Adjust OTP/session/lockout values as desired.
4. **Exclude both pages from full-page caching** (see [Caching](#caching-wp-engine)).
5. **Users** tab → create a portal account (email is enough). The user signs in on
   the Login page; their first successful sign-in activates the account.
6. **Permissions** tab → grant that user specific pages / categories / calendars /
   general submission rights.
7. *(Optional)* **Google Integration** tab → paste a service account key and add
   your calendar IDs to the whitelist to enable calendar sharing management.

---

## Decisions taken for the spec's open questions (Section 8)

All are configurable under **Settings**, and documented here so they can be revisited.

| # | Question | Default chosen | Setting |
|---|----------|----------------|---------|
| 1 | Calendar changes: live or queued? | **Live** (audit-logged) | `calendar_requires_approval` |
| 2 | Page-edit granularity | **Whole page** (payload is forward-compatible with field/block scoping) | — |
| 3 | Extension approval gate | **Require admin approval** | `extensions_require_approval` |
| 4 | Session timeout | 30 min idle / 12 h absolute, with an ARIA-live expiry warning | `session_*` |
| 5 | Password policy | ≥ 12 chars, letters + numbers; reset via OTP email | `password_min_length` |
| 6 | Auditing scope | Logins, permission changes, queue reviews, calendar changes, session revocations — viewable on the Audit Log tab | — |

---

## Caching (WP Engine)

Because of WP Engine's Global Edge Security / full-page cache, the Login and
Dashboard pages **must not be served from cache** — a cached page could leak one
portal user's session to another. The plugin sends `DONOTCACHEPAGE`, `nocache_headers()`
and `Cache-Control: no-store` on those pages, but the edge cache is configured at
the host level: add a **cache exclusion rule** for the Login and Dashboard page
paths in your WP Engine cache settings (or via a page rule). The plugin fires an
`exp_prevent_page_cache` action you can hook for host-specific integrations.

---

## Security notes

- OTP codes and session tokens are stored **hashed** (HMAC-SHA256 with a
  site-specific pepper); the raw values are only ever in the email / cookie.
- Passwords use WordPress's `wp_hash_password` (phpass).
- CSRF: portal forms carry a per-session token; login forms use WordPress nonces.
- Rate limiting: per-account lockout after N failed attempts plus a per-IP throttle.
- Google service-account credentials are stored base64-encoded in options; treat
  the database as sensitive and restrict access.
- Sessions set `HttpOnly`, `SameSite=Lax`, and `Secure` cookies (when on HTTPS).

---

## File map

```
external-portal.php                 Bootstrap, constants, autoloader, activation guard
uninstall.php                       Data removal on delete
includes/
  class-exp-install.php             DB schema (dbDelta) + defaults
  class-exp-settings.php            Settings accessor
  class-exp-util.php                IP/hash/token/time helpers
  class-exp-audit.php               Audit log
  class-exp-users.php               Portal user model (separate from wp_users)
  class-exp-otp.php                 OTP issue/verify (throttled)
  class-exp-mailer.php              OTP + admin notification emails
  class-exp-rate-limit.php          Lockout + IP throttle
  class-exp-session.php             Cookie/token sessions — the gatekeeper
  class-exp-auth.php                Login state machine, password policy
  class-exp-permissions.php         Grants + dynamic capability checks
  class-exp-registry.php            Extension registry + approval gate
  class-exp-queue.php               Content Update Queue
  class-exp-cache.php               Cache-exclusion signals
  class-exp-ui.php                  Accessible markup helpers
  class-exp-notices.php             PRG flash notices
  class-exp-plugin.php              Orchestrator / hooks
  class-exp-google-calendar-client.php  Service-account Calendar API client
  api.php                           Public exp_* functions for extensions
  modules/                          Core dashboard modules (page edit, posts,
                                    general request, calendar, my activity, account)
frontend/
  class-exp-router.php              template_redirect request routing (PRG)
  class-exp-shortcodes.php          [external_portal_login] / [external_portal_dashboard]
admin/
  class-exp-admin.php               Settings page controller + action handling
  views/                            Users, Permissions, Queue, Google, Extensions,
                                    Settings, Audit
assets/                             portal.css, admin.css, portal.js
docs/EXTENSION-API.md               Developer reference for extending the portal
```

---

## Known limitations / next steps

- **Beaver Builder pages:** the page editor updates `post_content` (a simple,
  accessible editor as the spec requires). It does not edit Beaver Builder's own
  layout data — that's intentional for v1; a future module could target specific
  BB modules/fields (spec Q2, field/block scoping).
- **Featured images** in the post module are sideloaded from a URL on approval;
  direct file upload from the portal is not yet implemented.
- **Google impersonation** (domain-wide delegation) is supported via the
  *Impersonate user* field but requires the service account to be configured for it
  in Google Workspace.
- Automated tests are not included yet.
