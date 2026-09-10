=== WPCode Values for Beaver Builder ===
Contributors: acps
Tags: beaver builder, wpcode, snippets, shortcode
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.0
Stable tag: 7.4.0
License: GPLv2 or later

Reads the "configurations" array out of your WPCode snippets and lets you
pick and edit those settings from a Beaver Builder module, per page.

== Description ==

Snippets often keep their settings in an array at the top:

    var configurations = [
        {key: 'eventColor', value: 'blue'},
        {key: 'noSchoolEvent', value: {
            badgeText: 'No School',
            searchForWords: ['schools closed']
        }}
    ];

This plugin finds that array, lists every setting in it, and lets a page
editor override any of them from a Beaver Builder module - without touching
the snippet and without affecting any other page.

Drop the "WPCode Values" module on a page and pick your snippet from the list
on the Setup tab. Every setting in THAT snippet appears on the Settings tab,
already filled in with the value the snippet uses - and nothing from any other
snippet does.
Change what you want for this page, leave the rest alone, and clear a box to
let the snippet's own value through again.

Settings written as true or false become a true/false dropdown, so they cannot
be given a value the snippet will not understand. Lists of words stay as text,
typed with commas between them.

Settings that belong to a block - everything under noSchoolEvent, say - are
grouped under that name and start collapsed, so the module opens as a short
list of headings rather than one long column of boxes.

The snippet ID sits on a second tab, "Setup", away from the values that get
edited day to day.

Every box has a help icon. Put a comment next to a setting in your snippet and
that comment becomes its help text:

    {key: 'noSchoolEvent', value: {
        badgeText: 'No School',   // Shown on the badge when school is closed
        searchForWords: ['schools closed']
    }}

Without a comment, common names (colours, badges, search words, on/off flags)
get a written description, and every box says what value the snippet itself
uses. Tools > WPCode Values prints your own array with a comment added to every
setting, ready to paste back into WPCode - edit the wording first, since you
know what these do better than the plugin does.

= Per page, and site-wide =

A module has three tabs.

**This page** - every setting, filled in with the value in force. Change one and
it applies to this page alone, whatever else is going on.

**Site-wide** - the settings the snippet marked siteWide, plus PHP values.
Change one here and it changes on every page that runs this snippet. This tab
only exists when the snippet has such settings.

**Setup** - which snippet the module runs, the Extra settings box, and Reset.

The snippet decides which settings can be site-wide, with a siteWide marker:

    // one setting
    {key: 'calendarID', siteWide: 'true', value: 'c_x'},

    // a whole block - everything inside it is site-wide
    {key: 'noSchoolEvent', siteWide: 'true', value: {
        badgeText: 'No School',
        primaryColor: 'red'
    }},

    // or name the ones you want
    {key: 'halfDayEvent', value: {
        badgeText: 'Half Day',
        siteWide: ['badgeText']
    }}

A site-wide setting still appears on the "This page" tab, so one page can
override the site-wide value for itself while every other page keeps following
it. Which is the point: set the badge text once for the whole site, then say
something different on the one page that needs to.

Values are read narrowest-first: this page's box, then the site-wide value,
then the value written in the snippet.

PHP values are site-wide only. Their value is read at runtime wherever the
snippet runs, including where no module is involved, so a per-page value could
not be honoured there and is not offered.

= Resetting =

To reset one setting, put its box back to the value it had when you opened it.

To reset more than one, use Reset on the Setup tab: clear this page's changes,
clear this snippet's site-wide values, or both. It takes effect when you save
and then goes back to "Leave everything as it is". Tools > WPCode Values also
has a button to clear a snippet's site-wide values.

= Reading the settings in your snippet =

The configurations array is a list of {key, value} pairs, which is awkward to
read from directly. Tools > WPCode Values has a helper to paste below the
array, giving you one object to look settings up by name:

    CONFIG.get('calendarID')                     // 'c_a13c7383...'
    CONFIG.get('noSchoolEvent.badgeText')        // 'No School'
    CONFIG.bool('noSchoolEvent.showBottomBadge') // true - a real boolean
    CONFIG.list('noSchoolEvent.searchForWords')  // ['schools closed']
    CONFIG.set('eventColor', 'crimson')          // updates the array too
    CONFIG.match('Schools Closed Friday')        // 'noSchoolEvent'

