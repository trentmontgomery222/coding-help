# ACPS Alert Popups

A managed site alert system for a single WordPress site, designed in Beaver Builder.

There are exactly **two alerts**, always, and neither can be created or deleted:

- **Normal Alert** — the resting state. What the site says when nothing is happening.
- **Current Alert** — the one you switch on, edit and archive. It is always there; on a quiet day it is simply off.

The split is deliberate:

- **Beaver Builder's own Popup module** on the status page *is* the alert. You build it there like any other popup. The plugin finds that node, hides it on the status page, and renders it — with the status page's generated CSS and JS — on every other page while the alert is on. It does not draw a popup of its own.
- **The Current Alert module** is the switch and the settings: on/off, level, when it comes down, which pages show it, who sees it, how it opens, how often it comes back. Its heading and text boxes are for the status page *banner* only, and if you leave them empty the banner takes the popup's own heading and text.
- **The Status Board module** is the template. It turns that heading and text into the status page banner, in the colour of the status level, and lists past updates underneath.
- **wp-admin** is for checking state and for the Normal Alert. You do not post from there.

The popup never opens on the status page itself — that page shows the banner instead. Everywhere else, the plugin shows the popup while the alert is on.

## Requirements

- WordPress 6.0+, PHP 7.4+ (single site; the plugin is not network-aware by design)
- Beaver Builder is **recommended, not required**. With it you design alerts in the builder; without it you write them in the normal WordPress editor and everything else works the same.

## Installing

1. Copy the `acps-alert-popups` folder into `wp-content/plugins/`.
2. Activate **ACPS Alert Popups** in Plugins.
3. Open **Site Alerts** in the admin menu.

### Where alerts are stored

The plugin registers its own `acps_alert` post type and hands it to Beaver Builder. On activation it creates the two alerts — Normal Alert and Current Alert — and protects them from deletion.

The Current Alert's own admin screen is deliberately just a signpost back to the status page: one alert with two editing surfaces is how the two drift apart. The Normal Alert keeps the full settings form.

It does not rely on Beaver Builder registering a popup post type, because that feature is not in every version and its slug has changed between them. Popups you already built on a Beaver Builder popup type are still detected and listed alongside your alerts: `fl-popup`, `fl_popup`, `fl-builder-popup`, `flbuilder_popup`, and Beaver Themer popup layouts (`fl-theme-layout` with a layout type of `popup`). You can also force a specific source under **Site Alerts → Settings → Popup post type**.

## The status page is the control panel

Your status page carries three things: Beaver Builder's **Popup** module (the alert itself), and two from the Site Alerts group — **Current Alert** (the switch and settings) and **School Status Board** (the banner and archive). From then on that page is where you work:

- **The popup is Beaver Builder's, not the plugin's.** Build it in the Popup module. The plugin's job is to decide when it is shown and to whom, and to put it on every other page.
- **The Current Alert module is the switch and the settings.** Editing it changes the one alert in place — it never creates a second one.
- **Show this alert now**, on its On/off tab, is the switch. On means visitors see it; off means the wording sits there ready for next time.
- **Every setting is on that module**, across its Popup, On/off, Where & who, How it opens and Style tabs. Posting an alert is one screen and under a minute.
- The popup is **hidden on the status page itself**, so somebody who went there to read the status does not get it covered by a box saying the same thing. The banner says it instead.
- The board renders the **current status banner** plus the **archive** of past updates, as an expandable list. The banner has two treatments, set by *Banner style*: **Card** (the default) matches the popup — a white card with the level colour as a top stripe and a badge, heading in ordinary dark text — and **Solid** floods the whole banner with the level colour. The board's two colour pickers describe the solid treatment only. With the Current Alert off, the banner shows the Normal Alert wording.
- It **archives itself and switches itself off at 5:50pm** (configurable) unless you chose "Keep it up until I switch it off". The archive entry is a separate record; the alert's own wording is left intact. An update posted after the cut-off runs until the following day.
- Set **Who can see it → Staff only** to stage an update on the live site where only people who can manage alerts see it. The board shows a dashed "Staff preview" strip so you can't forget.

Archived updates are stored as records in their own list, not as posts, so the archive can grow without the site ever gaining a third alert.

### The quick way: Post an Alert

There is also a one-screen shortcut in wp-admin, for when you just need the words changed fast and do not want to open Beaver Builder. **Site Alerts → Post an Alert** (there is a button on the alerts list too) is a short form: a **Level**, a **Header**, and a **Text** box, and one **Post alert** button.

