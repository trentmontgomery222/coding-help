# Tests

```
php tests/normalizer-test.php        # 30 cases — cache keys, no WordPress needed
npm install jsdom
node tests/browser-rules.test.js     # 76 cases — the browser rule engine
```

`normalizer-test.php` shims the handful of WordPress functions the normalizer
touches, so it runs anywhere PHP does.

`browser-rules.test.js` drives `assets/js/search-filter.js` against a fixture
built from real SearchWP result rows — highlight markup, absolute hrefs, PDFs
and images — including the rule payload the settings screen produces. Add a
case whenever you add an operator or action.

The PHP rule engine mirrors the browser one, so a change to how matching works
belongs in both `WPSQR_Rules::matches()` and the `matches()` in the JS.
