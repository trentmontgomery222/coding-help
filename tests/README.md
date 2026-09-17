# Crash-safety tests

Plain PHP scripts, no test framework and no WordPress install needed. They stub
the handful of WordPress functions the plugin touches and assert that the plugin
cannot take a site down.

These live outside `acps-alert-popups/` on purpose, so they are not part of the
plugin zip.

```bash
php tests/failsafe-test.php
php tests/post-type-test.php
php tests/status-test.php
php tests/render-test.php
php tests/help-test.php
for s in healthy admin-healthy missing-file missing-help safe-mode kill-switch; do php tests/boot-test.php "$s"; done
```

All exit non-zero on failure, so they work as a pre-release check.

## What they cover

`failsafe-test.php` — the containment helpers:

- `guard()` catches both `Exception` and `Error`, returns the fallback, and
  survives a non-callable callback
- a wrapped **filter** returns its first argument unchanged when it fails, so a
  broken callback can never break another plugin's filter chain
- `capture()` discards partially rendered output when a renderer throws
  mid-markup, and unwinds any output buffer the renderer leaked
- the circuit breaker trips after repeated failures and then short-circuits
  without running the failing code again
- the problem log records and clears
- the file-integrity lists match what is actually on disk

`boot-test.php` — the request survives every degraded state, one process per
scenario (a real fatal would end the process, so the exit status is the
assertion):

| Scenario | Expected |
|---|---|
| `healthy` | boots and loads its classes |
| `admin-healthy` | boots as an admin request, and the help layer loads too |
| `missing-file` | a required file is deleted mid-flight: stays dormant, no fatal, no false safe-mode |
| `missing-help` | the optional help files are deleted: the plugin still loads fully, only the tutorials go |
| `safe-mode` | a previous fatal was recorded: stays dormant |
| `kill-switch` | `ACPS_ALERTS_DISABLE` is set in wp-config: never boots |

`admin-healthy` is the control for `missing-help`. The help layer only loads on
admin requests, so without it `missing-help` would pass for the wrong reason.

`post-type-test.php` — pins the "there's no way to save it" bug. Alerts used to
live on whatever post type Beaver Builder registered for popups, so whether the
Add New screen had a title field and a Publish button was out of the plugin's
hands — and with Beaver Themer layouts it had neither. These checks assert:

- the alert post type is registered with an admin UI, and supports title,
  editor and revisions — the things that make a screen savable
- it is publicly queryable with a rewrite slug, because Beaver Builder edits a
  layout on a front-end URL, but stays out of search, archives and nav menus
- the post type is handed to Beaver Builder via its filter, preserving whatever
  other types were already enabled
- "Add New" points at the plugin's own type — even when a Beaver Builder popup
  type also exists, and even when an admin has overridden the source type
- popups that already exist on a Beaver Builder type are still listed, and
  `is_popup()` accepts each source while rejecting ordinary pages and non-popup
  Themer layouts
- the plugin reports itself ready with Beaver Builder switched off

`status-test.php` — the status board. The daily cut-off is the part worth
pinning:

- the cut-off time is validated, and anything malformed falls back to 17:50
- the deadline lands at the cut-off on the same day for a morning post, rolls to
  the following day for an evening one, and rolls exactly one day just before
  midnight
- posting exactly at the cut-off runs until tomorrow, so an update is never
  archived the instant it is posted
- the cut-off is 17:50 in **site** time, not UTC (checked against a UTC-5 site)
- "keep" and "custom" entries never expire on the daily sweep
- an entry past its cut-off stops being current even if cron never fired
- the admin-only view: a visitor and a logged-in non-staff user both fail to see
  a staff-only entry, staff see it, and nobody sees a preview-only entry on the
  board
- every level maps to a real severity and the ranks order correctly

`render-test.php` — pins the "it's duplicating everything" bug. Beaver Builder
hooks its layout renderer onto `the_content`, so asking it to render a post that
also has editor content returned both — the whole popup appeared twice, once
builder-styled and once theme-styled. These checks assert:

- a post with a builder layout renders that layout exactly once, and its editor
  content not at all
- a post without a layout renders its editor content once, and no layout is
  invented
- a post with the builder switched off falls back to the editor even when stale
  layout data is still in the database
- an enabled-but-empty layout falls back rather than blanking the popup
- something hooked on `the_content` that reaches back into the renderer cannot
  double the body (re-entry guard)
- the same alert queued twice prints once, and a second `wp_footer` pass prints
  nothing more

Verified non-vacuous: run against the pre-fix renderer it fails with exactly the
reported symptom.

`help-test.php` — the teaching layer:

- every checklist item carries the keys the view reads, and `done` is a real
  boolean
- the checklist reacts to actual site state (creating a popup ticks items off)
- every tour step has a title and body, is reachable, and uses a placement the
  engine understands
- every step anchored to `[data-acps-section="…"]` points at a section the
  settings form really renders — so a renamed section breaks the test rather
  than silently breaking the tour
