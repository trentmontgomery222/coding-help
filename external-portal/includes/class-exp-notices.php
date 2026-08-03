<?php
/**
 * Post/Redirect/Get flash notices.
 *
 * Notices are stashed in a short-lived transient keyed by a random token; the
 * token travels in the redirect URL (?exp_msg=token). This keeps sensitive text
 * out of the URL and avoids duplicate-submit on refresh.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flash-notice store.
 */
class EXP_Notices {

	/**
	 * Stash notices and return a token to append to a redirect URL.
	 *
	 * @param array $notices Array of ['type'=>, 'text'=>].
	 * @return string Token.
	 */
	public static function set( array $notices ) {
		$token = EXP_Util::random_token( 8 );
		set_transient( 'exp_notice_' . $token, $notices, 5 * MINUTE_IN_SECONDS );
		return $token;
	}

	/**
	 * Retrieve and delete notices for a token.
	 *
	 * @param string $token Token.
	 * @return array
	 */
	public static function take( $token ) {
		$token = preg_replace( '/[^a-f0-9]/', '', (string) $token );
		if ( '' === $token ) {
			return array();
		}
		$key      = 'exp_notice_' . $token;
		$notices  = get_transient( $key );
		delete_transient( $key );
		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * Convenience: notices from the current request's ?exp_msg token.
	 *
	 * @return array
	 */
	public static function from_request() {
		if ( empty( $_GET['exp_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return array();
		}
		return self::take( sanitize_text_field( wp_unslash( $_GET['exp_msg'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
}
