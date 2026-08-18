<?php
/**
 * Editor authentication + sessions.
 *
 * These are completely separate from WordPress users. An "editor" is a row in
 * the mcm_editors table with its own hashed password. Logging in sets an
 * httponly cookie holding a random token; the token's hash is stored in the
 * mcm_sessions table alongside a per-session CSRF token.
 *
 * @package mcm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Auth {

	/** @var MCM_Auth|null */
	private static $instance = null;

	/** @var object|null Cached current editor row for this request. */
	private $current_editor = false; // false = not yet resolved.

	/** @var object|null Cached current session row. */
	private $current_session = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Opportunistic garbage collection of expired sessions.
		add_action( 'mcm_gc_sessions', array( 'MCM_DB', 'gc_sessions' ) );
		if ( ! wp_next_scheduled( 'mcm_gc_sessions' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mcm_gc_sessions' );
		}
	}

	/**
	 * The visitor's IP, best effort. Used only for lockout keys + audit.
	 *
	 * @return string
	 */
	public function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 45 );
	}

	/**
	 * Attempt to log an editor in.
	 *
	 * @param string $username
	 * @param string $password
	 * @return true|WP_Error
	 */
	public function login( $username, $password ) {
		$settings = mcm_get_settings();
		$ip       = $this->ip();
		$key      = 'mcm_login_fails_' . md5( $ip );

		$fails = (int) get_transient( $key );
		if ( $fails >= (int) $settings['max_login_fails'] ) {
			return new WP_Error(
				'mcm_locked',
				sprintf(
					/* translators: %d minutes */
					__( 'Too many failed attempts. Please try again in about %d minutes.', 'mcm' ),
					(int) $settings['lockout_minutes']
				)
			);
		}

		$username = sanitize_user( $username, true );
		$editor   = MCM_DB::get_editor_by_username( $username );

		$ok = false;
		if ( $editor && (int) $editor->active === 1 ) {
			$ok = wp_check_password( $password, $editor->password_hash, $editor->id );
		}

		if ( ! $ok ) {
			set_transient( $key, $fails + 1, (int) $settings['lockout_minutes'] * MINUTE_IN_SECONDS );
			// Deliberately vague message (no username enumeration).
			return new WP_Error( 'mcm_bad_login', __( 'Invalid username or password.', 'mcm' ) );
		}

		delete_transient( $key );
		$this->start_session( $editor );
		MCM_DB::touch_editor_login( $editor->id );

		return true;
	}

	/**
	 * Create a session row + set the cookie.
	 *
	 * @param object $editor
	 */
	private function start_session( $editor ) {
		$settings   = mcm_get_settings();
		$lifetime   = max( 1, (int) $settings['session_lifetime'] ) * HOUR_IN_SECONDS;
		$token      = wp_generate_password( 64, false, false );
		$token_hash = hash( 'sha256', $token );
		$csrf       = wp_generate_password( 32, false, false );

		// Store expiry in site-local wall-clock time so it compares directly
		// against current_time('mysql'). current_time('timestamp') already
		// includes the site's GMT offset, and gmdate() formats it without
		// re-applying that offset.
		$expires_local = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $lifetime );

		MCM_DB::create_session( $editor->id, $token_hash, $csrf, $this->ip(), $expires_local );

		// Cookie value is editor_id:token — token itself is never stored raw.
		$value = $editor->id . ':' . $token;

		$secure = is_ssl();
		setcookie(
			MCM_SESSION_COOKIE,
			$value,
			array(
				'expires'  => time() + $lifetime,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ MCM_SESSION_COOKIE ] = $value;

		// Reset request cache.
		$this->current_editor  = false;
		$this->current_session = null;
	}

	/**
	 * Resolve the current logged-in editor (or null).
	 *
	 * @return object|null editor row
	 */
	public function current_editor() {
		if ( false !== $this->current_editor ) {
			return $this->current_editor;
		}
		$this->current_editor = null;

		if ( empty( $_COOKIE[ MCM_SESSION_COOKIE ] ) ) {
			return null;
		}

		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ MCM_SESSION_COOKIE ] ) );
		if ( false === strpos( $raw, ':' ) ) {
			return null;
		}
		list( $editor_id, $token ) = explode( ':', $raw, 2 );
		$editor_id                 = absint( $editor_id );
		$token                     = preg_replace( '/[^A-Za-z0-9]/', '', $token );

		if ( ! $editor_id || '' === $token ) {
			return null;
		}

		$token_hash = hash( 'sha256', $token );
		$session    = MCM_DB::get_session_by_token_hash( $token_hash );

		if ( ! $session || (int) $session->editor_id !== $editor_id ) {
			return null;
		}

		$editor = MCM_DB::get_editor( $editor_id );
		if ( ! $editor || (int) $editor->active !== 1 ) {
			return null;
		}

		$this->current_session = $session;
		$this->current_editor  = $editor;
		return $editor;
	}

	/**
	 * @return object|null
	 */
	public function current_session() {
		if ( false === $this->current_editor ) {
			$this->current_editor();
		}
		return $this->current_session;
	}

	/**
	 * @return string CSRF token for the active session (empty if none).
	 */
	public function csrf_token() {
		$session = $this->current_session();
		return $session ? $session->csrf : '';
	}

	/**
	 * @param string $token
	 * @return bool
	 */
	public function verify_csrf( $token ) {
		$session = $this->current_session();
		if ( ! $session || empty( $session->csrf ) ) {
			return false;
		}
		return hash_equals( $session->csrf, (string) $token );
	}

	/**
	 * Log the current editor out (delete session + clear cookie).
	 */
	public function logout() {
		if ( ! empty( $_COOKIE[ MCM_SESSION_COOKIE ] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_COOKIE[ MCM_SESSION_COOKIE ] ) );
			if ( false !== strpos( $raw, ':' ) ) {
				list( , $token ) = explode( ':', $raw, 2 );
				$token           = preg_replace( '/[^A-Za-z0-9]/', '', $token );
				if ( '' !== $token ) {
					MCM_DB::delete_session_by_token_hash( hash( 'sha256', $token ) );
				}
			}
		}

		setcookie(
			MCM_SESSION_COOKIE,
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		unset( $_COOKIE[ MCM_SESSION_COOKIE ] );

		$this->current_editor  = false;
		$this->current_session = null;
	}
}
