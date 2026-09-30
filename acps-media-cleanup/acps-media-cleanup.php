<?php
/**
 * Plugin Name:       ACPS Unused Media Cleanup
 * Plugin URI:        https://acpsmd.org/
 * Description:        Safely find and remove media library files (images, PDFs, documents, videos) that are not used anywhere on the site. Works with FileBird folders and Beaver Builder. Single-site only. Trash first, restore anytime.
 * Version:           1.19.0
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

define( 'ACPS_MC_VERSION', '1.19.0' );
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
 * Admin notice + resume control shown while dormant in safe mode. Shown ONLY on
 * the plugin's own pages — never at the top of other admin pages. (When paused,
 * recovery is normally done from the console URL, which works in safe mode.)
 */
function acps_mc_safe_mode_notice() {
	if ( ! acps_mc_is_own_admin_page() || ! current_user_can( 'activate_plugins' ) ) {
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
 * True only when the current admin request is one of THIS plugin's own screens.
 * Used to make sure the plugin never prints a top-of-page admin notice on any
 * page it did not create.
 *
 * @return bool
 */
function acps_mc_is_own_admin_page() {
	if ( ! is_admin() ) {
		return false;
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return in_array(
		$page,
		array( 'acps-media-manager', 'acps-mc-settings', 'acps-mc-trash', 'acps-mc-updates', 'acps-mc-remote' ),
		true
	);
}

/**
 * Admin notice if any of the plugin's files did not load (e.g. an incomplete
 * upload). The site is not crashed; the affected features are simply disabled.
 * Shown ONLY on the plugin's own pages — never at the top of other admin pages.
 */
function acps_mc_missing_files_notice() {
	if ( ! acps_mc_is_own_admin_page() || ! current_user_can( 'activate_plugins' ) ) {
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
			if ( empty( $acps_mc_opts['console_key'] ) ) {
				$acps_mc_opts['console_key'] = wp_generate_password( 44, false, false );
				$acps_mc_changed             = true;
			}
			if ( empty( $acps_mc_opts['console_password'] ) ) {
				$acps_mc_opts['console_password'] = wp_generate_password( 24, false, false );
				$acps_mc_changed                  = true;
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
		// Clean up the removed Google Drive importer's cron, if a prior version left it.
		wp_clear_scheduled_hook( 'acps_mc_drive_tick' );
	} catch ( \Throwable $e ) {
		acps_mc_log( 'Deactivation error: ' . $e->getMessage() );
	}
}
register_deactivation_hook( __FILE__, 'acps_mc_deactivate' );

// The "Resume plugin" control must work in every state, including while dormant.
add_action( 'admin_post_acps_mc_resume', 'acps_mc_resume_from_safe_mode' );

/* ===========================================================================
 * FAILSAFE LAYER 4 — the always-available control console + self-healing updater
 *
 * A plain-text, unstyled control panel reachable ONLY at a secret URL:
 *     https://SITE/?acpsupdater=<console_key>
 * gated by a console_key AND a console_password (both set in the hidden Updates
 * tab), with optional IP allow/block filtering. It runs on `init` and is
 * registered BEFORE the safe-mode short-circuit below, so it keeps working even
 * when the plugin is paused — meaning a broken release can always be reinstalled
 * from this URL. Nothing here depends on the plugin's feature classes, so a
 * broken class file cannot take the console down.
 * ======================================================================== */

define( 'ACPS_MC_CONSOLE_QV', 'acpsupdater' );

/** Raw settings array without needing the Settings class (safe-mode safe). */
function acps_mc_opts_raw() {
	$o = get_option( ACPS_MC_OPT_SETTINGS, array() );
	return is_array( $o ) ? $o : array();
}
function acps_mc_opt_raw( $k, $d = '' ) {
	$o = acps_mc_opts_raw();
	return array_key_exists( $k, $o ) ? $o[ $k ] : $d;
}

/** Best-effort client IP (throttling / filtering only, never trusted for auth). */
function acps_mc_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0'; // phpcs:ignore
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ); // phpcs:ignore
		$first = trim( (string) $parts[0] );
		if ( '' !== $first ) {
			$ip = $first;
		}
	}
	$ip = preg_replace( '/[^0-9a-fA-F:\.]/', '', (string) $ip );
	return $ip ? $ip : '0.0.0.0';
}

/** Parse a newline/comma list of IP prefixes into an array. */
function acps_mc_ip_list( $s ) {
	$s = (string) $s;
	if ( '' === trim( $s ) ) {
		return array();
	}
	$parts = preg_split( '/[\s,]+/', $s, -1, PREG_SPLIT_NO_EMPTY );
	return array_values( array_filter( array_map( 'trim', (array) $parts ) ) );
}

/** True if $ip exactly equals, or begins with (on a dot boundary), any pattern. */
function acps_mc_ip_prefix_match( $ip, $patterns ) {
	foreach ( (array) $patterns as $p ) {
		$p = trim( (string) $p );
		if ( '' === $p ) {
			continue;
		}
		if ( $ip === $p ) {
			return true;
		}
		$prefix = rtrim( $p, '.' ) . '.'; // "168.1" matches "168.1.x", not "168.10".
		if ( 0 === strpos( $ip, $prefix ) ) {
			return true;
		}
	}
	return false;
}

/** Apply the console's block/allow IP rules. */
function acps_mc_ip_denied( $ip, $o ) {
	$block = acps_mc_ip_list( isset( $o['console_ip_block'] ) ? $o['console_ip_block'] : '' );
	if ( $block && acps_mc_ip_prefix_match( $ip, $block ) ) {
		return true;
	}
	$allow = acps_mc_ip_list( isset( $o['console_ip_allow'] ) ? $o['console_ip_allow'] : '' );
	if ( $allow && ! acps_mc_ip_prefix_match( $ip, $allow ) ) {
		return true;
	}
	return false;
}

/* -- Self-contained "latest version" resolver + installer (no plugin classes) -- */

/** Resolve the latest release for whichever source is configured; cached. */
function acps_mc_resolve_remote( $force = false ) {
	if ( ! $force ) {
		$c = get_transient( 'acps_mc_update_remote' );
		if ( false !== $c ) {
			return $c ? $c : false;
		}
	}
	$o    = acps_mc_opts_raw();
	$src  = isset( $o['update_source'] ) ? $o['update_source'] : 'url';
	$data = ( 'github' === $src ) ? acps_mc_resolve_github( $o ) : acps_mc_resolve_manifest( $o );
	set_transient( 'acps_mc_update_remote', $data ? $data : array(), $data ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
	return $data;
}

function acps_mc_resolve_github( $o ) {
	$owner = trim( (string) ( isset( $o['gh_owner'] ) ? $o['gh_owner'] : '' ) );
	$repo  = trim( (string) ( isset( $o['gh_repo'] ) ? $o['gh_repo'] : '' ) );
	if ( '' === $owner || '' === $repo ) {
		return false;
	}
	$token = trim( (string) ( isset( $o['gh_token'] ) ? $o['gh_token'] : '' ) );
	$asset = trim( (string) ( isset( $o['gh_asset'] ) ? $o['gh_asset'] : '' ) );
	if ( '' === $asset ) {
		$asset = 'acps-media-cleanup.zip';
	}
	$headers = array(
		'Accept'               => 'application/vnd.github+json',
		'X-GitHub-Api-Version' => '2022-11-28',
		'User-Agent'           => 'ACPS-Media-Cleanup-Console',
	);
	if ( '' !== $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}
	$resp = wp_remote_get( sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $owner ), rawurlencode( $repo ) ), array( 'timeout' => 15, 'headers' => $headers ) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return false;
	}
	$rel = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $rel ) || empty( $rel['tag_name'] ) ) {
		return false;
	}
	$pkg      = '';
	$is_asset = false;
	if ( ! empty( $rel['assets'] ) && is_array( $rel['assets'] ) ) {
		foreach ( $rel['assets'] as $a ) {
			if ( ! isset( $a['name'] ) || $a['name'] !== $asset ) {
				continue;
			}
			if ( '' !== $token && ! empty( $a['url'] ) ) {
				$pkg      = (string) $a['url'];
				$is_asset = true;
			} elseif ( ! empty( $a['browser_download_url'] ) ) {
				$pkg = (string) $a['browser_download_url'];
			}
			break;
		}
	}
	if ( '' === $pkg ) {
		return false;
	}
	return array( 'version' => ltrim( (string) $rel['tag_name'], 'vV' ), 'package' => $pkg, 'is_asset' => $is_asset, 'token' => $token );
}

