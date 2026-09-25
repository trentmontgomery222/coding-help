# Crash-safety tests

Plain PHP scripts, no test framework and no WordPress install needed. They stub
the handful of WordPress functions the plugin touches and assert that the plugin
cannot take a site down.

These live outside `acps-alert-popups/` on purpose, so they are not part of the
plugin zip.

```bash
php tests/failsafe-test.php
php tests/idempotency-test.php
php tests/post-type-test.php
php tests/status-test.php
php tests/render-test.php
php tests/panel-test.php
php tests/help-test.php
php tests/popup-module-test.php
php tests/popup-source-test.php
php tests/shortcode-test.php
php tests/updater-test.php
php tests/safe-mode-test.php
php tests/resume-test.php
php tests/settings-test.php
php tests/wiring-test.php
node tests/admin-fields-test.js
node tests/frequency-test.js
node tests/plugins-screen-test.js   # real browser (Playwright + Chromium)
node tests/tour-test.js             # real browser (Playwright + Chromium)
for s in healthy admin-healthy missing-file missing-help broken-file safe-mode safe-mode-console safe-mode-new-version kill-switch; do php tests/boot-test.php "$s"; done
```

All exit non-zero on failure, so they work as a pre-release check.

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
| `admin-healthy` | boots as an admin request, and the help layer loads too |
| `missing-file` | a required file is deleted mid-flight: stays dormant, no fatal, no false safe-mode, and one email naming the file |
| `missing-help` | the optional help files are deleted: the plugin still loads fully, only the tutorials go |
| `broken-file` | a required file no longer parses: caught, safe mode armed (recording the version), one email |
| `safe-mode` | a previous fatal was recorded: stays dormant, loads none of the paused code, sends no second email |
| `safe-mode-console` | paused, but a request on the console's own URL still gets the console (and nothing of the front end) |
| `safe-mode-new-version` | paused on an older version: the newer code on disk lifts the pause and boots |
| `kill-switch` | `ACPS_ALERTS_DISABLE` is set in wp-config: never boots |

Each new behaviour above is mutation-checked: removing the version lift, the
safe-mode console, the console's URL gate, the parse-error arming or the
missing-files email each turns its scenario red.

`admin-healthy` is the control for `missing-help`. The help layer only loads on
admin requests, so without it `missing-help` would pass for the wrong reason.

`safe-mode-test.php` — the silent safe mode. It boots the real plugin, then:

- arming safe mode emails the operator **exactly once** per episode; a second
  arm on a following request while already dormant sends nothing
- the email carries the site URL, the wp-admin login link, the remote console
  URL (`acpsupdater=<key>`), the one-click recovery URL (`acps_alerts_resume=`)
  and the caught error
- no `admin_notices` safe-mode banner is hooked and the old on-screen notice
  function is gone, so nothing on any screen announces the failure
- `ACPS_Alerts_Admin::default_css()` returns the real plugin CSS (both source
  stylesheets) and survives the save-time sanitizer unchanged
- missing files email once per distinct set of files, and a different breakage
  is reported afresh
- a static scan of every string the plugin can print finds no failure, pause or
  missing-file notice outside the unlisted console and the operator email
- `ACPS_Alerts_Admin::is_own_screen()` recognises exactly the plugin's own
  screens, and nothing else

The email-once guard is mutation-checked: dropping it makes the second-arm case
fail.

`settings-test.php` — the settings class itself: **saving the hidden
maintenance screen keeps every ordinary setting.** It used to reset the Main
CSS, the cut-off, the rendering mode and the rest to their defaults, and turn
previews off. The reverse holds too: an ordinary save keeps the maintenance
settings. Mutation-checked.

`plugins-screen-test.js` — the warning before this plugin is deleted, in real
Chromium, against a stand-in Plugins screen with a stand-in for WordPress's own
delete handler: nothing shows until a delete of *this* plugin is asked for (row
link, top or bottom bulk button); the warning — its real wording, read out of
the PHP that sends it — says it is not advised, that features will stop working,
and that issues can be handled by switching the alert off or in Settings, with
Settings as the first choice; Cancel and Escape delete nothing; "Delete anyway" hands
over to WordPress exactly once; other plugins are never touched. It caught a
real bug on its first run: the dialog's `display:flex` overrode `hidden`, so a
cancelled warning left an invisible overlay blocking every click on the screen.
Mutation-checked (a bubble-phase listener lets the delete through first).

