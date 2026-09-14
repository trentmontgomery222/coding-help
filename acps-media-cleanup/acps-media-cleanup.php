<?php
/**
 * Plugin Name:       ACPS Unused Media Cleanup
 * Plugin URI:        https://acpsmd.org/
 * Description:        Safely find and remove media library files (images, PDFs, documents, videos) that are not used anywhere on the site. Works with FileBird folders and Beaver Builder. Single-site only. Trash first, restore anytime.
 * Version:           1.16.0
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

define( 'ACPS_MC_VERSION', '1.16.0' );
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

// Option holding "safe mode" state after a fatal was caught in our own code.
define( 'ACPS_MC_SAFE_MODE_OPT', 'acps_mc_safe_mode' );

/**
 * Log a plugin problem without ever throwing. Only writes when WP_DEBUG is on.
 *
 * @param string $message Message to log.
 */
function acps_mc_log( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
		error_log( '[ACPS Media Cleanup] ' . (string) $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/* ===========================================================================
 * FAILSAFE LAYER 1 — fatal-error "safe mode"
 *
 * If a fatal error ever originates in this plugin's files, a shutdown guard
 * flips a "safe mode" flag. Every following request then loads ONLY a small
 * "Resume plugin" admin notice and nothing else, so a fatal (even a parse error
 * in one of our own files, or a bad update) can never white-screen the site on
 * more than the single request that caused it. The guard is registered as early
 * as possible — before the class files are even included — so include-time
 * fatals are covered too.
 * ======================================================================== */

/**
 * Is the plugin currently held in safe mode (dormant after a caught fatal)?
 *
 * @return bool
 */
function acps_mc_is_safe_mode() {
	$s = get_option( ACPS_MC_SAFE_MODE_OPT );
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
function acps_mc_arm_safe_mode( $msg, $file = '', $line = 0 ) {
	update_option(
		ACPS_MC_SAFE_MODE_OPT,
		array(
			'msg'  => (string) $msg,
			'file' => (string) $file,
			'line' => (int) $line,
			'time' => time(),
		),
		true
	);
	acps_mc_log( 'Fatal caught — entering safe mode: ' . $msg . ' in ' . $file . ':' . $line );
}

/**
 * Shutdown guard: if the request is ending on a fatal that originated inside
 * this plugin's files, arm safe mode so subsequent requests stay up. It can't
 * rescue the current request (PHP is already ending), but it stops a crash loop.
 */
function acps_mc_shutdown_guard() {
	$e = error_get_last();
	if ( ! $e || empty( $e['type'] ) ) {
		return;
	}
	$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
	if ( ! in_array( $e['type'], $fatal_types, true ) ) {
		return;
	}
	if ( empty( $e['file'] ) || 0 !== strpos( $e['file'], ACPS_MC_DIR ) ) {
		return; // Not our fault — leave it alone.
	}
	acps_mc_arm_safe_mode( $e['message'], $e['file'], $e['line'] );
}

/**
 * Admin notice + resume control shown while dormant in safe mode.
 */
function acps_mc_safe_mode_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$s   = get_option( ACPS_MC_SAFE_MODE_OPT );
	$msg = is_array( $s ) && ! empty( $s['msg'] ) ? $s['msg'] : '';
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=acps_mc_resume' ), 'acps_mc_resume' );
	echo '<div class="notice notice-error"><p><strong>'
		. esc_html__( 'ACPS Unused Media Cleanup is paused (safe mode).', 'acps-media-cleanup' )
		. '</strong> '
		. esc_html__( 'A fatal error was caught in the plugin, so it stopped loading to keep the site online. The rest of the site is unaffected.', 'acps-media-cleanup' )
		. '</p>'
		. ( $msg ? '<p><code>' . esc_html( $msg ) . '</code></p>' : '' )
		. '<p><a href="' . esc_url( $url ) . '" class="button button-primary">'
		. esc_html__( 'Resume plugin', 'acps-media-cleanup' )
		. '</a> '
		. esc_html__( 'Use this once the problem is fixed (e.g. after an update).', 'acps-media-cleanup' )
		. '</p></div>';
}

/**
 * Clear safe mode (admin action).
 */
function acps_mc_resume_from_safe_mode() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'acps-media-cleanup' ), 403 );
	}
	check_admin_referer( 'acps_mc_resume' );
	delete_option( ACPS_MC_SAFE_MODE_OPT );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}

