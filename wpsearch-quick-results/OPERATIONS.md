# Operations — failsafe, updates, remote endpoint

This file is for whoever runs the site. None of it is linked from the plugin's
own screens; the remote endpoint is not mentioned anywhere a visitor or editor
would see. Keep this file out of the shipped zip if that matters to you.

## Failsafe (always on, nothing to configure)

- **Missing files are survived.** Each include is loaded behind an
  `is_readable` check. A half-finished update that drops a file leaves the rest
  of the plugin running and records the gap; the next complete update clears
  it. Reported on the remote status page as "Missing files".
- **A fatal in this plugin's own code trips safe mode.** A shutdown handler
  checks whether the fatal was in this plugin's directory — and only then —
  and sets a flag. The next request loads *only* a recovery notice with a
  "resume" link, so a bad release cannot white-screen the site. An unrelated
  plugin's fatal is left alone.
- **`boot()` is wrapped in try/catch**, so a thrown error during setup becomes
  safe mode rather than a broken page.

Resume from the admin notice, or remotely (see below).

## Self-updates

Settings live at **Settings → (the plugin's settings page) with `?updates=1`
added to the URL** — unlinked, so nobody lands on it by accident. There:

- **Manifest URL** — a URL you host. The plugin fetches it as
  `MANIFEST?plugin=<slug>&site=<url>&key=<key>`, no login required (it is a
  machine reading a file). It must return JSON with at least:

  ```json
  { "version": "1.3.0", "download_url": "https://.../wpsqr-1.3.0.zip" }
  ```

  Optional: `changelog`, `homepage`, `tested`.
- **Update key** — sent as `key`, so the manifest can confirm it is this site
  asking and serve a 404 otherwise.

When the manifest reports a higher version, "Update now" appears on the
Plugins screen as for any other plugin. A zip that unpacks to a differently
named folder is renamed back in place, so the update overwrites rather than
installing beside itself and deactivating.

**After every update**, on the next request, the plugin confirms it still
loaded *and* that the remote endpoint's key still exists — regenerating it if
an update somehow cleared it. This is the piece that stops an update from
severing the channel used to push the next one. The result is on the status
page as "Last update".

## The remote endpoint

A status page reachable **without a wp-admin login**, at a secret URL, for
checking on or steering the site when wp-admin itself is the problem.

Four gates, all of which must pass:

1. **The key in the URL.** The full URL is the secret. Without the exact key
   the request 404s like any bad URL — the endpoint does not announce itself.
2. **The visitor's IP**, against allow/deny rules. Default: `allow
   167.102.110.1`, everything else denied. Rules support full addresses,
   prefixes (`196.168.`), and CIDR blocks (`10.0.0.0/8`); most specific wins,
   deny wins a tie, and anything unmatched is denied. An empty box locks
   everyone out, so saving one falls back to the default address.
3. **A rate limit** — 20 requests per 5 minutes per IP — so the key and
   password cannot be pounded on.
4. **The password**, for anything that changes a setting. Reading the status
   page needs only the first three; editing needs all four.

The password is set **only in wp-admin**, on the `?updates=1` panel. And an
edit through the remote page is allowed **once per day** — even a fully
authenticated mistake cannot be repeated in a loop.

The status page reports version, safe-mode state, missing files, last-update
result, the engine and index, cache hit rate and average uncached search time,
and whether cron is running. From it, with the password, you can flip the
engine, toggle site-search takeover and warming, and — the reason it exists —
**clear safe mode remotely** to bring a crashed plugin back.

**It can also update the plugin.** "Check the source now" re-reads the
manifest (no password — looking is not changing anything) and shows whether a
newer version is offered. "Install update now" (password required) runs the
same upgrader the Plugins screen would, folder-rename and post-update
self-check included, so an update can be pushed and applied without ever
opening wp-admin. Installing has its own short cooldown rather than the
once-a-day settings limit, so a failed attempt can be retried.

**The key is yours to set.** In the `?updates=1` panel, type the endpoint key
you want (12+ characters, `A-Z a-z 0-9 . _ ~ -`) — it is not random unless you
ask for one. Changing it changes the URL and the old one stops working at
once. The key can also be changed from the remote page itself, with the
password.

**All settings are editable from the remote page**, not just a handful —
grouped the way the admin screen groups them, sanitized by the same rules, and
still capped at one change per day. The IP rules are editable there too, once
the password is entered.

**A blocked address is redirected to the homepage**, not shown a refusal, so
the endpoint gives no sign of existing to an address that is not allowed.

## Tests

```
php tests/netgate-test.php   # 34 — the IP gate, exhaustively
php tests/guard-test.php     # 12 — missing-file survival and safe mode
```

The IP gate is tested hardest because it is the only thing between the open
internet and a settings editor. The rest of the remote flow (password, rate
limit, once-a-day) needs a WordPress runtime to exercise end to end — the
setup checklist above is the manual test.
