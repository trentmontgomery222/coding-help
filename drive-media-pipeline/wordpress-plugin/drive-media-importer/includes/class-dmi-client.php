<?php
/**
 * HTTP client for the Apps Script Web App.
 *
 * Known failure modes handled here (build brief §6):
 *  - Apps Script answers with a 302 to script.googleusercontent.com; the
 *    request must be allowed to follow redirects. Because a POST following
 *    a 302 can be downgraded to GET and drop its body on some transports,
 *    we detect an unresolved redirect and retry against the Location
 *    target directly.
 *  - Custom headers are stripped across that redirect, so the shared
 *    secret travels in the POST BODY, never in an Authorization header.
 *  - An uncaught Apps Script exception returns an HTML error page, not
 *    JSON — non-JSON responses are turned into structured WP_Error.
 */

defined( 'ABSPATH' ) || exit;

class DMI_Client {

	/**
	 * POST a JSON action to the Web App and decode the JSON reply.
	 *
	 * @param array $payload Action payload; token is added automatically.
	 * @return array|WP_Error Decoded response array, or WP_Error.
	 */
	public static function request( array $payload ) {
		$settings = DMI_Settings::get();

		if ( empty( $settings['webapp_url'] ) || empty( $settings['token'] ) ) {
			return new WP_Error( 'dmi_not_configured', 'Web App URL or shared token is not configured.' );
		}

		$payload['token'] = $settings['token'];
		$body             = wp_json_encode( $payload );

		$response = self::post_following_redirects( $settings['webapp_url'], $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			// Probably an Apps Script HTML error page.
			return new WP_Error(
				'dmi_bad_response',
				sprintf(
					'Web App returned non-JSON (HTTP %d). First 200 chars: %s',
					$code,
					substr( wp_strip_all_tags( (string) $raw ), 0, 200 )
				)
			);
		}

		if ( empty( $decoded['ok'] ) ) {
			return new WP_Error(
				'dmi_' . ( $decoded['error'] ?? 'remote_error' ),
				(string) ( $decoded['message'] ?? 'Web App reported an error.' ),
				$decoded
			);
		}

		return $decoded;
	}

	/**
	 * POST with explicit handling of the Apps Script 302 hop.
	 */
	private static function post_following_redirects( $url, $body, $hop = 0 ) {
		if ( $hop > 3 ) {
			return new WP_Error( 'dmi_redirect_loop', 'Too many redirects from the Web App.' );
		}

		$args = array(
			'timeout'     => 60,
			'redirection' => 5,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => $body,
		);

		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( ! $location ) {
				return new WP_Error( 'dmi_redirect_no_location', 'Web App redirected without a Location header.' );
			}
			// Re-POST the full body to the redirect target so it is never
			// downgraded to a body-less GET.
			return self::post_following_redirects( $location, $body, $hop + 1 );
		}

		return $response;
	}

	// ------------------------------------------------------------------
	// Typed helpers for the three actions.
	// ------------------------------------------------------------------

	/** @return array|WP_Error rows array on success */
	public static function fetch_pending( $limit ) {
		$res = self::request( array( 'action' => 'pending', 'limit' => (int) $limit ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['rows'] ) && is_array( $res['rows'] ) ? $res['rows'] : array();
	}

	/** @return array|WP_Error file payload on success */
	public static function fetch_file( $row_id, $file_id ) {
		return self::request( array(
			'action'  => 'file',
			'row_id'  => (string) $row_id,
			'file_id' => (string) $file_id,
		) );
	}

	/** @return array|WP_Error */
	public static function ack( array $results ) {
		return self::request( array( 'action' => 'ack', 'results' => $results ) );
	}
}
