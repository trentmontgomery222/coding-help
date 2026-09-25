<?php
/**
 * Plugin Name:       ACPS Alert Popups
 * Plugin URI:        https://github.com/trentmontgomery222/coding-help
 * Description:       Turns Beaver Builder Popups into a managed site alert system. Design the alert in Beaver Builder, then enable, schedule, target and throttle it from the WordPress admin.
 * Version:           1.10.7
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ACPS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acps-alert-popups
 *
 * Single-site plugin. No multisite handling by design.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

// If this file is loaded twice — a second copy of the plugin folder, or an
// include from somewhere else — stop here. Loading it twice would wire every
// hook twice and print every menu, notice and popup twice.
if ( defined( 'ACPS_ALERTS_VERSION' ) ) {
	// Count it so the admin can be told, rather than left wondering why a
	// plugin that looks active is doing nothing.
	$GLOBALS['acps_alerts_duplicate_load'] = isset( $GLOBALS['acps_alerts_duplicate_load'] )
		? (int) $GLOBALS['acps_alerts_duplicate_load'] + 1
		: 1;

	return;
}

define( 'ACPS_ALERTS_VERSION', '1.10.7' );
define( 'ACPS_ALERTS_FILE', __FILE__ );
define( 'ACPS_ALERTS_BASENAME', plugin_basename( __FILE__ ) );
define( 'ACPS_ALERTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACPS_ALERTS_URL', plugin_dir_url( __FILE__ ) );

// Holds "safe mode" state after a fatal was caught in this plugin's own code.
define( 'ACPS_ALERTS_SAFE_MODE_OPT', 'acps_alerts_safe_mode' );

// The minimum PHP this plugin's code is written against.
define( 'ACPS_ALERTS_MIN_PHP', '7.4' );

/**
 * Whether the plugin is allowed to run at all.
 *
 * Two escape hatches, both usable without database access:
 *
 *   define( 'ACPS_ALERTS_DISABLE', true ) in wp-config.php keeps the plugin
 *   completely dormant — the last-resort switch when a site is in trouble and
 *   wp-admin cannot be reached to deactivate it.
 *
 *   An unsupported PHP version also keeps it dormant, rather than letting it
 *   fatal on syntax or functions the host does not have.
 *
 * @return bool
 */
function acps_alerts_may_run() {
	if ( defined( 'ACPS_ALERTS_DISABLE' ) && ACPS_ALERTS_DISABLE ) {
		return false;
	}

	return version_compare( PHP_VERSION, ACPS_ALERTS_MIN_PHP, '>=' );
}

/**
 * Warns, on this plugin's own screens, when a second copy is installed.
 *
 * Two copies is the usual reason for "everything appears twice": both get
 * loaded, both wire their hooks, and every menu and notice prints twice. The
 * second copy is stopped dead at the top of this file, but the admin still
 * needs to know it is there so they can delete it.
 *
 * @return void
 */