Submitting it does three things at once: it writes the header and text straight into the popup — the heading and rich-text modules inside the Beaver Builder Popup module on the status page, the very ones the popup already shows — sets the level, and switches the alert on. The popup, the status board and the `[schoolstatus]` shortcode all update together.

It only touches those three things. Everything else about the popup — any extra modules you added, the styling, the layout — is exactly as you built it in Beaver Builder, because the form edits the same popup rather than replacing it. So the two ways of working fit together: post the everyday changes from this form in a few seconds, and open Beaver Builder when you want to change how the popup is built. The boxes come pre-filled with what the popup says right now, so a small change is a small edit, and an empty box leaves that piece alone. Changing the wording of an alert that is already up does not restart its daily cut-off.

### Status levels are SRP

The levels are the five Standard Response Protocol actions from the "I Love U Guys" Foundation — **Hold**, **Secure**, **Shelter**, **Evacuate**, **Lockdown** — each with its directive and its colour, so the site says exactly what the drill says. Two everyday levels sit alongside them: **Normal** and **Information**, which are deliberately *not* marked as response actions.

Urgency runs Lockdown > Evacuate > Shelter > Secure > Hold > Information.

### When a level colour does not read

The SRP colours are chosen to read on white, and the same colour on a dark
banner can be nearly invisible. Each place that draws a level may override it
without changing that level anywhere else:

- **The status board** has a *Level colours on this board* section, one colour
  per level — that colour is the banner's, being the head on the card or the
  whole banner on the solid style. Empty keeps the standard colour.
- **A shortcode** takes `color="#ffffff"` for that one placement.
- **A developer** can filter `acps_alerts_level_color`, which receives the
  colour, the level key and the context (`board`, `shortcode`, `popup`).

### The status page banner

The banner is **the heading and the message, and nothing else** — no badge, no
level word, no directive. The level shows in the banner's own colour. Anywhere
those pieces are wanted, `[schoolstatus]` places them, which is what that
shortcode is for.

On the card treatment the banner is **one surface**: the heading and the message
sit on the same background, in the same colour. That is two settings on the
Status Board — *Banner colour* and *Banner text colour* — and there is no third
to disagree with them.

The pair is stated on the card itself, never inherited, and the head and the
message carry no colour of their own. A background and the text on it are a
single decision and cannot be taken separately, which this got wrong twice:
first the card said `color: inherit` and a site that had set a light colour back
when the banner was a solid block got white text on a white card; then the card
had two surfaces with a pair each, which is two chances to set a colour that
does not read against what it is sitting on. Neither failed loudly — the message
simply was not there, and you had to select it with the mouse to prove it
existed. One pair cannot be got half right.

**The status level is the severity** — there is no second setting. Pick it on the Current Alert module, Popup tab → *Status level*. It decides four things: the word on the banner, the colour of the banner, the colour of the popup's stripe, and the coloured badge drawn above the heading. Each level ships an inline SVG glyph, so the badge cannot 404 and takes the level's colour without a second request. Its size, shape and colour are written into the markup rather than a stylesheet, because the badge prints on pages that may carry neither the board stylesheet nor a freshly rebuilt module stylesheet — an SVG with no dimensions falls back to 300&times;150. Badge size is a setting on both modules (56px on the popup, 64px on the banner). There is no separate severity and no priority — with one Current Alert there is nothing to rank it against.

### The heading is your title

Both the popup and the status page banner use **the title you typed** as the heading. The level appears as the badge above it, and — if you switch *Show the level word* on — as a small label line with its SRP directive. Under the message the popup shows a link, "View updates" by default, pointing at the status page unless you give it another destination; clear the link text to drop it.

A popup you have designed in Beaver Builder gets none of this furniture, because it already has a heading and buttons of its own.

Check the directives against your own district's training materials before going live; a developer can adjust the wording with the `acps_alerts_status_levels` filter. Updates written before the move to SRP keep rendering with their old wording.

### Backfilling the archive

Use the Status Board module's **Add a past event to the archive** section — headline, message, level and a date (`YYYY-MM-DD`) — to write up something that already happened. The boxes empty themselves once filed. Archived entries never pop up and never reach the banner, and filing one never touches either alert.

The popup's look is the Current Alert module's **Style** tab: where the box sits, how wide it is, whether the page dims behind it, and how it can be closed.