`tour-test.js` — the guided-tour engine in real Chromium across real page loads:
the button on a step that lives elsewhere really navigates there, carrying the
tour and step; the tour resumes on arrival; a step whose element is missing on
the right screen is explained in place instead of reloading the page for ever;
Back walks back across screens; and the well-done note is only ever added to
the plugin's own screens. Each of the three engine fixes is mutation-checked.

`resume-test.php` — the always-works ways out of safe mode:
`?acps_alerts_resume=<key>` (lift the pause) and `?acps_alerts_reinstall=<key>`
(pull fresh files from the source, then lift the pause). Each case runs in its
own process, because the handlers end the request with `exit()`. For resume it
checks that the right key clears the pause and ends the request, the
update-secret fallback works when no console key is set, a wrong key / missing
key / missing param clears nothing and lets the page load normally, it is
idempotent when not paused, and — the point of the whole thing — it is reached
through `acps_alerts_boot()` before any other file loads, so it works when the
console cannot. For reinstall it checks the same secret gate, that the handler
actually runs `reinstall_now()` (which, with no source configured in the test,
reports it cannot reach the source before touching the WordPress upgrader) and
then lifts the pause, a wrong key does nothing, and it too is reached through
boot. Verified non-vacuous: dropping the secret fallback, accepting any key
(resume or reinstall), not clearing the pause, and not calling either handler at
boot each turn a case red.

The reinstall's version-independent restore is pinned in `updater-test.php`:
`force_reinstall_entry()` injects an update entry for the SAME version (an
ordinary update refuses to), carrying the source package and the plugin
basename the upgrader keys on, tolerating a not-yet-built transient; and
`reinstall_now()` bails with "could not reach the source" before touching the
upgrader when no source is configured. Verified non-vacuous: gate the entry on a
newer version and it fails; skip the no-source bail and it fatals reaching for
the upgrader.

`idempotency-test.php` — pins the "everything is twice everywhere" bug.
WordPress de-duplicates hook callbacks by a unique id, which is stable for
`[$obj, 'method']` but per-object for a closure. Wrapping every hook in a
closure quietly removed that protection, so anything that ran the wiring twice
duplicated every menu, notice and fragment. These checks assert:

- wiring the same hook + context + priority twice registers it once, and the
  callback runs once
- the same holds for filters
- two genuinely different callbacks on one hook both still register
- the same context on a different hook, or at a different priority, is not
  swallowed
- a de-duplicated wrapper still catches a throw
- an admin screen registered twice (top-level plus a same-slug submenu, the
  usual way to rename the first submenu item) yields the SAME renderer object,
  lands on one hook, and is drawn once
- the plugin file bails if loaded a second time, `boot()` runs once per request,
  and the container wires once

Verified non-vacuous: with the de-duplication removed it fails with
"registers it once: expected 1, got 2".

`post-type-test.php` — pins the "there's no way to save it" bug. Alerts used to
live on whatever post type Beaver Builder registered for popups, so whether the
Add New screen had a title field and a Publish button was out of the plugin's
hands — and with Beaver Themer layouts it had neither. These checks assert:

- the alert post type is registered with an admin UI, and supports title,
  editor and revisions — the things that make a screen savable
- it is publicly queryable with a rewrite slug, because Beaver Builder edits a
  layout on a front-end URL, but stays out of search, archives and nav menus
- the post type is handed to Beaver Builder via its filter, preserving whatever
  other types were already enabled
- "Add New" points at the plugin's own type — even when a Beaver Builder popup
  type also exists, and even when an admin has overridden the source type
- popups that already exist on a Beaver Builder type are still listed, and
  `is_popup()` accepts each source while rejecting ordinary pages and non-popup
  Themer layouts
- the plugin reports itself ready with Beaver Builder switched off

