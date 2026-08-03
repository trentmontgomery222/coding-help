<?php
/**
 * Portal session management (spec Section 4, table 3 + Section 3).
 *
 * This is the portal's OWN session system. Its gatekeeper NEVER calls
 * is_user_logged_in() or any WordPress capability check.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cookie/token-backed sessions for portal users.
 */
class EXP_Session {

	/**
	 * Current authenticated portal user for this request (cached).
	 *
	 * @var object|false|null null = not resolved yet, false = none.
	 */
	protected static $current = null;

	/**
	 * Create a session for a user and set the cookie.
	 *
	 * @param object $user Portal user row.
	 * @return object|WP_Error Session row on success.
	 */
	public static function create( $user ) {
		global $wpdb;

		$raw_token  = EXP_Util::random_token( 32 );
		$csrf       = EXP_Util::random_token( 16 );
		$absolute   = (int) EXP_Settings::get( 'session_absolute_hours', 12 ) * HOUR_IN_SECONDS;
		// expires_at is the ABSOLUTE (hard) cap. The inactivity timeout is enforced
		// separately against last_activity_at in current_user().
		$expires_at = EXP_Util::mysql_time( $absolute );

		$ok = $wpdb->insert(
			EXP_Install::table( 'sessions' ),
			array(
				'user_id'          => (int) $user->id,
				'token_hash'       => EXP_Util::hmac( $raw_token ),
				'csrf_token'       => $csrf,
				'ip'               => EXP_Util::client_ip(),
				'user_agent'       => EXP_Util::user_agent(),
				'last_activity_at' => EXP_Util::now(),
				'expires_at'       => $expires_at,
				'created_at'       => EXP_Util::now(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'exp_session_failed', __( 'Could not start a session.', 'external-portal' ) );
		}

		self::set_cookie( $raw_token, $absolute );
		self::$current = $user;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . EXP_Install::table( 'sessions' ) . ' WHERE id = %d', $wpdb->insert_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Resolve and validate the current session from the cookie.
	 * Enforces idle timeout + absolute lifetime, and refreshes activity.
	 *
	 * @return object|false The portal user row, or false if unauthenticated.
	 */
	public static function current_user() {
		if ( null !== self::$current ) {
			return self::$current;
		}
		self::$current = false;

		if ( empty( $_COOKIE[ EXP_SESSION_COOKIE ] ) ) {
			return false;
		}

		global $wpdb;
		$raw   = sanitize_text_field( wp_unslash( $_COOKIE[ EXP_SESSION_COOKIE ] ) );
		$table = EXP_Install::table( 'sessions' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", EXP_Util::hmac( $raw ) ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		if ( ! $row || (int) $row->revoked === 1 ) {
			self::clear_cookie();
			return false;
		}

		// Absolute lifetime.
		if ( EXP_Util::is_past( $row->expires_at ) ) {
			self::destroy_by_id( $row->id );
			self::clear_cookie();
			return false;
		}

		// Idle timeout.
		$idle_limit = (int) EXP_Settings::get( 'session_idle_minutes', 30 ) * MINUTE_IN_SECONDS;
		$last       = strtotime( $row->last_activity_at . ' UTC' );
		if ( ( time() - $last ) > $idle_limit ) {
			self::destroy_by_id( $row->id );
			self::clear_cookie();
			return false;
		}

		$user = EXP_Users::get( $row->user_id );
		if ( ! $user || EXP_Users::STATUS_DISABLED === $user->status ) {
			self::destroy_by_id( $row->id );
			self::clear_cookie();
			return false;
		}

		// Slide the idle window forward (throttled to once/minute to limit writes).
		if ( ( time() - $last ) > MINUTE_IN_SECONDS ) {
			$wpdb->update(
				$table,
				array( 'last_activity_at' => EXP_Util::now() ),
				array( 'id' => $row->id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		// Expose the active CSRF token + expiry for the current request.
		$user->_session_id   = (int) $row->id;
		$user->_csrf_token   = $row->csrf_token;
		$user->_expires_at   = $row->expires_at;
		$user->_idle_expires = gmdate( 'Y-m-d H:i:s', $last + $idle_limit );

		self::$current = $user;
		return $user;
	}

	/**
	 * Is there a live portal session? This is THE gatekeeper.
	 *
	 * @return bool
	 */
	public static function is_authenticated() {
		return (bool) self::current_user();
	}

	/**
	 * The CSRF token for the current session (empty if none).
	 *
	 * @return string
	 */
	public static function csrf_token() {
		$user = self::current_user();
		return $user && isset( $user->_csrf_token ) ? $user->_csrf_token : '';
	}

	/**
	 * Validate a submitted CSRF token against the current session.
	 *
	 * @param string $token Submitted token.
	 * @return bool
	 */
	public static function verify_csrf( $token ) {
		$expected = self::csrf_token();
		return '' !== $expected && is_string( $token ) && hash_equals( $expected, $token );
	}

	/**
	 * Destroy the current session (logout).
	 */
	public static function destroy_current() {
		$user = self::current_user();
		if ( $user && isset( $user->_session_id ) ) {
			self::destroy_by_id( $user->_session_id );
			EXP_Audit::log(
				'logout',
				array(
					'actor_type' => 'portal',
					'actor_id'   => $user->id,
					'object_ref' => 'user:' . $user->id,
				)
			);
		}
		self::clear_cookie();
		self::$current = false;
	}

	/**
	 * Revoke a specific session row.
	 *
	 * @param int $id Session id.
	 */
	public static function destroy_by_id( $id ) {
		global $wpdb;
		$wpdb->update(
			EXP_Install::table( 'sessions' ),
			array( 'revoked' => 1 ),
			array( 'id' => (int) $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Force-expire ALL sessions for a user (admin action).
	 *
	 * @param int $user_id User id.
	 * @return int Rows affected.
	 */
	public static function revoke_all_for_user( $user_id ) {
		global $wpdb;
		$affected = $wpdb->update(
			EXP_Install::table( 'sessions' ),
			array( 'revoked' => 1 ),
			array(
				'user_id' => (int) $user_id,
				'revoked' => 0,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);
		EXP_Audit::log(
			'session.revoked_all',
			array(
				'actor_type' => 'admin',
				'actor_id'   => get_current_user_id(),
				'object_ref' => 'user:' . (int) $user_id,
			)
		);
		return (int) $affected;
	}

	/**
	 * Delete expired/revoked sessions. Called from cron.
	 */
	public static function purge_expired() {
		global $wpdb;
		$table = EXP_Install::table( 'sessions' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at < %s OR revoked = 1", // phpcs:ignore WordPress.DB.PreparedSQL
				EXP_Util::mysql_time( -1 * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Set the portal session cookie. HttpOnly, Secure (when TLS), SameSite=Lax.
	 *
	 * @param string $raw_token   Raw (unhashed) token.
	 * @param int    $max_age_sec Cookie lifetime in seconds.
	 */
	protected static function set_cookie( $raw_token, $max_age_sec ) {
		$secure = is_ssl();
		$params = array(
			'expires'  => time() + $max_age_sec,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		);
		if ( ! headers_sent() ) {
			setcookie( EXP_SESSION_COOKIE, $raw_token, $params );
			$_COOKIE[ EXP_SESSION_COOKIE ] = $raw_token;
		}
	}

	/**
	 * Clear the session cookie.
	 */
	protected static function clear_cookie() {
		if ( ! headers_sent() ) {
			setcookie(
				EXP_SESSION_COOKIE,
				'',
				array(
					'expires'  => time() - HOUR_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		unset( $_COOKIE[ EXP_SESSION_COOKIE ] );
	}
}
