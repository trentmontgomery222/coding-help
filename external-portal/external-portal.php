<?php
/**
 * Plugin Name:       External Portal
 * Plugin URI:        https://github.com/trentmontgomery222/coding-help
 * Description:       A self-contained front-end portal with its own users, authentication (OTP + optional password), sessions, per-user permissions, a unified content-update review queue, Google Calendar sharing management, and an extension API for other plugins. Fully independent of WordPress's user/login system. Single-site only.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ACPS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       external-portal
 *
 * ---------------------------------------------------------------------------
 * IMPORTANT: This plugin is SINGLE-SITE scoped by design (see spec Section 1).
 * It must NOT be network-activated. All settings live under a normal site's
 * Settings menu, never Network Admin. A guard below hard-stops network
 * activation so this constraint can't be violated by accident.
 * ---------------------------------------------------------------------------
 *
 * Decisions taken for the open questions in the planning spec (Section 8).
 * Each is configurable in Settings and documented so it can be revisited:
 *
 *   1. Calendar sharing changes  -> APPLY LIVE (spec's lean), always audit-logged.
 *                                   Toggle: "calendar_requires_approval" (default off).
 *   2. Page-edit granularity     -> WHOLE PAGE for v1. Grant target + queue payload
 *                                   are structured so field/block scoping can be
 *                                   added later without a schema change.
 *   3. Extension approval gate   -> REQUIRE ADMIN APPROVAL (recommended default).
 *                                   Registered extension menu items stay inert until
 *                                   an admin approves them on the Extensions screen.
 *   4. Session policy            -> Inactivity timeout + absolute lifetime, both
 *                                   configurable. An ARIA-live "session expiring"
 *                                   warning is emitted client-side.
 *   5. Password policy           -> Min length configurable (default 12). Reset flows
 *                                   through the OTP-email mechanism, mirroring login.
 *   6. Auditing scope            -> Logins (success/fail), permission changes, queue
 *                                   approvals/rejections, calendar ACL changes and
 *                                   session revocations are logged; viewable in admin.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// ---------------------------------------------------------------------------
// Constants.
// ---------------------------------------------------------------------------
define( 'EXP_VERSION', '0.1.0' );
define( 'EXP_DB_VERSION', 1 );
define( 'EXP_PLUGIN_FILE', __FILE__ );
define( 'EXP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Distinct cookie name — deliberately NOT a WordPress auth cookie (spec Section 3).
if ( ! defined( 'EXP_SESSION_COOKIE' ) ) {
	define( 'EXP_SESSION_COOKIE', 'exp_portal_session' );
}

// Option keys.
define( 'EXP_OPT_SETTINGS', 'exp_settings' );
define( 'EXP_OPT_DB_VERSION', 'exp_db_version' );

// ---------------------------------------------------------------------------
// Autoloader — maps EXP_Foo_Bar => includes[/modules]/class-exp-foo-bar.php
// ---------------------------------------------------------------------------
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'EXP_' ) ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$dirs = array(
			EXP_PLUGIN_DIR . 'includes/',
			EXP_PLUGIN_DIR . 'includes/modules/',
			EXP_PLUGIN_DIR . 'admin/',
			EXP_PLUGIN_DIR . 'frontend/',
		);
		foreach ( $dirs as $dir ) {
			if ( is_readable( $dir . $file ) ) {
				require_once $dir . $file;
				return;
			}
		}
	}
);

// ---------------------------------------------------------------------------
// Activation / deactivation / uninstall hooks.
// ---------------------------------------------------------------------------

/**
 * Activation guard + installer.
 *
 * @param bool $network_wide True when "Network Activate" was used.
 */
function exp_activate( $network_wide ) {
	if ( $network_wide ) {
		// Hard stop: this plugin must never be network-activated (spec Section 1 / 9).
		deactivate_plugins( EXP_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'External Portal is a single-site plugin and cannot be network-activated. Activate it on an individual site instead.', 'external-portal' ),
			esc_html__( 'Network activation blocked', 'external-portal' ),
			array( 'back_link' => true )
		);
	}
	EXP_Install::activate();
}
register_activation_hook( __FILE__, 'exp_activate' );

register_deactivation_hook(
	__FILE__,
	static function () {
		EXP_Install::deactivate();
	}
);

// ---------------------------------------------------------------------------
// Boot.
// ---------------------------------------------------------------------------

/**
 * Retrieve the shared plugin instance.
 *
 * @return EXP_Plugin
 */
function external_portal() {
	return EXP_Plugin::instance();
}

// Refuse to run in a network-activated state even if forced on (belt and braces).
add_action(
	'plugins_loaded',
	static function () {
		// Run the DB migration check on load in case the plugin was updated.
		EXP_Install::maybe_upgrade();
		external_portal()->boot();
	},
	5
);