## Learning it

You should not need this file. The plugin teaches itself:

- **A guided tour.** Open **Site Alerts** and click **Show me how**. It dims the screen, spotlights one real control at a time and explains it, walking you from an empty list all the way to a live alert — across screens, picking up where it left off. Escape leaves at any point; you can replay it whenever.
- **Site Alerts → Help & Tutorials.** A setup checklist that ticks itself off as you go, illustrated guides for every setting, ready-made recipes (snow day, event, staff-only, click-to-open), troubleshooting, an FAQ and a glossary.
- **The Help tab** at the top right of every plugin screen, with a "my alert isn't showing" checklist.
- **A live preview** on the alert settings screen that redraws as you change position, width, severity and overlay — plus a warning if you switch off every way of closing the alert.

## Using it

**Site Alerts → All Alerts** lists the two alerts with their live status, level, schedule, targeting and trigger. There is no "Add New" — the list never grows. The Current Alert's row links to the status page; the Normal Alert's opens its settings form.

Settings, whether on the Current Alert module's tabs or the Normal Alert's admin form:

| Group | What it controls |
| --- | --- |
| Status | Live on/off, status level (the SRP actions plus Normal and Information), whether to show the badge and the level word |
| Schedule | Start and end date/time in the site timezone; leave either empty for open-ended |
| Where it shows | Entire site, front page, or selected post types / post IDs / URL paths, plus a never-show list |
| Who sees it | Everyone, logged out, logged in, or specific roles |
| How it opens | Page load, delay, scroll depth, exit intent, or click-only; and how often it may reappear |

**"Once, until I change this alert"** is worth calling out. The visitor sees it once and is then left alone — not for a day, not for a session, but until you edit the alert. Any change counts: the wording, the level, the targeting, when it comes down. The moment you save, everybody who has seen it sees it again.

**Being shown it is what counts, not closing it.** The visit is recorded when the popup opens, so somebody who reads it and then follows the link inside it — or just clicks away to another page — is not shown it again on the next one. Recording it on the close button meant only the people who pressed the X were remembered, and to everybody else the popup simply kept coming back.

It works off a revision counter bumped on every write, paired with the post's modified time, because most of an alert is post meta and `post_modified` sits still while the level or the schedule changes underneath it. **"Once, then never again"** is deliberately exempt from that reset — otherwise the two options would be the same thing.
| Appearance | Position, max width, overlay, close button, overlay click, Escape key, screen reader label |

**Site Alerts → Settings** holds the site-wide options: popup post type, rendering mode, the daily cut-off time, where dismissals are remembered (local storage, session storage or a cookie), z-index, whether editors see alerts, preview links, and extra CSS.

### Targeting notes

- URL paths are one per line, matched against the request path. `*` is a wildcard, so `/news/*` matches everything below `/news` and `/news*` also matches `/news` itself. Full URLs may be pasted in; only the path is compared.
- Exclusions always win over targeting.
- Alerts with the **click-only** trigger ignore page targeting, so a trigger button works wherever it is placed. Schedule, audience and exclusions still apply to them.
- Only the Current Alert can ever pop up, so a visitor never gets two alerts at once.

### Putting the status anywhere

`[schoolstatus]` prints the current status wherever it is typed — at the top of
the popup, in a header, in a sidebar, in a post. It reads the same Current Alert
the status board reads, so every place showing the status shows the same thing.

| Attribute | Default | What it does |
| --- | --- | --- |
| `show` | `icon level` | Which parts, in the order listed: `icon`, `level`, `directive`, `headline`, `message`, or `all`. Commas or spaces. |
| `when` | `always` | `live` prints nothing at all on a normal day. |
| `layout` | `stack` | `row` puts the parts side by side. |
| `align` | `center` | `left`, `center` or `right`. |
| `size` | `56` | Badge size in pixels. |
| `link` | `no` | `yes` wraps it in a link to the status page. |
| `source` | `board` | `alert` reports the Current Alert's own level whether or not it is showing. |
| `color` | — | Draw it in a colour of your own, for a background the SRP colour does not read on. |

`[school_status]` is an alias, because people type it both ways.

**`source` is two different questions.** `board` — the default — is what the
status page says: the Current Alert while it is showing, the normal state once
it is over. `alert` is what the Current Alert itself says, showing or not. Use
`alert` inside the popup: the popup *is* that alert, so it should keep the
alert's own badge rather than flipping to Normal the moment the event is filed
and the board goes back to resting. `when="live"` still asks the board, so an
alert that is switched off is not "live" whichever source is used.

