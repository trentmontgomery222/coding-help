# Facebook Feed → Carousel (Smash Balloon free + WPCode)

Turns the free Smash Balloon "Facebook Feed" grid into a swipeable
carousel — arrows, dots, autoplay, responsive items-per-view — without
needing Smash Balloon Pro. Built on [Splide.js](https://splidejs.com/)
(no jQuery dependency), driven entirely from WPCode.

## Why this works

Smash Balloon's Pro "Carousel" layout is really just their own JS/CSS
sitting on top of the same feed markup the free version already outputs
(`.cff-list-wrapper` > repeated `.cff-item-wrap`). This snippet does the
same thing client-side: it waits for the feed to finish its AJAX load,
then wraps the existing post nodes (without cloning or removing them —
any lightbox/click handlers Smash Balloon attached keep working) in the
structure Splide needs, and mounts the slider. It's plain CSS/JS
customization of the plugin's own public output, the same category of
thing Smash Balloon's own docs walk through for custom CSS.

## Setup

1. **Fix the feed's post count so it's not paginated.** In the Facebook
   Feed settings, set "Number of Posts" to a fixed count (e.g. 9–12) and
   turn off the "Load More" button. All slides need to exist in the DOM
   before the carousel initializes — a Load More button would add more
   items after Splide has already mounted.

2. **Wrap the shortcode in a container with a known ID.** In Beaver
   Builder, add an **HTML** module (not the Shortcode module, so you can
   control the wrapper) where the feed should go:

   ```html
   <div id="fb-feed-carousel">
     [custom-facebook-feed]
   </div>
   ```

   Adjust the shortcode to whatever your feed's actual shortcode is
   (check Smash Balloon → Facebook Feed → your feed's shortcode, it may
   include a feed ID like `[custom-facebook-feed feed=1]`).

3. **Add snippet 1 — CSS.** In WPCode → Add Snippet → New Snippet,
   paste in `carousel-theme.css`. Type: **CSS Snippet**. Insert Location:
   Site Wide Header is fine — it's fully scoped under `#fb-feed-carousel`
   so it's inert on every other page.

4. **Add snippet 2 — JS loader.** New Snippet, paste in
   `carousel-loader.js`. Type: **JavaScript Snippet** (not HTML Snippet —
   WPCode's JS editor lints out raw `<script>`/`<link>` tags, which is
   what throws the red "not allowed" marks; this version loads Splide via
   `document.createElement` instead, so it's pure JS with nothing to
   flag). Insert Location: **Footer**. Go to the **Insertion** tab and
   restrict it to just the page(s) that actually have the feed (Page
   Specific / Smart Conditional Logic) — no reason to load Splide
   site-wide.

5. **Activate both snippets** and load the page.

## Tuning

- `perPage` / the `breakpoints` block in `carousel-loader.js` controls
  how many cards show at each screen width — match whatever your Beaver
  Builder Post Carousel normally uses.
- `autoplay`, `interval`, `pauseOnHover`, `type: 'loop'` are all
  [Splide options](https://splidejs.com/guides/options/) — anything in
  that guide can be added to the config object.
- Arrow/dot colors live in `carousel-theme.css` (`.splide__arrow`,
  `.splide__pagination__page.is-active`).

## If it doesn't pick up the feed

Smash Balloon's markup has been stable across recent versions
(`.cff-list-wrapper` / `.cff-item-wrap`), but if your installed version
differs: open the page, right-click a feed post → **Inspect**, and check
what class wraps the repeating items and what class each individual post
uses. Update `list.querySelector('.cff-list-wrapper')` and
`':scope > .cff-item-wrap'` in `carousel-loader.js` to match.
