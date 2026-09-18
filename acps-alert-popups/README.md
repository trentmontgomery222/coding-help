# ACPS Alert Popups

A managed site alert system for a single WordPress site, designed in Beaver Builder.

There are exactly **two alerts**, always, and neither can be created or deleted:

- **Normal Alert** — the resting state. What the site says when nothing is happening.
- **Current Alert** — the one you switch on, edit and archive. It is always there; on a quiet day it is simply off.

The split is deliberate:

- **The alert content** — what it says and how it looks — is an ordinary WordPress post you design in Beaver Builder, exactly like any other layout. You design the two alerts once.
- **The Status Board module** on your status page owns the day-to-day wording, the level, and the on/off switch for the Current Alert.
- **wp-admin** owns the fine-grained rules — schedule, page targeting, audience, trigger and how often it comes back.

Content and settings are stored separately, so an alert can be redesigned at any time without touching its settings.

## Requirements

- WordPress 6.0+, PHP 7.4+ (single site; the plugin is not network-aware by design)
- Beaver Builder is **recommended, not required**. With it you design alerts in the builder; without it you write them in the normal WordPress editor and everything else works the same.

## Installing

1. Copy the `acps-alert-popups` folder into `wp-content/plugins/`.
2. Activate **ACPS Alert Popups** in Plugins.
3. Open **Site Alerts** in the admin menu.

### Where alerts are stored

The plugin registers its own `acps_alert` post type and hands it to Beaver Builder. On activation it creates the two alerts — Normal Alert and Current Alert — and protects them from deletion. Each opens on a normal WordPress editing screen with a **Launch Beaver Builder** button, which is where you design the popup body.

It does not rely on Beaver Builder registering a popup post type, because that feature is not in every version and its slug has changed between them. Popups you already built on a Beaver Builder popup type are still detected and listed alongside your alerts: `fl-popup`, `fl_popup`, `fl-builder-popup`, `flbuilder_popup`, and Beaver Themer popup layouts (`fl-theme-layout` with a layout type of `popup`). You can also force a specific source under **Site Alerts → Settings → Popup post type**.

## The status page is the control panel

Drop the **School Status Board** module (Beaver Builder → Site Alerts group) onto your status page. From then on that page is where you work:

- **The module's fields ARE the Current Alert.** Edit them and save and the alert changes in place — it never creates a second one. There is nothing to create and nothing to delete; you only ever modify the one that is already there.
- **Show this alert now** is the switch. On means visitors see it; off means the wording sits there ready for next time.
- The module renders the **current status banner** plus the **archive** of past updates, as an expandable list. With the Current Alert off, the banner shows the Normal Alert wording.
- The Current Alert can also **pop up across the rest of the site** — but never on the status page itself.
- It **archives itself and switches itself off at 5:50pm** (configurable) unless you chose "Keep it up until I switch it off". The archive entry is a separate record; the alert's own wording is left intact. An update posted after the cut-off runs until the following day.
- Set **Who can see it → Staff only** to stage an update on the live site where only people who can manage alerts see it. The board shows a dashed "Staff preview" strip so you can't forget.

Archived updates are stored as records in their own list, not as posts, so the archive can grow without the site ever gaining a third alert.

### Status levels are SRP

The levels are the five Standard Response Protocol actions from the "I Love U Guys" Foundation — **Hold**, **Secure**, **Shelter**, **Evacuate**, **Lockdown** — each with its directive and its colour, so the site says exactly what the drill says. Two everyday levels sit alongside them: **Normal** and **Information**, which are deliberately *not* marked as response actions.

Urgency runs Lockdown > Evacuate > Shelter > Secure > Hold > Information, and that decides which update takes the banner.

Check the directives against your own district's training materials before going live; a developer can adjust the wording with the `acps_alerts_status_levels` filter. Updates written before the move to SRP keep rendering with their old wording.

### Backfilling the archive

Use the module's **Add a past event to the archive** section — headline, message, level and a date (`YYYY-MM-DD`) — to write up something that already happened. The boxes empty themselves once filed. Archived entries never pop up and never reach the banner, and filing one never touches either alert.

Popup *layout* is still a Beaver Builder job: open the Current Alert in Site Alerts and use **Launch Beaver Builder**. Without a layout, the popup shows the message you typed.

## Learning it

You should not need this file. The plugin teaches itself:

- **A guided tour.** Open **Site Alerts** and click **Show me how**. It dims the screen, spotlights one real control at a time and explains it, walking you from an empty list all the way to a live alert — across screens, picking up where it left off. Escape leaves at any point; you can replay it whenever.
- **Site Alerts → Help & Tutorials.** A setup checklist that ticks itself off as you go, illustrated guides for every setting, ready-made recipes (snow day, event, staff-only, click-to-open), troubleshooting, an FAQ and a glossary.
- **The Help tab** at the top right of every plugin screen, with a "my alert isn't showing" checklist.
- **A live preview** on the alert settings screen that redraws as you change position, width, severity and overlay — plus a warning if you switch off every way of closing the alert.

## Using it

**Site Alerts → All Alerts** lists the two alerts with their live status, level, schedule, targeting, trigger and priority. Each row links to the alert settings, to the Beaver Builder editor for the content, and to a one-click on/off switch. There is no "Add New" — the list never grows.

Per-alert settings (also available as a meta box on the alert's own edit screen):

| Group | What it controls |
| --- | --- |
| Status | Live on/off, status level (the SRP actions plus Normal and Information), priority |
| Schedule | Start and end date/time in the site timezone; leave either empty for open-ended |
| Where it shows | Entire site, front page, or selected post types / post IDs / URL paths, plus a never-show list |
| Who sees it | Everyone, logged out, logged in, or specific roles |
| How it opens | Page load, delay, scroll depth, exit intent, or click-only; and how often it may reappear |
| Appearance | Position, max width, overlay, close button, overlay click, Escape key, screen reader label |

**Site Alerts → Settings** holds the site-wide options: popup post type, rendering mode, how many alerts may show on one page view, where dismissals are remembered (local storage, session storage or a cookie), z-index, whether editors see alerts, preview links, and extra CSS.

### Targeting notes

- URL paths are one per line, matched against the request path. `*` is a wildcard, so `/news/*` matches everything below `/news` and `/news*` also matches `/news` itself. Full URLs may be pasted in; only the path is compared.
- Exclusions always win over targeting.
- Alerts with the **click-only** trigger ignore page targeting, so a trigger button works wherever it is placed. Schedule, audience and exclusions still apply to them.
- When more alerts qualify than "alerts per page view" allows, the highest priority wins; ties fall back to title order.

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
