<?php
/**
 * Plugin Name:       Cayden Form Manager
 * Plugin URI:        https://acpsmd.org/
 * Description:        First-party page-journey analytics, an accessible feedback system, and a Google-Forms-replacement form builder — one engine, WCAG 2.2 AA / Section 508 throughout. Built to run behind aggressive edge caching (WP Engine Global Edge Security).
 * Version:           1.37.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            ACPS
 * Author URI:        https://acpsmd.org/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acps-site-toolkit
 * Domain Path:       /languages
 *
 * This is a SINGLE-SITE plugin. It intentionally contains no multisite support
 * (no blog_id columns, no switch_to_blog(), no Network Admin, no manage_network
 * capability checks). See the build spec, section 1.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------------
 */
define( 'ACPS_ST_VERSION', '1.37.0' );

// The DB schema version. Bumped whenever the table structure changes so that
// upgrades apply on load without a deactivate/reactivate cycle (spec §3, §11).
define( 'ACPS_ST_SCHEMA_VERSION', '1.3.1' );

define( 'ACPS_ST_FILE', __FILE__ );
define( 'ACPS_ST_BASENAME', plugin_basename( __FILE__ ) );
define( 'ACPS_ST_PATH', plugin_dir_path( __FILE__ ) );
define( 'ACPS_ST_URL', plugin_dir_url( __FILE__ ) );
define( 'ACPS_ST_REST_NAMESPACE', 'acps-st/v1' );

// Option keys.
define( 'ACPS_ST_OPT_SETTINGS', 'acps_st_settings' );
define( 'ACPS_ST_OPT_SCHEMA', 'acps_st_schema_version' );

/*
 * ---------------------------------------------------------------------------
 * Autoloader
 * ---------------------------------------------------------------------------
 *
 * Lightweight PSR-4-style autoloader for the ACPS\SiteToolkit namespace. Class
 * "ACPS\SiteToolkit\Foo_Bar" resolves to includes/class-foo-bar.php; classes
 * under the Admin sub-namespace resolve to includes/admin/.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'ACPS\\SiteToolkit\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', '/', $relative );

		// Split into directory + class name so we can lowercase the file name
		// (WordPress "class-foo-bar.php") without touching the directory.
		$parts      = explode( '/', $relative );
		$class_name = array_pop( $parts );
		$subdir     = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';

		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		$path      = ACPS_ST_PATH . 'includes/' . $subdir . $file_name;

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * Activation / Deactivation
 * ---------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Deactivator', 'deactivate' ) );

/*
 * ---------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------------
 */
/**
 * Return the shared plugin instance.
 *
 * @return Plugin
 */
function plugin() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new Plugin();
	}
	return $instance;
}

// Option holding "safe mode" state after a fatal was caught in our own code.
define( 'ACPS_ST_SAFE_MODE_OPT', 'acps_st_safe_mode' );

/**
 * Is the plugin currently held in safe mode (dormant after a caught fatal)?
 *
 * @return bool
 */
function is_safe_mode() {
	$s = get_option( ACPS_ST_SAFE_MODE_OPT );
	return is_array( $s ) && ! empty( $s['time'] );
}

/**
 * Record a caught fatal and arm safe mode so the NEXT request keeps the site up
 * by not loading the plugin's functional code.
 *
 * @param string $msg  Error message.
 * @param string $file File.
 * @param int    $line Line.
 */
function arm_safe_mode( $msg, $file = '', $line = 0 ) {
	update_option(
		ACPS_ST_SAFE_MODE_OPT,
		array(
			'msg'  => (string) $msg,
			'file' => (string) $file,
			'line' => (int) $line,
			'time' => time(),
		),
		true
	);
	if ( function_exists( 'error_log' ) ) {
		error_log( '[Cayden Form Manager] Fatal caught — entering safe mode: ' . $msg . ' in ' . $file . ':' . $line ); // phpcs:ignore
	}
}

/**
 * Shutdown guard: if the request is ending on a fatal that originated inside
 * this plugin's files, arm safe mode so subsequent requests stay up. It can't
 * rescue the current request (PHP is already ending), but it stops a crash loop.
 */
function shutdown_guard() {
	$e = error_get_last();
	if ( ! $e || empty( $e['type'] ) ) {
		return;
	}
	$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
	if ( ! in_array( $e['type'], $fatal_types, true ) ) {
		return;
	}
	if ( empty( $e['file'] ) || 0 !== strpos( $e['file'], ACPS_ST_PATH ) ) {
		return; // Not our fault — leave it alone.
	}
	arm_safe_mode( $e['message'], $e['file'], $e['line'] );
}

/**
 * Admin notice + resume control shown while dormant in safe mode.
 */
function safe_mode_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$s   = get_option( ACPS_ST_SAFE_MODE_OPT );
	$msg = is_array( $s ) && ! empty( $s['msg'] ) ? $s['msg'] : '';
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=acps_st_resume' ), 'acps_st_resume' );
	echo '<div class="notice notice-error"><p><strong>'
		. esc_html__( 'Cayden Form Manager is paused (safe mode).', 'acps-site-toolkit' )
		. '</strong> '
		. esc_html__( 'A fatal error was caught in the plugin, so it stopped loading to keep the site online. The rest of the site is unaffected.', 'acps-site-toolkit' )
		. '</p>'
		. ( $msg ? '<p><code>' . esc_html( $msg ) . '</code></p>' : '' )
		. '<p><a href="' . esc_url( $url ) . '" class="button button-primary">'
		. esc_html__( 'Resume plugin', 'acps-site-toolkit' )
		. '</a> '
		. esc_html__( 'Use this once the problem is fixed (e.g. after an update).', 'acps-site-toolkit' )
		. '</p></div>';
}

/**
 * Clear safe mode (admin action).
 */
function resume_from_safe_mode() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'acps-site-toolkit' ), 403 );
	}
	check_admin_referer( 'acps_st_resume' );
	delete_option( ACPS_ST_SAFE_MODE_OPT );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}

/**
 * Boot the plugin with crash protection. A caught fatal during boot arms safe
 * mode instead of white-screening the site; while in safe mode only a small
 * admin notice + resume control load.
 */
function boot() {
	// Always allow resuming, even while dormant.
	add_action( 'admin_post_acps_st_resume', __NAMESPACE__ . '\\resume_from_safe_mode' );

	if ( is_safe_mode() ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\safe_mode_notice' );
		}
		return; // Stay dormant — keep the site up.
	}

	// Catch a fatal that happens later in the request (in a hook callback) so
	// the following requests fall into safe mode instead of crashing repeatedly.
	register_shutdown_function( __NAMESPACE__ . '\\shutdown_guard' );

	try {
		plugin();
	} catch ( \Throwable $e ) {
		// A throwable during boot (hook registration, schema, etc.) — don't let
		// it propagate and take down the request.
		arm_safe_mode( $e->getMessage(), $e->getFile(), $e->getLine() );
	}
}

// Kick everything off once all plugins are loaded.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );
