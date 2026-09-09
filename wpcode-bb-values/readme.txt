=== WPCode Values for Beaver Builder ===
Contributors: acps
Tags: beaver builder, wpcode, snippets, shortcode
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.0
Stable tag: 4.1.0
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

Drop the "WPCode Values" module on a page and enter your snippet's ID - the
number in [wpcode id="123"]. Every setting in that snippet appears in the
module straight away, already filled in with the value the snippet uses.
Change what you want for this page, leave the rest alone, and clear a box to
let the snippet's own value through again.

Settings written as true or false become a true/false dropdown, so they cannot
be given a value the snippet will not understand. Lists of words stay as text,
typed with commas between them.

Settings that belong to a block - everything under noSchoolEvent, say - are
grouped under that name and start collapsed, so the module opens as a short
list of headings rather than one long column of boxes.

Every box has a help icon. Put a comment next to a setting in your snippet and
that comment becomes its help text:

    {key: 'noSchoolEvent', value: {
        badgeText: 'No School',   // Shown on the badge when school is closed
        searchForWords: ['schools closed']
    }}

Without a comment, common names (colours, badges, search words, on/off flags)
get a written description, and every box says what value the snippet itself
uses.

Nested settings use a dot (noSchoolEvent.badgeText). Word lists are typed with
commas between them (schools closed, no school). Tools > WPCode Values lists
everything found in your snippets.

= How the values get in =

The values are literals inside the JavaScript your snippet prints, so they
cannot be passed in as shortcode attributes. This plugin edits them in that
output, on the way to the browser, only on the page holding the module. If the
array cannot be found or read, the snippet's output is passed through exactly
as written.

PHP snippets can also read the overrides from $GLOBALS['wpcode_bb_values'].

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
