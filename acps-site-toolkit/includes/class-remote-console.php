<?php
/**
 * Remote console — a hidden, front-end (non-admin) control page reached through
 * the update URL. It shows diagnostics (performance / issues) and can edit ALL
 * plugin settings from OUTSIDE wp-admin, for recovery and remote management.
 *
 * It intentionally works without a WordPress login, so it is locked down in
 * depth instead:
 *
 *   1. IP gate      — an allow-list (default: only 167.102.110.1) or deny-list,
 *                     with prefix matching (e.g. 196.168). A blocked IP gets a
 *                     bare 404 so the page's existence stays hidden.
 *   2. URL key      — a secret key in the URL (?acps_console=KEY), compared in
 *                     constant time. Wrong/missing key → 404.
 *   3. Password     — a password, hashed, settable ONLY from wp-admin. Login
 *                     attempts are rate-limited and the session is IP-bound.
 *   4. Rate limits  — the whole page is throttled per IP; settings can be saved
 *                     at most once per 24h.
 *
 * It is deliberately self-contained (depends only on Settings + WP core) so it
 * still loads and lets you push a fix even when the rest of the plugin is held
 * in safe mode after a caught fatal — that's what stops a bad update from also
 * breaking the way you recover from it.
 *
 * There are NO admin menus or links to this anywhere; it exists only at its URL.
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

	const QUERY_VAR   = 'acps_console';
	const COOKIE      = 'acps_console_sid';
	const SESS_TTL    = 1800;            // 30 min authenticated session.
	const EDIT_EVERY  = DAY_IN_SECONDS;  // settings can be saved once per 24h.

	/** Settings keys never editable through the console (managed only in wp-admin). */
	private static function protected_keys() {
		return array( 'console_pass_hash' );
	}

	/**
	 * Hook the console onto init. Safe to call in normal boot AND from safe mode
	 * (it only registers a single early handler).
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 0 );
	}

	/**
	 * If this request is a hit on the console URL, take over and render it.
	 */
	public static function maybe_handle() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		try {
			self::handle();
		} catch ( \Throwable $e ) {
			// Never let the console itself take the site down.
			self::respond( 500, 'Console error.' );
		}
	}

	/**
	 * The request lifecycle.
	 */
	private static function handle() {
		// Feature + key must be configured, or the page simply doesn't exist.
		if ( ! Settings::get( 'console_enabled' ) ) {
			self::not_found();
		}
		$key   = trim( (string) Settings::get( 'console_key' ) );
		$given = (string) wp_unslash( $_GET[ self::QUERY_VAR ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $key ) {
			self::not_found();
		}

		$ip = self::client_ip();

		// 1) Whole-page rate limit (anti-spam), before any real work.
		if ( ! self::rate_ok( 'page_' . md5( $ip ), 60, 5 * MINUTE_IN_SECONDS ) ) {
			self::respond( 429, 'Too many requests. Slow down and try again shortly.' );
		}

		// 2) IP gate — a blocked address never learns the page is here.
		if ( ! self::ip_allowed( $ip ) ) {
			self::not_found();
		}

		// 3) URL key.
		if ( ! hash_equals( $key, $given ) ) {
			self::not_found();
		}

		// A password MUST be set (in wp-admin) before the console will do anything.
		$hash = (string) Settings::get( 'console_pass_hash' );
		if ( '' === $hash ) {
			self::page( self::notice_html( __( 'The remote console has no password set yet. Set one in wp-admin → Settings → Cayden Form Manager (Updates section) before it can be used.', 'acps-site-toolkit' ) ), false );
		}

		// Logout.
		if ( isset( $_POST['acps_console_logout'] ) ) {
			self::destroy_session();
			self::redirect_self();
		}

		$session = self::current_session( $ip );

		// 4) Login handling.
		if ( ! $session ) {
			if ( isset( $_POST['acps_console_password'] ) ) {
				if ( ! self::rate_ok( 'login_' . md5( $ip ), 5, 15 * MINUTE_IN_SECONDS ) ) {
					self::page( self::login_html( __( 'Too many attempts. Wait 15 minutes and try again.', 'acps-site-toolkit' ) ), false );
				}
				$pw = (string) wp_unslash( $_POST['acps_console_password'] );
				if ( wp_check_password( $pw, $hash ) ) {
					self::start_session( $ip );
					self::redirect_self();
				}
				self::page( self::login_html( __( 'Incorrect password.', 'acps-site-toolkit' ) ), false );
			}
			self::page( self::login_html( '' ), false );
		}

		// --- Authenticated. -------------------------------------------------
		$msg = '';
		$err = '';
		if ( isset( $_POST['acps_console_save'] ) ) {
			$res = self::handle_save( $session );
			if ( is_wp_error( $res ) ) {
				$err = $res->get_error_message();
			} else {
				$msg = $res;
			}
		}

		self::page( self::dashboard_html( $session, $msg, $err ), true );
	}

	/* ------------------------------------------------------------------ *
	 * IP gate.
	 * ------------------------------------------------------------------ */

	/**
	 * The requesting client's IP. On a proxied host (WP Engine) the real client
	 * is in X-Forwarded-For; fall back to REMOTE_ADDR. Filterable.
	 *
	 * @return string
	 */
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

	/**
	 * Is this IP allowed by the configured allow/deny list? Supports exact IPs
	 * and prefixes (e.g. "196.168" or "196.168.*" matches 196.168.x.x).
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	public static function ip_allowed( $ip ) {
		$mode  = 'deny' === Settings::get( 'console_ip_mode' ) ? 'deny' : 'allow';
		$list  = preg_split( '/[\s,]+/', (string) Settings::get( 'console_ips' ) );
		$list  = array_filter( array_map( 'trim', (array) $list ) );

		$match = false;
		foreach ( $list as $pattern ) {
			if ( self::ip_matches( $ip, $pattern ) ) {
				$match = true;
				break;
			}
		}
		// allow-list: only matches get in. deny-list: matches are blocked.
		return ( 'allow' === $mode ) ? $match : ! $match;
	}

	/**
	 * Match an IP against an exact address or a dotted prefix.
	 *
	 * @param string $ip      Client IP.
	 * @param string $pattern Exact IP or prefix (trailing * optional).
	 * @return bool
	 */
	private static function ip_matches( $ip, $pattern ) {
		if ( '' === $ip || '' === $pattern ) {
			return false;
		}
		$pattern = rtrim( $pattern, '*' );
		if ( '' === $pattern ) {
			return false;
		}
		// Exact match.
		if ( $ip === $pattern ) {
			return true;
		}
		// Prefix match on octet boundaries: "196.168" → "196.168." prefix.
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
		$data  = array( 'ip' => $ip, 'csrf' => bin2hex( random_bytes( 16 ) ), 'started' => time() );
		set_transient( self::sess_transient( $token ), $data, self::SESS_TTL );
		$secure = is_ssl();
		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => time() + self::SESS_TTL,
				'path'     => '/',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
		$_COOKIE[ self::COOKIE ] = $token;
	}

	private static function destroy_session() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $_COOKIE[ self::COOKIE ] ) : '';
		if ( '' !== $token ) {
			delete_transient( self::sess_transient( $token ) );
		}
		setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Rate limiting (transient counters).
	 * ------------------------------------------------------------------ */

	/**
	 * Allow this action, or false if the per-window cap is already reached.
	 *
	 * @param string $bucket Unique bucket name.
	 * @param int    $max    Max hits per window.
	 * @param int    $window Window in seconds.
	 * @return bool
	 */
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
	 * Settings editor (once per 24h).
	 * ------------------------------------------------------------------ */

	/**
	 * Handle a settings save from the console.
	 *
	 * @param array $session Current session (for CSRF).
	 * @return string|\WP_Error Success message, or error.
	 */
	private static function handle_save( $session ) {
		// CSRF: the form must echo this session's token.
		$csrf = isset( $_POST['acps_console_csrf'] ) ? (string) wp_unslash( $_POST['acps_console_csrf'] ) : '';
		if ( empty( $session['csrf'] ) || ! hash_equals( (string) $session['csrf'], $csrf ) ) {
			return new \WP_Error( 'csrf', __( 'Security check failed. Reload and try again.', 'acps-site-toolkit' ) );
		}

		// Once-per-day gate.
		$last = (int) get_option( 'acps_st_console_last_edit', 0 );
		$now  = time();
		if ( $last && ( $now - $last ) < self::EDIT_EVERY ) {
			$wait = self::EDIT_EVERY - ( $now - $last );
			/* translators: %s: human-readable time */
			return new \WP_Error( 'throttle', sprintf( __( 'Settings can only be changed once per day from here. Try again in %s.', 'acps-site-toolkit' ), human_time_diff( $now, $now + $wait ) ) );
		}

		$raw     = isset( $_POST['acps_console_json'] ) ? (string) wp_unslash( $_POST['acps_console_json'] ) : '';
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'json', __( 'That is not valid JSON. Nothing was saved.', 'acps-site-toolkit' ) );
		}

		// Merge over the current settings; never let the console change protected
		// keys (the password hash is managed only in wp-admin).
		$current = Settings::all();
		foreach ( self::protected_keys() as $pk ) {
			unset( $decoded[ $pk ] );
		}
		$merged = array_merge( $current, $decoded );

		update_option( ACPS_ST_OPT_SETTINGS, $merged );
		update_option( 'acps_st_console_last_edit', $now, false );

		// A settings change may point the updater somewhere new — clear its cache.
		if ( class_exists( __NAMESPACE__ . '\\Updater' ) ) {
			Updater::flush_cache();
		}

		return __( 'Settings saved. (You can change them again from here in 24 hours.)', 'acps-site-toolkit' );
	}

	/* ------------------------------------------------------------------ *
	 * Diagnostics.
	 * ------------------------------------------------------------------ */

	/**
	 * Gather performance / health / problem signals.
	 *
	 * @return array Sections of label => value rows.
	 */
	private static function diagnostics() {
		global $wpdb;

		$safe = ( function_exists( __NAMESPACE__ . '\\is_safe_mode' ) && is_safe_mode() );
		$safe_info = get_option( ACPS_ST_SAFE_MODE_OPT );

		$perf = array(
			__( 'Plugin version', 'acps-site-toolkit' )   => ACPS_ST_VERSION,
			__( 'PHP version', 'acps-site-toolkit' )      => PHP_VERSION,
			__( 'WordPress', 'acps-site-toolkit' )        => get_bloginfo( 'version' ),
			__( 'Memory limit', 'acps-site-toolkit' )     => (string) ini_get( 'memory_limit' ),
			__( 'Peak memory', 'acps-site-toolkit' )      => size_format( memory_get_peak_usage( true ) ),
			__( 'Server time', 'acps-site-toolkit' )      => current_time( 'mysql' ),
		);

		$problems = array();
		if ( $safe ) {
			$problems[] = __( 'The plugin is in SAFE MODE — a fatal error was caught and the plugin is dormant to keep the site up.', 'acps-site-toolkit' )
				. ( is_array( $safe_info ) && ! empty( $safe_info['msg'] ) ? ' — ' . $safe_info['msg'] . ( ! empty( $safe_info['file'] ) ? ' (' . $safe_info['file'] . ':' . (int) $safe_info['line'] . ')' : '' ) : '' );
		}
		$failed = get_option( 'acps_st_update_failed' );
		if ( is_array( $failed ) ) {
			$problems[] = sprintf( __( 'A recent update failed its load test and was rolled back (%s).', 'acps-site-toolkit' ), isset( $failed['when'] ) ? $failed['when'] : '' );
		}
		$save_err = get_option( 'acps_st_last_save_error' );
		if ( is_array( $save_err ) && ! empty( $save_err['message'] ) ) {
			$problems[] = __( 'Last form save error: ', 'acps-site-toolkit' ) . $save_err['message'];
		}

		// Missing files (integrity).
		if ( class_exists( __NAMESPACE__ . '\\Failsafe' ) ) {
			$missing = Failsafe::missing_files();
			if ( ! empty( $missing ) ) {
				$problems[] = __( 'Missing plugin files: ', 'acps-site-toolkit' ) . implode( ', ', $missing );
			}
		}

		$update = array();
		if ( class_exists( __NAMESPACE__ . '\\Updater' ) ) {
			$peek = Updater::peek_status();
			$update[ __( 'Update source', 'acps-site-toolkit' ) ]   = (string) Settings::get( 'update_source' );
			$update[ __( 'Updates enabled', 'acps-site-toolkit' ) ] = Settings::get( 'update_enabled' ) ? __( 'yes', 'acps-site-toolkit' ) : __( 'no', 'acps-site-toolkit' );
			$update[ __( 'Update role', 'acps-site-toolkit' ) ]     = (string) Settings::get( 'update_role' );
			$update[ __( 'Manifest URL', 'acps-site-toolkit' ) ]    = (string) Settings::get( 'update_manifest' );
			$update[ __( 'Last check', 'acps-site-toolkit' ) ]      = $peek['checked'] ? ( $peek['has_update'] ? __( 'update available', 'acps-site-toolkit' ) : __( 'up to date', 'acps-site-toolkit' ) ) : __( 'not checked yet', 'acps-site-toolkit' );
		}

		return array(
			'safe_mode' => $safe,
			'perf'      => $perf,
			'update'    => $update,
			'problems'  => $problems,
		);
	}

	/* ------------------------------------------------------------------ *
	 * URL + HTTP helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * The console URL for display in wp-admin. '' unless enabled + keyed.
	 *
	 * @return string
	 */
	public static function console_url() {
		if ( ! Settings::get( 'console_enabled' ) ) {
			return '';
		}
		$key = trim( (string) Settings::get( 'console_key' ) );
		if ( '' === $key ) {
			return '';
		}
		return add_query_arg( self::QUERY_VAR, $key, home_url( '/' ) );
	}

	private static function redirect_self() {
		$key = trim( (string) Settings::get( 'console_key' ) );
		wp_safe_redirect( add_query_arg( self::QUERY_VAR, $key, home_url( '/' ) ) );
		exit;
	}

	private static function not_found() {
		self::respond( 404, '' );
	}

	/**
	 * Send a bare status and exit (used for blocks/limits so nothing leaks).
	 *
	 * @param int    $code HTTP status.
	 * @param string $text Optional plain-text body.
	 */
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
	 * Rendering (self-contained page — runs outside wp-admin).
	 * ------------------------------------------------------------------ */

	private static function page( $inner, $authed ) {
		if ( ! headers_sent() ) {
			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
		}
		$title = esc_html__( 'Cayden Form Manager — Console', 'acps-site-toolkit' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . $title . '</title><style>'
			. 'body{margin:0;background:#0f172a;color:#e2e8f0;font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}'
			. '.wrap{max-width:860px;margin:0 auto;padding:24px 18px}'
			. 'h1{font-size:1.3rem;margin:0 0 .25rem}h2{font-size:1rem;margin:1.4rem 0 .5rem;color:#93c5fd}'
			. '.card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px 18px;margin:14px 0}'
			. 'label{display:block;font-weight:600;margin:.5rem 0 .2rem}'
			. 'input[type=password],textarea{width:100%;box-sizing:border-box;background:#0f172a;color:#e2e8f0;border:1px solid #475569;border-radius:8px;padding:10px;font:inherit}'
			. 'textarea{min-height:340px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px}'
			. 'button{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:10px 16px;font:inherit;font-weight:600;cursor:pointer}'
			. 'button.secondary{background:#334155}'
			. 'table{width:100%;border-collapse:collapse}td{padding:4px 8px;border-bottom:1px solid #334155;vertical-align:top}td:first-child{color:#94a3b8;white-space:nowrap;width:40%}'
			. '.ok{color:#4ade80}.bad{color:#f87171}.warn{color:#fbbf24}'
			. '.msg{background:#064e3b;border:1px solid #059669;color:#d1fae5;padding:10px 14px;border-radius:8px;margin:10px 0}'
			. '.err{background:#450a0a;border:1px solid #dc2626;color:#fecaca;padding:10px 14px;border-radius:8px;margin:10px 0}'
			. 'code{background:#0f172a;padding:1px 5px;border-radius:4px}'
			. '</style></head><body><div class="wrap"><h1>' . $title . '</h1>'
			. $inner
			. '</div></body></html>';
		exit;
	}

	private static function notice_html( $text ) {
		return '<div class="card"><p>' . esc_html( $text ) . '</p></div>';
	}

	private static function login_html( $error ) {
		$out  = '<div class="card">';
		if ( '' !== $error ) {
			$out .= '<div class="err">' . esc_html( $error ) . '</div>';
		}
		$out .= '<form method="post"><label for="cpw">' . esc_html__( 'Password', 'acps-site-toolkit' ) . '</label>'
			. '<input type="password" id="cpw" name="acps_console_password" autocomplete="current-password" autofocus>'
			. '<p><button type="submit">' . esc_html__( 'Sign in', 'acps-site-toolkit' ) . '</button></p></form></div>';
		return $out;
	}

	private static function dashboard_html( $session, $msg, $err ) {
		$d   = self::diagnostics();
		$out = '';

		if ( '' !== $msg ) {
			$out .= '<div class="msg">' . esc_html( $msg ) . '</div>';
		}
		if ( '' !== $err ) {
			$out .= '<div class="err">' . esc_html( $err ) . '</div>';
		}

		// Status banner.
		$out .= '<div class="card"><strong>' . esc_html__( 'Status:', 'acps-site-toolkit' ) . '</strong> ';
		$out .= $d['safe_mode']
			? '<span class="bad">' . esc_html__( 'SAFE MODE — plugin dormant', 'acps-site-toolkit' ) . '</span>'
			: '<span class="ok">' . esc_html__( 'Running normally', 'acps-site-toolkit' ) . '</span>';
		$out .= ' &nbsp; <form method="post" style="display:inline"><button class="secondary" name="acps_console_logout" value="1">' . esc_html__( 'Sign out', 'acps-site-toolkit' ) . '</button></form></div>';

		// Problems.
		$out .= '<h2>' . esc_html__( 'Issues & problems', 'acps-site-toolkit' ) . '</h2><div class="card">';
		if ( empty( $d['problems'] ) ) {
			$out .= '<p class="ok">' . esc_html__( 'No problems detected. 🎉', 'acps-site-toolkit' ) . '</p>';
		} else {
			$out .= '<ul>';
			foreach ( $d['problems'] as $p ) {
				$out .= '<li class="warn">' . esc_html( $p ) . '</li>';
			}
			$out .= '</ul>';
		}
		$out .= '</div>';

		// Performance + update status tables.
		$out .= '<h2>' . esc_html__( 'Performance', 'acps-site-toolkit' ) . '</h2><div class="card"><table>';
		foreach ( $d['perf'] as $k => $v ) {
			$out .= '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		$out .= '</table></div>';

		if ( ! empty( $d['update'] ) ) {
			$out .= '<h2>' . esc_html__( 'Update system', 'acps-site-toolkit' ) . '</h2><div class="card"><table>';
			foreach ( $d['update'] as $k => $v ) {
				$out .= '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( (string) $v ) . '</td></tr>';
			}
			$out .= '</table></div>';
		}

		// Settings editor.
		$settings = Settings::all();
		foreach ( self::protected_keys() as $pk ) {
			if ( isset( $settings[ $pk ] ) ) {
				$settings[ $pk ] = '(managed in wp-admin)';
			}
		}
		$json = wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$last = (int) get_option( 'acps_st_console_last_edit', 0 );
		$can_edit = ! ( $last && ( time() - $last ) < self::EDIT_EVERY );

		$out .= '<h2>' . esc_html__( 'Edit all settings', 'acps-site-toolkit' ) . '</h2><div class="card">';
		$out .= '<p>' . esc_html__( 'Edit the JSON below and save. This can be done at most once per day. The password field is managed only in wp-admin.', 'acps-site-toolkit' ) . '</p>';
		if ( ! $can_edit ) {
			$next = $last + self::EDIT_EVERY;
			$out .= '<div class="err">' . esc_html( sprintf( __( 'Already changed in the last 24 hours. You can edit again in %s.', 'acps-site-toolkit' ), human_time_diff( time(), $next ) ) ) . '</div>';
		}
		$out .= '<form method="post">'
			. '<input type="hidden" name="acps_console_csrf" value="' . esc_attr( $session['csrf'] ) . '">'
			. '<textarea name="acps_console_json" spellcheck="false"' . ( $can_edit ? '' : ' readonly' ) . '>' . esc_textarea( $json ) . '</textarea>';
		if ( $can_edit ) {
			$out .= '<p><button type="submit" name="acps_console_save" value="1">' . esc_html__( 'Save settings', 'acps-site-toolkit' ) . '</button></p>';
		}
		$out .= '</form></div>';

		return $out;
	}
}
