<?php
/**
 * Plugin Name:       ACPS Unused Media Cleanup
 * Plugin URI:        https://acpsmd.org/
 * Description:        Safely find and remove media library files (images, PDFs, documents, videos) that are not used anywhere on the site. Works with FileBird folders and Beaver Builder. Single-site only. Trash first, restore anytime.
 * Version:           1.15.0
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

define( 'ACPS_MC_VERSION', '1.15.0' );
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

/**
 * The plugin's class files and the class each one defines. Used to load them
 * defensively and to detect any that are missing.
 *
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
	);
}

/*
 * Load the class files WITHOUT ever fataling the site if one is missing. A
 * partially-uploaded plugin (some files not transferred) is the classic cause of
 * a "white screen of death"; using an existence check + include_once (never a
 * bare require) turns that into, at worst, a dismissible admin notice while the
 * rest of the site keeps working. Any file whose class does not end up defined
 * is recorded so boot skips that feature and we can prompt for a re-upload.
 */
$GLOBALS['acps_mc_missing_files'] = array();
foreach ( acps_mc_class_map() as $acps_mc_rel => $acps_mc_class ) {
	$acps_mc_path = ACPS_MC_DIR . $acps_mc_rel;
	if ( is_readable( $acps_mc_path ) ) {
		try {
			include_once $acps_mc_path;
		} catch ( \Throwable $acps_mc_e ) {
			acps_mc_log( 'Failed loading ' . $acps_mc_rel . ': ' . $acps_mc_e->getMessage() );
		}
	}
	if ( ! class_exists( $acps_mc_class ) ) {
		$GLOBALS['acps_mc_missing_files'][] = $acps_mc_rel;
	}
}
unset( $acps_mc_rel, $acps_mc_class, $acps_mc_path );

/**
 * Admin notice if any of the plugin's files did not load (e.g. an incomplete
 * upload). The site is not crashed; the affected features are simply disabled.
 */
function acps_mc_missing_files_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$missing = isset( $GLOBALS['acps_mc_missing_files'] ) ? (array) $GLOBALS['acps_mc_missing_files'] : array();
	if ( empty( $missing ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'ACPS Unused Media Cleanup', 'acps-media-cleanup' ) . ':</strong> ' .
		esc_html__( 'some of the plugin’s files are missing, so those features were safely disabled to protect your site (nothing has crashed). Please re-upload the complete plugin folder. Missing files:', 'acps-media-cleanup' ) . '</p><ul style="list-style:disc;margin-left:22px;">';
	foreach ( $missing as $m ) {
		echo '<li><code>' . esc_html( (string) $m ) . '</code></li>';
	}
	echo '</ul></div>';
}
add_action( 'admin_notices', 'acps_mc_missing_files_notice' );

/**
 * Activation: create the audit-log table and seed default settings. Guarded so a
 * missing class can never fatal the activation request.
 */
function acps_mc_activate() {
	try {
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
		// Seed the force-update secret once (guards the secret update URL). Only
		// generated if it doesn't already exist, so re-activation never rotates it.
		if ( class_exists( 'ACPS_MC_Settings' ) ) {
			$acps_mc_opts = get_option( ACPS_MC_OPT_SETTINGS, array() );
			if ( ! is_array( $acps_mc_opts ) ) {
				$acps_mc_opts = array();
			}
			if ( empty( $acps_mc_opts['update_trigger'] ) ) {
				$acps_mc_opts['update_trigger'] = wp_generate_password( 40, false, false );
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

// Option holding "safe mode" state after a fatal was caught in our own code.
define( 'ACPS_MC_SAFE_MODE_OPT', 'acps_mc_safe_mode' );

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

/**
 * Boot the plugin once all plugins are loaded (so folder plugins such as
 * FileBird have registered their tables/taxonomies first). Every instantiation
 * is guarded by class_exists() and the whole thing is wrapped in try/catch so a
 * missing file or a runtime error disables a feature instead of the whole site.
 * On top of that, a caught fatal arms "safe mode" so the NEXT request keeps the
 * site up by loading only a small resume notice.
 */
function acps_mc_boot() {
	// Always allow resuming, even while dormant.
	add_action( 'admin_post_acps_mc_resume', 'acps_mc_resume_from_safe_mode' );

	if ( acps_mc_is_safe_mode() ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', 'acps_mc_safe_mode_notice' );
		}
		return; // Stay dormant — keep the site up.
	}

	// Catch a fatal that happens later in the request (in a hook callback) so
	// the following requests fall into safe mode instead of crashing repeatedly.
	if ( function_exists( 'register_shutdown_function' ) ) {
		register_shutdown_function( 'acps_mc_shutdown_guard' );
	}

	try {
		load_plugin_textdomain( 'acps-media-cleanup', false, dirname( ACPS_MC_BASENAME ) . '/languages' );

		// Foundational classes used across the whole plugin. If any is missing
		// (incomplete upload), don't wire up ANY feature — otherwise other classes
		// would call a class that isn't there and could fatal in a hook. The admin
		// notice above already tells the user to re-upload; the site stays up.
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
