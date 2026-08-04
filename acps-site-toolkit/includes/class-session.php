<?php
/**
 * Session handling for journey tracking.
 *
 * Sessions are first-party and session-scoped (spec §4.3). The token is
 * generated client-side and sent with each beacon; the server maps it to a
 * row. IPs are anonymized before storage (spec §4.5).
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Session.
 */
class Session {

	/**
	 * Look up a session row id by token, creating it if needed.
	 *
	 * @param string $token   40-char client token.
	 * @param array  $context Optional context for a freshly-created session.
	 * @return int|null Session row id, or null if token invalid.
	 */
	public static function resolve( $token, $context = array() ) {
		$token = self::sanitize_token( $token );
		if ( '' === $token ) {
			return null;
		}

		global $wpdb;
		$table = Schema::table( 'sessions' );
		$now   = current_time( 'mysql' );

		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE session_token = %s", $token ) ); // phpcs:ignore WordPress.DB

		if ( $id ) {
			// Expire stale sessions: if idle beyond the window, start a fresh row
			// under the same token so the sequence restarts.
			$last = $wpdb->get_var( $wpdb->prepare( "SELECT last_activity_at FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
			$idle = (int) Settings::get( 'session_idle_minutes', 30 ) * MINUTE_IN_SECONDS;
			if ( $last && ( time() - strtotime( $last . ' GMT' ) ) > $idle ) {
				// Rotate: delete-then-recreate would break FKs, so we just reset
				// the started_at and let seq_index continue from the visits side.
				$wpdb->update( $table, array( 'started_at' => $now, 'last_activity_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
			} else {
				$wpdb->update( $table, array( 'last_activity_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
			}
			return (int) $id;
		}

		$defaults = array(
			'session_token'      => $token,
			'started_at'         => $now,
			'last_activity_at'   => $now,
			'ip_anon'            => self::anonymize_ip( self::client_ip() ),
			'user_agent_summary' => self::user_agent_summary(),
			'device_type'        => isset( $context['device_type'] ) ? sanitize_text_field( $context['device_type'] ) : self::device_type(),
			'viewport'           => isset( $context['viewport'] ) ? sanitize_text_field( $context['viewport'] ) : null,
			'user_id'            => get_current_user_id() ?: null,
			'entry_page_id'      => isset( $context['post_id'] ) ? absint( $context['post_id'] ) : null,
			'entry_url'          => isset( $context['url'] ) ? esc_url_raw( $context['url'] ) : null,
			'referrer'           => isset( $context['referrer'] ) ? esc_url_raw( $context['referrer'] ) : null,
			'consent'            => ! empty( $context['consent'] ) ? 1 : 0,
		);

		$wpdb->insert( $table, $defaults ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	/**
	 * Look up a session id by token WITHOUT creating one. Used by read/write
	 * endpoints that must not spawn empty sessions (recent-pages, unload).
	 *
	 * @param string $token Client token.
	 * @return int|null
	 */
	public static function lookup( $token ) {
		$token = self::sanitize_token( $token );
		if ( '' === $token ) {
			return null;
		}
		global $wpdb;
		$table = Schema::table( 'sessions' );
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE session_token = %s", $token ) ); // phpcs:ignore WordPress.DB
		return $id ? (int) $id : null;
	}

	/**
	 * Bump a session's last-activity timestamp (heartbeat).
	 *
	 * @param int $session_id Session id.
	 */
	public static function touch( $session_id ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Schema::table( 'sessions' ),
			array( 'last_activity_at' => current_time( 'mysql' ) ),
			array( 'id' => absint( $session_id ) )
		);
	}

	/**
	 * Validate a session token format (40 hex chars).
	 *
	 * @param string $token Raw token.
	 * @return string Sanitized token or empty string.
	 */
	public static function sanitize_token( $token ) {
		$token = is_string( $token ) ? strtolower( trim( $token ) ) : '';
		return preg_match( '/^[a-f0-9]{40}$/', $token ) ? $token : '';
	}

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	public static function client_ip() {
		// WP Engine sits behind a CDN; the real client is typically in
		// X-Forwarded-For. Take the first hop, then anonymize.
		$candidates = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$raw = wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore
				$ip  = trim( explode( ',', $raw )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}

	/**
	 * Anonymize an IP by zeroing the last octet (IPv4) or last 80 bits (IPv6).
	 * Uses core's wp_privacy_anonymize_ip when available.
	 *
	 * @param string $ip Raw IP.
	 * @return string
	 */
	public static function anonymize_ip( $ip ) {
		if ( '' === $ip ) {
			return '';
		}
		if ( function_exists( 'wp_privacy_anonymize_ip' ) ) {
			return wp_privacy_anonymize_ip( $ip );
		}
		// Fallback for IPv4.
		$parts = explode( '.', $ip );
		if ( 4 === count( $parts ) ) {
			$parts[3] = '0';
			return implode( '.', $parts );
		}
		return '';
	}

	/**
	 * A short parsed browser/OS summary rather than the full UA string, unless
	 * the admin has opted to store full UA (spec §4.5).
	 *
	 * @return string
	 */
	public static function user_agent_summary() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore
		if ( '' === $ua ) {
			return '';
		}
		if ( Settings::get( 'store_full_user_agent' ) ) {
			return mb_substr( $ua, 0, 191 );
		}

		$browser = 'Other';
		foreach ( array( 'Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari' ) as $needle => $name ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$browser = $name;
				break;
			}
		}
		$os = 'Other';
		foreach ( array( 'Windows' => 'Windows', 'Mac OS' => 'macOS', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Linux' => 'Linux' ) as $needle => $name ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$os = $name;
				break;
			}
		}
		return $browser . ' / ' . $os;
	}

	/**
	 * Crude device-type classification from the UA.
	 *
	 * @return string desktop | mobile | tablet
	 */
	public static function device_type() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore
		if ( preg_match( '/iPad|Tablet/i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Mobile|Android|iPhone/i', $ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}
}