`status-test.php` — the status board. The daily cut-off is the part worth
pinning:

- the cut-off time is validated, and anything malformed falls back to 17:50
- the deadline lands at the cut-off on the same day for a morning post, rolls to
  the following day for an evening one, and rolls exactly one day just before
  midnight
- posting exactly at the cut-off runs until tomorrow, so an update is never
  archived the instant it is posted
- the cut-off is 17:50 in **site** time, not UTC (checked against a UTC-5 site)
- "keep" and "custom" entries never expire on the daily sweep
- an entry past its cut-off stops being current even if cron never fired
- the admin-only view: a visitor and a logged-in non-staff user both fail to see
  a staff-only entry, staff see it, and nobody sees a preview-only entry on the
  board
- every level maps to a real severity and the ranks order correctly
- level colours: a level is drawn in its own colour, an override for that level
  wins, one for a different level is ignored, and an empty or junk override is
  not an override. Bare hex, hashed hex and rgba are all colours; a style
  injection is not, and never reaches the badge.
- every level offered in the picker ships a glyph, the badge comes out in that
  level's colour and carries its class, an unknown level falls back to
  Information rather than drawing nothing, and the size is clamped because it
  lands in a style attribute
- the badge is right with **no stylesheet at all**: it sizes itself, rounds
  itself and lays its glyph out from inline styles, the `<svg>` carries real
  width and height attributes, and the glyph is smaller than the disc it sits
  in. This pins "the icon is super big" — an SVG with no dimensions falls back
  to 300x150 and a span with no border-radius is a rectangle, which is exactly
  what a giant coloured block on the page looks like. The badge prints on pages
  that carry neither the board stylesheet nor a freshly rebuilt module
  stylesheet, so nothing about its geometry may depend on CSS.

  Verified non-vacuous: strip the inline geometry and the SVG dimensions and it
  fails with "the svg carries a real width attribute" and five more.
- no shipped glyph contains a quote or angle bracket, since the path data goes
  straight into an attribute

`render-test.php` — pins the "it's duplicating everything" bug. Beaver Builder
hooks its layout renderer onto `the_content`, so asking it to render a post that
also has editor content returned both — the whole popup appeared twice, once
builder-styled and once theme-styled. These checks assert:

- a post with a builder layout renders that layout exactly once, and its editor
  content not at all
- a post without a layout renders its editor content once, and no layout is
  invented
- a post with the builder switched off falls back to the editor even when stale
  layout data is still in the database
- an enabled-but-empty layout falls back rather than blanking the popup
- something hooked on `the_content` that reaches back into the renderer cannot
  double the body (re-entry guard)
- the same alert queued twice prints once, and a second `wp_footer` pass prints
  nothing more
- the popup's own furniture: the badge is drawn, the heading is the alert's
  **title** (not the words "School Status"), the link falls back to the status
  page when no destination is given, and the level word is off unless asked for
- a popup with a Beaver Builder layout gets none of that furniture, so a
  designed popup never ends up with two headings
- an alert whose body was designed in the builder is marked `acps-alert--built`
  and loses our panel's inline width, so the popup's own box is the only box;
  the overlay and close button stay, because a popup lifted off its own page no
  longer opens or closes itself. A plain alert keeps the panel, its width and
  our heading.

  Verified non-vacuous: stop marking it and the render test fails with "the
  shell says its body was built elsewhere".

Verified non-vacuous: run against the pre-fix renderer it fails with exactly the
reported symptom.

`admin-fields-test.js` — pins the false "every way of closing this alert is
switched off" warning. Each checkbox is preceded by a hidden input of the same
name so an unticked box still posts a 0. That is right on save (PHP takes the
last value for a repeated name) but a trap in the browser: `querySelector`
returns the FIRST match in document order, the hidden input, so a ticked box
read as "0" forever. The warning fired with everything switched on, and the live
preview always drew with no overlay and no close button. These checks assert:

- the form really does emit a hidden input before each checkbox, so the test is
  pinned to the actual markup rather than a memory of it