Watch the on/off settings: they are the strings "true" and "false", not real
booleans, so if (CONFIG.get('x')) is true even when the setting says false,
because "false" is a non-empty string. Use CONFIG.bool() for those.

= While you are editing =

Inside the Beaver Builder editor each module shows a small note saying which
snippet it runs, how many settings it has, how many you have changed on this
page, and how many are site-wide. On a page holding several of these it is the
difference between a row of identical blocks and knowing which is which.

Nobody else ever sees it. It needs the Beaver Builder editor to be open AND a
logged-in user who can edit posts - so it is absent on the live page, absent in
Beaver Builder's own preview, and absent for visitors.

= If part of the plugin breaks =

Every file this plugin owns can be deleted or corrupted without taking the site
down. A damaged file costs the feature in it, an admin notice says which file
and why, and everything else - including the rest of the site - carries on.

Two files are the exception, and cannot be otherwise: PHP compiles a file
before running any of it, so an entry point cannot catch a parse error in
itself.

* wpcode-bb-values.php - the plugin's main file. It is deliberately small (the
  loader, the crash guards, and wpcodebbv_cfg) with the features in
  includes/functions-core.php, so there is very little in it to break. If it
  does, WordPress's own recovery mode handles it, and an update that lands a
  broken one is caught by the post-update crash test and rolled back.
* modules/wpcode-values/includes/frontend.php - Beaver Builder includes this
  directly. It is a stable ~30-line stub that loads frontend-render.php inside
  try/catch, so the render code that actually gets edited is protected.

A fatal anywhere in the plugin also arms safe mode: the next request loads only
a notice with a "Resume plugin" button, so a crash cannot repeat.

= Updates =

This plugin does not live on wordpress.org, so it checks a source you control -
a JSON manifest URL or GitHub releases.

It does NOT appear on the Plugins screen's update list and does not
auto-update. New versions are installed by requesting the secret force-update
URL, from a deploy hook, cron, or by pasting it in a browser. If you ever want
the ordinary "Update now" row back, one line turns it on:

    add_filter( 'wpcodebbv_offer_updates_in_admin', '__return_true' );

The settings are deliberately out of the way: Tools > WPCode Values with
?wpcodebbv_updates=1 on the URL.

What protects the site:

* After an update installs, the plugin loads itself in a fresh request and
  looks for a marker. A real 5xx deactivates the plugin and records the
  failure; an inconclusive result (a host that blocks a site calling itself)
  leaves it enabled, so a blocked loopback never disables a good update.
* A fatal error inside this plugin's own files arms safe mode. The next
  request loads only a notice with a "Resume plugin" button instead of the
  plugin's code, so a bad release cannot white-screen the site.
* A secret URL forces an immediate check and install, for a deploy hook or
  cron.
* A dev site can be made to update first and publish "I verified version X";
  production then only offers that version once dev has passed.

UPDATE-SYSTEM.md, included in the plugin folder, documents the whole thing and
how to port it to another plugin.

== Frequently Asked Questions ==

= The module is not in the Beaver Builder editor =

Check Settings > Beaver Builder > Modules. If that list has ever been
narrowed down, a newly installed module stays off until you tick it.

= The dropdowns are empty =

The scan reads published and draft WPCode snippets. Use the Rescan button on
Tools > WPCode Values after editing a snippet. You can always type settings by
hand in the module's Advanced tab as "path = value" lines - those are applied
to whatever the snippet prints, so they work even when the scan finds nothing.

== Changelog ==

= 7.4.0 =
* A page can now override a site-wide value for itself. Site-wide settings
  appear on both tabs: change one on "Site-wide" and it changes everywhere;
  change it on "This page" and only that page differs, while every other page
  keeps following the site-wide value.