// Register the shutdown guard NOW — before including any class files — so a
// fatal during the includes below (e.g. a parse error in a partially-uploaded
// file) still arms safe mode for the next request.
if ( function_exists( 'register_shutdown_function' ) ) {
	register_shutdown_function( 'acps_mc_shutdown_guard' );
}

/* ===========================================================================
 * FAILSAFE LAYER 2 — file integrity
 *
 * The plugin's class files and the class each one defines. Used to load them
 * defensively (existence check + include_once, never a bare require) and to
 * detect any that are missing after an incomplete upload.
 * ======================================================================== */

/**
 * @return array<string,string> relative path => class name.
 */
function acps_mc_class_map() {
	return array(
		'includes/class-acps-mc-settings.php'     => 'ACPS_MC_Settings',
		'includes/class-acps-mc-logger.php'       => 'ACPS_MC_Logger',
		'includes/class-acps-mc-folders.php'      => 'ACPS_MC_Folders',
		'includes/class-acps-mc-scanner.php'      => 'ACPS_MC_Scanner',
		'includes/class-acps-mc-usage.php'        => 'ACPS_MC_Usage',
		'includes/class-acps-mc-deleter.php'      => 'ACPS_MC_Deleter',
		'includes/class-acps-mc-admin.php'        => 'ACPS_MC_Admin',
		'includes/class-acps-mc-ajax.php'         => 'ACPS_MC_Ajax',
		'includes/class-acps-mc-manager.php'      => 'ACPS_MC_Manager',
		'includes/class-acps-mc-manager-ajax.php' => 'ACPS_MC_Manager_Ajax',
		'includes/class-acps-mc-heic.php'         => 'ACPS_MC_Heic',
		'includes/class-acps-mc-duplicates.php'   => 'ACPS_MC_Duplicates',
		'includes/class-acps-mc-drive.php'        => 'ACPS_MC_Drive',
		'includes/class-acps-mc-cron.php'         => 'ACPS_MC_Cron',
		'includes/class-acps-mc-updater.php'      => 'ACPS_MC_Updater',
		'includes/class-acps-mc-remote-api.php'   => 'ACPS_MC_Remote_Api',
	);
}

/**
 * Front-end / admin asset files the plugin needs. Missing ones do NOT crash the
 * site (they only degrade a screen), but they are reported so an incomplete
 * upload is obvious.
 *
 * @return array<string> relative paths.
 */
function acps_mc_asset_map() {
	return array(
		'assets/manager.js',
		'assets/manager.css',
		'assets/admin.css',
		'assets/modal.js',
		'assets/modal.css',
	);
}

/**
 * Include every class file that is present, recording any whose class does not
 * end up defined. Safe to call more than once (include_once). Populates
 * $GLOBALS['acps_mc_missing_files'].
 *
 * @return array Missing class-file relative paths.
 */
function acps_mc_load_classes() {
	$missing = array();
	foreach ( acps_mc_class_map() as $rel => $class ) {
		$path = ACPS_MC_DIR . $rel;
		if ( is_readable( $path ) ) {
			try {
				include_once $path;
			} catch ( \Throwable $e ) {
				acps_mc_log( 'Failed loading ' . $rel . ': ' . $e->getMessage() );
			}
		}
		if ( ! class_exists( $class ) ) {
			$missing[] = $rel;
		}
	}
	$GLOBALS['acps_mc_missing_files'] = $missing;
	return $missing;
}

/**
 * Any expected asset files that are missing from disk.
 *
 * @return array Missing asset relative paths.
 */
function acps_mc_missing_assets() {
	$missing = array();
	foreach ( acps_mc_asset_map() as $rel ) {
		if ( ! is_readable( ACPS_MC_DIR . $rel ) ) {
			$missing[] = $rel;
		}
	}
	return $missing;
}

/**
 * Admin notice if any of the plugin's files did not load (e.g. an incomplete
 * upload). The site is not crashed; the affected features are simply disabled.
 */
