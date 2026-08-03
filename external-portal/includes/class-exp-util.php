<?php
/**
 * Shared helpers.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small static utilities used across the plugin.
 */
class EXP_Util {

	/**
	 * Best-effort client IP. Behind WP Engine's edge we prefer the forwarded header
	 * but fall back to REMOTE_ADDR. Value is only used for throttling + audit, never
	 * for authorization decisions.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				$ip  = trim( explode( ',', $raw )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}

	/**
	 * Truncated, sanitized user agent.
	 *
	 * @return string
	 */
	public static function user_agent() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 );
	}

	/**
	 * Cryptographically secure URL-safe token.
	 *
	 * @param int $bytes Entropy in bytes.
	 * @return string
	 */
	public static function random_token( $bytes = 32 ) {
		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * Peppered hash for secrets we store (OTP codes, session tokens).
	 * Uses HMAC-SHA256 with a site-specific salt so a raw DB read isn't enough
	 * to reverse short values like OTP codes.
	 *
	 * @param string $value Secret to hash.
	 * @return string
	 */
	public static function hmac( $value ) {
		return hash_hmac( 'sha256', $value, wp_salt( 'exp_portal_pepper' ) );
	}

	/**
	 * Current UTC time as a MySQL datetime string. All stored datetimes use UTC
	 * so comparisons are timezone-safe regardless of the site's configured zone.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * MySQL UTC datetime N seconds from now.
	 *
	 * @param int $seconds Offset (may be negative).
	 * @return string
	 */
	public static function mysql_time( $seconds = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $seconds );
	}

	/**
	 * Generate a numeric OTP of the given length, zero-padded.
	 *
	 * @param int $length Digits.
	 * @return string
	 */
	public static function numeric_code( $length = 6 ) {
		$length = max( 4, min( 10, (int) $length ) );
		$max    = ( 10 ** $length ) - 1;
		return str_pad( (string) random_int( 0, $max ), $length, '0', STR_PAD_LEFT );
	}

	/**
	 * Whether a MySQL datetime string is in the past (relative to site UTC time).
	 *
	 * @param string $mysql_datetime Datetime.
	 * @return bool
	 */
	public static function is_past( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) ) {
			return true;
		}
		return strtotime( $mysql_datetime . ' UTC' ) < time();
	}
}