function acps_alerts_duplicate_notice() {
	if ( empty( $GLOBALS['acps_alerts_duplicate_load'] ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	// Only ever on this plugin's own screens, never at the top of any other
	// page in wp-admin.
	if ( ! class_exists( 'ACPS_Alerts_Admin' ) || ! ACPS_Alerts_Admin::is_own_screen() ) {
		return;
	}

	echo '<div class="notice notice-warning"><p><strong>'
		. esc_html__( 'ACPS Alert Popups is installed more than once.', 'acps-alert-popups' )
		. '</strong> '
		. esc_html__( 'Only one copy is running; the extra copies were stopped so they could not duplicate your menus and alerts. Go to Plugins, deactivate and delete the duplicates, and keep a single copy.', 'acps-alert-popups' )
		. '</p><p><a class="button" href="' . esc_url( admin_url( 'plugins.php?s=ACPS+Alert+Popups' ) ) . '">'
		. esc_html__( 'Open Plugins', 'acps-alert-popups' )
		. '</a></p></div>';
}

/**
 * Is the plugin held in safe mode (dormant after a caught fatal)?
 *
 * @return bool
 */
function acps_alerts_is_safe_mode() {
	$state = get_option( ACPS_ALERTS_SAFE_MODE_OPT );

	if ( ! is_array( $state ) || empty( $state['time'] ) ) {
		return false;
	}

	// Different code is on disk than the code that crashed — an update was
	// installed, by any route (Plugins screen, auto-update, the console, or a
	// re-upload). Give the new code its chance. If it fatals too, the shutdown
	// guard pauses it again on that first request and a fresh email goes out.
	if ( ! empty( $state['version'] ) && ACPS_ALERTS_VERSION !== (string) $state['version'] ) {
		delete_option( ACPS_ALERTS_SAFE_MODE_OPT );

		return false;
	}

	return true;
}

/**
 * Emails the operator that the plugin entered safe mode, with the links to fix
 * it. Best-effort and non-critical: nothing here is allowed to throw, and a
 * host with no mail simply sends nothing. No on-screen notice is shown — this
 * email is the only signal, so the site itself stays clean.
 *
 * @param array $state The stored safe-mode state.
 * @return void
 */
function acps_alerts_notify_safe_mode( array $state ) {
	try {
		if ( ! function_exists( 'wp_mail' ) ) {
			return;
		}

		/**
		 * Filters the address told when the plugin enters safe mode.
		 *
		 * @param string $to Email address.
		 */
		$to = apply_filters( 'acps_alerts_safe_mode_email', 'cayden@reactallegany.org' );

		if ( ! $to ) {
			return;
		}

		$site  = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$name  = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : $site;
		$admin = function_exists( 'wp_login_url' ) ? wp_login_url() : ( function_exists( 'admin_url' ) ? admin_url() : '' );

		// The remote status/console URL, when it can be assembled, plus the
		// one-click recovery URL that lifts the pause on its own.
		$console   = '';
		$resume    = '';
		$reinstall = '';
		$key       = acps_alerts_recovery_key();

		if ( '' !== $key && function_exists( 'add_query_arg' ) ) {
			$resume    = add_query_arg( 'acps_alerts_resume', $key, $site );
			$reinstall = add_query_arg( 'acps_alerts_reinstall', $key, $site );

			if ( class_exists( 'ACPS_Alerts_Panel' ) ) {
				$console = add_query_arg( ACPS_Alerts_Panel::QUERY_VAR, $key, $site );
			}
		}

		$missing = ! empty( $state['missing'] ) && is_array( $state['missing'] );

		$subject = $missing
			? sprintf( 'ACPS Alert Popups paused (files missing) on %s', $name ? $name : $site )
			: sprintf( 'ACPS Alert Popups paused (safe mode) on %s', $name ? $name : $site );

		$body  = $missing
			? "The ACPS Alert Popups plugin found some of its own files missing and paused itself to keep the site online.\n\n"
			: "The ACPS Alert Popups plugin caught a fatal error and paused itself to keep the site online.\n\n";
		$body .= 'Site: ' . $site . "\n";
		$body .= 'wp-admin login: ' . $admin . "\n";

		if ( '' !== $console ) {
			$body .= 'Remote status/console: ' . $console . "\n";
		}

		if ( $missing ) {
			$body .= "\nMissing files:\n  " . implode( "\n  ", array_map( 'strval', $state['missing'] ) ) . "\n";

			if ( '' !== $reinstall ) {
				$body .= "\nTo restore the missing files from the update source now, open this link (no login needed):\n";
				$body .= '  ' . $reinstall . "\n";
			}

			$body .= "\nThe rest of the site is unaffected. The plugin also comes back by itself as soon as the files are restored (re-upload the plugin).\n";
		} else {
			$body .= "\nError:\n";
			$body .= '  ' . ( isset( $state['msg'] ) ? $state['msg'] : '' ) . "\n";
			$body .= '  ' . ( isset( $state['file'] ) ? $state['file'] : '' ) . ':' . ( isset( $state['line'] ) ? $state['line'] : 0 ) . "\n";

			if ( '' !== $resume ) {
				$body .= "\nTo take it out of safe mode now, open this link (no login needed):\n";
				$body .= '  ' . $resume . "\n";
			}

			if ( '' !== $reinstall ) {
				$body .= "\nOr, if a plugin file is damaged, reinstall fresh files from the update source (no login needed):\n";
				$body .= '  ' . $reinstall . "\n";
			}

			$body .= "\nThe rest of the site is unaffected. The plugin stays paused until one of these:\n";
			$body .= "  - the resume or reinstall link above is opened;\n";
			$body .= "  - a new version is installed (any way: Plugins screen, the console's update button, or a re-upload) — it lifts the pause by itself;\n";
			$body .= "  - the plugin is deactivated and reactivated in wp-admin.\n";
		}

		wp_mail( $to, $subject, $body );
	} catch ( \Throwable $e ) {
		return; // Non-critical; never let notification break the shutdown path.
	}
}

/**
 * Records a caught fatal and arms safe mode for the next request.
 *
 * @param string $msg  Message.
 * @param string $file File.
 * @param int    $line Line.
 * @return void
 */
function acps_alerts_arm_safe_mode( $msg, $file = '', $line = 0 ) {
	// This runs while the request is already dying, so it must not assume the
	// database is reachable or that WordPress is in a usable state.
	try {
		if ( function_exists( 'update_option' ) ) {
			// Only email on the FIRST arm of a safe-mode episode, not on every
			// following request while it stays dormant.
			$already = function_exists( 'get_option' ) ? get_option( ACPS_ALERTS_SAFE_MODE_OPT ) : false;

			$state = array(
				'msg'     => (string) $msg,
				'file'    => (string) $file,
				'line'    => (int) $line,
				'time'    => time(),
				// The code that crashed: a different version on disk later means
				// a fix was installed, which lifts the pause automatically.
				'version' => ACPS_ALERTS_VERSION,
			);

			update_option( ACPS_ALERTS_SAFE_MODE_OPT, $state, true );

			if ( ! ( is_array( $already ) && ! empty( $already['time'] ) ) ) {
				acps_alerts_notify_safe_mode( $state );
			}
		}
	} catch ( \Throwable $e ) {
		// Nothing more can be done here; the error log line below is the record.
		$msg .= ' (safe mode could not be stored: ' . $e->getMessage() . ')';
	}

	if ( function_exists( 'error_log' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[ACPS Alert Popups] Fatal caught — entering safe mode: ' . $msg . ' in ' . $file . ':' . $line ); // phpcs:ignore
	}
}

/**
 * Shutdown guard: if the request is ending on a fatal inside this plugin's
 * files, arm safe mode so the following requests stay up.
 *
 * @return void
 */
function acps_alerts_shutdown_guard() {
	try {
		$err = error_get_last();

		if ( ! $err || empty( $err['type'] ) ) {
			return;
		}

		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

		if ( ! in_array( $err['type'], $fatal, true ) ) {
			return;
		}

		// Only ever blame ourselves. A fatal in the theme or another plugin is
		// not this plugin's to act on, and silencing it would hide a real bug.
		if ( empty( $err['file'] ) || 0 !== strpos( $err['file'], ACPS_ALERTS_DIR ) ) {
			return;
		}

		acps_alerts_arm_safe_mode( $err['message'], $err['file'], $err['line'] );
	} catch ( \Throwable $e ) {
		// A guard that throws during shutdown would be the worst of both worlds.
		return;
	}
}

/**
 * Emails the operator once per distinct set of missing required files.
 *
 * Missing files do NOT arm safe mode — restoring them brings the plugin back
 * on the very next request — so this keeps its own "already told" marker,
 * keyed to exactly which files are missing, to avoid one email per request.
 *
 * @param string[] $missing Missing files, relative to the plugin root.
 * @return void
 */
function acps_alerts_notify_missing_files( array $missing ) {
	try {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$sig = md5( implode( '|', $missing ) );

		if ( get_option( 'acps_alerts_missing_notified' ) === $sig ) {
			return;
		}

		update_option( 'acps_alerts_missing_notified', $sig, false );

		acps_alerts_notify_safe_mode(
			array(
				'missing' => array_values( $missing ),
				'time'    => time(),
			)
		);
	} catch ( \Throwable $e ) {
		return; // Non-critical.
	}
}

/**
 * While paused, still answer the remote console — and only the console.
 *
 * The safe-mode email links to the console, so it has to work in exactly this
 * situation. Nothing is loaded unless the request carries the console's query
 * var, so ordinary page views never touch the paused code at all; the worst a
 * still-broken file can do is fail the operator's own console request.
 *
 * @return void
 */
function acps_alerts_boot_console_only() {
	// Literal on purpose: ACPS_Alerts_Panel::QUERY_VAR is not loaded yet, and
	// loading it just to read a constant would defeat the point.
	if ( ! isset( $_GET['acpsupdater'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	try {
		if ( ! acps_alerts_load_files() ) {
			return;
		}

		// The updater's hooks are needed for the console's update button: they
		// tell WordPress where the package is and fix up its folder name.
		$updater = new ACPS_Alerts_Updater();
		$panel   = new ACPS_Alerts_Panel( $updater );

		ACPS_Alerts_Failsafe::guard( array( $updater, 'register' ), array(), 'boot/updater' );
		ACPS_Alerts_Failsafe::guard( array( $panel, 'register' ), array(), 'boot/panel' );
	} catch ( \Throwable $e ) {
		return;
	}
}

/**
 * The key that unlocks the recovery URL: the console key, or the update secret
 * when no console key is set. Read straight from the options row, so this needs
 * none of the plugin's classes and works even when every file is broken.
 *
 * @return string
 */
function acps_alerts_recovery_key() {
	if ( ! function_exists( 'get_option' ) ) {
		return '';
	}

	$settings = get_option( 'acps_alerts_settings' );

	if ( ! is_array( $settings ) ) {
		return '';
	}

	$key = isset( $settings['console_key'] ) ? trim( (string) $settings['console_key'] ) : '';

	if ( '' === $key ) {
		$key = isset( $settings['update_secret'] ) ? trim( (string) $settings['update_secret'] ) : '';
	}

	return $key;
}

/**
 * Lifts safe mode from a secret URL, without loading anything else.
 *
 * This is the always-works way out of safe mode:
 *
 *     https://yoursite/?acps_alerts_resume=<console key or update secret>
 *
 * It reads the key straight from the database and clears the pause with plain
 * option writes, so it never touches the plugin's other files — the ones that
 * may be exactly what broke, and that a parse error in would otherwise take the
 * whole request (the remote console included) down with them. The long random
 * key in the URL is the only credential, the same gate the self-test and
 * force-update URLs already use; it lifts the pause and nothing more, so if the
 * cause is not fixed the next request simply pauses again.
 *
 * @return void
 */
function acps_alerts_maybe_resume_via_url() {
	if ( ! isset( $_GET['acps_alerts_resume'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	try {
		$key = acps_alerts_recovery_key();

		if ( '' === $key ) {
			return;
		}

		$given = function_exists( 'wp_unslash' ) ? wp_unslash( $_GET['acps_alerts_resume'] ) : $_GET['acps_alerts_resume']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! is_string( $given ) || ! hash_equals( $key, $given ) ) {
			return;
		}

		delete_option( ACPS_ALERTS_SAFE_MODE_OPT );
		delete_option( 'acps_alerts_update_failed' );
		delete_option( 'acps_alerts_missing_notified' );

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		if ( ! headers_sent() ) {
			if ( function_exists( 'status_header' ) ) {
				status_header( 200 );
			}

			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}

		echo "ACPS Alert Popups: safe mode cleared.\n\n";
		echo "The plugin will run again on the next page load. If whatever caused the pause is still there, it will pause again and email you.\n";
		exit;
	} catch ( \Throwable $e ) {
		// Recovery must never itself fatal. Fall through and let the request go
		// on as it would have.
		return;
	}
}

/**
 * Reinstalls the plugin from the update source, from a secret URL, to repair a
 * missing or damaged file:
 *
 *     https://yoursite/?acps_alerts_reinstall=<console key or update secret>
 *
 * This is deliberately SELF-CONTAINED: everything it needs lives in this one
 * file plus WordPress core. It does not load — or even touch — the failsafe,
 * the settings class or the updater, because any of those could be the file
 * that is missing or broken, and a broken file is exactly what this repairs. It
 * reads the update source out of the options row itself, resolves the package,
 * and installs it over the plugin. As long as this main file and WordPress load,
 * the plugin can always be restored. Same secret gate as the resume URL; the
 * request ends here on either outcome.
 *
 * @return void
 */
function acps_alerts_maybe_reinstall_via_url() {
	if ( ! isset( $_GET['acps_alerts_reinstall'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	try {
		$key = acps_alerts_recovery_key();

		if ( '' === $key ) {
			return;
		}

		$given = function_exists( 'wp_unslash' ) ? wp_unslash( $_GET['acps_alerts_reinstall'] ) : $_GET['acps_alerts_reinstall']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! is_string( $given ) || ! hash_equals( $key, $given ) ) {
			return;
		}

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		if ( ! headers_sent() ) {
			if ( function_exists( 'status_header' ) ) {
				status_header( 200 );
			}

			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}

		echo acps_alerts_recovery_reinstall();

		// Fresh files are in place; lift the pause so they get to run.
		delete_option( ACPS_ALERTS_SAFE_MODE_OPT );
		delete_option( 'acps_alerts_update_failed' );
		delete_option( 'acps_alerts_missing_notified' );

		echo "\nSafe mode cleared. Load any page to confirm the plugin is running again.\n";
		exit;
	} catch ( \Throwable $e ) {
		// Never let recovery itself fatal; say what happened and stop.
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
		}

		echo 'Reinstall failed: ' . $e->getMessage() . "\n";
		exit;
	}
}

/**
 * The update source, read straight from the options row.
 *
 * @return array
 */
function acps_alerts_recovery_settings() {
	if ( ! function_exists( 'get_option' ) ) {
		return array();
	}

	$settings = get_option( 'acps_alerts_settings' );

	return is_array( $settings ) ? $settings : array();
}

/**
 * Resolves the package to install from the configured update source, using only
 * WordPress core. Mirrors the updater's own resolution, but stands alone so it
 * works when the updater file is the one that is broken.
 *
 * @return array|false { version, package (url or local file), html } or false.
 */
function acps_alerts_recovery_resolve_package() {
	$settings = acps_alerts_recovery_settings();
	$source   = isset( $settings['update_source'] ) ? (string) $settings['update_source'] : 'manifest';

	if ( 'github' === $source ) {
		return acps_alerts_recovery_github( $settings );
	}

	return acps_alerts_recovery_manifest( $settings );
}

/**
 * Resolves the package from a manifest URL.
 *
 * @param array $settings Settings row.
 * @return array|false
 */
function acps_alerts_recovery_manifest( array $settings ) {
	$base = isset( $settings['update_base'] ) ? trim( (string) $settings['update_base'] ) : '';

	if ( '' === $base || ! function_exists( 'wp_remote_get' ) ) {
		return false;
	}

	$path = isset( $settings['update_path'] ) ? trim( (string) $settings['update_path'], " \t\n\r/" ) : '';

	if ( '' === $path ) {
		$path = dirname( ACPS_ALERTS_BASENAME );
	}

	$url = rtrim( $base, '/' ) . '/' . $path;
	$key = isset( $settings['update_key'] ) ? trim( (string) $settings['update_key'] ) : '';

	if ( '' !== $key ) {
		$url = add_query_arg( 'key', rawurlencode( $key ), $url );
	}

	$resp = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/json' ) ) );

	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return false;
	}

	$body = json_decode( wp_remote_retrieve_body( $resp ), true );

	if ( ! is_array( $body ) || empty( $body['download_url'] ) ) {
		return false;
	}

	return array(
		'version' => ! empty( $body['version'] ) ? ltrim( (string) $body['version'], 'vV' ) : ACPS_ALERTS_VERSION,
		'package' => (string) $body['download_url'],
		'html'    => ! empty( $body['homepage'] ) ? (string) $body['homepage'] : '',
	);
}

/**
 * Resolves the package from the latest GitHub release. A private repo's asset is
 * downloaded here to a temp file (following the signed redirect ourselves), so
 * the package handed to the installer is a plain local path.
 *
 * @param array $settings Settings row.
 * @return array|false
 */
function acps_alerts_recovery_github( array $settings ) {
	$owner = isset( $settings['gh_owner'] ) ? trim( (string) $settings['gh_owner'] ) : '';
	$repo  = isset( $settings['gh_repo'] ) ? trim( (string) $settings['gh_repo'] ) : '';

	if ( '' === $owner || '' === $repo || ! function_exists( 'wp_remote_get' ) ) {
		return false;
	}

	$token = isset( $settings['gh_token'] ) ? trim( (string) $settings['gh_token'] ) : '';
	$asset = isset( $settings['gh_asset'] ) ? trim( (string) $settings['gh_asset'] ) : '';

	if ( '' === $asset ) {
		$asset = dirname( ACPS_ALERTS_BASENAME ) . '.zip';
	}

	$headers = array(
		'Accept'               => 'application/vnd.github+json',
		'X-GitHub-Api-Version' => '2022-11-28',
		'User-Agent'           => 'ACPS-Alerts-Recovery',
	);

	if ( '' !== $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}

	$api  = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $owner ), rawurlencode( $repo ) );
	$resp = wp_remote_get( $api, array( 'timeout' => 15, 'headers' => $headers ) );

	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return false;
	}

	$release = json_decode( wp_remote_retrieve_body( $resp ), true );

	if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
		return false;
	}

	$version = ltrim( (string) $release['tag_name'], 'vV' );
	$html    = ! empty( $release['html_url'] ) ? (string) $release['html_url'] : '';
	$api_url = '';
	$public  = '';

	if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
		foreach ( $release['assets'] as $item ) {
			if ( ! isset( $item['name'] ) || $item['name'] !== $asset ) {
				continue;
			}

			if ( '' !== $token && ! empty( $item['url'] ) ) {
				$api_url = (string) $item['url'];
			} elseif ( ! empty( $item['browser_download_url'] ) ) {
				$public = (string) $item['browser_download_url'];
			}

			break;
		}
	}

	// Public asset (or public repo): the installer can download the URL itself.
	if ( '' !== $public ) {
		return array( 'version' => $version, 'package' => $public, 'html' => $html );
	}

	// Private asset: GitHub redirects the API url to a signed link that refuses a
	// forwarded auth header, so resolve the redirect here and download it clean.
	if ( '' !== $token && '' !== $api_url ) {
		$file = acps_alerts_recovery_download_private( $api_url, $token );

		if ( '' !== $file ) {
			return array( 'version' => $version, 'package' => $file, 'html' => $html );
		}
	}

	return false;
}

/**
 * Downloads a private GitHub release asset to a temp file, following the signed
 * redirect without the auth header (which the signed URL rejects).
 *
 * @param string $api_url The asset's API url.
 * @param string $token   GitHub token.
 * @return string Local temp path, or '' on failure.
 */
function acps_alerts_recovery_download_private( $api_url, $token ) {
	if ( ! function_exists( 'wp_remote_get' ) ) {
		return '';
	}

	$resp = wp_remote_get(
		$api_url,
		array(
			'timeout'     => 30,
			'redirection' => 0, // We want the redirect itself.
			'headers'     => array(
				'Accept'        => 'application/octet-stream',
				'Authorization' => 'Bearer ' . $token,
				'User-Agent'    => 'ACPS-Alerts-Recovery',
			),
		)
	);

	if ( is_wp_error( $resp ) ) {
		return '';
	}

	$location = wp_remote_retrieve_header( $resp, 'location' );

	if ( ! $location ) {
		return '';
	}

	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$tmp = download_url( $location );

	return is_wp_error( $tmp ) ? '' : (string) $tmp;
}

/**
 * Installs the resolved package over the current plugin, using only WordPress
 * core's upgrader — no plugin classes. Forces an update entry (same version
 * counts) so the upgrader replaces every file, and renames the extracted folder
 * to the plugin's own slug. Returns a plain-text log.
 *
 * @return string
 */
function acps_alerts_recovery_reinstall() {
	$pkg = acps_alerts_recovery_resolve_package();

	if ( ! $pkg || empty( $pkg['package'] ) ) {
		return "Could not reach the configured update source.\n";
	}

	if ( ! defined( 'ABSPATH' ) || ! function_exists( 'get_site_transient' ) ) {
		return "Cannot reinstall: WordPress is not fully loaded.\n";
	}

	$out  = "Reinstalling the plugin from the update source.\n";
	$out .= 'Installed version: ' . ACPS_ALERTS_VERSION . "\n";
	$out .= 'Source version:    ' . $pkg['version'] . "\n";

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$slug = dirname( ACPS_ALERTS_BASENAME );

	// Rename whatever top folder the zip carries (a GitHub zipball is named for
	// the tag or a hash) to the plugin's own slug, or the reinstall would land
	// in the wrong directory and not replace the plugin.
	$fix = function ( $source, $remote_source ) use ( $slug ) {
		global $wp_filesystem;

		if ( ! is_string( $source ) ) {
			return $source;
		}

		$desired = untrailingslashit( trailingslashit( $remote_source ) . $slug );
		$current = untrailingslashit( $source );

		if ( $desired === $current ) {
			return trailingslashit( $current );
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	};

	add_filter( 'upgrader_source_selection', $fix, 10, 2 );

	// Force an update entry for this plugin, even at the same version, so the
	// upgrader replaces every file rather than reporting "up to date".
	$transient = get_site_transient( 'update_plugins' );

	if ( ! is_object( $transient ) ) {
		$transient = new stdClass();
	}

	if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
		$transient->response = array();
	}

	$transient->response[ ACPS_ALERTS_BASENAME ] = (object) array(
		'slug'        => $slug,
		'plugin'      => ACPS_ALERTS_BASENAME,
		'new_version' => (string) $pkg['version'],
		'package'     => (string) $pkg['package'],
		'url'         => (string) $pkg['html'],
	);

	set_site_transient( 'update_plugins', $transient );

	$skin     = new Automatic_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->upgrade( ACPS_ALERTS_BASENAME );

	remove_filter( 'upgrader_source_selection', $fix, 10 );
	delete_site_transient( 'update_plugins' );

	$messages = $skin->get_upgrade_messages();

	if ( $messages ) {
		$out .= "\n" . implode( "\n", array_map( 'wp_strip_all_tags', (array) $messages ) ) . "\n";
	}

	$ok = ( ! is_wp_error( $result ) && $result );

	if ( $ok ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// The upgrader deactivates a plugin before replacing it; put it back.
		if ( ! is_plugin_active( ACPS_ALERTS_BASENAME ) ) {
			activate_plugin( ACPS_ALERTS_BASENAME, '', false, true );
		}
	}

	return $out . "\n" . ( $ok ? 'SUCCESS — files restored from the source.' : 'FAILED' ) . "\n";
}

/**
 * Loads the plugin's files, guarding against a missing one.
 *
 * @return bool True when every required file loaded.
 */
function acps_alerts_load_files() {
	// The failsafe class is loaded first and by hand: it lists the rest and
	// must be available even to report that the rest are not.
	$failsafe = ACPS_ALERTS_DIR . 'includes/class-acps-alerts-failsafe.php';

	if ( ! is_readable( $failsafe ) ) {
		return false;
	}

	require_once $failsafe;

	$missing = ACPS_Alerts_Failsafe::missing_files();

	if ( ! empty( $missing ) ) {
		// Stay dormant and silent on screen: no admin notice. The operator gets
		// one email, and the plugin comes back by itself once the files are
		// restored.
		if ( function_exists( 'error_log' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ACPS Alert Popups] Missing required files — staying dormant: ' . implode( ', ', $missing ) ); // phpcs:ignore
		}

		acps_alerts_notify_missing_files( $missing );

		return false;
	}

	foreach ( ACPS_Alerts_Failsafe::required_files() as $rel ) {
		// Re-checked per file: the list was verified a moment ago, but a file
		// removed in between (a deploy in progress) must still not fatal.
		if ( ! is_readable( ACPS_ALERTS_DIR . $rel ) ) {
			return false;
		}

		require_once ACPS_ALERTS_DIR . $rel;
	}

	// Files are whole again: forget the missing-files email so a later,
	// separate breakage is reported afresh.
	if ( function_exists( 'get_option' ) && get_option( 'acps_alerts_missing_notified' ) ) {
		delete_option( 'acps_alerts_missing_notified' );
	}

	return true;
}

/**
 * Main plugin instance.
 *
 * @return ACPS_Alerts_Plugin
 */
function acps_alerts() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new ACPS_Alerts_Plugin();
	}

	return $instance;
}

/**
 * Boots the plugin with crash protection.
 *
 * @return void
 */
function acps_alerts_boot() {
	// Boot once per request, whatever fires this.
	static $booted = false;

	if ( $booted ) {
		return;
	}

	$booted = true;

	// The last-resort recovery, before every other check — the PHP guard, the
	// kill switch, the safe-mode gate. Resume lifts the pause using this file
	// alone; reinstall pulls fresh files from the update source to repair a
	// missing or damaged one. Both are reached even while paused.
	acps_alerts_maybe_resume_via_url();
	acps_alerts_maybe_reinstall_via_url();

	// Hard stops first: an unsupported PHP version or the wp-config kill switch
	// means nothing else in this plugin runs at all. Silently — WordPress itself
	// refuses to activate on too-old PHP, from the Requires PHP header.
	if ( ! acps_alerts_may_run() ) {
		return;
	}

	if ( is_admin() ) {
		add_action( 'admin_notices', 'acps_alerts_duplicate_notice' );
	}

	if ( acps_alerts_is_safe_mode() ) {
		// No on-screen notice: the operator is told by email instead, so the
		// site (and every admin screen) stays clean. Whatever crashed simply
		// does nothing until it is fixed. Only the remote console still
		// answers, and only on its own URL.
		acps_alerts_boot_console_only();

		return; // Stay dormant, keep the site up.
	}

	// Catch a fatal from here on — including an uncatchable compile error while
	// the files load below — so following requests fall into safe mode instead
	// of crashing repeatedly. Registered before loading for exactly that reason.
	register_shutdown_function( 'acps_alerts_shutdown_guard' );

	// Integrity guard: a missing file keeps the plugin dormant rather than
	// fataling on "class not found".
	try {
		if ( ! acps_alerts_load_files() ) {
			return;
		}
	} catch ( \Throwable $e ) {
		// A file that is present but broken (a parse error in a half-uploaded
		// file, say) is a crash that was caught: pause and tell the operator,
		// rather than retrying the broken file on every request.
		acps_alerts_arm_safe_mode( $e->getMessage(), $e->getFile(), $e->getLine() );

		return;
	}

	try {
		acps_alerts()->init();
	} catch ( \Throwable $e ) {
		acps_alerts_arm_safe_mode( $e->getMessage(), $e->getFile(), $e->getLine() );
	}
}

add_action( 'plugins_loaded', 'acps_alerts_boot' );

register_activation_hook( __FILE__, 'acps_alerts_activate' );
register_deactivation_hook( __FILE__, 'acps_alerts_deactivate' );

/**
 * Activation: seed the secret, then defer to the plugin class if it loaded.
 *
 * @return void
 */
function acps_alerts_activate() {
	// Even activation must never fatal: a missing settings file means we skip
	// seeding and let the plugin boot into safe mode rather than white-screen
	// the activation request.
	if ( is_readable( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-settings.php' ) ) {
		require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-settings.php';
	}

	if ( class_exists( 'ACPS_Alerts_Settings' ) ) {
		$settings = get_option( ACPS_Alerts_Settings::OPTION, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, ACPS_Alerts_Settings::defaults() );

		// A random secret guards the update endpoint and the console.
		if ( empty( $settings['update_secret'] ) ) {
			$settings['update_secret'] = sanitize_key( wp_generate_password( 32, false, false ) );
		}

		// A random key guards the staged-rollout status endpoint, shared with the
		// paired production site.
		if ( empty( $settings['verify_status_key'] ) ) {
			$settings['verify_status_key'] = sanitize_key( wp_generate_password( 32, false, false ) );
		}

		// The key in acpsupdater=<key> that reaches the remote console.
		if ( empty( $settings['console_key'] ) ) {
			$settings['console_key'] = sanitize_key( wp_generate_password( 24, false, false ) );
		}

		update_option( ACPS_Alerts_Settings::OPTION, $settings );
	}

	// A deliberate activation is a clean slate: clear the rollback flag, leave
	// safe mode, close every breaker and empty the problem log.
	delete_option( 'acps_alerts_update_failed' );
	delete_option( ACPS_ALERTS_SAFE_MODE_OPT );

	if ( is_readable( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-failsafe.php' ) ) {
		require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-failsafe.php';

		ACPS_Alerts_Failsafe::clear_problems();
		ACPS_Alerts_Failsafe::reset_breakers();
	}

	// Register the alert post type and rebuild permalinks now, so the builder's
	// front-end editing URL works on the very first alert instead of 404ing.
	if ( is_readable( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-post-type.php' ) ) {
		require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-post-type.php';

		ACPS_Alerts_Post_Type::activate();
	}
}

/**
 * Deactivation.
 *
 * @return void
 */
function acps_alerts_deactivate() {
	// Alert settings live on the alert posts and are intentionally preserved.
	// The daily archive sweep is not: leaving a scheduled event behind for a
	// plugin that is switched off is just litter in wp_cron.
	if ( is_readable( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-status.php' ) ) {
		require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-status.php';

		if ( method_exists( 'ACPS_Alerts_Status', 'unschedule' ) ) {
			ACPS_Alerts_Status::unschedule();
		}
	}
}