- a ticked box reads as on, an unticked one as off, and a mix reads correctly
- the accessibility warning fires only when all three closing routes really are
  off
- selects and number fields are unaffected, and a missing field reads as empty

The DOM is a small stand-in, but the behaviour under test is modelled
faithfully: `querySelector` returns the first element matching, in document
order. Verified non-vacuous: against the pre-fix reader it fails with "no
warning when all three are on: expected false, got true".

`panel-test.php` — the unlisted maintenance console. Its address gate is the
front door, so the edge cases matter:

- exact, prefix (`192.168.`), wildcard (`192.168.*`) and CIDR rules, v4 and v6,
  with malformed rules refused rather than matched loosely
- the shipped default lets in `167.102.110.1` and nothing else
- deny mode blocks the listed addresses and passes everyone else
- an empty allow list fails **closed**; an empty deny list fails open
- rate limiting refuses requests past the cap, per address
- the console can change every operational setting, including the daily cut-off
- it **cannot** change anything guarding itself — password, address rules, proxy
  switch, rate limit, lockout, edit throttle, the secret, or its own on/off. A
  console that can raise its own rate limit and unlock its own address list is
  not gated at all, so those stay wp-admin only
- bad values fall back to defaults rather than being stored

`help-test.php` — the teaching layer:

- every checklist item carries the keys the view reads, and `done` is a real
  boolean
- the checklist reacts to actual site state (creating a popup ticks items off)
- every tour step has a title and body, is reachable, and uses a placement the
  engine understands
- every step anchored to `[data-acps-section="…"]` points at a section the
  settings form really renders — so a renamed section breaks the test rather
  than silently breaking the tour
