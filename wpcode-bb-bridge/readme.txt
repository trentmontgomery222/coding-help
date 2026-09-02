=== WPCode Values for Beaver Builder ===
Contributors: acps
Tags: wpcode, beaver builder, shortcode, snippets
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Edit WPCode snippet values from inside the Beaver Builder page editor.

== Description ==

WPCode is great for managing reusable code snippets, but changing a value
inside a snippet (a headline, a color, a phone number) normally means
going back into the Code Snippets screen and editing PHP.

This plugin closes that gap for Beaver Builder sites. It adds:

* A **Configurations** admin screen (WPCode BB Configs) where you map a
  WPCode snippet's shortcode tag to a list of editable fields (text,
  textarea, number, color, URL, image, select, yes/no, rich text).
* A **"WPCode Value" Beaver Builder module**. Drop it on a page, pick a
  Configuration, and the fields you defined show up right there in the
  Beaver Builder settings panel. Each page instance stores its own
  values, so the same snippet/config can be reused across many pages
  with different values on each.
* A **"Custom" mode** on that same module for when you don't want to set
  up a Configuration first: type the shortcode tag and a list of
  `key = value` variables directly into a text-editor-style box inside
  the Beaver Builder panel. That box only ever appears while editing the
  page - never on the live site.
* At render time, the module calls your WPCode snippet's shortcode and
  passes the configured values in as shortcode attributes
  (`$atts['your_key']`), and also exposes them via
  `$GLOBALS['wpcode_bb_values']['your_key']` for snippets that prefer
  reading a global.

This plugin is **site-managed only**. It is not designed for multisite
network activation, and will show a warning if network-activated.

= Requirements =

* [WPCode](https://wordpress.org/plugins/insert-headers-and-footers/) with
  a snippet set to "Shortcode" insertion.
* [Beaver Builder](https://www.wpbeaverbuilder.com/) (Lite or Pro).

Both are soft dependencies - the Configurations screen works without
them, but you'll need WPCode to write the snippet and Beaver Builder to
see the module.

== Installation ==

1. Upload the `wpcode-bb-bridge` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen in wp-admin.
3. In WPCode, set your snippet's Insertion method to "Shortcode" and
   note the generated tag (e.g. `wpcode_snippet_123`).
4. Go to **WPCode BB Configs > Add New**, paste in the shortcode tag,
   and list the fields you want editable.
5. Edit a page with Beaver Builder, add the **WPCode Value** module
   (under the WPCode category), choose your Configuration, and fill in
   the values.

== Frequently Asked Questions ==

= Does this work with the free version of Beaver Builder? =

Yes, module registration uses the standard `FLBuilder::register_module()`
API available in Beaver Builder Lite and Pro.

= Can I reuse one Configuration on multiple pages with different values? =

Yes. The Configuration only defines the schema (which fields exist and
their type/defaults). Each Beaver Builder module instance stores its own
values, so page A and page B can use the same Configuration with
different text, colors, etc.

= What if I don't want to use shortcode attributes? =

Right before the shortcode runs, the module sets
`$GLOBALS['wpcode_bb_values']` to an array of `key => value` for the
current instance, so your snippet can read from there instead.

== Changelog ==

= 1.0.0 =
* Initial release.
