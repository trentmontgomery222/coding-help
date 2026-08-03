=== Text Tokens ===
Contributors: acps
Tags: shortcode, tokens, placeholders, beaver builder, school year
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Define placeholder tokens like [SCHOOL-YEAR] and have them replaced with static
or dynamically calculated values everywhere text is rendered.

== Description ==

Text Tokens is a single-site plugin that lets editors type placeholder tokens
(for example `[SCHOOL-YEAR]`) into any text field on the site and have the
plugin swap them for real values at render time.

* **Static tokens** are replaced with text an admin types.
* **Dynamic tokens** are calculated from a built-in rule (current year, school
  year with a configurable rollover date, current date with format options,
  current day of week, days until a target date, current semester, and a
  copyright year for footers).

Token syntax is `[CODE]`, matching is case-insensitive, and unknown tokens are
left in place so typos are visible. To print literal brackets, double them:
`[[TEXT]]` renders as `[TEXT]`.

= Where replacements run =

* Standard post/page content and excerpts
* Post/page titles and the document title
* Text and block widgets, plus widget titles
* Navigation menu item labels
* **Beaver Builder** module output (hooked via `fl_builder_render_content`,
  because Beaver Builder renders outside `the_content`)

= Performance =

Resolved values are cached in a transient for a configurable window (default one
hour) so dynamic values are not recalculated on every request. The cache is
cleared automatically whenever a token or setting changes.

= Accessibility =

The settings screen targets WCAG 2.1 AA (with 2.2 focus guidance) and Section
508: real table headers with `scope`, programmatically associated labels on all
fields, text labels on action buttons, thick high-contrast focus indicators,
an `aria-live` region for dynamic changes, and status shown with an icon plus
text rather than color alone.

== Installation ==

1. Upload the `text-tokens` folder to `/wp-content/plugins/`.
2. Activate the plugin through the “Plugins” screen in WordPress.
3. Go to **Settings → Text Tokens** to define your tokens.

== Frequently Asked Questions ==

= Does this support multisite / network admin? =

No. It is intentionally a single-site plugin with one settings screen.

= What happens to a token that is not defined? =

It is displayed literally, exactly as typed, so mistakes are easy to spot.

== Changelog ==

= 1.0.0 =
* Initial release.