function acps_mc_resolve_manifest( $o ) {
	$url = trim( (string) ( isset( $o['update_manifest'] ) ? $o['update_manifest'] : '' ) );
	if ( '' === $url ) {
		return false;
	}
	$key = trim( (string) ( isset( $o['update_manifest_key'] ) ? $o['update_manifest_key'] : '' ) );
	if ( '' !== $key ) {
		$url = add_query_arg( 'key', rawurlencode( $key ), $url );
	}
	$resp = wp_remote_get( $url, array( 'timeout' => 15 ) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return false;
	}
	$b = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $b ) || empty( $b['version'] ) || empty( $b['download_url'] ) ) {
		return false;
	}
	return array( 'version' => ltrim( (string) $b['version'], 'vV' ), 'package' => esc_url_raw( (string) $b['download_url'] ), 'is_asset' => false, 'token' => '' );
}

/** Download the package to a local temp file (handles private GitHub assets). */
function acps_mc_download_package( $remote ) {
	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$pkg = isset( $remote['package'] ) ? $remote['package'] : '';
	if ( ! empty( $remote['is_asset'] ) && ! empty( $remote['token'] ) ) {
		$resp = wp_remote_get(
			$pkg,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Accept'        => 'application/octet-stream',
					'Authorization' => 'Bearer ' . $remote['token'],
					'User-Agent'    => 'ACPS-Media-Cleanup-Console',
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$loc = wp_remote_retrieve_header( $resp, 'location' );
		if ( ! $loc ) {
			return new WP_Error( 'no_redirect', 'private asset redirect missing' );
		}
		return download_url( $loc );
	}
	return download_url( $pkg );
}

/**
 * Download + install the latest version over the current plugin folder, then
 * (on success) make sure the plugin is active and clear paused mode. Works with
 * or without the feature classes loaded. Returns an array of log lines.
 *
 * @param bool $force Reinstall even if the version is already current.
 * @return array
 */
function acps_mc_perform_install( $force = false ) {
	$log = array();
	try {
		$remote = acps_mc_resolve_remote( true );
		if ( ! $remote ) {
			$log[] = 'ERROR: could not reach the update source (check the source settings).';
			return $log;
		}
		$log[] = 'Installed version: ' . ACPS_MC_VERSION;
		$log[] = 'Latest version:    ' . $remote['version'];
		if ( ! $force && ! version_compare( $remote['version'], ACPS_MC_VERSION, '>' ) ) {
			$log[] = 'Already up to date — nothing to do. (Use Reinstall to overwrite anyway.)';
			return $log;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$pkg = acps_mc_download_package( $remote );
		if ( is_wp_error( $pkg ) ) {
			$log[] = 'ERROR downloading package: ' . $pkg->get_error_message();
			return $log;
		}

		$slug   = dirname( ACPS_MC_BASENAME );
		$rename = function ( $source, $remote_source ) use ( $slug ) {
			global $wp_filesystem;
			$desired = trailingslashit( $remote_source ) . $slug;
			$source  = untrailingslashit( $source );
			if ( untrailingslashit( $desired ) === $source ) {
				return trailingslashit( $source );
			}
			if ( $wp_filesystem && $wp_filesystem->move( $source, untrailingslashit( $desired ), true ) ) {
				return trailingslashit( $desired );
			}
			return $source;
		};
		add_filter( 'upgrader_source_selection', $rename, 10, 2 );

		// From a logged-out / front-end request WordPress has no page to show its
		// FTP-credentials form, so the upgrader would ask for credentials and bail.
		// Force the credential-free "direct" method for this request.
		$force_direct = function () {
			return 'direct';
		};
		add_filter( 'filesystem_method', $force_direct, 99 );
		WP_Filesystem();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->run(
			array(
				'package'                     => $pkg,
				'destination'                 => trailingslashit( WP_PLUGIN_DIR ) . $slug,
				'clear_destination'           => true,
				'clear_working'               => true,
				'abort_if_destination_exists' => false,
				'hook_extra'                  => array( 'type' => 'plugin', 'action' => 'update', 'plugin' => ACPS_MC_BASENAME ),
			)
		);
		remove_filter( 'filesystem_method', $force_direct, 99 );
		remove_filter( 'upgrader_source_selection', $rename, 10 );

		// If the normal upgrader failed but the files are actually writable, try a
		// manual per-file copy that continues past a single failure (WordPress'
		// copy_dir aborts the whole install on the first file it can't write).
		if ( ( is_wp_error( $result ) || ! $result ) && function_exists( 'acps_mc_manual_install_from_zip' ) ) {
			$manual = acps_mc_manual_install_from_zip( $pkg, $log );
			if ( $manual ) {
				$result = true;
			}
		}
		if ( is_string( $pkg ) && file_exists( $pkg ) ) {
			@unlink( $pkg ); // phpcs:ignore
		}

		foreach ( (array) $skin->get_upgrade_messages() as $m ) {
			$log[] = wp_strip_all_tags( $m );
		}
		if ( is_wp_error( $result ) ) {
			$log[] = 'RESULT: FAILED — ' . $result->get_error_message();
			return $log;
		}
		if ( ! $result ) {
			$log[] = 'RESULT: FAILED (the installer returned no result).';
			return $log;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( ACPS_MC_BASENAME ) ) {
			activate_plugin( ACPS_MC_BASENAME, '', false, true );
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore -- the new bytecode must replace the old, or the next load fatals.
		}
		delete_option( ACPS_MC_SAFE_MODE_OPT ); // self-heal: leave paused mode.
		delete_transient( 'acps_mc_update_remote' );
		update_option( 'acps_mc_post_update_check', time(), false );
		$log[] = 'RESULT: SUCCESS — installed ' . $remote['version'] . ' and cleared paused mode.';
	} catch ( \Throwable $e ) {
		$log[] = 'RESULT: FAILED (exception) — ' . $e->getMessage();
	}
	return $log;
}

/* ===========================================================================
 * ROBUST self-update for hosts that block overwriting IN-USE .php from a normal
 * request (e.g. WP Engine): stage the new files now (writing NEW files is
 * allowed), then copy them over the live files in the early bootstrap window —
 * before this plugin loads its own includes — which is the one instant those
 * files are not "in use". A rollback backup makes a bad release self-heal.
 * ======================================================================== */

/** The live plugin directory, without a trailing slash. */
function acps_mc_live_path() {
	return untrailingslashit( ACPS_MC_DIR );
}
function acps_mc_content_dir() {
	return defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( dirname( untrailingslashit( ACPS_MC_DIR ) ) );
}

/** Recursively @copy a tree, continuing past any single failure. */
function acps_mc_copy_tree( $src, $dst ) {
	$src = untrailingslashit( $src );
	$dst = untrailingslashit( $dst );
	if ( ! is_dir( $src ) ) {
		return array( 'ok' => false, 'failed' => array( '(source missing)' ) );
	}
	if ( ! is_dir( $dst ) ) {
		@mkdir( $dst, 0755, true ); // phpcs:ignore
	}
	$failed = array();
	try {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $it as $item ) {
			$rel    = substr( $item->getPathname(), strlen( $src ) + 1 );
			$target = $dst . '/' . $rel;
			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) ) {
					@mkdir( $target, 0755, true ); // phpcs:ignore
				}
				continue;
			}
			if ( ! @copy( $item->getPathname(), $target ) ) { // phpcs:ignore
				@chmod( $target, 0644 ); // phpcs:ignore
				if ( ! @copy( $item->getPathname(), $target ) ) { // phpcs:ignore
					$failed[] = $rel;
				}
			}
		}
	} catch ( \Throwable $e ) {
		$failed[] = '(' . $e->getMessage() . ')';
	}
	return array( 'ok' => empty( $failed ), 'failed' => $failed );
}