- the complete tour visits every screen (alerts list, Post an Alert, Wording,
  Archive, the Pages list, Help, Settings, an alert's settings), and each
  feature has its own tour
- **any** step that moves to a different screen from the step before carries
  the url to get there, or the tour would dead-end on the old screen
- every `#acps-…` id and `.acps-…` class a step points at exists in the
  plugin's markup
- no tour names the update system or the console

Each of those four is mutation-checked.

`popup-module-test.php` — the Current Alert module, which is the one place the
alert is edited. The module holds every setting the alert has, and saves the
whole set at once, so the risks are the ones a complete save creates:

- every setting the alert has is offered as a field, so nobody is ever sent to
  wp-admin to change one — and `severity` and `priority` are gone and must not
  come back
- the heading becomes the alert's title and the text becomes its body and its
  board summary
- editing it three times in a row modifies the one alert and creates nothing
- an empty heading means "leave the wording alone", not "blank it"
- switching it on records when it went up and clears any earlier archived flag;
  editing an alert that is already up does **not** restart its cut-off clock,
  and the complete save carries the old timestamp through rather than resetting
  it to the schema default
- start and end dates apply on the custom schedule and are ignored on the others
- the link text, the link itself, the badge toggle and the level-word toggle all
  reach the alert, and switching a toggle off really clears it
- only the X dismisses the popup. It keeps coming back until the visitor
  physically clicks the close button; Escape and a background click close it
  for the moment but record nothing, and being shown it records nothing. The
  test captures the real document event listeners the script registers and
  fires an X click, an Escape key and an overlay click, checking which one
  actually wrote a dismissal — calling `close()` directly would not prove the
  bindings pass the right flag. It also boots two page loads and checks the
  popup returns until the X is pressed.

  Verified non-vacuous: make the Escape or overlay binding record a dismissal
  and it fails ("pressing Escape records nothing" / "clicking the background
  records nothing"); record on `open()` and it fails ten ways, including
  "opening the alert records nothing on its own".
- a background and its text colour are one decision, and the card is one
  surface. The card states both, neither inherited; the head and the message are
  spacing only and carry no colour of their own; and there is one pair of
  pickers, so there is no second colour to disagree with the first. This pins
  invisible text twice over: while the card inherited, a site that had set a
  light colour for the old solid banner got white text on a white card; then the
  card had a coloured head and a white body, each with its own pair, which is
  two chances to get it wrong. Either way you had to select the text with the
  mouse to prove it was there.

  Verified non-vacuous: put `color: inherit` back on the card and it fails with
  "and the text colour that goes on it" plus "neither inherited"; give the head
  a background of its own and it fails with "the head sets no background of its
  own".
- the banner draws the heading and the message and nothing else: no badge, no
  level word, no directive, and no setting left offering to put one back. Read
  from the template itself, because this is a rule about what reaches the page.

  Verified non-vacuous: put a badge and a level word back and it fails with "the
  banner draws no badge".
- the board's own colour for a level: with nothing picked the level keeps its
  own, a colour picked for that level on this board wins, one picked for a
  different level is ignored, an empty picker is not a choice, and the resting
  state has no level colour. Every level in the picker gets a field, or one of
  them could not be recoloured.

  Verified non-vacuous: ignore the overrides and it fails with "a colour picked
  for that level on this board wins".
- the status board's two banner treatments: card is the default and an
  unrecognised value falls back to it, the card takes the level colour as a top
  stripe while the solid one floods its background, and the resting state
  invents no colour at all
- the card never asks for the solid treatment's classes, and the module
  stylesheet hangs the chosen text colour off `--solid` only. This pins a blank
  banner: the two colour pickers describe the solid banner, so a card picking
  up the text colour would paint white text onto a white card.

  Verified non-vacuous: widen that rule back to `.acps-board__banner` and it
  fails with "the module stylesheet only colours text on the solid banner".
- a user without the capability saves nothing

Verified non-vacuous: drop `posted_at` from the saved set and it fails with
"carries the old timestamp through: expected 1000, got NULL" — which is the
alert silently losing its cut-off.

`wiring-test.php` — every method the plugin calls on itself must exist. This
exists because four admin action handlers were referenced by `handle_actions()`
and never written: the on/off switch, both archive links, the per-alert settings
form and the settings form all dispatched into nothing. PHP only complains when
the line is reached, the failsafe swallows the resulting error, and the button
just appears to do nothing. The test reads every plugin file with the tokenizer
and compares each `$this->x()` and `self::x()` against what is declared, so the
whole plugin is checked at once without booting WordPress.

Verified non-vacuous: delete `handle_toggle()` and it fails with
"ACPS_Alerts_Admin calls handle_toggle(), which nothing declares".

It also scans every plugin file for a raw `add_action()`, `add_filter()` or
`add_shortcode()` that bypasses the failsafe (only the boot file, which runs
before the failsafe loads, is exempt). The updater's and the console's public
request handlers were once registered raw, so a throw in them escaped as a
fatal. Verified non-vacuous: put the console's `init` hook back to a plain
`add_action()` and it fails naming that file and line.

And every callback on a notice hook (`admin_notices` and its relatives) must
check it is on one of the plugin's own screens, so no plugin message can appear
at the top of any other page. Removing the check from either notice fails it.

`frequency-test.js` — how often a visitor is shown the same alert, with
`mayShow()` loaded out of the real script rather than restated. The rule needing
the most pinning is "Once, until I change this alert": shown once, then nobody
is bothered again until the alert is edited, at which point everybody sees it.
That rests on two things, neither obvious from one file:

- the version stamp has to move on a **settings-only** edit. WordPress stamps a
  post when its content changes, but most of an alert is post meta, so
  `post_modified` alone would sit still while the level, the targeting and the
  schedule all changed
- "Once, then never again" has to be settled **before** the version is looked
  at, or the two options do the same thing and plain "once" quietly stops
  meaning once

Also covered: a first visit always shows, a new browser session does not reset
"until I change it", a record stored before versioning counts as stale rather
than as a match, session/days/always still behave, and a preview ignores all of
it.

Verified non-vacuous twice. Move the version gate above the "once" branch and it
fails with "once: still never, even after the alert is rewritten". Drop the
revision counter from the version stamp in `get_js_config()` and
`render-test.php` fails with "a settings-only edit changes the version" — the
PHP half of the same rule.

`popup-source-test.php` — taking the alert from Beaver Builder's own Popup
module. The plugin does not draw the popup: the Popup module goes on the status
page, and the plugin finds that node and shows it everywhere else. The Beaver
Builder calls are another plugin's internals and are guarded at every call site,
so they are not exercised; everything around them is ours and is:

- the popup module is found in the layout and the status board is not mistaken
  for it, under each slug Beaver Builder has used, with an unknown module left
  alone rather than guessed at and the filter able to teach it one
- the detected node is cached with the page it came from, and is re-detected
  when the popup is deleted from the layout or the board moves to another page —
  a stale id would have the front end asking Beaver Builder to render nothing on
  every page of the site
- one node is lifted back out of a rendered layout by its `fl-node-<id>`
  wrapper, without the rest of the page, refusing a node id that is not one
- `fl-node-popup1` does not match `fl-node-popup10`, so a page with two popups
  cannot serve the wrong one
- the banner reads the heading and text from inside the popup, never a heading
  elsewhere on the status page, and a layout whose parents form a loop returns
  rather than hanging the request
- the quick "Post an Alert" form writes the heading and text back into those
  same two modules — the inverse of the read above — and nowhere else. What is
  written is what the popup then reads back; a heading module OUTSIDE the popup
  is left alone even when it is listed before the popup's own, so the scope
  check is load-bearing; an empty field leaves that piece standing rather than
  blanking it; the builder's draft copy is kept in step with the published
  layout so a later Save in the builder does not republish the old wording; and
  with no popup on the page nothing is claimed as written.

  Verified non-vacuous: dropping the "inside the popup" check makes the write
  land on the page title, failing "a heading outside the popup, listed first,
  is skipped"
- the popup is hidden on the status page itself and left alone everywhere else
- the popup's own close button is wired to the alert and keeps its class, so it
  keeps its styling and its place at the popup's corner; wiring it twice adds
  the attribute once, a self-closing tag stays self-closing, a single-quoted
  class is matched, and the class name written in prose is not. A popup that
  brought no close button reports so, and `render-test.php` checks the alert
  then supplies one — and only then, so there is never a second thing to click.

  Verified non-vacuous twice: always drawing ours fails with "the alert does not
  add a second one", and dropping the already-wired guard fails with "wiring it
  twice adds the attribute once".
- the popup is styled in complete isolation: **nothing** is loaded globally.
  Beaver Builder scopes rules to `.fl-builder-content` / `.fl-col` / `.fl-row`,
  classes present on every builder page, so any of its stylesheets loaded
  globally restyle the host page's own columns — the reported bug, worst on
  mobile. So both the base layout stylesheet and this page's compiled stylesheet
  are read off disk, every rule confined under `.acps-alert` by `scope_css()`
  (`.fl-col` → `.acps-alert .fl-col`; `@media` recursed into; `@font-face` /
  `@keyframes` left alone; `:root`/`html`/`body` given the scope in their
  place), and printed as one inline block. Comments and braces inside strings do
  not throw the brace matching off. Beaver Builder's own global assets are off by
  default (the filter re-enables them), and even then its unscoped compiled
  stylesheet is dequeued by matching its src. With no compiled file yet the
  scoped base alone still styles the popup; with neither, nothing is injected.

  `scope_css()` covered directly (comma lists and `:not()`, `@media`,
  `@font-face`/`@keyframes`, `@import`, comments, braces in strings,
  `:root`/`body`, empty input). Verified non-vacuous: a no-op scoper, an
  unscoped `@media` inner, a scoped `@keyframes`/`@font-face`, a default filter
  of true, base CSS not folded in, and skipping the opt-in dequeue each turn a
  case red.
- the shell says which of the two cases it is, with `acps-alert--own-close`,
  because the stylesheet has to size the dialog differently for each: spanning
  the page gives the popup's percentage width a basis, but puts a close button
  of *ours* in the corner of the window instead of the corner of the popup.

  Verified non-vacuous: never adding the class fails with "the shell says the
  popup brought its own close button".
- a `[schoolstatus]` shortcode inside the popup is not frozen by the cache. The
  rendered popup is cached, and a shortcode baked into cached markup says
  whatever it said when that markup was stored — so the status folds into the
  cache key: a different level, or an edit to the same alert, is stored under a
  different key, while an unchanged status reuses its own rather than
  re-rendering a whole page layout on every view.

  Verified non-vacuous: drop the status from the key and it fails with "a
  different status is stored under a different key".
- the lifted node is wrapped back in `fl-builder-content` and
  `fl-builder-content-<page id>`, with the post id as a data attribute; markup
  that already carries this page's wrapper is left alone, and one carrying
  another page's is not mistaken for it. This pins the transparent popup and the
  unstyled button: Beaver Builder writes most of a layout's CSS against an
  ancestor, so a node taken out of its page loses every rule that names one —
  silently, with the stylesheet loaded and the node classes still correct. Only
  the rules that need no ancestor survive, which is why the icon kept its purple
  disc and the heading its font while the popup went transparent.

  Verified non-vacuous: drop the wrapper and it fails with "the bare container
  class is restored" and three more.
- a lifted popup stops being a popover. Beaver Builder's popup carries
  `popover="manual"`, and a browser keeps any such element at `display:none`
  until `showPopover()` is called — so inside the alert dialog, where nothing
  calls it, the element sits in the DOM greyed out and the alert appears empty.
  Checked against the markup off the real site, plus every spelling the
  attribute has (quoted, single-quoted, bare and unquoted), every element in a
  fragment rather than just the first, `data-popover` not being mistaken for it,
  and the word "popover" in someone's alert text left alone

  Verified non-vacuous: leave the attribute on and it fails with seven cases,
  including "every popover in the fragment is stripped: expected 0, got 2".
- an alert with nothing in it is not an alert. The popup is a container, so
  rendering the module on its own returns the shell and none of the content —
  and that shell is a non-empty string, which is exactly why a plain "is it
  empty" check let it through and the alert reached the page holding only a
  close button. A shell, and empty rows and columns inside one, count as
  nothing; words count; so do an image and a video, since a popup can
  legitimately be a picture.

  Verified non-vacuous: swap the check back for `'' !== trim( $html )` and it
  fails with "a popup shell with no children does not count as content".

Verified non-vacuous twice: a naive class match fails with "a longer node id is
not matched by a shorter one", and a cache that is never revalidated fails with
"a cached node that has gone is not trusted".

`shortcode-test.php` — `[schoolstatus]`, which puts the current status wherever
it is typed. Two things make it worth pinning: it has to agree with the status
board, because two places reporting different statuses is worse than either
being wrong; and it has to style itself, because it can be typed into a page
that loads none of this plugin's stylesheets — including the popup, which is
rendered onto pages that are not the one it was built on.

- registered under both `schoolstatus` and `school_status`
- the resting state still says something, marked as the resting state; a live
  alert reports its own level and wording, and the live wording wins over the
  normal wording
- `show` picks parts in the order listed, accepting commas or spaces, ignoring
  a part nobody has heard of rather than printing it, and drawing nothing when
  nothing recognisable was asked for
- `when="live"` draws nothing on a normal day
- the wrapper lays itself out inline, in the direction and alignment asked for,
  and an alignment nobody recognises falls back to centre rather than reaching
  the style attribute
- `link="yes"` links to the status page, and does not invent a link when no
  status page is set

Verified non-vacuous twice: trusting the alignment attribute fails with "a junk
alignment falls back to centre", and ignoring the live alert's wording fails
five cases.

The shortcode suite also covers the two questions it can be asked and the colour
override:

- `source="board"` says NORMAL once an event is over while `source="alert"` still
  says HOLD — the popup is that alert, so its badge must not flip to Normal the
  moment the board goes back to resting. While the alert is showing the two
  agree, and an unrecognised source is the board rather than an error.
- `when="live"` asks the board whichever source supplied the wording, so an
  alert that is switched off is not "live".
- `color` overrides the level's colour, understands a bare hex, refuses a style
  injection, and recolours the badge as well as the word so the two cannot
  disagree.

Verified non-vacuous: ignoring `source` fails with "while the alert still says
HOLD", and skipping the colour check fails with "and the standard colour is
kept".
