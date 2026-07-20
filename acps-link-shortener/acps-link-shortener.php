<?php
/**
 * Plugin Name:       ACPS Link Shortener
 * Plugin URI:        https://acpsmd.org/
 * Description:       Self-hosted, branded URL shortener. Creates /link/{slug} redirects with click tracking, an accessible admin UI, and optional Google Sheet sync.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ACPS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acps-link-shortener
 *
 * @package ACPS_Link_Shortener
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core constants.
 *
 * ACPS_LS_SLUG_PREFIX is the single source of truth for the path segment used
 * in front of every short link (acpsmd.org/{prefix}/{slug}). Changing it here
 * (and re-flushing rewrite rules by re-activating) changes it everywhere.
 */
define( 'ACPS_LS_VERSION', '1.0.0' );
define( 'ACPS_LS_DB_VERSION', '1.0.0' );
define( 'ACPS_LS_SLUG_PREFIX', 'link' );
define( 'ACPS_LS_QUERY_VAR', 'acps_ls_slug' );
define( 'ACPS_LS_FILE', __FILE__ );
define( 'ACPS_LS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ACPS_LS_URL', plugin_dir_url( __FILE__ ) );
define( 'ACPS_LS_BASENAME', plugin_basename( __FILE__ ) );

// Option keys.
define( 'ACPS_LS_OPT_DB_VERSION', 'acps_ls_db_version' );
define( 'ACPS_LS_OPT_SETTINGS', 'acps_ls_settings' );

// WP-Cron hook + interval used for the Google Sheet sync.
define( 'ACPS_LS_CRON_HOOK', 'acps_ls_sheet_sync' );
define( 'ACPS_LS_CRON_INTERVAL', 'acps_ls_three_minutes' );

/**
 * Load plugin classes.
 */
require_once ACPS_LS_PATH . 'includes/class-acps-ls-install.php';
require_once ACPS_LS_PATH . 'includes/class-acps-ls-db.php';
require_once ACPS_LS_PATH . 'includes/class-acps-ls-rewrite.php';
require_once ACPS_LS_PATH . 'includes/class-acps-ls-redirect.php';
require_once ACPS_LS_PATH . 'includes/class-acps-ls-sync.php';

/**
 * Return the capability required to manage links.
 *
 * Defaults to `manage_options` (a site administrator). Filterable so a site can
 * grant a custom role instead.
 *
 * @return string
 */
function acps_ls_manage_capability() {
	return apply_filters( 'acps_ls_manage_capability', 'manage_options' );
}

/**
 * Fully-qualified name of the links table.
 *
 * @return string
 */
function acps_ls_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'acps_links';
}

/**
 * Activation: build the table, seed options, flush rewrite rules, schedule cron.
 *
 * Rewrite rules are flushed here ONLY (never on every load).
 */
function acps_ls_activate() {
	ACPS_LS_Install::activate();
}
register_activation_hook( __FILE__, 'acps_ls_activate' );

/**
 * Deactivation: clear the scheduled sync. Data + table are preserved.
 */
function acps_ls_deactivate() {
	ACPS_LS_Install::deactivate();
}
register_deactivation_hook( __FILE__, 'acps_ls_deactivate' );

/**
 * Boot the runtime pieces on every request.
 */
function acps_ls_bootstrap() {
	// Run migrations if the stored DB version is behind the code.
	ACPS_LS_Install::maybe_upgrade();

	// Rewrite rule + query var so /link/{slug} routes to us.
	$rewrite = new ACPS_LS_Rewrite();
	$rewrite->register();

	// Redirect handler.
	$redirect = new ACPS_LS_Redirect();
	$redirect->register();

	// Google Sheet sync (WP-Cron).
	$sync = new ACPS_LS_Sync();
	$sync->register();

	// Admin UI; load it lazily.
	if ( is_admin() ) {
		require_once ACPS_LS_PATH . 'includes/class-acps-ls-admin.php';
		$admin = new ACPS_LS_Admin();
		$admin->register();
	}
}
add_action( 'plugins_loaded', 'acps_ls_bootstrap' );

/**
 * Custom cron schedule: every 3 minutes (for the Sheet sync).
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function acps_ls_cron_schedules( $schedules ) {
	$schedules[ ACPS_LS_CRON_INTERVAL ] = array(
		'interval' => 3 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 3 minutes (ACPS Link Shortener sync)', 'acps-link-shortener' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'acps_ls_cron_schedules' );
