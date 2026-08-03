# WordPress "External Portal" Plugin — Concept & Planning Spec

> **Purpose of this document:** This is a **planning / reference document, not code.**
> It exists so an AI assistant (or a developer) can read it *before* writing any code
> for this plugin and understand the full intended architecture, decisions already
> made, and open questions still to resolve. **No implementation has started yet.**

---

## Table of Contents

1. [Site Context (relevant constraints)](#1-site-context-relevant-constraints)
2. [Core Concept](#2-core-concept)
3. [Authentication Flow](#3-authentication-flow)
4. [Data Model (conceptual)](#4-data-model-conceptual)
5. [Dashboard Modules](#5-dashboard-modules)
6. [wp-admin Side (Settings area, single-site only)](#6-wp-admin-side-settings-area-single-site-only)
7. [Extensibility: Letting Other Plugins Add to the Portal](#7-extensibility-letting-other-plugins-add-to-the-portal)
8. [Open Questions](#8-open-questions)
9. [Explicit Non-Goals](#9-explicit-non-goals)
10. [Status of This Document](#10-status-of-this-document)

---

## 1. Site Context (relevant constraints)

- **Platform:** WordPress multisite, but this plugin is explicitly **single-site scoped** — it must **NOT** be a network-activated / network-managed plugin. All settings live in a normal site's **Settings** area in `wp-admin`, not Network Admin.
- **Hosting:** WP Engine, using WP Engine's **Global Edge Security**.
- **Page builder:** **Beaver Builder** is used for page layout/content on the main site.
- **Theme:** A **child theme** is in use for additional customization.
- **Accessibility requirements:** Minimum **WCAG 2.1 AA**, target **WCAG 2.2 AA or better**, and **Section 508** compliance. This applies to *every* custom UI this plugin renders — login screens, dashboards, forms, error/status messaging — since none of it uses WordPress's built-in accessible form/login markup.
- **Caching consideration:** Because of WP Engine's Global Edge Security / page caching, any page that renders this plugin's login or dashboard shortcode **must be excluded from full-page caching** (or otherwise handled so cached pages don't leak one portal user's session/data to another, and so cookies/session tokens are reliably set and read).

---

## 2. Core Concept

A plugin that creates a **second, fully independent authentication and user system**, running alongside WordPress but sharing nothing with it:

- These **"portal users" are not WordPress users.** They are **not** stored in `wp_users`, have no WP role or capability, and never log in via `wp-login.php`.
- They log in through a dedicated **front-end page** that contains a plugin-provided **shortcode** (a login form).
- Once authenticated, they get a **separate session mechanism** (its own cookie/token, own expiration, own storage) — entirely disconnected from WordPress's auth cookies. Being logged into WP as an admin has no effect on this system, and vice versa.
- After logging in, they land on a **dashboard page** (also a shortcode), which only shows the specific tools/modules an admin has **explicitly granted** them. **Nothing is available by default** — everything is permission-scoped per individual portal user.

> **Mental model:** a small, self-contained "mini app" living inside the WordPress
> install, sharing the database and domain, but isolated from WP's login system and
> capability model entirely.

---

## 3. Authentication Flow

- **Account creation:** Only a **WordPress admin** can create a portal account, from a Settings screen inside `wp-admin` (single-site, not network admin). Admin supplies at minimum an **email address**.
- **Auth modes (settable per user):**
  - **OTP-only** (a one-time code emailed at each login).
  - **Password with OTP fallback.**
  - The portal user can set/change their own password later, but **only from inside the dashboard** — which they can only reach by first authenticating via OTP (or an existing password). This is a deliberate UI/UX detail from the original request.
- **Login page:** A normal WP page with the plugin's login shortcode on it. User enters email → receives a one-time code by email → enters the code → session is created.
- **Session behavior:** Distinct cookie name from WordPress's; the plugin's own gatekeeper function checks this session — **never** `is_user_logged_in()` or any WP capability check. Sessions should have configurable expiration/inactivity timeout (open decision, but recommended).
- **Security features to include:** Rate limiting/lockout on OTP and password attempts; audit logging of logins and permission changes (decided in extension planning, see [Section 7](#7-extensibility-letting-other-plugins-add-to-the-portal)).

---

## 4. Data Model (conceptual)

*Conceptual only — no code yet, just table purposes.*

| # | Table | Purpose / key fields |
|---|-------|----------------------|
| 1 | **Portal Users** | `id`, `email`, display name, `status` (active / invited / disabled), `password_hash` (nullable — OTP-only allowed), created/updated timestamps, last login timestamp. |
| 2 | **Portal OTP Codes** | `user_id`, hashed code, `expiration`, `used` flag, context (IP, etc.) for throttling/lockout. |
| 3 | **Portal Sessions** | Hashed session token, `user_id`, `expiration`, IP/user-agent fingerprint, `revoked` flag. This is what the portal's cookie references. |
| 4 | **Portal Permissions / Grants** | A row per `(portal user, capability key, target)`. E.g., "user #3 can edit page #12," "user #3 can post to category #7," "user #3 can manage calendar X." This is the mechanism that scopes each portal user to only what they've been explicitly given — **no all-or-nothing roles.** |
| 5 | **Content Update Queue** | A **single shared table** for **ALL** submitted changes regardless of type (page edits, PDF replacements, new posts, third-party plugin submissions). Columns: item/content reference, type, submitting portal user, status (pending/approved/rejected), admin notes, timestamps. |

---

## 5. Dashboard Modules

*(As originally requested.)*

All content-changing actions from portal users flow into the **Content Update Queue** — nothing saves live **except (tentatively) calendar sharing changes** (see open question in [Section 8](#8-open-questions)). This was a deliberate simplification decided mid-conversation: **one unified review pipeline** instead of separate direct-save logic per module.

### 5.1 Page Content Editing

- Admin grants a portal user edit access to **specific pages** (or, ideally, specific fields/content regions within a page — exact **granularity is still an open decision:** whole page vs. specific block/field).
- Portal user sees a **simple form-style editor** for just that content — **NOT** the Beaver Builder page builder itself.
- Submission goes into the **Content Update Queue** for admin review/approval before going live.

### 5.2 Category-Scoped Post Creation (for an existing post carousel)

- Admin grants a portal user rights to add/edit posts within **one specific category** (the category the site's existing carousel pulls from).
- Portal user gets a **minimal add/edit post form scoped to that category** (title, excerpt, featured image, maybe a couple custom fields) — not the full WP post editor, and no `wp-admin` access.
- Submission goes into the **Content Update Queue**.

### 5.3 Google Calendar Sharing Management

- **Architecturally different** — this isn't a WP content type, it's a call to the **Google Calendar API**.
- **Decision made:** Use a **single shared connection** (one service account or OAuth app with delegated access), configured once by the site admin in `wp-admin`'s **Google Integration** settings tab. Portal users never see a Google login screen and never need their own Google account.
- Admin also maintains a **whitelist of which calendar ID(s)** exist in the system, and the Permissions/Grants table scopes which portal user can manage which calendar(s).
- **Portal dashboard UI:** list of calendars the user is granted, with add/remove-person and change-access-level controls for calendar sharing (**ACL**), executed via the API using the shared service account credentials.
- **Open question (unresolved):** should calendar sharing changes also route through the Content Update Queue for admin approval, or apply live since Google's own interface retains its own history/undo? **Current lean: apply live**, since it's lower-risk and reversible in Google directly — but this is **NOT finalized.**

### 5.4 General Content Update Queue Submission

- A **catch-all submission form** for things like "replace this PDF" or "update this section," used when there's no dedicated module for the request.
- Lands in the same **Content Update Queue** as everything else, tagged with its own type.

### 5.5 My Activity (portal user–facing history view)

- A panel in the portal dashboard showing the logged-in portal user **their own submission history**: what they submitted, current status (pending/approved/rejected), and any admin notes.
- Reuses the same **Content Update Queue** table, just filtered to `submitted_by = current portal user` — no separate data structure needed.
- **Accessibility note:** status must be shown as **clear text labels** (e.g., "Rejected — see note"), **not conveyed by color alone** (WCAG requirement).

---

## 6. wp-admin Side (Settings area, single-site only)

A **"Portal Access"** settings page with sections/tabs:

- **Users** — create/invite/disable portal accounts, reset OTP, force-expire sessions. Must support **search, filtering (by status), and pagination** — designed to scale beyond a handful of users to dozens or more.
- **Permissions** — assign what each portal user can access: specific pages, category-posting rights, specific calendar(s), general queue-submission rights. Should support **bulk actions** (e.g., bulk disable users, bulk revoke a specific permission across many users) and ideally **reusable permission presets/bundles** (e.g., a "Carousel Contributor" preset that pre-checks the relevant grants) so admins aren't manually rebuilding the same permission set for every similar new user.
- **Content Update Queue (review screen)** — one unified place to review/approve/reject **ALL** submission types (page edits, PDFs, new posts, third-party plugin submissions). Needs **filtering/search** (by type, status, submitter) and, as volume grows, **pagination**. Should trigger an **email notification to admins** when new items are queued, rather than relying on manual checking.
- **Google Integration** — connect/manage the single shared service account/OAuth credentials; maintain the calendar ID whitelist.
- **General Settings** — OTP expiration time, session lifetime/inactivity timeout, email templates, lockout/throttling thresholds.

---

## 7. Extensibility: Letting Other Plugins Add to the Portal

**Decision made:** This plugin should function as a **platform, not a closed system.** Other plugins (built now or later, by you or an AI assistant) must be able to **register new dashboard features into the portal without modifying this plugin's core code.** This mirrors how WordPress core lets plugins register their own admin menu pages — same idea, applied to the portal instead of `wp-admin`.

### What needs to be registerable by third-party plugins

1. **A menu item** in the portal dashboard — label, icon, unique identifier/slug.
2. **A permission/capability key** that the registering plugin defines (e.g., a newsletter plugin registering `manage_newsletter_signup`). Once registered, this new capability **must automatically appear as an assignable option on the `wp-admin` Permissions screen** — the admin should not have to hand-code new checkboxes for every new capability. **This means the Permissions/Grants system must be dynamic/loopable, not a hardcoded list.**
3. **The content/renderer** — a way for the registering plugin to supply the function/shortcode/callback that generates that module's panel content when a portal user clicks its menu item.
4. **(Optional) Queue integration** — a way for a third-party plugin's submissions to land in the same **shared Content Update Queue**, tagged with its own type, so admins review everything in one screen instead of every plugin building its own separate review UI.
5. **(Optional) Activity log integration** — same idea, so the "My Activity" panel stays unified even when third-party modules are involved.

### Guarantees this plugin should provide to any registering plugin

- A registered callback **will only ever be invoked after the portal session has already been verified as authenticated** — third-party code should never need to reimplement auth/session checks itself.
- Registered content should **render inside a consistent styling/markup wrapper controlled by this plugin**, so accessibility (WCAG 2.2 AA / Section 508) isn't broken by inconsistent third-party markup. Consider enforcing specific ARIA/markup patterns, or auto-wrapping third-party output in an accessible container.

### Safety / Governance decision — still open

Should any plugin's registered menu item **automatically appear** to portal users the moment it's registered, or should there be an **admin approval gate** in `wp-admin` — an **"Approved Extensions"** list — where the site admin must explicitly turn on a registered item before portal users ever see it? Given this system touches sensitive things (calendars, page content, PDFs), the **recommended default leaning is to require explicit admin approval per registered item**, but this is **not yet finalized** and should be decided before development of the extension system begins.

### Deliverable still needed

A **separate developer-facing reference document** (living alongside this plugin, e.g. as its own markdown file in the plugin repo) that documents the **actual registration hooks/functions once they're built** — written specifically so an AI assistant or developer can read it before extending the plugin. That document should include:

1. Overview of the extension system and its purpose.
2. **Menu registration** — required fields/data shape.
3. **Permission registration** — how new capabilities surface in the admin grants screen.
4. **Content-rendering contract** — what a third-party callback must produce, and what it can assume is already handled for it (auth check, accessible wrapper).
5. **Queue integration** — how to tag a submission so it appears in the shared review screen.
6. **Activity log integration** — same, for the user-facing history view.
7. **Accessibility requirements** for anything rendered inside the portal.
8. A **changelog/versioning section** tracking changes to the registration API itself, so future AI-assisted work isn't written against outdated hooks.

> *(That reference document should be written once the actual hook names/data shapes
> are decided — this current spec is the pre-code planning stage.)*

---

## 8. Open Questions

*Not yet resolved — flag before/during build.*

1. **Calendar sharing changes:** live/immediate, or routed through the Content Update Queue like everything else? *(Current lean: live, not finalized.)*
2. **Page-edit permission granularity:** whole-page access, or field/block-level scoping? Needs deciding before the Permissions/Grants table's `target` structure is finalized for this module.
3. **Extension approval gate:** should third-party registered menu items require explicit admin approval in `wp-admin` before appearing to portal users? *(Recommended: yes, not finalized.)*
4. **Session inactivity/expiration policy:** exact timeout values, and whether there's a user-facing "session about to expire" warning *(also an accessibility touchpoint — must be announced via ARIA live region, not just visual)*.
5. **Password policy:** if a portal user sets a password, what complexity/reset rules apply, and does reset flow purely through the OTP-email mechanism *(most likely, mirroring the primary login flow)*?
6. **Auditing scope:** what exactly gets logged (logins, permission changes, queue approvals/rejections) and whether/where admins can view that log.

---

## 9. Explicit Non-Goals

- This is **not** a network/multisite-wide plugin. It must not appear in or rely on Network Admin.
- Portal users are **never** given any WordPress role, capability, or `wp-admin` access of any kind.
- Portal users **never** use Beaver Builder directly — all their content actions are simplified forms feeding into the queue or (for calendars) the Google API.

---

## 10. Status of This Document

This is a **pre-code planning document** capturing all decisions and open questions discussed so far. **No database schema, hooks, function names, or actual code have been written.** The next steps, in order, should be:

1. **Resolve the open questions in [Section 8](#8-open-questions)** (at least items 1–3, which affect data structure).
2. **Finalize the concrete database schema** (field names/types) based on [Section 4](#4-data-model-conceptual).
3. **Design the actual extension/registration hook names and data contracts** ([Section 7](#7-extensibility-letting-other-plugins-add-to-the-portal)), then write the developer-facing reference document described there.
4. **Only then begin implementation**, starting with the **auth/session system** ([Section 3](#3-authentication-flow)) — since every other module depends on it.
