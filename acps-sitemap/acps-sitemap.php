<?php
/**
 * Plugin Name:       ACPS Sitemap
 * Plugin URI:        https://github.com/trentmontgomery222/coding-help
 * Description:       Single-site XML and HTML sitemap generator, fully managed from the WordPress admin. No multisite or network install required.
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

/* =========================================================================
 * FAILSAFE LAYER
 *
 * Goal: this plugin must never be able to take the whole site down.
 *
 * Three defenses, from outermost in:
 *   1. A shutdown guard registered BEFORE any file is loaded, so a fatal in
 *      one of this plugin's files parks it in "safe mode" for the next request
 *      instead of white-screening every page thereafter.
 *   2. A file-integrity manifest check that runs BEFORE any require(), so a
 *      missing file never triggers an uncatchable require() fatal at all.
 *   3. Construction wrapped in try/catch, plus per-callback try/catch on the
 *      public-facing hooks (see the component classes).
 *
 * All of the functions below are plain, unconditional top-level declarations,
 * so they are available even if loading later returns early.
 * ========================================================================= */

/**
 * The files this plugin must have to run. Checked before they are required.
 *
 * @return string[] Paths relative to the plugin directory.
 */
function acps_sitemap_required_files() {
	return array(
		'includes/class-acps-sitemap.php',
		'includes/class-acps-sitemap-xml.php',
		'includes/class-acps-sitemap-html.php',
		'includes/class-acps-sitemap-updater.php',
		'includes/class-acps-sitemap-admin.php',
	);
}

/**
 * Which required files are missing (readable) right now.
 *
 * @return string[] Relative paths that are absent or unreadable.
 */
function acps_sitemap_missing_files() {
	$missing = array();
	foreach ( acps_sitemap_required_files() as $rel ) {
		$path = ACPS_SITEMAP_DIR . $rel;
		if ( ! is_readable( $path ) ) {
			$missing[] = $rel;
		}
	}
	return $missing;
}

/**
 * Accessor to the running plugin instance.
 *
 * @return ACPS_Sitemap
 */
function acps_sitemap() {
	return ACPS_Sitemap::instance();
}

/**
 * Whether the plugin is currently parked in safe mode.
 *
 * @return bool
 */
function acps_sitemap_is_safe_mode() {
	if ( defined( 'ACPS_SITEMAP_FORCE_ACTIVE' ) && ACPS_SITEMAP_FORCE_ACTIVE ) {
		return false; // Emergency override for developers.
	}
	$state = get_option( ACPS_SITEMAP_SAFE_MODE_OPT );
	return is_array( $state ) && ! empty( $state );
}

/**
 * Record a failure and park the plugin in safe mode.
 *
 * @param array $info Failure details ('type' => 'fatal'|'files', plus context).
 */
function acps_sitemap_arm_safe_mode( $info ) {
	// Never let the guard itself throw.
	if ( function_exists( 'update_option' ) ) {
		update_option( ACPS_SITEMAP_SAFE_MODE_OPT, $info, false );
	}
}

/**
 * Shutdown handler: if the request ended on a fatal located inside this
 * plugin's own files, park the plugin so the NEXT request loads safe mode
 * instead of the (broken) plugin. Fatals belonging to other code are ignored.
 */
function acps_sitemap_shutdown_guard() {
	$err = error_get_last();
	if ( ! $err ) {
		return;
	}
	$fatal_types = array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
	if ( ! in_array( $err['type'], $fatal_types, true ) ) {
		return;
	}
	if ( empty( $err['file'] ) || 0 !== strpos( $err['file'], ACPS_SITEMAP_DIR ) ) {
		return; // Not our code — leave it alone.
	}
	acps_sitemap_arm_safe_mode(
		array(
			'type' => 'fatal',
			'msg'  => $err['message'],
			'file' => $err['file'],
			'line' => $err['line'],
			'time' => time(),
		)
	);
}

// Defense #1: register the guard before ANY plugin file is loaded.
register_shutdown_function( 'acps_sitemap_shutdown_guard' );

/**
 * Load and construct the plugin, guarded at every step.
 */
