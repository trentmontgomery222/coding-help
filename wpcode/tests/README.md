# Rule engine tests

Runs the real `02-search-filter.js` against a fake search results page in
jsdom, so you can check a rule does what you expect before putting it on the
live site.

```
npm install jsdom
node wpcode/tests/rules.test.js
```

The harness swaps the `QUERY_RULES` and `RULES` arrays in the snippet for the
ones each test supplies, then asserts on the resulting DOM. If you add a new
operator or action, add a case here.
