# Crash-safety tests

Plain PHP scripts, no test framework and no WordPress install needed. They stub
the handful of WordPress functions the plugin touches and assert that the plugin
cannot take a site down.

These live outside `acps-alert-popups/` on purpose, so they are not part of the
plugin zip.

```bash
php tests/failsafe-test.php
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

`help-test.php` — the teaching layer:

- every checklist item carries the keys the view reads, and `done` is a real
  boolean
- the checklist reacts to actual site state (creating a popup ticks items off)
- every tour step has a title and body, is reachable, and uses a placement the
  engine understands
- every step anchored to `[data-acps-section="…"]` points at a section the
  settings form really renders — so a renamed section breaks the test rather
  than silently breaking the tour
