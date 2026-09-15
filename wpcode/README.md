# WPCode snippets: filter & restyle search results on the front end

Three snippets that let your hide-plugin's "hidden" flag control what shows up
on the search results page — without touching the search query or the index.

| File | WPCode Code Type | Location |
|---|---|---|
| `01-search-bridge.php` | PHP Snippet | Run Everywhere |
| `02-search-filter.js` | JavaScript Snippet | Site Wide Footer |
| `03-search-styles.css` | CSS Snippet | Site Wide Header |
| `04-search-filter-admin.php` | PHP Snippet | Run Everywhere (priority 11) |

## How it fits together

JavaScript alone can't know which results are hidden — that fact lives in the
database. So snippet #1 is a small PHP bridge: it looks up everything your
hide-plugin has flagged and prints the IDs and URL paths into
`window.ACPS_SEARCH` on search pages only. Snippet #2 reads that and rewrites
the page. Snippet #3 styles the result.

Matching happens three ways, most reliable first:

1. The `acps-hidden-result` class that snippet #1 adds via `post_class()`.
2. The post ID, read from the theme's own `post-123` class or a
   `data-post-id` attribute.
3. The result's link path, compared against the hidden permalinks.

That third path is the fallback that makes this work even when the search
plugin renders its own markup and ignores `post_class()` entirely.

## Writing rules in the snippet

Snippet #2 has two arrays at the top. Uncomment a line, edit it, save.

**`QUERY_RULES`** act on what the visitor typed, before any result is looked
at. First match wins.

```js
// Searching this exact phrase returns nothing at all.
{ op: 'equals', value: 'staff directory', then: 'noResults',
  message: 'That information isn\'t available here.' },

// Any search containing this word returns nothing.
{ op: 'contains', value: 'payroll', then: 'noResults' },

// Several phrases at once.
{ op: 'in', value: [ 'ssn', 'social security', 'w-2' ], then: 'noResults' },

// Send a common search straight to the right page instead.
{ op: 'equals', value: 'lunch menu', then: 'redirect', url: '/menus/' },

// Banner above the results, results left alone.
{ op: 'contains', value: 'enrollment', then: 'notice', message: 'Try admissions.' },

// Let one term through even if a broader rule below would catch it.
{ op: 'equals', value: 'board policy', then: 'allow' }
```

**`RULES`** act on each individual result. They run top to bottom; `hide`,
`dim` and `keep` stop the rest.

```js
{ when: 'url',   op: 'contains', value: '/staff-only/', then: 'hide' },
{ when: 'title', op: 'starts',   value: 'Draft',        then: 'hide' },
{ when: 'id',    op: 'in',       value: [ 412, 998 ],   then: 'hide' },
{ when: 'url',   op: 'regex',    value: '/20(1[0-9])/', then: 'hide' },
{ when: 'title', op: 'contains', value: 'archived',     then: 'dim' },
{ when: 'title', op: 'contains', value: 'ACPS - ', then: 'rewrite', replace: '' },
{ when: 'url',   op: 'contains', value: '/news/',  then: 'badge', label: 'News' },
{ when: 'url',   op: 'contains', value: '/enrollment/', then: 'top' },
{ when: 'url',   op: 'contains', value: '/important/',  then: 'keep' }
```

| | values |
|---|---|
| `when` | `title` `url` `id` `type` `excerpt` `text` |
| `op` | `equals` `contains` `starts` `ends` `regex` `in` |
| `then` (results) | `hide` `dim` `rewrite` `badge` `top` `keep` |
| `then` (queries) | `noResults` `redirect` `notice` `allow` |

All text matching is case-insensitive and trims surrounding whitespace.

`when: 'url'` gives you the **path only**, lower-cased. Pages carry a trailing
slash (`/about/`, not the full URL) so `/enrollment/` matches a top-level page
as well as a nested one. File links keep their real ending, so a rule anchored
on an extension works:

```js
// Drop the images SearchWP indexes, keep the PDFs
{ when: 'url', op: 'regex', value: '\\.(jpe?g|png|gif|svg|webp)$', then: 'hide' }
```

`when: 'type'` reads the post type off the result's own `type-…` class — on
this site `page`, `post` or `attachment`:

```js
// A broad search returns a wall of PDFs; this drops them all
{ when: 'type', op: 'equals', value: 'attachment', then: 'hide' }
```

