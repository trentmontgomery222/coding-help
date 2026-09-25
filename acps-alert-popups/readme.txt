=== ACPS Alert Popups ===
Contributors: acps
Tags: beaver builder, popups, alerts, notifications
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.10.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns Beaver Builder Popups into a managed site alert system, controlled from wp-admin.

== Description ==

Design the alert in Beaver Builder; post, schedule, target and throttle it from wp-admin. See README.md for full documentation.

== Changelog ==

= 1.10.4 =
* Added an always-works way out of safe mode: opening https://yoursite/?acps_alerts_resume=<your console key or update secret> lifts the pause using only the main plugin file, so it works even when the remote console cannot load. The safe-mode email now includes this one-click link.

= 1.10.3 =
* Fixed for real: the popup's styles no longer touch the rest of the page. All of the popup's CSS is now confined to the popup itself and nothing is loaded globally, so site columns (especially on mobile) are left alone. Beaver Builder's own webfonts and icon sheets are off by default now; add_filter( 'acps_alerts_load_bb_scripts', '__return_true' ) brings them back.

= 1.10.2 =
* Fixed: the popup's styles could affect columns and other layout on the rest of the site, most visibly on mobile. The status page's stylesheet is now confined to the alert popup and cannot reach the page around it.

= 1.10.1 =
* Removed the per-feature on/off switches from Settings. The delete warning now points to switching the alert off or changing a setting.

= 1.10.0 =
* A complete guided tour that takes you to each screen in turn and shows every feature, plus a short tour for each feature.
* Settings → Features: switch any single part of the plugin off (and back on) without removing anything.
* Deleting the plugin now warns first, and points to the feature switches instead.
* The plugin's notices appear only on its own screens, never at the top of other pages.
* Fixed: saving one of the settings screens could reset the Main CSS and other settings to their defaults.

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
