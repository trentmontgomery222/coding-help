=== ACPS Alert Popups ===
Contributors: acps
Tags: beaver builder, popups, alerts, notifications
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns Beaver Builder Popups into a managed site alert system, controlled from wp-admin.

== Description ==

Design the alert in Beaver Builder; post, schedule, target and throttle it from wp-admin. See README.md for full documentation.

== Changelog ==

= 1.9.0 =
* Every hook, filter, shortcode, endpoint, admin screen and Beaver Builder module template and save handler now runs under the failsafe, so a failure in any one of them cannot take a page down.
* A plugin file that no longer parses now pauses the plugin and emails the operator, instead of being retried on every request.
* Missing plugin files email the operator once; the plugin still comes back by itself when they are restored.
* Installing a new version lifts a pause automatically.
* No failure or missing-file notices are shown anywhere on screen.
* Uninstall now removes all of the plugin's stored data.
* The setup checklist remembers the first time the Current Alert was used.
* Removed the unused "Alerts per page view" setting and out-of-date help text about level colours.

= 1.8.0 =
* Main CSS editor on the settings page, with load and reset-to-defaults.

= 1.0.0 =
* First release.
