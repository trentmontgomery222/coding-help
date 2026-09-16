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

## "It says no searches yet"

Searches are recorded from the moment the plugin is active — you do **not**
need the shortcode in place for the dashboard to fill up. That was the
original design and it was backwards: the numbers are how you decide whether
to add the shortcode, so they have to come first.

If the table is still empty, the **Status** panel at the top of the dashboard
says why. It checks, in order: whether the tables were created, whether
anything is being recorded, whether the shortcode is in use, what path and
parameters count as a search, whether SearchWP was detected, whether cron is
running, whether a persistent object cache exists, and whether the hide-plugin
meta key matches anything.

The usual cause is the **results page path or query parameter** not matching
where you actually land. Both are on the Settings screen and both are shown in
the Status panel.

Two notes on what gets recorded:

- **Crawlers are skipped** by user-agent, so the popularity numbers reflect
  people. It's a cheap check and not airtight.
- **A watched search has no result count.** The observer sees the term, not
  what SearchWP found, so those rows show "—" rather than 0 — a zero there
  would wrongly appear in "searches that found nothing".

## Setup

1. Activate the plugin (creates two tables).
2. Leave it recording for a few days. Check the dashboard: do the top terms
   repeat? If they do, caching will help; the dashboard says so directly.
3. Only then replace the SearchWP results module with `[wpsqr_results]`.
4. **Set your hide-plugin's meta key** under Quick Results → Settings. Until
   this matches, the hidden-content filtering can't work — the Status panel
   will tell you if it matches nothing.

## People results

When someone searches a name, the useful answer is that person — not the
directory page they appear on. `[wpsqr_people]` shows matching staff above the
ordinary results.

This plugin owns none of that data. It defines a contract; whatever staff
directory plugin is installed implements one filter and decides who is visible
and who matches. That split is deliberate — visibility rules belong with the
data, and a search plugin guessing at them is how someone who asked to be
unlisted ends up listed.

**[INTEGRATION.md](INTEGRATION.md) is the brief for whoever writes that side.**
Hand it over as-is; it's written to be implemented against without needing this
conversation.

`[wpsqr_people]` is a separate shortcode from `[wpsqr_results]`, so it can go
on the existing SearchWP results page today without waiting on the caching
half. `[wpsqr_results]` includes it automatically.

To see it working before the real integration exists, activate
`examples/example-people-provider.php` as its own plugin — six invented staff,
one of them hidden, so visibility can be tested as well as matching.

### The directory page itself

Separate from people results, and the more important half.

The directory page's indexed text contains everyone in it, hidden people
included — hiding controls what the page renders, not what was indexed. So
searching a hidden person's name returns the directory page, and its
appearance confirms that person exists. That is most of what hiding was
supposed to prevent.

**The directory page has to be identified before any of this happens.** It's
found from, in order: the path you put in **Settings → The staff directory
page**, a `wpsqr_directory_pages` filter from the directory plugin, or the
"search the full directory" link if one is configured. Identified by none of
those, it stays an ordinary result — keeps its excerpt, appears for hidden
names — and both the admin notice and `?acpsdebug=1` say so rather than
leaving you to wonder.

Once identified:

- **Its description is always replaced** with a preset. The automatic one is a
  run of employee names and admin interface labels.
- **It is hidden when the search names a hidden person** (the default), so
  that person cannot be confirmed to exist.

That second rule needs the directory plugin to answer one question:
*does this term match somebody hidden?* Because these two searches both return
no visible people and need opposite treatment:

| Search | What should happen |
|---|---|
| `aust`, a hidden employee | hide the page — its appearance confirms them |
| `staff directory` | show it — it is exactly what was wanted |

Without that signal the page is **not** hidden, and the Status panel says so.
Hiding it from every topical search — which is what "nobody visible matched"
amounts to — would make the directory page essentially unreachable from
search, which is worse than the risk it prevents. The description replacement
happens either way, so no names leak through the excerpt regardless.

A `strict` mode is available for hiding on any no-visible-match, if you'd
rather lose the page from topical searches than wait for the signal.

Directory rules are applied before any of your own and can't be overridden by
a `keep`. A `hide` there isn't a preference.

Contact details are **off by default**. A directory page publishing an email
is a decision about that page; repeating it across search results, for anyone
who types a common surname, is a different one.

## Rules

Everything the WPCode snippets could do, now built on **Quick Results →
Settings** instead of edited in code. Rules are stored in the same shape the
browser engine uses, so there is no translation layer to drift out of sync.

**Result rules** — run top to bottom against each result. `Hide` and `Keep`
are final, so an early Keep protects a result from every rule below it.

