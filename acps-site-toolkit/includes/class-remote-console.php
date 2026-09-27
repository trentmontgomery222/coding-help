<?php
/**
 * Remote console — a hidden, front-end (non-admin) control page reached through
 * a secret URL (?acpsupdater=KEY). It mirrors what you can do in wp-admin from
 * OUTSIDE wp-admin: view diagnostics, edit ALL settings, and update / reinstall
 * the plugin remotely — without loading the slow full admin.
 *
 * Locked down in depth (no WordPress login needed):
 *   1. IP gate   — allow-list (default: only 167.102.110.1) or deny-list, with
 *                  prefix matching (e.g. 168.1 matches 168.1.*.*). Blocked → 404.
 *   2. URL key   — a secret key in the URL, compared in constant time.
 *   3. Password  — hashed, settable ONLY from wp-admin. Attempts rate-limited.
 *   4. Rate limit— the whole page is throttled per IP.
 *
 * Output is deliberately PLAIN (no CSS/JS) so a simple script can POST the
 * password and drive it. Field names are stable:
 *   - login / inline auth: POST field `pw`
 *   - actions: POST `do` = save | update | reinstall | logout
 *   - settings JSON: POST `json`
 * A script can authenticate and act in ONE request by POSTing pw + do together.
 *
 * Self-contained (depends only on Settings + WP core) so it still works when the
 * rest of the plugin is dormant in safe mode — the recovery path a broken update
 * can't take away.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remote_Console.
 */
class Remote_Console {

	/** Query vars that open the console (first is canonical). */
	const QUERY_VARS = array( 'acpsupdater', 'acps_console' );
	const COOKIE     = 'acps_console_sid';
	const SESS_TTL   = 1800; // 30-minute browser session.

	/** Settings keys never editable through the console (managed only in wp-admin). */
	private static function protected_keys() {
		return array( 'console_pass_hash' );
	}

