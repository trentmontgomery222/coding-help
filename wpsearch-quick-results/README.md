# WPSearch Quick Results

A WordPress plugin that serves popular searches from a cache instead of
re-running the search engine, and filters what appears in the results.

It replaces the six WPCode snippets in `../wpcode/` — same behaviour, but
versioned, testable, and in one place that can't be half-deactivated.

## Why this is faster

SearchWP scores every indexed row against the search term on every request.
That work is identical each time someone searches "staff". So the first search
for a term stores the ordered result IDs; every search after that is a
primary-key lookup plus one `post__in` query, with no scoring at all. With a
persistent object cache installed, a warm search touches the database zero
times.

**This does not make an uncached search faster.** If every search on the site
were unique, this plugin would add a write and nothing else. It works because
search traffic is top-heavy — and the dashboard measures whether that's true
here rather than assuming it. If the ten most common terms account for a small
share of searches, the dashboard says so in as many words.

## What's in it

| Piece | Job |
|---|---|
| `WPSQR_Normalizer` | Folds "Staff", "staff ", "the staff" and "Staffs" into one cache entry |
| `WPSQR_Cache` | Stores ordered result IDs, keyed by term + page + viewer |
| `WPSQR_Stats` | Records what people search, how often, and what found nothing |
| `WPSQR_Engine` | Cache lookup, then SearchWP, then core search as a fallback |
| `WPSQR_Rules` | Removes hidden and rule-blocked results **server-side** |
| `WPSQR_Renderer` | SearchWP-compatible markup, so existing CSS and JS still apply |
| `WPSQR_Warmer` | Cron job re-running the top terms after each flush |
| `WPSQR_SearchWP` | The `/?s=` redirect, direct media links, attachment sinking |

## Setup

1. Activate the plugin (creates two tables).
2. Replace the SearchWP results module with `[wpsqr_results]`.
3. **Set your hide-plugin's meta key** under Quick Results → Settings. Until
   this matches, the hidden-content filtering can't work.

## Three things worth knowing

**Filtering moved to the server.** Hidden results are removed before the page
is built, so they no longer reach the browser at all. The JavaScript layer is
still there for what only it can do — title rewrites, reordering, reacting to
live search — but it's no longer the only thing between a visitor and hidden
content. This is a real security improvement over the snippet version.

**The cache is flushed on every post save.** Coarse on purpose: working out
which cached searches a given edit affects means re-running those searches,
which costs exactly what the cache exists to avoid. Flushing and letting the
warmer refill is cheaper and can never serve a stale result.

**Warming needs WP-Cron.** If cron is disabled, the first visitor after each
save pays full price. Everything else still works.

## Tests

```
php tests/normalizer-test.php    # 30 cases, no WordPress needed
```

The normalizer decides the hit rate, so it's tested on its own. The rest needs
a WordPress test harness to exercise meaningfully.

## Honest limits

This caches the *results* of a slow search. If SearchWP itself is slow because
the index is stale, the engine is misconfigured, or the database needs
attention, that's still true — searches just hit it less often. Worth checking
the average uncached time on the dashboard: if it's several seconds, the
underlying problem is worth fixing too, because every cache miss and every
warm-up run still pays it.