| | |
|---|---|
| When the | Title, URL path, Post ID, Post type, Excerpt, Anything in the row |
| Test | contains, is exactly, starts with, ends with, matches pattern, is any of |
| Then | Hide, Keep, Grey out, Find and replace text, Replace the description, Add a badge, Move to top, Move to bottom |

**Find and replace** takes its own *Find* box, separate from what the rule
matched on. Leave it empty and it replaces the text the rule matched — which
is what you usually want. Fill it in when you need to match one thing and
replace another: *when the URL is the directory page, replace "Directory" with
"Lookup"*.

### Combining conditions

A rule starts with one test and takes as many more as you need, via
**+ and/or…**. Every `and` must match; if you add any `or` conditions, at
least one of those must match too. `not` inverts any single test.

One of the fields a condition can test is **the search** — what the visitor
actually typed — so a rule can depend on the search as well as the result:

```
When the description contains "Edited Hidden"
  and the search contains "staff"
  → Replace the description with "Directory results for {query}."
```

That's the staff directory case: its indexed excerpt leaks admin interface
text, but only worth rewriting when someone is actually searching the
directory.

### Variables

`{query}` `{title}` `{desc}` `{url}` `{type}` `{id}` work in rule values *and*
in output text — descriptions, badges, messages, redirect URLs.

In a **value** they turn a rule into a comparison between the result and the
search:

```
When the title contains {query} → Move to the top
```

In **output text** they quote things back:

```
→ Replace the description with "Everything about {query} at {title}"
```

An unknown placeholder is left visible rather than blanked, so a typo looks
like a typo instead of quietly producing empty text.

**Search term rules** — act on what the visitor typed, before any result is
looked at. First match wins. These take conditions too.

| Then | |
|---|---|
| Return no results | with an optional message |
| Show a notice | above the results, leaving them alone |
| Send them to a page | for a term with an obvious destination |
| Allow | shields a term from a broader rule below it |

### Descriptions

The text under each result is usually the page's own content trimmed down,
which often reads badly out of context — a staff directory whose excerpt is a
run of employee names being the obvious case here. Three ways to fix that, in
order of how much they cost you:

**A Search description on the page itself.** Every post and page gets a
*Search description* box in the sidebar, next to the visibility checkbox. Write
one and it replaces the automatic summary wherever that page appears. The
placeholder shows what the automatic summary would be, so you can see whether
it's worth overriding. This is the one to reach for when a specific page reads
poorly.

**Replace the description by rule**, when a pattern covers several pages:

```
When the URL contains /staff/directory/ → Replace the description
```

**Find and replace text**, which now takes a target — the title, the
description, or both. Matching and replacement are both case-insensitive; a
rule that matched a row on "hub" and then failed to replace "Hub" would just
look broken.

Rules see the description that will actually be displayed, including a custom
one, so a rewrite can edit text a Search description put there.

**Description length** for auto-generated summaries is on the settings screen.
If you already store descriptions elsewhere — an SEO plugin's meta key — point
*Search description field* at that key and it'll use those instead.

### Where each rule runs

`Hide` and `Keep` are applied on the **server** — those results never reach
the browser at all, which is what makes hiding a real boundary rather than a
cosmetic one — as long as *every* condition on the rule tests Title, URL, Post
ID, Post type or the search.

Add a condition on `Excerpt` or `Anything in the row` and the whole rule moves
to the browser, because the excerpt the server would build isn't necessarily
the one the template renders. Half-applying a rule on an incomplete test would
be worse than not applying it there at all.

Everything else is presentation and runs in the browser: greying out,
rewrites, badges and reordering.

### In the editor

Ported from the snippets: a **Hide from search results** checkbox on every
post and page, a row action and bulk actions in the posts list, and a
**Search** column showing what's hidden at a glance. All write the same meta
key your hide-plugin uses, so the two never disagree.

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
php tests/normalizer-test.php        # 30 cases — cache keys
node tests/browser-rules.test.js     # 98 cases — the browser rule engine
php tests/people-test.php            # 32 cases — person-row sanitizing
node tests/admin-builder.test.js     # 38 cases — the settings rule builder
```

See `tests/README.md`. The normalizer decides the hit rate and the rule engine
decides what people see, so both are tested; the WordPress-dependent parts
would need a full test harness to exercise meaningfully.

## Honest limits

This caches the *results* of a slow search. If SearchWP itself is slow because
the index is stale, the engine is misconfigured, or the database needs
attention, that's still true — searches just hit it less often. Worth checking
the average uncached time on the dashboard: if it's several seconds, the
underlying problem is worth fixing too, because every cache miss and every
warm-up run still pays it.