function acps_sitemap_boot() {
	// Already parked: load only the recovery notice, nothing else.
	if ( acps_sitemap_is_safe_mode() ) {
		add_action( 'admin_notices', 'acps_sitemap_safe_mode_notice' );
		return;
	}

	// Defense #2: verify every file exists before requiring anything. A missing
	// require() is an uncatchable fatal, so we simply never reach it.
	$missing = acps_sitemap_missing_files();
	if ( $missing ) {
		acps_sitemap_arm_safe_mode(
			array(
				'type'    => 'files',
				'missing' => $missing,
				'time'    => time(),
			)
		);
		add_action( 'admin_notices', 'acps_sitemap_safe_mode_notice' );
		return;
	}

	// Defense #3: construction inside try/catch. (Parse errors in a required
	// file are not catchable here, but the shutdown guard above catches those.)
	try {
		require_once ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap.php';
		if ( ! class_exists( 'ACPS_Sitemap' ) ) {
			throw new \RuntimeException( 'Core class ACPS_Sitemap not found after loading.' );
		}
		acps_sitemap();
	} catch ( \Throwable $e ) {
		acps_sitemap_arm_safe_mode(
			array(
				'type' => 'fatal',
				'msg'  => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'time' => time(),
			)
		);
		add_action( 'admin_notices', 'acps_sitemap_safe_mode_notice' );
	}
}
add_action( 'plugins_loaded', 'acps_sitemap_boot' );

// The resume handler is always available so the plugin can be recovered.
add_action( 'admin_post_acps_sitemap_resume', 'acps_sitemap_resume_from_safe_mode' );

/**
 * Admin notice shown while the plugin is in safe mode, with a "Resume" button.
 */
function acps_sitemap_safe_mode_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$state = get_option( ACPS_SITEMAP_SAFE_MODE_OPT );
	$type  = is_array( $state ) && ! empty( $state['type'] ) ? $state['type'] : 'fatal';
	$url   = wp_nonce_url( admin_url( 'admin-post.php?action=acps_sitemap_resume' ), 'acps_sitemap_resume' );

	echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'ACPS Sitemap is paused (safe mode).', 'acps-sitemap' ) . '</strong> ';

	if ( 'files' === $type ) {
		echo esc_html__( 'It will not load because one or more of its files are missing. Reinstall the plugin, then resume it. The rest of the site is unaffected.', 'acps-sitemap' );
	} else {
		echo esc_html__( 'It stopped itself after a fatal error so the rest of the site keeps working. Fix the problem, then resume it.', 'acps-sitemap' );
	}

	echo ' <a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Resume plugin', 'acps-sitemap' ) . '</a></p>';

	if ( 'files' === $type && ! empty( $state['missing'] ) && is_array( $state['missing'] ) ) {
		echo '<p><code>' . esc_html__( 'Missing files:', 'acps-sitemap' ) . ' ' . esc_html( implode( ', ', $state['missing'] ) ) . '</code></p>';
	} elseif ( ! empty( $state['msg'] ) ) {
		$detail = $state['msg'];
		if ( ! empty( $state['file'] ) ) {
			$detail .= ' @ ' . $state['file'] . ':' . ( isset( $state['line'] ) ? $state['line'] : '?' );
		}
		echo '<p><code>' . esc_html( $detail ) . '</code></p>';
	}
	echo '</div>';
}

/**
 * Clear safe mode and return to the Plugins screen. If the underlying problem
 * (e.g. missing files) is still present, the next request simply re-arms it.
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

/* -------------------------------------------------------------------------
 * Activation / deactivation, wrapped so a broken install fails cleanly
 * rather than fataling.
 * ------------------------------------------------------------------------- */

/**
 * Activation handler wrapper.
 *
 * @param bool $network_wide Whether activated network-wide.
 */
function acps_sitemap_activate( $network_wide = false ) {
	$missing = acps_sitemap_missing_files();
	if ( $missing ) {
		wp_die(
			esc_html__( 'ACPS Sitemap cannot be activated because some of its files are missing:', 'acps-sitemap' )
				. ' ' . esc_html( implode( ', ', $missing ) )
				. '. ' . esc_html__( 'Please reinstall the plugin.', 'acps-sitemap' ),
			esc_html__( 'ACPS Sitemap — incomplete install', 'acps-sitemap' ),
			array( 'back_link' => true )
		);
	}
	require_once ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap.php';
	ACPS_Sitemap::activate( $network_wide );
}

/**
 * Deactivation handler wrapper.
 */
function acps_sitemap_deactivate() {
	if ( is_readable( ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap.php' ) ) {
		require_once ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap.php';
	}
	if ( class_exists( 'ACPS_Sitemap' ) ) {
		ACPS_Sitemap::deactivate();
	} elseif ( function_exists( 'flush_rewrite_rules' ) ) {
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, 'acps_sitemap_activate' );
register_deactivation_hook( __FILE__, 'acps_sitemap_deactivate' );
