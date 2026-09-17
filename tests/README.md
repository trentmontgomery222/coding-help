# Crash-safety tests

Plain PHP scripts, no test framework and no WordPress install needed. They stub
the handful of WordPress functions the plugin touches and assert that the plugin
cannot take a site down.

These live outside `acps-alert-popups/` on purpose, so they are not part of the
plugin zip.

```bash
php tests/failsafe-test.php
for s in healthy missing-file safe-mode kill-switch; do php tests/boot-test.php "$s"; done
```

Both exit non-zero on failure, so they work as a pre-release check.

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
| `missing-file` | a required file is deleted mid-flight: stays dormant, no fatal, no false safe-mode |
| `safe-mode` | a previous fatal was recorded: stays dormant |
| `kill-switch` | `ACPS_ALERTS_DISABLE` is set in wp-config: never boots |