/** Recursively delete a directory tree. */
function acps_mc_remove_tree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	try {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore
			}
		}
	} catch ( \Throwable $e ) {
		acps_mc_log( 'remove_tree: ' . $e->getMessage() );
	}
	@rmdir( $dir ); // phpcs:ignore
}

/* ---- rollback: back up the live files before a swap; restore on a bad load ---- */

function acps_mc_rollback_dir() {
	return acps_mc_content_dir() . '/acps-mc-rollback';
}
function acps_mc_arm_rollback() {
	acps_mc_remove_tree( acps_mc_rollback_dir() );
	$res = acps_mc_copy_tree( acps_mc_live_path(), acps_mc_rollback_dir() );
	update_option( 'acps_mc_rollback', array( 'version' => ACPS_MC_VERSION, 'time' => time() ), false );
	return $res['ok'];
}
function acps_mc_disarm_rollback() {
	acps_mc_remove_tree( acps_mc_rollback_dir() );
	delete_option( 'acps_mc_rollback' );
}
/**
 * If the plugin is paused (a bad update fataled) and a recent rollback backup
 * exists, restore the previous files. Runs at the very top of the bootstrap.
 *
 * @return bool True if a rollback was performed (caller should stop this request).
 */
function acps_mc_maybe_rollback() {
	if ( ! acps_mc_is_safe_mode() ) {
		return false;
	}
	$r = get_option( 'acps_mc_rollback' );
	if ( ! is_array( $r ) || ! is_dir( acps_mc_rollback_dir() ) ) {
		return false;
	}
	if ( empty( $r['time'] ) || ( time() - (int) $r['time'] ) > DAY_IN_SECONDS ) {
		return false; // Too old — don't restore stale code; leave paused + console.
	}
	try {
		acps_mc_copy_tree( acps_mc_rollback_dir(), acps_mc_live_path() );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore
		}
		delete_option( ACPS_MC_SAFE_MODE_OPT );
		acps_mc_disarm_rollback();
		acps_mc_log( 'Auto rolled back to ' . ( isset( $r['version'] ) ? $r['version'] : 'previous' ) . ' after a failed update.' );
		return true;
	} catch ( \Throwable $e ) {
		acps_mc_log( 'maybe_rollback: ' . $e->getMessage() );
		return false;
	}
}

/* ---- staged install: write new files now, apply them in the pristine window ---- */

/** Find the folder that contains the main plugin file inside an unzipped package. */
function acps_mc_locate_plugin_dir( $base ) {
	if ( is_file( trailingslashit( $base ) . 'acps-media-cleanup.php' ) ) {
		return untrailingslashit( $base );
	}
	$dirs = glob( trailingslashit( $base ) . '*', GLOB_ONLYDIR );
	if ( is_array( $dirs ) ) {
		foreach ( $dirs as $d ) {
			if ( is_file( trailingslashit( $d ) . 'acps-media-cleanup.php' ) ) {
				return untrailingslashit( $d );
			}
		}
		foreach ( $dirs as $d ) {
			$deep = acps_mc_locate_plugin_dir( $d );
			if ( '' !== $deep ) {
				return $deep;
			}
		}
	}
	return '';
}

/**
 * Download + unzip the latest release into a staging folder (new files, which
 * the host allows), and record it. The next request applies it. Returns a log.
 */
