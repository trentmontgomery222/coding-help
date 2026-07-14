<?php
/**
 * Plugin Name:       Drive Media Importer
 * Description:       Polls a Google Apps Script Web App for queued Google Drive images and imports them into the media library, with required alt text (WCAG 1.1.1). All traffic is outbound; no inbound endpoint is exposed. Configure under Settings → Drive Media Importer.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ACPS Web Team
 * License:           GPL-2.0-or-later
 * Text Domain:       drive-media-importer
 */

defined( 'ABSPATH' ) || exit;

define( 'DMI_VERSION', '1.1.0' );
define( 'DMI_PLUGIN_FILE', __FILE__ );
define( 'DMI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DMI_CRON_HOOK', 'dmi_poll_queue' );
define( 'DMI_LOCK_TRANSIENT', 'dmi_poll_lock' );

require_once DMI_PLUGIN_DIR . 'includes/class-dmi-settings.php';
require_once DMI_PLUGIN_DIR . 'includes/class-dmi-client.php';
require_once DMI_PLUGIN_DIR . 'includes/class-dmi-importer.php';
require_once DMI_PLUGIN_DIR . 'includes/class-dmi-poller.php';

DMI_Settings::init();

/**
 * Custom 5-minute cron interval.
 */
add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['dmi_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 5 minutes (Drive Media Importer)', 'drive-media-importer' ),
	);
	return $schedules;
} );

/**
 * Register the recurring poll event. This plugin is activated on ONE site
 * (activate it only on the main site of the network); that single site is
 * the poller, so there is exactly one poller per network.
 */
function dmi_maybe_schedule_cron() {
	if ( ! wp_next_scheduled( DMI_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'dmi_five_minutes', DMI_CRON_HOOK );
	}
}
add_action( 'init', 'dmi_maybe_schedule_cron' );

register_activation_hook( __FILE__, 'dmi_maybe_schedule_cron' );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( DMI_CRON_HOOK );
} );

/**
 * The poll callback.
 */
add_action( DMI_CRON_HOOK, function () {
	DMI_Poller::run();
} );

/**
 * WP-CLI: `wp dmi poll` runs one poll cycle immediately.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'dmi poll', function () {
		$summary = DMI_Poller::run( true );
		WP_CLI::log( $summary );
	} );
}
