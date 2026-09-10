=== WPCode Values for Beaver Builder ===
Contributors: acps
Tags: beaver builder, wpcode, snippets, shortcode
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.0
Stable tag: 6.3.0
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

By default a value set on a module changes that page only.

The snippet decides which settings are different, with a siteWide marker.
A setting marked siteWide is edited on any module, in any page, and the value
applies everywhere that snippet runs through this module:

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
        primaryColor: 'orange',
        siteWide: ['badgeText']
    }}

Site-wide boxes are labelled "(site-wide)" in the module and always show the
value in force everywhere, so you are editing the real thing rather than a
copy. Typing the snippet's own value back in clears it again. Tools > WPCode
Values lists which settings are site-wide and what each is currently set to,
with a button to reset them.

A site-wide value cannot reach a snippet placed by any other means, because
the only output this plugin can touch is its own module's.

= More than one set of settings =

A page can hold as many modules as you like. Each one points at its own
snippet and keeps its own values, and nothing one module does reaches another.

A single snippet can also hold more than one configurations array - name them
configurations, configurationsFooter, configurationsSidebar and so on. Each
array gets its own group in the module, so two arrays using the same key stay
separately editable.

One thing to watch when two modules on the same page run different snippets:
if both snippets declare a variable with the same name at the top level, the
second one loaded wins in the browser, whatever this plugin does. Give each
snippet's array (and its CONFIG helper) a distinct name.

= Marking a variable Configurable =

Settings do not have to live in a configurations array. A "Configurable"
comment makes any assignment editable, wherever it is in the snippet:

    var apiKey = 'AIza-x';       // Configurable siteWide: the API key
    var debugMode = 'false';     // Configurable - turn console logging on
    var timezone = 'America/New_York';   // Configurable

The assignment has to start its line, and the comment has to be on that same
line. Anything after "Configurable" (past a colon or dash) becomes the
setting's help text, and siteWide works exactly as it does in an array. These
appear in the module under "Marked variables".

This works in CSS too - //, # and /* */ all count as the marker:

    :root{
      --accent: #1A73E8;   /* Configurable siteWide: brand colour */
      --font-body: "Google Sans", Roboto, sans-serif;  /* Configurable */
    }

The comment marks where the value ends, so a colour, a size or a font stack
with its own commas and quotes is kept exactly as written. Prefer custom
properties over plain declarations: a plain "background:" appears all over a
stylesheet and only the first one marked wins.

= PHP snippets =

WPCode RUNS a PHP snippet rather than printing it, so its source never
reaches the browser and there is nothing to rewrite on the way out. A PHP
snippet asks for its values instead:

    $api_key   = wpcodebbv_cfg( 'api_key', 'AIza-DEFAULT' );   // Configurable siteWide
    $debug     = wpcodebbv_cfg( 'debug', false );              // Configurable
    $max_items = wpcodebbv_cfg( 'max_items', 25 );             // Configurable
    $roles     = wpcodebbv_cfg( 'roles', array( 'editor' ) );  // Configurable

The default you write is what the module shows and what applies until someone
changes it. You get back the same TYPE you passed as the default - a boolean
default returns a boolean, a number a number, an array an array - so the
snippet never has to think about the editor typing text.

PHP values are ALWAYS site-wide. There is no per-page option for them, and no
need to write siteWide: a PHP snippet usually runs in more than one place - a
WPCode auto-insert, a shortcode in a template - and a per-page value would
apply on the one page and silently not apply anywhere else it runs. Changing a
PHP value changes it everywhere the snippet runs, module or no module.

Only JavaScript and CSS values are per-page by default, with siteWide to opt
one of them into applying everywhere.

A plain PHP assignment marked Configurable - $var = 'x'; or define( ... ) - is
listed on Tools > WPCode Values but NOT offered in the module, because nothing
could make it work: WPCode executes a PHP snippet, so its source never reaches
the output there is to rewrite. That screen shows the wpcodebbv_cfg() line to
replace it with.

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
