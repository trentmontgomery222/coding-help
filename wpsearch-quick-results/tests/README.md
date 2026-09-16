# Tests

```
php tests/normalizer-test.php        # 30 cases — cache keys, no WordPress needed
npm install jsdom
node tests/browser-rules.test.js     # 95 cases — the browser rule engine
php tests/people-test.php            # 32 cases — person-row sanitizing
node tests/admin-builder.test.js     # 38 cases — the settings rule builder
```

`normalizer-test.php` shims the handful of WordPress functions the normalizer
touches, so it runs anywhere PHP does.

`browser-rules.test.js` drives `assets/js/search-filter.js` against a fixture
built from real SearchWP result rows — highlight markup, absolute hrefs, PDFs
and images — including the rule payload the settings screen produces. Add a
case whenever you add an operator or action.

`admin-builder.test.js` exists because the builder failed silently once: the
Add rule button did nothing, the page looked fine, and there was nothing in
the console. It checks what actually reaches the server — field names, indexes
after a removal, prototypes staying out of the submission — rather than how
the page looks. Its fixture mirrors the markup in
`includes/class-wpsqr-admin.php`; change one and change the other.

`people-test.php` covers the boundary where data written by another plugin —
or another AI session — reaches a public page: markup stripping, URL protocol
rejection, contact details staying opt-in, and undocumented fields being
discarded rather than passed through.

The PHP rule engine mirrors the browser one, so a change to how matching works
belongs in both `WPSQR_Rules::matches()` and the `matches()` in the JS.