	/**
	 * Which console query var (if any) is present on this request, and its value.
	 *
	 * @return array{0:string,1:string} [ var_name, given_value ] or [ '', '' ].
	 */
	private static function requested() {
		foreach ( self::QUERY_VARS as $var ) {
			if ( isset( $_GET[ $var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return array( $var, (string) wp_unslash( $_GET[ $var ] ) ); // phpcs:ignore
			}
		}
		return array( '', '' );
	}

	/**
	 * Hook the console onto init (very early). Safe to call in normal boot AND in
	 * safe mode — it only registers one handler.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 0 );
	}

	/**
	 * If this request is a hit on the console URL, take over and render it.
	 */
	public static function maybe_handle() {
		list( $var ) = self::requested();
		if ( '' === $var ) {
			return;
		}
		try {
			self::handle();
		} catch ( \Throwable $e ) {
			self::respond( 500, "Console error.\n" . $e->getMessage() );
		}
	}

	/**
	 * The request lifecycle.
	 */
	private static function handle() {
		if ( ! Settings::get( 'console_enabled' ) ) {
			self::not_found();
		}
		$key = trim( (string) Settings::get( 'console_key' ) );
		if ( '' === $key ) {
			self::not_found();
		}
		list( , $given ) = self::requested();

		$ip = self::client_ip();

		// 1) Whole-page rate limit.
		if ( ! self::rate_ok( 'page_' . md5( $ip ), 120, 5 * MINUTE_IN_SECONDS ) ) {
			self::respond( 429, 'Too many requests. Slow down and try again shortly.' );
		}
		// 2) IP gate — blocked address never learns the page exists.
		if ( ! self::ip_allowed( $ip ) ) {
			self::not_found();
		}
		// 3) URL key.
		if ( ! hash_equals( $key, $given ) ) {
			self::not_found();
		}

		// A password MUST be set in wp-admin first.
		$hash = (string) Settings::get( 'console_pass_hash' );
		if ( '' === $hash ) {
			self::page( "The remote console has no password set yet.\nSet one in wp-admin (Settings -> Forms, then add &updates=1 to the URL) before it can be used." );
		}

		// --- Authentication --------------------------------------------------
		// A) Inline: a password posted with the request (script-friendly, no
		//    cookie needed). B) A valid browser session cookie.
		$posted_pw = isset( $_POST['pw'] ) ? (string) wp_unslash( $_POST['pw'] ) : ''; // phpcs:ignore
		$inline    = false;
		$session   = self::current_session( $ip );

		if ( '' !== $posted_pw ) {
			if ( ! self::rate_ok( 'login_' . md5( $ip ), 8, 15 * MINUTE_IN_SECONDS ) ) {
				self::page( "Too many attempts. Wait 15 minutes and try again." );
			}
			if ( wp_check_password( $posted_pw, $hash ) ) {
				$inline = true;
				// Also start a browser session for convenience (scripts ignore it).
				if ( ! $session ) {
					$session = self::start_session( $ip );
				}
			} else {
				self::login_page( 'Incorrect password.' );
			}
		}

		if ( ! $inline && ! $session ) {
			self::login_page( '' );
		}

		// --- Action ----------------------------------------------------------
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore

		// CSRF: required for cookie-session actions; the posted password itself is
		// proof for inline (scripted) requests, so no token needed there.
		if ( $do && ! $inline ) {
			$csrf = isset( $_POST['csrf'] ) ? (string) wp_unslash( $_POST['csrf'] ) : ''; // phpcs:ignore
			if ( empty( $session['csrf'] ) || ! hash_equals( (string) $session['csrf'], $csrf ) ) {
				self::page( "Security check failed. Reload and try again." );
			}
		}

		if ( 'logout' === $do ) {
			self::destroy_session();
			self::redirect_self();
		}

		$msg = '';
		if ( 'save' === $do ) {
			$msg = self::do_save();
		} elseif ( 'update' === $do ) {
			$msg = self::do_update( false );
		} elseif ( 'reinstall' === $do ) {
			$msg = self::do_update( true );
		}

		self::dashboard_page( $session, $msg );
	}

	/* ------------------------------------------------------------------ *
	 * Actions.
	 * ------------------------------------------------------------------ */

	/** Save all settings from the posted JSON. No daily limit. */
	private static function do_save() {
		$raw     = isset( $_POST['json'] ) ? (string) wp_unslash( $_POST['json'] ) : ''; // phpcs:ignore
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return 'ERROR: that is not valid JSON. Nothing was saved.';
		}
		$current = Settings::all();
		foreach ( self::protected_keys() as $pk ) {
			unset( $decoded[ $pk ] ); // password hash stays wp-admin-only.
		}
		update_option( ACPS_ST_OPT_SETTINGS, array_merge( $current, $decoded ) );
		if ( class_exists( __NAMESPACE__ . '\\Updater' ) ) {
			Updater::flush_cache();
		}
		return 'OK: settings saved.';
	}

	/**
	 * Run an update (or a forced reinstall of the latest version).
	 *
	 * @param bool $force Reinstall even if already up to date.
	 * @return string Result text.
	 */
	private static function do_update( $force ) {
		if ( ! class_exists( __NAMESPACE__ . '\\Updater' ) ) {
			return 'ERROR: updater unavailable.';
		}
		if ( ! self::rate_ok( 'update', 6, 10 * MINUTE_IN_SECONDS ) ) {
			return 'ERROR: too many update attempts, wait a few minutes.';
		}
		$res = ( new Updater() )->install_now( $force );
		return ( ! empty( $res['ok'] ) ? 'OK: ' : 'ERROR: ' ) . ( isset( $res['message'] ) ? $res['message'] : '' );
	}

	/* ------------------------------------------------------------------ *
	 * IP gate.
	 * ------------------------------------------------------------------ */

	public static function client_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ); // phpcs:ignore
			$ip    = trim( $parts[0] );
		} elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip = trim( (string) wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ); // phpcs:ignore
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // phpcs:ignore
		}
		$ip = preg_replace( '/[^0-9a-f:\.]/i', '', $ip );
		return (string) apply_filters( 'acps_st_console_client_ip', $ip );
	}

	public static function ip_allowed( $ip ) {
		$mode = 'deny' === Settings::get( 'console_ip_mode' ) ? 'deny' : 'allow';
		$list = preg_split( '/[\s,]+/', (string) Settings::get( 'console_ips' ) );
		$list = array_filter( array_map( 'trim', (array) $list ) );

		$match = false;
		foreach ( $list as $pattern ) {
			if ( self::ip_matches( $ip, $pattern ) ) {
				$match = true;
				break;
			}
		}
		return ( 'allow' === $mode ) ? $match : ! $match;
	}

	private static function ip_matches( $ip, $pattern ) {
		if ( '' === $ip || '' === $pattern ) {
			return false;
		}
		$pattern = rtrim( $pattern, '*' );
		if ( '' === $pattern ) {
			return false;
		}
		if ( $ip === $pattern ) {
			return true;
		}
		$prefix = rtrim( $pattern, '.' ) . '.';
		return 0 === strpos( $ip, $prefix );
	}

	/* ------------------------------------------------------------------ *
	 * Sessions (IP-bound, transient-backed).
	 * ------------------------------------------------------------------ */

	private static function sess_transient( $token ) {
		return 'acps_console_sess_' . $token;
	}

	private static function current_session( $ip ) {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $_COOKIE[ self::COOKIE ] ) : '';
		if ( '' === $token ) {
			return null;
		}
		$data = get_transient( self::sess_transient( $token ) );
		if ( ! is_array( $data ) || empty( $data['ip'] ) || ! hash_equals( (string) $data['ip'], (string) $ip ) ) {
			return null;
		}
		$data['token'] = $token;
		return $data;
	}

	private static function start_session( $ip ) {
		$token = bin2hex( random_bytes( 16 ) );
		$data  = array( 'ip' => $ip, 'csrf' => bin2hex( random_bytes( 16 ) ), 'started' => time(), 'token' => $token );
		set_transient( self::sess_transient( $token ), $data, self::SESS_TTL );
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$token,
				array( 'expires' => time() + self::SESS_TTL, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict' )
			);
		}
		$_COOKIE[ self::COOKIE ] = $token;
		return $data;
	}

	private static function destroy_session() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $_COOKIE[ self::COOKIE ] ) : '';
		if ( '' !== $token ) {
			delete_transient( self::sess_transient( $token ) );
		}
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict' ) );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Rate limiting.
	 * ------------------------------------------------------------------ */

	private static function rate_ok( $bucket, $max, $window ) {
		$k = 'acps_console_rl_' . $bucket;
		$n = (int) get_transient( $k );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $k, $n + 1, $window );
		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Diagnostics.
	 * ------------------------------------------------------------------ */

	private static function diagnostics() {
		$safe      = ( function_exists( __NAMESPACE__ . '\\is_safe_mode' ) && is_safe_mode() );
		$safe_info = get_option( ACPS_ST_SAFE_MODE_OPT );

		$perf = array(
			'Plugin version' => ACPS_ST_VERSION,
			'PHP version'    => PHP_VERSION,
			'WordPress'      => get_bloginfo( 'version' ),
			'Memory limit'   => (string) ini_get( 'memory_limit' ),
			'Peak memory'    => size_format( memory_get_peak_usage( true ) ),
			'Server time'    => current_time( 'mysql' ),
			'Safe mode'      => $safe ? 'YES (plugin dormant)' : 'no',
		);

		$problems = array();
		if ( $safe ) {
			$problems[] = 'SAFE MODE: a fatal was caught; plugin dormant.'
				. ( is_array( $safe_info ) && ! empty( $safe_info['msg'] ) ? ' ' . $safe_info['msg'] . ( ! empty( $safe_info['file'] ) ? ' (' . $safe_info['file'] . ':' . (int) $safe_info['line'] . ')' : '' ) : '' );
		}
		$failed = get_option( 'acps_st_update_failed' );
		if ( is_array( $failed ) ) {
			$problems[] = 'A recent update failed its load test and was rolled back (' . ( isset( $failed['when'] ) ? $failed['when'] : '' ) . ').';
		}
		$save_err = get_option( 'acps_st_last_save_error' );
		if ( is_array( $save_err ) && ! empty( $save_err['message'] ) ) {
			$problems[] = 'Last form save error: ' . $save_err['message'];
		}
		if ( class_exists( __NAMESPACE__ . '\\Failsafe' ) ) {
			$missing = Failsafe::missing_files();
			if ( ! empty( $missing ) ) {
				$problems[] = 'Missing plugin files: ' . implode( ', ', $missing );
			}
		}

		$update = array();
		if ( class_exists( __NAMESPACE__ . '\\Updater' ) ) {
			$peek                        = Updater::peek_status();
			$update['Update source']     = (string) Settings::get( 'update_source' );
			$update['Update role']       = (string) Settings::get( 'update_role' );
			$update['Manifest URL']      = (string) Settings::get( 'update_manifest' );
			$update['Latest known']      = ( $peek['remote'] && ! empty( $peek['remote']['version'] ) ) ? $peek['remote']['version'] : '(unknown — run Update)';
			$update['Update available']  = $peek['has_update'] ? 'yes' : 'no';
		}

		return array( 'safe_mode' => $safe, 'perf' => $perf, 'update' => $update, 'problems' => $problems );
	}

	/* ------------------------------------------------------------------ *
	 * URL + HTTP helpers.
	 * ------------------------------------------------------------------ */

	public static function console_url() {
		if ( ! Settings::get( 'console_enabled' ) ) {
			return '';
		}
		$key = trim( (string) Settings::get( 'console_key' ) );
		if ( '' === $key ) {
			return '';
		}
		return add_query_arg( self::QUERY_VARS[0], $key, home_url( '/' ) );
	}

	private static function redirect_self() {
		$key = trim( (string) Settings::get( 'console_key' ) );
		wp_safe_redirect( add_query_arg( self::QUERY_VARS[0], $key, home_url( '/' ) ) );
		exit;
	}

	private static function not_found() {
		self::respond( 404, '' );
	}

	private static function respond( $code, $text ) {
		if ( ! headers_sent() ) {
			status_header( $code );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		if ( '' !== $text ) {
			echo esc_html( $text );
		}
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Rendering — plain, unstyled HTML (script-friendly).
	 * ------------------------------------------------------------------ */

	/** Emit page head/tail with no CSS. $body is already-escaped HTML. */
	private static function page_shell( $body ) {
		if ( ! headers_sent() ) {
			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
		}
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>ACPS Updater Console</title></head><body>'
			. $body
			. '</body></html>';
		exit;
	}

	/** A one-off plain message page. */
	private static function page( $text ) {
		self::page_shell( '<h1>ACPS Updater Console</h1><pre>' . esc_html( $text ) . '</pre>' );
	}

	private static function login_page( $error ) {
		$key = self::QUERY_VARS[0];
		$b   = '<h1>ACPS Updater Console</h1>';
		if ( '' !== $error ) {
			$b .= '<p><strong>' . esc_html( $error ) . '</strong></p>';
		}
		$b .= '<form method="post" action="">'
			. '<p><label>Password: <input type="password" name="pw" autofocus></label></p>'
			. '<p><button type="submit">Sign in</button></p>'
			. '</form>';
		self::page_shell( $b );
	}

	private static function dashboard_page( $session, $msg ) {
		$d = self::diagnostics();
		$b = '<h1>ACPS Updater Console</h1>';

		if ( '' !== $msg ) {
			$b .= '<p><strong>' . esc_html( $msg ) . '</strong></p><hr>';
		}

		// Custom links (configured in the hidden Updates tab).
		$links = self::custom_links();
		if ( $links ) {
			$b .= '<h2>Links</h2><ul>';
			foreach ( $links as $l ) {
				$b .= '<li><a href="' . esc_url( $l['url'] ) . '">' . esc_html( $l['label'] ) . '</a></li>';
			}
			$b .= '</ul>';
		}

		// Problems.
		$b .= '<h2>Issues &amp; problems</h2>';
		if ( empty( $d['problems'] ) ) {
			$b .= '<p>None detected.</p>';
		} else {
			$b .= '<ul>';
			foreach ( $d['problems'] as $p ) {
				$b .= '<li>' . esc_html( $p ) . '</li>';
			}
			$b .= '</ul>';
		}

		// Diagnostics tables (plain).
		$b .= '<h2>Status</h2><table border="1" cellpadding="4"><tbody>';
		foreach ( $d['perf'] as $k => $v ) {
			$b .= '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		foreach ( $d['update'] as $k => $v ) {
			$b .= '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		$b .= '</tbody></table>';

		// Hidden fields shared by the action forms (CSRF for cookie sessions).
		$csrf = ( is_array( $session ) && ! empty( $session['csrf'] ) ) ? $session['csrf'] : '';
		$hidden = '<input type="hidden" name="csrf" value="' . esc_attr( $csrf ) . '">';

		// Update / reinstall.
		$b .= '<h2>Update</h2>';
		$b .= '<form method="post" action="" style="display:inline">' . $hidden
			. '<input type="hidden" name="do" value="update"><button type="submit">Update to latest</button></form> ';
		$b .= '<form method="post" action="" style="display:inline">' . $hidden
			. '<input type="hidden" name="do" value="reinstall"><button type="submit" onclick="return confirm(\'Re-download and reinstall the latest version now?\')">Reinstall latest (repair)</button></form>';
		$b .= '<p>Reinstall re-downloads and re-applies the latest package even if the version is unchanged — use it if a file was edited wrong.</p>';

		// Settings editor.
		$settings = Settings::all();
		foreach ( self::protected_keys() as $pk ) {
			if ( isset( $settings[ $pk ] ) ) {
				$settings[ $pk ] = '(managed in wp-admin)';
			}
		}
		$json = wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$b   .= '<h2>Edit all settings</h2>'
			. '<form method="post" action="">' . $hidden
			. '<input type="hidden" name="do" value="save">'
			. '<p><textarea name="json" rows="24" cols="100" spellcheck="false">' . esc_textarea( (string) $json ) . '</textarea></p>'
			. '<p><button type="submit">Save settings</button></p></form>';

		// Sign out.
		$b .= '<hr><form method="post" action="">' . $hidden
			. '<input type="hidden" name="do" value="logout"><button type="submit">Sign out</button></form>';

		self::page_shell( $b );
	}

	/**
	 * Parse the admin-configured custom links (one per line: "Label | URL").
	 *
	 * @return array[] Each: label, url.
	 */
	private static function custom_links() {
		$raw   = (string) Settings::get( 'console_links', '' );
		$out   = array();
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( count( $parts ) === 2 && '' !== $parts[1] ) {
				$out[] = array( 'label' => $parts[0], 'url' => esc_url_raw( $parts[1] ) );
			} else {
				$out[] = array( 'label' => $parts[0], 'url' => esc_url_raw( $parts[0] ) );
			}
		}
		return $out;
	}
}
