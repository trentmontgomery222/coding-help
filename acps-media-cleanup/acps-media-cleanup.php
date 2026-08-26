<?php
/**
 * Plugin Name:       ACPS Unused Media Cleanup
 * Plugin URI:        https://acpsmd.org/
 * Description:        Safely find and remove media library files (images, PDFs, documents, videos) that are not used anywhere on the site. Works with FileBird folders and Beaver Builder. Single-site only. Trash first, restore anytime.
 * Version:           1.6.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            ACPS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acps-media-cleanup
 *
 * This is a SINGLE-SITE plugin. It intentionally does not add any network /
 * multisite screens. Everything is managed from the normal wp-admin of the site
 * it is activated on.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'ACPS_MC_VERSION', '1.6.0' );
define( 'ACPS_MC_FILE', __FILE__ );
define( 'ACPS_MC_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACPS_MC_URL', plugin_dir_url( __FILE__ ) );
define( 'ACPS_MC_BASENAME', plugin_basename( __FILE__ ) );

// Shared option / transient / capability names.
define( 'ACPS_MC_OPT_SETTINGS', 'acps_media_cleanup_settings' );
define( 'ACPS_MC_OPT_RESULTS', 'acps_media_cleanup_results' );
define( 'ACPS_MC_OPT_SCANMETA', 'acps_media_cleanup_scan_meta' );
define( 'ACPS_MC_TRANSIENT_INDEX', 'acps_mc_usage_index' );
define( 'ACPS_MC_CAP', 'manage_options' );

require_once ACPS_MC_DIR . 'includes/class-acps-mc-settings.php';
require_once ACPS_MC_DIR . 'includes/class-acps-mc-logger.php';
require_once ACPS_MC_DIR . 'includes/class-acps-mc-folders.php';
require_once ACPS_MC_DIR . 'includes/class-acps-mc-scanner.php';
require_once ACPS_MC_DIR . 'includes/class-acps-mc-usage.php';
require_once ACPS_MC_DIR . 'includes/class-acps-mc-deleter.php';
require_once ACPS_MC_DIR . 'includes/class-acps-mc-admin.php';
require_once ACPS_MC_DIR . 'includes/class-acps-mc-ajax.php';
require_once ACPS_MC_DIR . 'includes/class-acps-mc-manager.php';
require_once ACPS_MC_DIR . 'includes/class-acps-mc-manager-ajax.php';
require_once ACPS_MC_DIR . 'includes/class-acps-mc-heic.php';
require_once ACPS_MC_DIR . 'includes/class-acps-mc-cron.php';

/**
 * Activation: create the audit-log table and seed default settings.
 */
function acps_mc_activate() {
	ACPS_MC_Settings::install_defaults();
	ACPS_MC_Logger::install_table();
	ACPS_MC_Scanner::install_index_table();
	if ( ACPS_MC_Settings::get( 'auto_nightly_scan' ) ) {
		ACPS_MC_Cron::schedule();
	}
	add_option( 'acps_media_cleanup_activated', time() );
}
register_activation_hook( __FILE__, 'acps_mc_activate' );

/**
 * Deactivation: only clear transient scan state. Settings, results and the
 * audit log are preserved so nothing the admin cares about is lost.
 */
function acps_mc_deactivate() {
	delete_transient( ACPS_MC_TRANSIENT_INDEX );
	ACPS_MC_Cron::unschedule();
	wp_clear_scheduled_hook( ACPS_MC_Cron::CONTINUE_HOOK );
}
register_deactivation_hook( __FILE__, 'acps_mc_deactivate' );

/**
 * Boot the plugin once all plugins are loaded (so folder plugins such as
 * FileBird have registered their tables/taxonomies first).
 */
function acps_mc_boot() {
	load_plugin_textdomain( 'acps-media-cleanup', false, dirname( ACPS_MC_BASENAME ) . '/languages' );

	// Runs in every context (cron ticks and REST/AJAX uploads have no is_admin()).
	new ACPS_MC_Cron();
	new ACPS_MC_Heic();

	if ( is_admin() ) {
		$admin = new ACPS_MC_Admin();
		new ACPS_MC_Ajax();
		new ACPS_MC_Manager( $admin );
		new ACPS_MC_Manager_Ajax();
	}
}
add_action( 'plugins_loaded', 'acps_mc_boot' );
