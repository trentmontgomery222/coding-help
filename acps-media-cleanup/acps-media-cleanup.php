<?php
/**
 * Plugin Name:       ACPS Unused Media Cleanup
 * Plugin URI:        https://acpsmd.org/
 * Description:        Safely find and remove media library files (images, PDFs, documents, videos) that are not used anywhere on the site. Works with FileBird folders and Beaver Builder. Single-site only. Trash first, restore anytime.
 * Version:           1.14.1
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

define( 'ACPS_MC_VERSION', '1.14.1' );
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

/**
 * Boot the plugin once all plugins are loaded (so folder plugins such as
 * FileBird have registered their tables/taxonomies first). Every instantiation
 * is guarded by class_exists() and the whole thing is wrapped in try/catch so a
 * missing file or a runtime error disables a feature instead of the whole site.
 */
function acps_mc_boot() {
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
		acps_mc_log( 'Boot error: ' . $e->getMessage() );
	}
}
add_action( 'plugins_loaded', 'acps_mc_boot' );