function acps_mc_stage_install( $force = false ) {
	$log = array();
	try {
		$remote = acps_mc_resolve_remote( true );
		if ( ! $remote ) {
			$log[] = 'ERROR: could not reach the update source.';
			return $log;
		}
		$log[] = 'Installed version: ' . ACPS_MC_VERSION;
		$log[] = 'Latest version:    ' . $remote['version'];
		if ( ! $force && ! version_compare( $remote['version'], ACPS_MC_VERSION, '>' ) ) {
			$log[] = 'Already up to date — nothing to stage. (Use force to stage anyway.)';
			return $log;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		$pkg = acps_mc_download_package( $remote );
		if ( is_wp_error( $pkg ) ) {
			$log[] = 'ERROR downloading package: ' . $pkg->get_error_message();
			return $log;
		}
		$base = acps_mc_content_dir() . '/acps-mc-staging-' . wp_generate_password( 8, false, false );
		@mkdir( $base, 0755, true ); // phpcs:ignore
		$unz = unzip_file( $pkg, $base );
		if ( is_string( $pkg ) && file_exists( $pkg ) ) {
			@unlink( $pkg ); // phpcs:ignore
		}
		if ( is_wp_error( $unz ) ) {
			acps_mc_remove_tree( $base );
			$log[] = 'ERROR unzipping package: ' . $unz->get_error_message();
			return $log;
		}
		$src = acps_mc_locate_plugin_dir( $base );
		if ( '' === $src ) {
			acps_mc_remove_tree( $base );
			$log[] = 'ERROR: could not find the plugin folder inside the package.';
			return $log;
		}
		update_option(
			'acps_mc_staged_install',
			array( 'dir' => $src, 'base' => $base, 'version' => $remote['version'], 'time' => time() ),
			false
		);
		$log[] = 'STAGED version ' . $remote['version'] . '. Reload any page to apply it (the plugin applies it in its early bootstrap, before its own PHP is in use).';
	} catch ( \Throwable $e ) {
		$log[] = 'ERROR staging: ' . $e->getMessage();
	}
	return $log;
}

/**
 * Apply a staged install in the pristine bootstrap window (before includes load).
 * Consumes the marker immediately so a crash can never loop. Returns true if it
 * applied (caller should stop loading this request; the next request runs clean).
 */
function acps_mc_maybe_apply_staged() {
	$stage = get_option( 'acps_mc_staged_install' );
	if ( ! is_array( $stage ) || empty( $stage['dir'] ) ) {
		if ( false !== $stage ) {
			delete_option( 'acps_mc_staged_install' );
		}
		return false;
	}
	delete_option( 'acps_mc_staged_install' ); // consume now — never re-run on a crash.
	if ( ! is_dir( $stage['dir'] ) ) {
		return false;
	}
	try {
		acps_mc_arm_rollback();
		$res = acps_mc_copy_tree( $stage['dir'], acps_mc_live_path() );
		acps_mc_remove_tree( ! empty( $stage['base'] ) ? $stage['base'] : $stage['dir'] );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore
		}
		if ( $res['ok'] ) {
			delete_option( ACPS_MC_SAFE_MODE_OPT );
			delete_transient( 'acps_mc_update_remote' );
			update_option( 'acps_mc_post_update_check', time(), false );
			acps_mc_log( 'Applied staged update ' . ( isset( $stage['version'] ) ? $stage['version'] : '' ) . '.' );
			return true;
		}
		// Some files (often the in-use .php) could not be written — undo the swap.
		acps_mc_log( 'Staged apply could not write: ' . implode( ', ', (array) $res['failed'] ) . ' — restoring previous files.' );
		acps_mc_copy_tree( acps_mc_rollback_dir(), acps_mc_live_path() );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore
		}
		acps_mc_disarm_rollback();
		update_option( 'acps_mc_staged_failed', array( 'failed' => (array) $res['failed'], 'time' => time() ), false );
		return false;
	} catch ( \Throwable $e ) {
		acps_mc_log( 'apply staged: ' . $e->getMessage() );
		return false;
	}
}

/* ---- background (queued) install: stage from a writable admin/cron context ---- */

function acps_mc_queue_install( $force ) {
	update_option( 'acps_mc_pending_update', array( 'force' => (bool) $force, 'requested' => time(), 'attempts' => 0 ), false );
	if ( ! wp_next_scheduled( 'acps_mc_apply_pending' ) ) {
		wp_schedule_single_event( time() + 20, 'acps_mc_apply_pending' );
	}
	if ( function_exists( 'spawn_cron' ) ) {
		@spawn_cron(); // phpcs:ignore
	}
}
function acps_mc_apply_pending() {
	$pending = get_option( 'acps_mc_pending_update' );
	if ( ! is_array( $pending ) ) {
		return;
	}
	if ( get_transient( 'acps_mc_pending_lock' ) ) {
		return;
	}
	set_transient( 'acps_mc_pending_lock', 1, 120 );
	try {
		$attempts = (int) ( isset( $pending['attempts'] ) ? $pending['attempts'] : 0 );
		$old      = isset( $pending['requested'] ) && ( time() - (int) $pending['requested'] ) > DAY_IN_SECONDS;
		if ( $attempts >= 6 || $old ) {
			delete_option( 'acps_mc_pending_update' );
			delete_transient( 'acps_mc_pending_lock' );
			return;
		}
		$log = acps_mc_stage_install( ! empty( $pending['force'] ) );
		if ( is_array( get_option( 'acps_mc_staged_install' ) ) ) {
			delete_option( 'acps_mc_pending_update' );
			set_transient( 'acps_mc_last_update_log', $log, 10 * MINUTE_IN_SECONDS );
		} else {
			$pending['attempts'] = $attempts + 1;
			update_option( 'acps_mc_pending_update', $pending, false );
			if ( ! wp_next_scheduled( 'acps_mc_apply_pending' ) ) {
				wp_schedule_single_event( time() + 120, 'acps_mc_apply_pending' );
			}
		}
	} catch ( \Throwable $e ) {
		acps_mc_log( 'apply_pending: ' . $e->getMessage() );
	}
	delete_transient( 'acps_mc_pending_lock' );
}

/* ---- post-update: reset opcache and make sure we are active/unpaused ---- */

function acps_mc_ensure_active() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active( ACPS_MC_BASENAME ) ) {
		@activate_plugin( ACPS_MC_BASENAME ); // phpcs:ignore
	}
	if ( function_exists( 'wp_paused_plugins' ) ) {
		$paused = wp_paused_plugins();
		foreach ( array( ACPS_MC_BASENAME, dirname( ACPS_MC_BASENAME ) ) as $key ) {
			if ( method_exists( $paused, 'delete' ) && ( ! method_exists( $paused, 'get' ) || $paused->get( $key ) ) ) {
				$paused->delete( $key );
			}
		}
	}
}
function acps_mc_post_update_check() {
	if ( ! get_option( 'acps_mc_post_update_check' ) ) {
		return;
	}
	delete_option( 'acps_mc_post_update_check' );
	if ( function_exists( 'opcache_reset' ) ) {
		@opcache_reset(); // phpcs:ignore
	}
	acps_mc_ensure_active();
}

/* ---- manual per-file install fallback (used by acps_mc_perform_install) ---- */