It styles itself inline, so it looks right on a page that loads none of this
plugin's stylesheets — which is what lets it work inside the popup, on every
other page of the site. For the same reason the popup's cached markup is keyed
on the current status as well as the page's modified time: a shortcode baked
into cached markup would otherwise be frozen at whatever it said when that
markup was stored.

### Opening an alert from a page

Three ways, all equivalent:

- The **Alert Trigger** module in Beaver Builder (group "Site Alerts") — pick the alert, set the button text and style.
- The shortcode `[acps_alert_trigger id="123" text="Read the alert"]`.
- Any element with `class="acps-alert-open" data-alert="123"`.

### Previewing

Editors can open `https://example.org/?acps_alert_preview=123` to see one alert on the live site, ignoring its schedule, targeting and frequency. Turn this off under Settings.

## For developers

JavaScript API (`window.ACPSAlerts`):

```js
ACPSAlerts.open( 123 );          // open an alert
ACPSAlerts.close( 123, true );   // close it and remember the dismissal
ACPSAlerts.config( 123 );        // its runtime config

document.addEventListener( 'acps-alert:open', function ( e ) { console.log( e.detail.id ); } );
document.addEventListener( 'acps-alert:close', function ( e ) { console.log( e.detail.id ); } );
```

PHP filters:

| Filter | Purpose |
| --- | --- |
| `acps_alerts_capability` | Capability required to manage alerts (default `edit_pages`) |
| `acps_alerts_post_type_candidates` | Post type slugs searched when auto-detecting popups |
| `acps_alerts_alert_passes` | Final say on whether an alert runs on the current request |
| `acps_alerts_native_open_callback` | Dotted path to a Beaver Builder JS function that should open popups instead of this plugin's modal, e.g. `FLBuilderPopup.open` |

Action `acps_alerts_saved` fires with the popup ID and sanitized settings after a save.

Alert settings are stored as post meta on the popup, prefixed `_acps_alert_`; site settings live in the `acps_alerts_settings` option. Deleting the plugin removes both.

## Accessibility

The plugin's own modal sets `role="dialog"` and `aria-modal`, moves focus into the alert, traps Tab while it is open, restores focus on close, honours the Escape key, and respects `prefers-reduced-motion`. If every closing option is switched off for an alert, keyboard users have no way out — the settings screen warns about this, but it is not enforced.

### How the popup is found and shown

The status page's Beaver Builder layout is scanned for a module whose slug is a
popup — `popup`, `fl-popup`, `fl_popup`, `unified-popup`, `popup-module`, or
anything added through the `acps_alerts_popup_module_types` filter. The node id
is cached per page and re-detected whenever the status page is saved, when the
cached node is no longer in the layout, or when the board moves to another page.

The popup is a **container**: its heading, text and buttons are separate nodes
in the layout that name the popup as their parent, not part of the popup module
itself. Rendering just the module returns the shell with nothing in it. So there
are three strategies, tried in order of how complete the result is, each only
reached while the one before came back with nothing to read:

1. Render the whole layout through `[fl_builder_insert_layout]` and keep the
   `fl-node-<id>` subtree. The popup comes back exactly as the status page
   builds it.
2. Render the popup's child nodes on their own, for versions that render popups
   outside the layout flow. The alert supplies its own frame, so the popup's
   shell is no loss.
3. Render the popup module alone — the shell, for a popup that genuinely has no
   children.

A result with no text and no image counts as nothing, so an empty shell falls
through to the alert's own heading and text rather than reaching a visitor as a
popup holding only a close button. The finished markup is cached in a transient
keyed on the status page's modified time, because rendering a page layout in the
footer of every page is the most expensive thing this plugin does.

Throughout, Beaver Builder is pointed at the status page with
`FLBuilderModel::set_post_id` and pointed back in a `finally`, so a throw
mid-render cannot leave every later builder call on the request reading the
wrong layout.