function acps_mc_missing_files_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$missing_classes = isset( $GLOBALS['acps_mc_missing_files'] ) ? (array) $GLOBALS['acps_mc_missing_files'] : array();
	$missing_assets  = acps_mc_missing_assets();
	if ( empty( $missing_classes ) && empty( $missing_assets ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'ACPS Unused Media Cleanup', 'acps-media-cleanup' ) . ':</strong> ' .
		esc_html__( 'some of the plugin’s files are missing, so those features were safely disabled to protect your site (nothing has crashed). Please re-upload the complete plugin folder.', 'acps-media-cleanup' ) . '</p>';
	if ( ! empty( $missing_classes ) ) {
		echo '<p>' . esc_html__( 'Missing program files (features disabled):', 'acps-media-cleanup' ) . '</p><ul style="list-style:disc;margin-left:22px;">';
		foreach ( $missing_classes as $m ) {
			echo '<li><code>' . esc_html( (string) $m ) . '</code></li>';
		}
		echo '</ul>';
	}
	if ( ! empty( $missing_assets ) ) {
		echo '<p>' . esc_html__( 'Missing interface files (some screens may look or behave wrong until re-uploaded):', 'acps-media-cleanup' ) . '</p><ul style="list-style:disc;margin-left:22px;">';
		foreach ( $missing_assets as $m ) {
			echo '<li><code>' . esc_html( (string) $m ) . '</code></li>';
		}
		echo '</ul>';
	}
	echo '</div>';
}

/* ===========================================================================
 * Activation / deactivation — always registered, guarded so a missing class or
 * a runtime error can never fatal the (de)activation request.
 * ======================================================================== */

/**
 * Activation: create tables, seed defaults, seed the force-update secret. Clears
 * safe mode (a deliberate re-activation is a "I fixed it" signal) and makes sure
 * the class files are loaded even if this request booted while dormant.
 */
function acps_mc_activate() {
	try {
		delete_option( ACPS_MC_SAFE_MODE_OPT );
		acps_mc_load_classes();

		if ( class_exists( 'ACPS_MC_Settings' ) ) {
			ACPS_MC_Settings::install_defaults();
		}
		if ( class_exists( 'ACPS_MC_Logger' ) ) {
			ACPS_MC_Logger::install_table();
		}
		if ( class_exists( 'ACPS_MC_Scanner' ) ) {
			ACPS_MC_Scanner::install_index_table();
		}
		if ( class_exists( 'ACPS_MC_Settings' ) && class_exists( 'ACPS_MC_Cron' ) && ACPS_MC_Settings::get( 'auto_nightly_scan' ) ) {
			ACPS_MC_Cron::schedule();
		}
		// Seed the secrets that guard the hidden URLs (force-update + remote API),
		// once each. Only generated if not already present, so re-activation never
		// rotates them.
		if ( class_exists( 'ACPS_MC_Settings' ) ) {
			$acps_mc_opts = get_option( ACPS_MC_OPT_SETTINGS, array() );
			if ( ! is_array( $acps_mc_opts ) ) {
				$acps_mc_opts = array();
			}
			$acps_mc_changed = false;
			if ( empty( $acps_mc_opts['update_trigger'] ) ) {
				$acps_mc_opts['update_trigger'] = wp_generate_password( 40, false, false );
				$acps_mc_changed                = true;
			}
			if ( empty( $acps_mc_opts['remote_api_key'] ) ) {
				$acps_mc_opts['remote_api_key'] = wp_generate_password( 48, false, false );
				$acps_mc_changed                = true;
			}
			if ( $acps_mc_changed ) {
				update_option( ACPS_MC_OPT_SETTINGS, $acps_mc_opts );
			}
		}
		add_option( 'acps_media_cleanup_activated', time() );
	} catch ( \Throwable $e ) {
		acps_mc_log( 'Activation error: ' . $e->getMessage() );
	}
}
register_activation_hook( __FILE__, 'acps_mc_activate' );

/**
 * Deactivation: only clear transient scan state. Settings, results and the
 * audit log are preserved so nothing the admin cares about is lost.
 */
function acps_mc_deactivate() {
	try {
		delete_transient( ACPS_MC_TRANSIENT_INDEX );
		if ( class_exists( 'ACPS_MC_Cron' ) ) {
			ACPS_MC_Cron::unschedule();
			wp_clear_scheduled_hook( ACPS_MC_Cron::CONTINUE_HOOK );
		}
		if ( class_exists( 'ACPS_MC_Drive' ) ) {
			ACPS_MC_Drive::unschedule();
		}
	} catch ( \Throwable $e ) {
		acps_mc_log( 'Deactivation error: ' . $e->getMessage() );
	}
}
register_deactivation_hook( __FILE__, 'acps_mc_deactivate' );