function acps_mc_manual_install_from_zip( $zip, &$log ) {
	try {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		$base = acps_mc_content_dir() . '/acps-mc-manual-' . wp_generate_password( 8, false, false );
		@mkdir( $base, 0755, true ); // phpcs:ignore
		$unz = unzip_file( $zip, $base );
		if ( is_wp_error( $unz ) ) {
			acps_mc_remove_tree( $base );
			return false;
		}
		$src = acps_mc_locate_plugin_dir( $base );
		if ( '' === $src ) {
			acps_mc_remove_tree( $base );
			return false;
		}
		$res = acps_mc_copy_tree( $src, acps_mc_live_path() );
		acps_mc_remove_tree( $base );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore
		}
		if ( ! $res['ok'] ) {
			$log[] = 'Manual copy could not write: ' . implode( ', ', (array) $res['failed'] );
			return false;
		}
		$log[] = 'Installed via manual per-file copy.';
		return true;
	} catch ( \Throwable $e ) {
		$log[] = 'Manual install error: ' . $e->getMessage();
		return false;
	}
}

/* ---- write probe: measure which host case you are in (don't guess) ---- */

function acps_mc_write_probe() {
	$out = array();
	$dir = acps_mc_live_path();
	$out[] = 'Plugin dir: ' . $dir;
	$out[] = 'is_writable(dir): ' . ( is_writable( $dir ) ? 'yes' : 'no' );
	$tag   = wp_generate_password( 6, false, false );
	foreach ( array( 'md', 'txt', 'js', 'css', 'php' ) as $ext ) {
		$f  = $dir . '/acps-mc-writetest-' . $tag . '.' . $ext;
		$ok = ( false !== @file_put_contents( $f, "test\n" ) ); // phpcs:ignore
		if ( $ok ) {
			@unlink( $f ); // phpcs:ignore
		}
		$out[] = 'new .' . $ext . ' : ' . ( $ok ? 'OK' : 'FAILED' );
	}
	$out[] = '';
	$out[] = 'Interpretation:';
	$out[] = '  new .php OK      -> host only blocks overwriting IN-USE .php; use "Stage update".';
	$out[] = '  new .php FAILED  -> host blocks ALL .php writes by the web user; use SFTP or cron-as-owner.';
	return $out;
}

/* -------------------------------- the console -------------------------------- */

