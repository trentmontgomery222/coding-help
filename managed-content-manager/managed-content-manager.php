<?php
/**
 * Plugin Name:       Managed Content Manager
 * Plugin URI:        https://github.com/trentmontgomery222/coding-help
 * Description:       Let non-WordPress users edit specific, pre-defined pieces of page content through a separate, restricted front-end portal with their own logins. Single-site only (not multisite / not network aware).
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ACPS
 * License:           GPL-2.0-or-later
 * Text Domain:       mcm
 *
 * ---------------------------------------------------------------------------
 * PROOF OF CONCEPT
 * ---------------------------------------------------------------------------
 * This plugin intentionally keeps its own user accounts and sessions in
 * custom database tables so that "content editors" never touch the real
 * WordPress user table or wp-admin. Admins define which blocks of content
 * exist and which editor may touch which block; editors log in through a
 * front-end page and get a locked-down UI that only exposes the fields you
 * allow, formatted the way you allow.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define( 'MCM_VERSION', '0.1.0' );
define( 'MCM_FILE', __FILE__ );
define( 'MCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCM_URL', plugin_dir_url( __FILE__ ) );
define( 'MCM_SESSION_COOKIE', 'mcm_editor_session' );
define( 'MCM_DB_VERSION', '1' );

// ---------------------------------------------------------------------------
// Includes
// ---------------------------------------------------------------------------
require_once MCM_DIR . 'includes/class-mcm-db.php';
require_once MCM_DIR . 'includes/class-mcm-auth.php';
require_once MCM_DIR . 'includes/class-mcm-admin.php';
require_once MCM_DIR . 'includes/class-mcm-portal.php';

/**
 * Single-site guard. This plugin is deliberately NOT built for multisite /
 * network activation. If someone network-activates it we bail loudly rather
 * than create tables in the wrong place.
 */
function mcm_is_multisite_network_activated() {
	if ( ! is_multisite() ) {
		return false;
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active_for_network( plugin_basename( MCM_FILE ) );
}

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------
register_activation_hook(
	MCM_FILE,
	function ( $network_wide ) {
		if ( $network_wide ) {
			// Refuse network-wide activation.
			deactivate_plugins( plugin_basename( MCM_FILE ) );
			wp_die(
				esc_html__( 'Managed Content Manager is a single-site plugin and cannot be network activated. Activate it on the individual site instead.', 'mcm' ),
				esc_html__( 'Network activation not supported', 'mcm' ),
				array( 'back_link' => true )
			);
		}
		MCM_DB::install();
		add_option( 'mcm_db_version', MCM_DB_VERSION );

		// Sensible defaults.
		add_option(
			'mcm_settings',
			array(
				'portal_page_id'   => 0,
				'session_lifetime' => 8, // hours
				'max_login_fails'  => 5,
				'lockout_minutes'  => 15,
			)
		);
	}
);

register_deactivation_hook(
	MCM_FILE,
	function () {
		// Housekeeping only. Data + tables are removed in uninstall.php.
		wp_clear_scheduled_hook( 'mcm_gc_sessions' );
	}
);

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------
add_action(
	'plugins_loaded',
	function () {
		if ( mcm_is_multisite_network_activated() ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'Managed Content Manager is running network-activated. This plugin only supports single-site activation.', 'mcm' ) .
						'</p></div>';
				}
			);
			return;
		}

		// Run any pending DB upgrades.
		if ( get_option( 'mcm_db_version' ) !== MCM_DB_VERSION ) {
			MCM_DB::install();
			update_option( 'mcm_db_version', MCM_DB_VERSION );
		}

		MCM_Auth::instance();
		MCM_Portal::instance();

		if ( is_admin() ) {
			MCM_Admin::instance();
		}
	}
);

/**
 * Convenience accessor used by the shortcode + portal.
 *
 * @param array $settings_override Not used; reserved.
 * @return array
 */
function mcm_get_settings() {
	$defaults = array(
		'portal_page_id'   => 0,
		'session_lifetime' => 8,
		'max_login_fails'  => 5,
		'lockout_minutes'  => 15,
	);
	$settings = get_option( 'mcm_settings', array() );
	return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
}