* Added Reset on the Setup tab - clear this page's changes, this snippet's
  site-wide values, or both. Resetting one setting is still just putting its
  box back to what it said when you opened it.
* The module's settings are now split into "This page", "Site-wide" and
  "Setup" tabs, so nothing changes the whole site by accident, and each group
  heading shows how many settings it holds.

= 7.3.0 =
* Every file can now be deleted or corrupted without taking the site down.
  Includes are loaded through a guard that catches a parse error as well as a
  missing file, every hook this plugin registers runs inside a net, and what
  failed is reported in an admin notice.
* The features moved to includes/functions-core.php, leaving the main plugin
  file as a small loader - because a plugin's main file is the one thing PHP
  compiles before any of its own code can run.
* The module's render template is now loaded from a small stable stub, so a
  problem in the render code cannot break the pages using the module.

= 7.2.0 =
* This plugin no longer appears on the Plugins screen's update list and no
  longer auto-updates. The three hooks that produced that are off unless
  something opts back in with the wpcodebbv_offer_updates_in_admin filter.
* Everything else about updates is unchanged: the force-update URL installs a
  new version on demand, the crash test still rolls back a release that will
  not load, and safe mode still catches a fatal.

= 7.1.0 =
* Each module now identifies itself in the Beaver Builder editor: which snippet
  it runs, how many settings, how many changed on this page, how many are
  site-wide or PHP. Shown only inside the editor to a logged-in user who can
  edit posts - never on the live page, never to visitors.
* A module with no snippet chosen says so in the editor instead of rendering
  nothing.

= 7.0.0 =
* Added the update system ported from the ACPS Site Toolkit: update checks
  against a manifest URL or GitHub releases, "Update now" on the Plugins
  screen, optional auto-update, a crash test after installing that rolls back
  a release which fails to load, fatal-error safe mode with a Resume button, a
  secret force-update URL, and the optional dev-then-production rollout.
* Update settings live at Tools > WPCode Values with ?wpcodebbv_updates=1.
* Deleting the plugin now cleans up everything it stored.

= 6.3.0 =
* Fixed PHP values not taking effect. wpcodebbv_cfg() only read values while
  this plugin's module was rendering, so a snippet that also ran anywhere else
  - a WPCode auto-insert, a shortcode in a template - got its default back.
  It now reads the stored value, so a PHP value applies wherever the snippet
  runs.
* PHP values are always site-wide, with no per-page option and no need to
  write siteWide. Only JavaScript and CSS values are per-page by default.
* A plain PHP assignment or define() marked Configurable is no longer offered
  in the module, since nothing could make it work. Tools > WPCode Values lists
  it as "not editable" and shows the wpcodebbv_cfg() line to replace it with.

= 6.2.0 =
* A module now shows only the settings of the snippet it is running. Every
  other snippet's settings are hidden rather than listed alongside them.
* The snippet is chosen from a list on the Setup tab instead of typed. A box
  for typing an ID by hand is still there for a snippet the list cannot show;
  the module runs it, but its settings have to go in Extra settings.

= 6.1.0 =
* The Configurable marker now works in CSS and PHP snippets, not just
  JavaScript. //, # and /* */ all count as the marker, and the value in front
  of it is kept exactly as written - a colour, a size, a font stack, an
  array(), a PHP constant.
* Added wpcodebbv_cfg( name, default ) for PHP snippets. WPCode executes a PHP
  snippet rather than printing it, so there is no output to rewrite; the
  snippet reads its values at runtime instead, and gets back the same type as
  the default it passed.
* Marked names may now contain $ and -, so $variables and --css-properties
  work, in the module and in the Extra settings box.

= 6.0.0 =
* A snippet can hold several configurations arrays now. Each gets its own
  group in the module, so two arrays that use the same key stay separately
  editable.
* Added the "Configurable" comment marker: any assignment followed by
  // Configurable becomes an editable setting, wherever it sits in the
  snippet. Text after the word becomes its help, and siteWide works there too.
* Grouping is decided when a snippet is scanned rather than in the module, so
  blocks, array names and marked variables all group consistently.

