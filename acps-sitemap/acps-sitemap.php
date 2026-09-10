<?php
/**
 * Plugin Name:       ACPS Sitemap
 * Plugin URI:        https://github.com/trentmontgomery222/coding-help
 * Description:       Single-site XML and HTML sitemap generator, fully managed from the WordPress admin. No multisite or network install required. Includes self-hosted updates with crash-safe recovery.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.0
 * Author:            ACPS
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acps-sitemap
 * Network:           false
 *
 * @package ACPS_Sitemap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'ACPS_SITEMAP_VERSION', '1.0.0' );
define( 'ACPS_SITEMAP_FILE', __FILE__ );
define( 'ACPS_SITEMAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACPS_SITEMAP_URL', plugin_dir_url( __FILE__ ) );
define( 'ACPS_SITEMAP_BASENAME', plugin_basename( __FILE__ ) );
define( 'ACPS_SITEMAP_REST_NAMESPACE', 'acps-sitemap/v1' );
define( 'ACPS_SITEMAP_SAFE_MODE_OPT', 'acps_sitemap_safe_mode' );

require_once ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap.php';

/**
 * Boot the plugin.
 *
 * @return ACPS_Sitemap
 */
function acps_sitemap() {
	return ACPS_Sitemap::instance();
}

/* ------------------------------------------------------------------------- *
 * Crash-safe bootstrap ("safe mode").
 *
 * The plugin is constructed on `plugins_loaded` inside a try/catch, with a
 * shutdown guard that only arms when a fatal happened INSIDE this plugin's
 * files. If a release ever fatals, the next request loads only a "Resume"
 * notice instead of the plugin, so a bad build can't white-screen the site.
 * ------------------------------------------------------------------------- */

/**
 * Whether the plugin is currently parked in safe mode.
 *
 * @return bool
 */
function acps_sitemap_is_safe_mode() {
	$state = get_option( ACPS_SITEMAP_SAFE_MODE_OPT );
	return is_array( $state ) && ! empty( $state );
}

/**
 * Record that the plugin fataled, parking it in safe mode.
 *
 * @param array $info Failure details.
 */
function acps_sitemap_arm_safe_mode( $info ) {
	update_option( ACPS_SITEMAP_SAFE_MODE_OPT, $info, false );
}

/**
 * Shutdown handler: park the plugin only if the last error was a fatal in one
 * of this plugin's own files.
 */
function acps_sitemap_shutdown_guard() {
	$err = error_get_last();
	if ( ! $err || ! in_array( $err['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_USER_ERROR ), true ) ) {
		return;
	}
	if ( empty( $err['file'] ) || 0 !== strpos( $err['file'], ACPS_SITEMAP_DIR ) ) {
		return; // Not our code — leave it alone.
	}
	acps_sitemap_arm_safe_mode(
		array(
			'msg'  => $err['message'],
			'file' => $err['file'],
			'line' => $err['line'],
			'time' => time(),
		)
	);
}

/**
 * Construct the plugin, guarded against fatals.
 */
function acps_sitemap_boot() {
	if ( acps_sitemap_is_safe_mode() ) {
		add_action( 'admin_notices', 'acps_sitemap_safe_mode_notice' );
		add_action( 'admin_post_acps_sitemap_resume', 'acps_sitemap_resume_from_safe_mode' );
		return;
	}

	register_shutdown_function( 'acps_sitemap_shutdown_guard' );

	try {
		acps_sitemap();
	} catch ( \Throwable $e ) {
		acps_sitemap_arm_safe_mode(
			array(
				'msg'  => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'time' => time(),
			)
		);
	}
}
add_action( 'plugins_loaded', 'acps_sitemap_boot' );

/**
 * Admin notice shown while the plugin is in safe mode, with a "Resume" button.
 */
function acps_sitemap_safe_mode_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$state = get_option( ACPS_SITEMAP_SAFE_MODE_OPT );
	$url   = wp_nonce_url( admin_url( 'admin-post.php?action=acps_sitemap_resume' ), 'acps_sitemap_resume' );

	echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'ACPS Sitemap is paused (safe mode).', 'acps-sitemap' ) . '</strong> '
		. esc_html__( 'It stopped itself after a fatal error so the rest of the site keeps working. Fix the problem, then resume it.', 'acps-sitemap' )
		. ' <a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Resume plugin', 'acps-sitemap' ) . '</a></p>';

	if ( is_array( $state ) && ! empty( $state['msg'] ) ) {
		$detail = $state['msg'];
		if ( ! empty( $state['file'] ) ) {
			$detail .= ' @ ' . $state['file'] . ':' . ( isset( $state['line'] ) ? $state['line'] : '?' );
		}
		echo '<p><code>' . esc_html( $detail ) . '</code></p>';
	}
	echo '</div>';
}

/**
 * Clear safe mode and return to the Plugins screen.
 */
function acps_sitemap_resume_from_safe_mode() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'acps-sitemap' ) );
	}
	check_admin_referer( 'acps_sitemap_resume' );
	delete_option( ACPS_SITEMAP_SAFE_MODE_OPT );
	wp_safe_redirect( admin_url( 'plugins.php' ) );
	exit;
}

/*
 * Activation / deactivation. Declared in the main file so WordPress can
 * register them before the plugin's classes load on the activation request.
 */
register_activation_hook( __FILE__, array( 'ACPS_Sitemap', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ACPS_Sitemap', 'deactivate' ) );