## Fitted to this site's markup

The selectors in snippet #2 are already set for the SearchWP results module:

| | |
|---|---|
| container | `.swp-search-results` |
| result row | `.swp-result-item` |
| title link | `.entry-title a` |
| excerpt | `.swp-result-item--desc` |
| count | `.swp-total-results-notice p` |

Two details worth knowing, both handled:

**The wrapper id is generated per render** (`swp-search-results-6aa940fc80207`),
so nothing matches on it — only the class.

**SearchWP wraps every matched term in `<mark class="searchwp-highlight">`.**
Rules read the title through `textContent`, so a phrase matches straight
across a highlight boundary. A `rewrite` walks the text nodes rather than
replacing them, so the highlighting survives being edited. The one limit: a
phrase split *across* a `<mark>` lives in two nodes and won't be replaced —
target a prefix like `"ACPS - "`, not the word someone just searched for.

**The result count** notice sits outside the results wrapper, so it's looked
up document-wide. Note that it reports the whole result set (271) while the
page only holds one page of it; if your results are paginated, set
`updateCount: false` in `OPTIONS` rather than correcting a number that was
never about this page.

## Managing rules from wp-admin

Snippet #4 is what keeps you out of the code. It adds:

- **Settings → Search Filters** — manual rules: specific post IDs or pasted
  URLs, URL fragments, title keywords, `find => replace` title rewrites, plus
  the display options (remove vs. grey-out, editor preview, no-results text).
  The page also lists everything currently hidden, and where each item came
  from, with edit links.
- **A "Hide from search results" checkbox** in the sidebar of every post and
  page edit screen.
- **A "Hide from search" row action** and **bulk actions** in the posts list,
  so you can flag a batch in one go.
- **A "Search" column** showing at a glance what's hidden.

Rules saved there are translated into the same rule shape as the arrays above
and appended to `RULES`, so the two ways of working are interchangeable — use
the arrays for things you want in version control, the settings page for
things editors need to change. Manual IDs are merged with whatever your
hide-plugin flags, and the keyword
and display rules ride along in the same `window.ACPS_SEARCH` payload. The JS
merges them in, so a routine change never means editing code.

The settings page writes the same meta key as your hide-plugin
(`ACPS_HIDE_META_KEY`), so the two stay in sync rather than fighting — flag
something in either place and both agree.

## Setup

1. **Point snippet #1 at your plugin's meta key.** At the top of the file,
   set `ACPS_HIDE_META_KEY` and `ACPS_HIDE_META_VALUE` to whatever your
   hide-plugin actually writes. If it stores the flag somewhere other than
   post meta, leave those alone and hook the `acps_search_hidden_map` filter
   instead.
2. **Point snippet #2 at your theme's markup.** Load a search results page,
   right-click a result, Inspect. Put the real container and item selectors
   at the front of `containerSelectors` and `itemSelectors`.
3. **Install snippet #4** at priority 11 so it loads after #1, then add your
   rules under Settings → Search Filters.
4. **Turn on `debug: true`** in snippet #2 and watch the browser console —
   it logs which container and items it matched and why each result was
   filtered. Set `hideMode: 'dim'` while tuning so you can see what's being
   caught, then switch back to `'remove'`. (Both are toggles on the settings
   page too.)

## Testing a rule before it goes live

`wpcode/tests/rules.test.js` runs the real snippet against a fake results page
in jsdom:

```
npm install jsdom
node wpcode/tests/rules.test.js      # 37 cases: every operator and action
node wpcode/tests/searchwp.test.js   # 23 cases: against the real markup
```

`searchwp.test.js` builds a fixture from actual result rows off this site —
highlight markup, absolute hrefs, PDFs and images included — so a rule can be
proven before it goes near the live page. Add a case when you add a rule
you're unsure about.

## Worth knowing

Front-end filtering is cosmetic, not a security boundary. A filtered result is
gone from the rendered page, but the title and excerpt still travel to the
browser in the initial HTML, and they're still reachable through the REST API,
feeds, sitemaps and any AJAX search endpoint. For anything genuinely
confidential, keep it out of the query server-side. This approach is the right
tool for tidying up noisy results — not for protecting private content.

Snippet #1 caches its lookup for 10 minutes and busts the cache on any post or
meta save, so editors see their changes immediately. It caps out at 500 hidden
posts; raise `posts_per_page` if you have more.