// The "Resume plugin" control must work in every state, including while dormant.
add_action( 'admin_post_acps_mc_resume', 'acps_mc_resume_from_safe_mode' );

/* ===========================================================================
 * Orchestration.
 *
 * In safe mode: load NOTHING but the resume notice, and stop. This is what makes
 * even a persistent fatal (e.g. a parse error that would recur on every include)
 * safe — the broken code is never loaded again until the admin clicks "Resume".
 * ======================================================================== */
if ( acps_mc_is_safe_mode() ) {
	if ( is_admin() ) {
		add_action( 'admin_notices', 'acps_mc_safe_mode_notice' );
	}
	return; // Top-level return: stop loading the plugin, keep the site up.
}

acps_mc_load_classes();
add_action( 'admin_notices', 'acps_mc_missing_files_notice' );

/**
 * Boot the plugin once all plugins are loaded (so folder plugins such as
 * FileBird have registered their tables/taxonomies first). Every instantiation
 * is guarded by class_exists() and the whole thing is wrapped in try/catch so a
 * missing file or a runtime error disables a feature instead of the whole site;
 * a caught throwable also arms safe mode for the next request.
 */
function acps_mc_boot() {
	try {
		load_plugin_textdomain( 'acps-media-cleanup', false, dirname( ACPS_MC_BASENAME ) . '/languages' );

		// Foundational classes used across the whole plugin. If any is missing
		// (incomplete upload), don't wire up ANY feature — otherwise other classes
		// would call a class that isn't there and could fatal in a hook. The admin
		// notice already tells the user to re-upload; the site stays up.
		foreach ( array( 'ACPS_MC_Settings', 'ACPS_MC_Folders', 'ACPS_MC_Logger' ) as $acps_mc_core ) {
			if ( ! class_exists( $acps_mc_core ) ) {
				acps_mc_log( 'Foundational class missing (' . $acps_mc_core . '); features disabled until re-upload.' );
				return;
			}
		}

		// Runs in every context (cron ticks and REST/AJAX uploads have no is_admin()).
		if ( class_exists( 'ACPS_MC_Cron' ) ) {
			new ACPS_MC_Cron();
		}
		if ( class_exists( 'ACPS_MC_Heic' ) ) {
			new ACPS_MC_Heic();
		}
		if ( class_exists( 'ACPS_MC_Duplicates' ) ) {
			new ACPS_MC_Duplicates();
		}
		if ( class_exists( 'ACPS_MC_Drive' ) ) {
			new ACPS_MC_Drive();
		}
		// Self-hosted updater — runs in every context (the force-update URL, the
		// crash-test self-test responder and the REST status route are not admin).
		// register() is a no-op unless updates are turned on in Settings.
		if ( class_exists( 'ACPS_MC_Updater' ) ) {
			$acps_mc_updater = new ACPS_MC_Updater();
			$acps_mc_updater->register();
		}
		// Hidden remote photo API — REST routes are not admin-context. register()
		// is a no-op unless the remote API is turned on in its hidden settings.
		if ( class_exists( 'ACPS_MC_Remote_Api' ) ) {
			$acps_mc_remote = new ACPS_MC_Remote_Api();
			$acps_mc_remote->register();
		}

		if ( is_admin() ) {
			$admin = class_exists( 'ACPS_MC_Admin' ) ? new ACPS_MC_Admin() : null;
			if ( class_exists( 'ACPS_MC_Ajax' ) ) {
				new ACPS_MC_Ajax();
			}
			if ( $admin && class_exists( 'ACPS_MC_Manager' ) ) {
				new ACPS_MC_Manager( $admin );
			}
			if ( class_exists( 'ACPS_MC_Manager_Ajax' ) ) {
				new ACPS_MC_Manager_Ajax();
			}
		}
	} catch ( \Throwable $e ) {
		// A throwable during boot (hook registration, etc.) — don't let it
		// propagate and take down the request, and arm safe mode so the NEXT
		// request loads only the resume notice instead of crashing again.
		acps_mc_log( 'Boot error: ' . $e->getMessage() );
		acps_mc_arm_safe_mode( $e->getMessage(), $e->getFile(), $e->getLine() );
	}
}
add_action( 'plugins_loaded', 'acps_mc_boot' );