= 5.2.0 =
* Added a CONFIG helper to paste below your configurations array, for reading
  and setting values by key instead of walking the array by hand: get, set,
  bool, list, num, has, keys, all, and match for the search-words lookup.
  Tools > WPCode Values has it in a copy box.

= 5.1.0 =
* Site-wide settings are now declared by the snippet with a siteWide marker,
  rather than being chosen in wp-admin. Mark one setting, a whole block (every
  setting inside it is then site-wide), or name individual settings with
  siteWide: ['a', 'b'].
* A site-wide setting is edited from any module on any page and applies
  everywhere. Its box always shows the value currently in force, and typing
  the snippet's own value back in clears it.
* Tools > WPCode Values now reports which settings are site-wide and what they
  are set to, with a reset button, instead of setting them there.

= 5.0.0 =
* The snippet ID moved to its own "Setup" tab, alongside the extra-settings
  box, so it is not sitting next to the values that get edited regularly.
* Added site-wide values under Tools > WPCode Values. A module that was left
  alone follows the site-wide value; one whose box was changed keeps its own
  value for that page.
* Tools > WPCode Values now prints your configurations array with a comment
  added to every setting that lacks one, ready to copy back into WPCode.
* Help text now says whether a setting has a site-wide value, and what it is.

= 4.1.0 =
* Every setting now has a help icon. A comment written next to the setting in
  the snippet is used as its help text; otherwise a description is worked out
  from the setting's name. Either way the help says what value the snippet
  itself uses and that clearing the box restores it.
* Nested settings are grouped under their parent key in their own collapsible
  panel, instead of one flat list. Settings with no nesting sit in "General".

= 4.0.0 =
* The module no longer asks you to pick settings. Enter the snippet ID and
  every setting in that snippet is listed automatically, pre-filled with the
  value the snippet currently uses.
* Settings whose value is true or false are detected and shown as a
  true/false dropdown instead of a text box.
* The snippet is identified by its WPCode ID now, and rendered as
  [wpcode id="123"]. Pasting the whole shortcode works too.
* Clearing a box removes that override, so the snippet's own value applies
  again. A module nobody has edited renders exactly what the snippet does.

= 3.0.0 =
* The module now works with the "configurations" arrays snippets actually use.
  It scans your snippets, lists every setting it finds - including settings
  nested one level down and lists of words - and lets you pick them from
  dropdowns in the module and give them new values for that page.
* Values are rewritten in place in the snippet's output. Only the values you
  picked change; comments, formatting and every other line are untouched, and
  output with no configurations array passes through unchanged.
* Added Tools > WPCode Values, listing every setting found and the value the
  snippet uses for it, with a Rescan button.
* Added an Advanced box for typing "path = value" lines directly, for anything
  the scan does not pick up.

= 2.0.1 =
* Removed the last key in the module's field schema that is not one Beaver
  Builder's own modules use (a section "description"), after confirming the
  earlier conflict appeared when opening the module for editing rather than
  when saving. That is the settings-form render path, where Beaver Builder
  loads a file named after each field's type - so an invented type or key
  breaks the response instead of being ignored. The guidance moved to a
  field help tooltip.

= 2.0.0 =
* Complete rewrite. The previous version built its Beaver Builder fields at
  runtime from a separate "Configurations" post type and swapped them with
  Beaver Builder's toggle mechanism. That machinery was the likeliest source
  of Beaver Builder's "detected a plugin conflict that is preventing the page
  from saving" error, and it is gone.
* The module's field schema is now fixed and identical on every request, and
  every field is a plain text field - no toggles, no code editor, no rich
  text, nothing that has to initialise in JavaScript before a page can save.
* The Configurations post type, its admin screens, and its JavaScript are
  removed. Names and values are typed directly on the module.
* The plugin now hooks only 'init' (to register the module) and 'admin_menu'
  (for a read-only help screen under Tools). It adds no filters to anything
  Beaver Builder owns.
* Snippet output is buffered, so a PHP notice from your snippet cannot land
  in the middle of a Beaver Builder AJAX response.