**Styling is a separate job from markup.** Beaver Builder writes one stylesheet
per post, so the popup's design lives in the status page's stylesheet and is
simply not on any other page. That gets loaded on `wp_enqueue_scripts`, not at
render time — a stylesheet asked for in the footer arrives after the browser has
already painted the popup unstyled. Beaver Builder's base layout stylesheet is enqueued (it is absent on a page with
no builder content of its own), its own enqueue method is called for whichever
name that version has, and then the status page's cached stylesheet is loaded
under our own handle regardless, located through
`FLBuilderModel::get_asset_info()`. There is no reliable way to tell whether
Beaver Builder's call did anything — the handle for a layout has changed shape
between versions, so looking for one by name answers "no" for a version that
named it something else — and being wrong means the popup arrives with its
structure and none of its design. Loading it twice costs one cached request;
not loading it costs the whole look.

Two details that silently cost everything if got wrong: the stylesheet is only
linked when the cached file is really on disk, and Beaver Builder's base handle
is only named as a dependency when it is really registered — WordPress declines,
without a word, to print a style whose dependency it has never heard of.

**The lifted node has to stay inside the container its CSS names.** Beaver
Builder writes most of a layout's rules against an ancestor —
`.fl-builder-content .fl-node-xxx.fl-button-group .fl-button`,
`.fl-builder-content-123 .fl-node-yyy.fl-popup` — so taking the node out of its
page makes every one of those rules stop matching, silently, with the
stylesheet loaded and the node classes all still correct. The rules that happen
not to need an ancestor still apply, which is what makes the failure so
confusing: the icon keeps its colour and the heading its font while the popup
loses its background, border, radius and width and every button loses its fill.
The markup is therefore wrapped back in
`<div class="fl-builder-content fl-builder-content-<page id>">` before it is
used. Beaver Builder also lays the popup out under `.fl-popup:popover-open`,
which can never match once the attribute is stripped, so `alerts.css` restates
that layout.

**The browser's popover defaults go with the attribute.** `[popover]` supplies
more than positioning — it also supplies `background-color: Canvas` and
`color: CanvasText`, and Beaver Builder's popup settings do not write a
background unless one is picked. So a popup that looked solid white on its own
page arrives see-through once the attribute is stripped. `alerts.css` restates
those defaults, without `!important`, so a popup that does set its own
background still wins.

**The lifted popup is `position: relative`, never `static`.** Its close button —
and anything else inside it that Beaver Builder positions absolutely — is placed
against the popup's own corner (`top: -20px; right: -20px`), and an absolutely
positioned element anchors to its nearest *positioned* ancestor. Make the popup
static and it stops being one, so the button skips past it to the alert's
dialog, which spans the page, and lands 20px outside the corner of the window.
Relative lifts it out of the popover's fixed positioning just as static does
while keeping the containing block intact.

**The popup's own close button is used**, wired to close the alert rather than
calling `hidePopover()` on something that is no longer a popover. It is styled
and positioned against the popup's corner, which is where it belongs; the
alert's dialog spans the page so a percentage width has something to be a
percentage of, and a button positioned against *that* lands in the corner of
the window. The alert draws a close button of its own only when the popup did
not bring one.

**Assets are versioned by file modification time**, not by the plugin version.
A stylesheet edited between releases keeps the same version, so browsers and
page caches go on serving the old one — and the symptom is not "no styling",
which would be obvious, but styling from some earlier state of the file,
differing from one browser to the next depending on what each has cached.

**The popup is the box.** An alert whose body was designed in the builder gets
`acps-alert--built` on its shell, and the shell then contributes only the
overlay and the close button: no panel, no corners, no shadow, no max-width of
ours. The popup's own size is never overridden either — once the `popover`
attribute is stripped the browser's popover rules stop applying, so there is
nothing to fight, and a `max-width: none` aimed at those rules would instead
beat the popup's real width and throw it across the screen. Otherwise the popup's own white box sits inside a second white box and the
alert stops looking like the thing that was built.

Beaver Builder's popup is a real HTML popover — the element carries
`popover="manual"` — and a browser keeps any such element at `display:none`
until `showPopover()` is called, then promotes it to the top layer. Neither is
right once the popup has been lifted into the alert dialog and *is* that
dialog's body: while the attribute is on it the element sits in the DOM greyed
out and nothing shows, and opening it would take it straight back out of the
dialog. So the attribute is stripped server-side, and `alerts.css` undoes the
rest of the closed-popup styling. Only the hiding and the positioning are
overridden — the popup's own background, borders, spacing and typography are
left exactly as they were built.

If all three come back empty, the Current Alert module's own heading and text
stand in, so an alert still reaches people. Every Beaver Builder entry point is
checked with `method_exists` before it is called.
