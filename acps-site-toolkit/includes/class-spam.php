<?php
/**
 * Layered, first-party spam prevention (spec §7.4). No third-party CAPTCHA.
 *
 * Order matters: cheap silent checks first (honeypot, time-trap), then nonce,
 * then rate limit, then content blocklist, then the optional challenge.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spam.
 */
class Spam {

	const NONCE_ACTION = 'acps_st_submit';

	/**
	 * Evaluate a submission. Returns array( 'spam' => bool, 'silent' => bool,
	 * 'message' => string ). "silent" means we should pretend success and
	 * discard, giving the bot no signal (honeypot/time-trap).
	 *
	 * @param array $request Combined request data.
	 * @param Form  $form    Target form.
	 * @return array
	 */
	public static function check( $request, Form $form ) {
		$result = array( 'spam' => false, 'silent' => false, 'message' => '' );

		// 1. Honeypot — the field name is dynamic; JS moves the value into
		// acps_hp. A filled honeypot = bot.
		if ( Settings::get( 'spam_honeypot' ) && ! empty( $request['acps_hp'] ) ) {
			return array( 'spam' => true, 'silent' => true, 'message' => '' );
		}

		// 2. Time trap — reject faster-than-human submissions.
		if ( Settings::get( 'spam_time_trap' ) ) {
			$threshold = (int) Settings::get( 'spam_time_threshold', 3 );
			$ts        = isset( $request['acps_ts'] ) ? (int) $request['acps_ts'] : 0;
			if ( $ts > 0 && ( time() - $ts ) < $threshold ) {
				return array( 'spam' => true, 'silent' => true, 'message' => '' );
			}
		}

		// 4. Rate limiting by anonymized IP + session.
		if ( (int) Settings::get( 'spam_rate_limit', 0 ) > 0 && self::is_rate_limited() ) {
			return array(
				'spam'    => true,
				'silent'  => false,
				'message' => __( 'You are submitting too frequently. Please wait a moment and try again.', 'acps-site-toolkit' ),
			);
		}

		// 5. Keyword / pattern blocklist over all submitted values.
		$blocklist = self::blocklist();
		if ( $blocklist ) {
			$haystack = strtolower( wp_json_encode( isset( $request['fields'] ) ? $request['fields'] : array() ) );
			foreach ( $blocklist as $term ) {
				if ( '' !== $term && false !== strpos( $haystack, $term ) ) {
					return array( 'spam' => true, 'silent' => true, 'message' => '' );
				}
			}
		}

		// 6. Optional accessible challenge.
		if ( Settings::get( 'spam_challenge_enable' ) && Settings::get( 'spam_challenge_q' ) ) {
			$expected = strtolower( trim( (string) Settings::get( 'spam_challenge_a' ) ) );
			$given    = strtolower( trim( (string) ( $request['acps_challenge'] ?? '' ) ) );
			if ( '' !== $expected && $given !== $expected ) {
				return array(
					'spam'    => false, // treated as a validation failure, not silent spam.
					'silent'  => false,
					'message' => __( 'The answer to the verification question was incorrect.', 'acps-site-toolkit' ),
				);
			}
		}

		return $result;
	}

	/**
	 * Verify the submission nonce (spec §7.4 layer 3 — also CSRF protection).
	 *
	 * @param string $nonce Nonce value.
	 * @return bool
	 */
	public static function verify_nonce( $nonce ) {
		return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Rate-limit check using a transient counter keyed to IP + session token.
	 *
	 * @return bool True when over the limit.
	 */
	private static function is_rate_limited() {
		$limit  = (int) Settings::get( 'spam_rate_limit', 10 );
		$window = (int) Settings::get( 'spam_rate_window', 60 ) * MINUTE_IN_SECONDS;

		$ip  = Session::anonymize_ip( Session::client_ip() );
		$key = 'acps_st_rl_' . md5( $ip . '|' . ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ); // phpcs:ignore

		$count = (int) get_transient( $key );
		$count++;
		set_transient( $key, $count, $window );

		return $count > $limit;
	}

	/**
	 * Parsed blocklist terms.
	 *
	 * @return string[]
	 */
	private static function blocklist() {
		$raw = (string) Settings::get( 'spam_blocklist', '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$terms = preg_split( '/\r\n|\r|\n|,/', $raw );
		$terms = array_filter( array_map( 'trim', array_map( 'strtolower', $terms ) ) );
		return array_values( $terms );
	}
}