function acps_mc_console_out( $text, $status = 200 ) {
	if ( ! headers_sent() ) {
		status_header( $status );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
	echo $text; // phpcs:ignore WordPress.Security.EscapeOutput -- plain text output.
	exit;
}

function acps_mc_console_html_head( $status = 200 ) {
	if ( ! headers_sent() ) {
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
	echo '<!doctype html><meta charset="utf-8"><title>ACPS Console</title>';
}

function acps_mc_console_login( $wrong = false ) {
	$key = isset( $_REQUEST[ ACPS_MC_CONSOLE_QV ] ) ? (string) wp_unslash( $_REQUEST[ ACPS_MC_CONSOLE_QV ] ) : ''; // phpcs:ignore
	$hk  = htmlspecialchars( $key, ENT_QUOTES, 'UTF-8' );
	acps_mc_console_html_head( 401 );
	echo '<pre>ACPS MEDIA CLEANUP — CONTROL CONSOLE' . "\n" . ( $wrong ? "Wrong password.\n" : '' ) . '</pre>';
	echo '<form method="post"><input type="hidden" name="' . htmlspecialchars( ACPS_MC_CONSOLE_QV, ENT_QUOTES, 'UTF-8' ) . '" value="' . $hk . '">';
	echo 'Password: <input type="password" name="pw" autofocus> <button type="submit">Enter</button></form>';
	exit;
}

/** Machine-readable status (returned by ?do=raw as JSON). */
function acps_mc_console_status_data() {
	$remote = get_transient( 'acps_mc_update_remote' );
	$remote = ( is_array( $remote ) && $remote ) ? $remote : null;
	$latest = $remote && ! empty( $remote['version'] ) ? $remote['version'] : '';
	return array(
		'plugin'            => 'acps-media-cleanup',
		'installed_version' => ACPS_MC_VERSION,
		'latest_version'    => $latest,
		'update_available'  => ( '' !== $latest ) ? version_compare( $latest, ACPS_MC_VERSION, '>' ) : false,
		'paused_safe_mode'  => acps_mc_is_safe_mode(),
		'site_url'          => home_url( '/' ),
		'your_ip'           => acps_mc_client_ip(),
	);
}

function acps_mc_console_home( $o, $pw ) {
	$rawkey = isset( $_REQUEST[ ACPS_MC_CONSOLE_QV ] ) ? (string) wp_unslash( $_REQUEST[ ACPS_MC_CONSOLE_QV ] ) : ''; // phpcs:ignore
	$d      = acps_mc_console_status_data();
	$h      = function ( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	};
	$base   = '?' . rawurlencode( ACPS_MC_CONSOLE_QV ) . '=' . rawurlencode( $rawkey ) . '&pw=' . rawurlencode( $pw );

	acps_mc_console_html_head( 200 );
	echo '<pre>ACPS MEDIA CLEANUP — CONTROL CONSOLE' . "\n";
	echo 'installed_version : ' . $h( $d['installed_version'] ) . "\n";
	echo 'latest_version    : ' . $h( '' !== $d['latest_version'] ? $d['latest_version'] : '(unknown — press Update to check)' ) . "\n";
	echo 'update_available  : ' . ( $d['update_available'] ? 'YES' : 'no' ) . "\n";
	echo 'paused (safe mode): ' . ( $d['paused_safe_mode'] ? 'YES — the plugin is dormant; press Reinstall or Resume' : 'no' ) . "\n";
	echo 'site              : ' . $h( $d['site_url'] ) . "\n";
	echo 'your_ip           : ' . $h( $d['your_ip'] ) . "\n</pre>";

	$btn = function ( $do, $label ) use ( $h, $rawkey, $pw ) {
		echo '<form method="post" style="display:inline">'
			. '<input type="hidden" name="' . $h( ACPS_MC_CONSOLE_QV ) . '" value="' . $h( $rawkey ) . '">'
			. '<input type="hidden" name="pw" value="' . $h( $pw ) . '">'
			. '<input type="hidden" name="do" value="' . $h( $do ) . '">'
			. '<button type="submit">' . $h( $label ) . '</button></form> ';
	};
	echo '<p>Actions:</p><p>';
	$btn( 'status', 'Refresh / check' );
	$btn( 'update', 'Update to latest' );
	$btn( 'reinstall', 'Reinstall latest (fix broken files)' );
	$btn( 'stage', 'Stage update (WP Engine / locked hosts)' );
	$btn( 'probe', 'Write probe (diagnose host)' );
	if ( $d['paused_safe_mode'] ) {
		$btn( 'resume', 'Resume (clear paused mode)' );
	}
	$btn( 'settings', 'View / edit settings' );
	$btn( 'media', 'Private media (upload / download)' );
	echo '</p>';

	$links = acps_mc_opt_raw( 'console_links', array() );
	if ( is_array( $links ) && $links ) {
		echo '<p>Custom links:</p><ul>';
		foreach ( $links as $l ) {
			if ( is_array( $l ) && ! empty( $l['url'] ) ) {
				echo '<li><a href="' . $h( $l['url'] ) . '">' . $h( ! empty( $l['label'] ) ? $l['label'] : $l['url'] ) . '</a></li>';
			}
		}
		echo '</ul>';
	}

	echo '<p>wp-admin pages:</p><ul>';
	echo '<li><a href="' . $h( admin_url( 'upload.php?page=acps-media-manager' ) ) . '">FileMedia manager</a></li>';
	echo '<li><a href="' . $h( admin_url( 'upload.php?page=acps-mc-settings' ) ) . '">Settings</a></li>';
	echo '<li><a href="' . $h( admin_url( 'admin.php?page=acps-mc-updates' ) ) . '">Updates (hidden)</a></li>';
	echo '<li><a href="' . $h( admin_url( 'admin.php?page=acps-mc-remote' ) ) . '">Remote photo API (hidden)</a></li>';
	echo '</ul>';

	echo '<pre>For scripts (single request, no forms):' . "\n";
	echo '  Status JSON : GET  ' . $h( $base . '&do=raw' ) . "\n";
	echo '  Update      : GET  ' . $h( $base . '&do=update' ) . "\n";
	echo '  Reinstall   : GET  ' . $h( $base . '&do=reinstall' ) . "\n";
	echo '  Stage       : GET  ' . $h( $base . '&do=stage' ) . '   (add &force=1 to reinstall same version)' . "\n";
	echo '  Queue        : GET  ' . $h( $base . '&do=queue' ) . "\n";
	echo '  Probe       : GET  ' . $h( $base . '&do=probe' ) . "\n";
	echo '  Resume      : GET  ' . $h( $base . '&do=resume' ) . "\n</pre>";
	exit;
}

function acps_mc_console_settings( $o, $pw ) {
	$rawkey = isset( $_REQUEST[ ACPS_MC_CONSOLE_QV ] ) ? (string) wp_unslash( $_REQUEST[ ACPS_MC_CONSOLE_QV ] ) : ''; // phpcs:ignore
	$h      = function ( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	};
	acps_mc_console_html_head( 200 );
	echo '<pre>Edit the settings JSON and Save. Keys you include are written; others are left alone.</pre>';
	echo '<form method="post"><input type="hidden" name="' . $h( ACPS_MC_CONSOLE_QV ) . '" value="' . $h( $rawkey ) . '">'
		. '<input type="hidden" name="pw" value="' . $h( $pw ) . '">'
		. '<input type="hidden" name="do" value="savesettings">'
		. '<textarea name="json" rows="30" cols="100">' . $h( wp_json_encode( $o, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</textarea><br>'
		. '<button type="submit">Save settings</button></form>';
	echo '<p><a href="?' . $h( rawurlencode( ACPS_MC_CONSOLE_QV ) . '=' . rawurlencode( $rawkey ) . '&pw=' . rawurlencode( $pw ) ) . '">&larr; back</a></p>';
	exit;
}

function acps_mc_console_save_settings( $o ) {
	$json = isset( $_POST['json'] ) ? (string) wp_unslash( $_POST['json'] ) : ''; // phpcs:ignore
	$new  = json_decode( $json, true );
	if ( ! is_array( $new ) ) {
		acps_mc_console_out( "ERROR: the settings were not valid JSON — nothing was changed.\n", 400 );
	}
	$merged = $o;
	foreach ( $new as $k => $v ) {
		$merged[ (string) $k ] = $v;
	}
	update_option( ACPS_MC_OPT_SETTINGS, $merged );
	acps_mc_console_out( "Settings saved.\n" );
}

/* ---- private console-only media store (files NEVER enter the WP library) ----
 *
 * Files uploaded through the console are written to a private uploads sub-folder
 * with a neutral, non-executable filename and indexed in an option. They are NOT
 * WordPress attachments, so they appear nowhere in the WordPress media library or
 * FileBird — only on the console. Downloads are streamed through the console
 * (auth-gated), so the private files are never linkable without the key+password.
 */

function acps_mc_store_dir() {
	$u   = wp_get_upload_dir();
	$dir = trailingslashit( $u['basedir'] ) . 'acps-mc-private';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		@file_put_contents( trailingslashit( $dir ) . 'index.html', '' ); // phpcs:ignore
		@file_put_contents( trailingslashit( $dir ) . '.htaccess', "Require all denied\nOrder allow,deny\nDeny from all\n" ); // phpcs:ignore
	}
	return trailingslashit( $dir );
}

function acps_mc_store_index() {
	$i = get_option( 'acps_mc_private_files', array() );
	return is_array( $i ) ? $i : array();
}

function acps_mc_store_add( $orig_name, $src_path, $mime = '' ) {
	$dir    = acps_mc_store_dir();
	$stored = 'bin_' . wp_generate_password( 28, false, false ) . '.dat'; // neutral ext = never executed.
	$dest   = $dir . $stored;
	if ( ! @copy( $src_path, $dest ) ) { // phpcs:ignore
		return false;
	}
	$name = sanitize_file_name( wp_basename( (string) $orig_name ) );
	$rec  = array(
		'id'    => 'f' . time() . wp_generate_password( 8, false, false ),
		'name'  => '' !== $name ? $name : 'upload.dat',
		'store' => $stored,
		'size'  => (int) @filesize( $dest ), // phpcs:ignore
		'mime'  => (string) $mime,
		'time'  => time(),
	);
	$idx = acps_mc_store_index();
	array_unshift( $idx, $rec );
	update_option( 'acps_mc_private_files', array_values( $idx ), false );
	return $rec;
}

function acps_mc_store_get( $id ) {
	foreach ( acps_mc_store_index() as $rec ) {
		if ( isset( $rec['id'] ) && $rec['id'] === $id ) {
			return $rec;
		}
	}
	return null;
}

function acps_mc_store_delete( $id ) {
	$dir   = acps_mc_store_dir();
	$out   = array();
	$found = false;
	foreach ( acps_mc_store_index() as $rec ) {
		if ( isset( $rec['id'] ) && $rec['id'] === $id ) {
			$found = true;
			if ( ! empty( $rec['store'] ) ) {
				@unlink( $dir . $rec['store'] ); // phpcs:ignore
			}
			continue;
		}
		$out[] = $rec;
	}
	update_option( 'acps_mc_private_files', array_values( $out ), false );
	return $found;
}

function acps_mc_store_stream( $id ) {
	$rec = acps_mc_store_get( $id );
	if ( ! $rec || empty( $rec['store'] ) ) {
		acps_mc_console_out( "Not found.\n", 404 );
	}
	$path = acps_mc_store_dir() . $rec['store'];
	if ( ! is_file( $path ) ) {
		acps_mc_console_out( "File missing on disk.\n", 404 );
	}
	if ( ! headers_sent() ) {
		status_header( 200 );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $rec['name'] . '"' );
		header( 'Content-Length: ' . (int) @filesize( $path ) ); // phpcs:ignore
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
	$fp = @fopen( $path, 'rb' ); // phpcs:ignore
	if ( $fp ) {
		while ( ! feof( $fp ) ) {
			echo fread( $fp, 8192 ); // phpcs:ignore
		}
		fclose( $fp ); // phpcs:ignore
	}
	exit;
}

/** Handle a console upload (multipart file[] or base64). Returns added names. */
function acps_mc_console_media_upload() {
	$added = array();
	if ( ! empty( $_FILES['file'] ) && ! empty( $_FILES['file']['name'] ) ) { // phpcs:ignore
		$f = $_FILES['file']; // phpcs:ignore
		if ( is_array( $f['name'] ) ) {
			$n = count( $f['name'] );
			for ( $i = 0; $i < $n; $i++ ) {
				if ( empty( $f['tmp_name'][ $i ] ) || ! is_uploaded_file( $f['tmp_name'][ $i ] ) ) {
					continue;
				}
				$rec = acps_mc_store_add( $f['name'][ $i ], $f['tmp_name'][ $i ], isset( $f['type'][ $i ] ) ? $f['type'][ $i ] : '' );
				if ( $rec ) {
					$added[] = $rec['name'];
				}
			}
		} elseif ( ! empty( $f['tmp_name'] ) && is_uploaded_file( $f['tmp_name'] ) ) {
			$rec = acps_mc_store_add( $f['name'], $f['tmp_name'], isset( $f['type'] ) ? $f['type'] : '' );
			if ( $rec ) {
				$added[] = $rec['name'];
			}
		}
	} else {
		$b64 = isset( $_POST['content_base64'] ) ? (string) wp_unslash( $_POST['content_base64'] ) : ''; // phpcs:ignore
		if ( '' !== $b64 ) {
			if ( false !== strpos( $b64, ',' ) && 0 === strpos( $b64, 'data:' ) ) {
				$b64 = substr( $b64, strpos( $b64, ',' ) + 1 );
			}
			$bytes = base64_decode( $b64, true );
			if ( false !== $bytes && '' !== $bytes ) {
				$tmp = wp_tempnam( 'acps-mc-store' );
				if ( $tmp && false !== file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore
					$name = isset( $_POST['filename'] ) ? (string) wp_unslash( $_POST['filename'] ) : 'upload.dat'; // phpcs:ignore
					$rec  = acps_mc_store_add( $name, $tmp, '' );
					if ( $rec ) {
						$added[] = $rec['name'];
					}
					@unlink( $tmp ); // phpcs:ignore
				}
			}
		}
	}
	return $added;
}

/** Render the private media list + upload form. */
function acps_mc_console_media( $o, $pw, $notice = '' ) {
	$rawkey = isset( $_REQUEST[ ACPS_MC_CONSOLE_QV ] ) ? (string) wp_unslash( $_REQUEST[ ACPS_MC_CONSOLE_QV ] ) : ''; // phpcs:ignore
	$h      = function ( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	};
	$base   = '?' . rawurlencode( ACPS_MC_CONSOLE_QV ) . '=' . rawurlencode( $rawkey ) . '&pw=' . rawurlencode( $pw );
	$idx    = acps_mc_store_index();

	acps_mc_console_html_head( 200 );
	echo '<pre>PRIVATE MEDIA — console only.' . "\n";
	echo 'These files are NOT in the WordPress media library or FileBird. They live only here.' . "\n";
	if ( '' !== $notice ) {
		echo $h( $notice ) . "\n";
	}
	echo count( $idx ) . " file(s).\n</pre>";

	echo '<form method="post" enctype="multipart/form-data">'
		. '<input type="hidden" name="' . $h( ACPS_MC_CONSOLE_QV ) . '" value="' . $h( $rawkey ) . '">'
		. '<input type="hidden" name="pw" value="' . $h( $pw ) . '">'
		. '<input type="hidden" name="do" value="mediaupload">'
		. 'Upload: <input type="file" name="file[]" multiple> <button type="submit">Upload</button></form>';

	echo '<table border="1" cellpadding="4" cellspacing="0"><tr><th align="left">name</th><th>size</th><th>date</th><th>actions</th></tr>';
	foreach ( $idx as $rec ) {
		if ( empty( $rec['id'] ) ) {
			continue;
		}
		$dl = $base . '&do=download&id=' . rawurlencode( $rec['id'] );
		echo '<tr><td>' . $h( $rec['name'] ) . '</td>'
			. '<td align="right">' . $h( size_format( (int) $rec['size'] ) ) . '</td>'
			. '<td>' . $h( gmdate( 'Y-m-d H:i', (int) $rec['time'] ) ) . '</td>'
			. '<td><a href="' . $h( $dl ) . '">download</a> '
			. '<form method="post" style="display:inline">'
			. '<input type="hidden" name="' . $h( ACPS_MC_CONSOLE_QV ) . '" value="' . $h( $rawkey ) . '">'
			. '<input type="hidden" name="pw" value="' . $h( $pw ) . '">'
			. '<input type="hidden" name="do" value="mediadelete">'
			. '<input type="hidden" name="id" value="' . $h( $rec['id'] ) . '">'
			. '<button type="submit" onclick="return confirm(\'Delete this file?\')">delete</button></form>'
			. '</td></tr>';
	}
	echo '</table>';
	echo '<pre>For scripts:' . "\n";
	echo '  Upload (multipart field "file")  : POST ' . $h( $base . '&do=mediaupload' ) . "\n";
	echo '  Upload (JSON filename+base64)     : POST ' . $h( $base . '&do=mediaupload' ) . "\n";
	echo '  Download                          : GET  ' . $h( $base . '&do=download&id=<id>' ) . "\n</pre>";
	echo '<p><a href="' . $h( $base ) . '">&larr; back</a></p>';
	exit;
}

/** Route an authenticated console request. */
function acps_mc_console_route( $do, $o, $pw ) {
	switch ( $do ) {
		case 'raw':
			acps_mc_console_out( wp_json_encode( acps_mc_console_status_data(), JSON_PRETTY_PRINT ) . "\n" );
			break;
		case 'update':
			acps_mc_console_out( implode( "\n", acps_mc_perform_install( false ) ) . "\n" );
			break;
		case 'reinstall':
			acps_mc_console_out( implode( "\n", acps_mc_perform_install( true ) ) . "\n" );
			break;
		case 'stage':
			acps_mc_console_out( implode( "\n", acps_mc_stage_install( isset( $_REQUEST['force'] ) ) ) . "\n" ); // phpcs:ignore
			break;
		case 'queue':
			acps_mc_queue_install( isset( $_REQUEST['force'] ) ); // phpcs:ignore
			acps_mc_console_out( "Queued a background install — it will be staged on the next admin request or cron run, then applied.\n" );
			break;
		case 'probe':
			acps_mc_console_out( implode( "\n", acps_mc_write_probe() ) . "\n" );
			break;
		case 'resume':
			delete_option( ACPS_MC_SAFE_MODE_OPT );
			acps_mc_console_out( "Paused mode cleared — the plugin will load normally on the next request.\n" );
			break;
		case 'settings':
			acps_mc_console_settings( $o, $pw );
			break;
		case 'savesettings':
			acps_mc_console_save_settings( $o );
			break;
		case 'media':
			acps_mc_console_media( $o, $pw );
			break;
		case 'mediaupload':
			$added = acps_mc_console_media_upload();
			if ( isset( $_REQUEST['raw'] ) ) { // phpcs:ignore
				acps_mc_console_out( 'Uploaded ' . count( $added ) . " file(s):\n" . implode( "\n", $added ) . "\n" );
			}
			acps_mc_console_media( $o, $pw, 'Uploaded ' . count( $added ) . ' file(s).' );
			break;
		case 'download':
			acps_mc_store_stream( isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '' ); // phpcs:ignore
			break;
		case 'mediadelete':
			$did = acps_mc_store_delete( isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '' ); // phpcs:ignore
			acps_mc_console_media( $o, $pw, $did ? 'File deleted.' : 'File not found.' );
			break;
		default:
			acps_mc_console_home( $o, $pw );
	}
}

/**
 * The console entry point (on `init`). Invisible unless the exact secret key is
 * present AND the console is configured; then password + IP gated.
 */
function acps_mc_console() {
	if ( ! isset( $_REQUEST[ ACPS_MC_CONSOLE_QV ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	try {
		$o   = acps_mc_opts_raw();
		$key = isset( $o['console_key'] ) ? trim( (string) $o['console_key'] ) : '';
		$pw  = isset( $o['console_password'] ) ? (string) $o['console_password'] : '';
		if ( '' === $key || '' === $pw ) {
			return; // Not configured — stay completely invisible.
		}
		$given_key = (string) wp_unslash( $_REQUEST[ ACPS_MC_CONSOLE_QV ] ); // phpcs:ignore
		if ( ! hash_equals( $key, $given_key ) ) {
			return; // Wrong key — behave as if the console doesn't exist.
		}

		nocache_headers();
		$ip = acps_mc_client_ip();
		if ( acps_mc_ip_denied( $ip, $o ) ) {
			acps_mc_console_out( "403 Forbidden — your IP is not allowed.\n", 403 );
		}

		$iph = substr( md5( 'acps-mc-console|' . $ip ), 0, 16 );
		if ( (int) get_transient( 'acps_mc_con_lock_' . $iph ) ) {
			acps_mc_console_out( "429 Too many attempts — try again in ~15 minutes.\n", 429 );
		}

		$given_pw = isset( $_REQUEST['pw'] ) ? (string) wp_unslash( $_REQUEST['pw'] ) : ''; // phpcs:ignore
		$authed   = ( '' !== $given_pw && hash_equals( $pw, $given_pw ) );
		if ( ! $authed ) {
			if ( '' !== $given_pw ) {
				$fails = (int) get_transient( 'acps_mc_con_fail_' . $iph ) + 1;
				set_transient( 'acps_mc_con_fail_' . $iph, $fails, 900 );
				if ( $fails >= 10 ) {
					set_transient( 'acps_mc_con_lock_' . $iph, 1, 900 );
				}
			}
			acps_mc_console_login( '' !== $given_pw );
		}
		delete_transient( 'acps_mc_con_fail_' . $iph );

		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : ''; // phpcs:ignore
		acps_mc_console_route( $do, $o, $given_pw );
	} catch ( \Throwable $e ) {
		acps_mc_log( 'Console error: ' . $e->getMessage() );
		acps_mc_console_out( "CONSOLE ERROR: " . $e->getMessage() . "\nTry &do=reinstall to redownload and apply the latest version.\n", 500 );
	}
}

/* -------- conditional shortcode for Beaver Builder / any content -------- */

/**
 * [acps_when condition="plugin_disabled"] shown only when paused [/acps_when]
 * Conditions: safe_mode|plugin_disabled|paused, plugin_active|active|enabled,
 * update_available|has_update, up_to_date. Prefix with ! to negate.
 */
function acps_mc_shortcode_when( $atts, $content = '' ) {
	try {
		$a    = shortcode_atts( array( 'condition' => '', 'is' => '' ), $atts, 'acps_when' );
		$cond = strtolower( trim( (string) ( '' !== $a['condition'] ? $a['condition'] : $a['is'] ) ) );
		if ( '' === $cond ) {
			return '';
		}
		$neg = false;
		if ( isset( $cond[0] ) && '!' === $cond[0] ) {
			$neg  = true;
			$cond = ltrim( $cond, '!' );
		}
		$val = acps_mc_condition( $cond );
		if ( $neg ) {
			$val = ! $val;
		}
		return $val ? do_shortcode( (string) $content ) : '';
	} catch ( \Throwable $e ) {
		return '';
	}
}

function acps_mc_condition( $cond ) {
	switch ( $cond ) {
		case 'safe_mode':
		case 'paused':
		case 'plugin_disabled':
		case 'disabled':
			return acps_mc_is_safe_mode();
		case 'plugin_active':
		case 'active':
		case 'plugin_enabled':
		case 'enabled':
			return ! acps_mc_is_safe_mode();
		case 'update_available':
		case 'has_update':
			$r = get_transient( 'acps_mc_update_remote' );
			return is_array( $r ) && ! empty( $r['version'] ) && version_compare( $r['version'], ACPS_MC_VERSION, '>' );
		case 'up_to_date':
			$r = get_transient( 'acps_mc_update_remote' );
			return ! ( is_array( $r ) && ! empty( $r['version'] ) && version_compare( $r['version'], ACPS_MC_VERSION, '>' ) );
		default:
			return false;
	}
}

// Register the console + shortcode in EVERY state (they must survive safe mode).
add_action( 'init', 'acps_mc_console', 0 );
add_shortcode( 'acps_when', 'acps_mc_shortcode_when' );

// Background/queued installs and the post-update opcache+activate check run in a
// writable context (admin request or system cron), registered in every state.
add_action( 'acps_mc_apply_pending', 'acps_mc_apply_pending' );
add_action( 'admin_init', 'acps_mc_apply_pending' );
add_action( 'admin_init', 'acps_mc_post_update_check' );

/* ===========================================================================
 * Self-heal window — runs BEFORE the plugin loads its own includes (the one
 * instant those .php files are not in use), so it can apply a staged update or
 * roll a bad one back even on hosts that block overwriting in-use PHP.
 * ======================================================================== */
if ( acps_mc_maybe_rollback() ) {
	return; // Restored previous files; the next request loads the clean old code.
}
if ( acps_mc_maybe_apply_staged() ) {
	return; // Applied a staged update; the next request loads the clean new code.
}

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
