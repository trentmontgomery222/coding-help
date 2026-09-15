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

Manual IDs are merged with whatever your hide-plugin flags, and the keyword
and display rules ride along in the same `window.ACPS_SEARCH` payload. The JS
merges them over its own defaults, so adding a rule never means editing
snippet #2.

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
