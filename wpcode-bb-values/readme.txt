=== WPCode Values for Beaver Builder ===
Contributors: acps
Tags: beaver builder, wpcode, snippets, shortcode
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.0
Stable tag: 2.0.1
License: GPLv2 or later

Adds one Beaver Builder module where you type values in the editor and pass
them to a WPCode snippet as shortcode attributes.

== Description ==

Set your WPCode snippet's Insertion method to Shortcode, then drop the
"WPCode Values" module onto a page in Beaver Builder. Put the snippet's
shortcode tag in the Snippet tab, and fill in up to eight name/value rows in
the Values tab. Each row reaches your snippet as a shortcode attribute:

    $atts = shortcode_atts( array( 'headline' => '' ), $atts );
    echo esc_html( $atts['headline'] );

The same values are also available as `$GLOBALS['wpcode_bb_values']`.

Values are stored on the individual module, so one snippet can be used on
many pages with different values on each. Nothing is shown to visitors except
what your snippet outputs.

== Frequently Asked Questions ==

= The module is not in the Beaver Builder editor =

Check Settings > Beaver Builder > Modules. If that list has ever been
narrowed down, a newly installed module stays off until you tick it.

= I need more than eight values =

Add a second module, or open an issue - the number of rows is a single
constant (WPCODEBBV_SLOTS) in the main plugin file.

== Changelog ==

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
