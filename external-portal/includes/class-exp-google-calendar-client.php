<?php
/**
 * Minimal Google Calendar API client using a shared service account.
 *
 * Implements the JWT-bearer OAuth2 flow (RS256 via OpenSSL) and the Calendar v3
 * ACL endpoints the portal needs. No external libraries. One shared connection is
 * configured by the admin; portal users never see Google (spec Section 5.3).
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service-account-backed Calendar ACL client.
 */
class EXP_Google_Calendar_Client {

	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_BASE  = 'https://www.googleapis.com/calendar/v3';
	const SCOPE     = 'https://www.googleapis.com/auth/calendar';

	/**
	 * @var string Service account client email.
	 */
	protected $client_email;

	/**
	 * @var string PEM private key.
	 */
	protected $private_key;

	/**
	 * @var string Optional subject to impersonate (domain-wide delegation).
	 */
	protected $subject;

	/**
	 * Constructor.
	 *
	 * @param string $client_email Service account email.
	 * @param string $private_key  PEM private key.
	 * @param string $subject      Optional impersonation subject.
	 */
	public function __construct( $client_email, $private_key, $subject = '' ) {
		$this->client_email = $client_email;
		$this->private_key  = $private_key;
		$this->subject      = $subject;
	}

	/**
	 * Build a client from stored settings, or return a configuration error.
	 *
	 * @return EXP_Google_Calendar_Client|WP_Error
	 */
	public static function from_settings() {
		$raw = (string) EXP_Settings::get( 'google_service_account', '' );
		if ( '' === $raw ) {
			return new WP_Error( 'exp_gcal_unconfigured', __( 'Google integration is not configured.', 'external-portal' ) );
		}
		$json = json_decode( self::maybe_base64_decode( $raw ), true );
		if ( ! is_array( $json ) || empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
			return new WP_Error( 'exp_gcal_badcreds', __( 'The Google service account credentials are invalid.', 'external-portal' ) );
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'exp_gcal_openssl', __( 'The OpenSSL PHP extension is required for Google integration.', 'external-portal' ) );
		}
		return new self(
			$json['client_email'],
			$json['private_key'],
			(string) EXP_Settings::get( 'google_impersonate_user', '' )
		);
	}

	/**
	 * Decode base64 if the value looks base64-encoded, else return as-is.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	protected static function maybe_base64_decode( $value ) {
		$decoded = base64_decode( $value, true );
		if ( false !== $decoded && '' !== $decoded && false !== strpos( $decoded, '"private_key"' ) ) {
			return $decoded;
		}
		return $value;
	}

	/**
	 * Get a (cached) OAuth2 access token.
	 *
	 * @return string|WP_Error
	 */
	public function access_token() {
		$cache_key = 'exp_gcal_token_' . md5( $this->client_email . '|' . $this->subject );
		$cached    = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$assertion = $this->build_assertion();
		if ( is_wp_error( $assertion ) ) {
			return $assertion;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) $code || empty( $body['access_token'] ) ) {
			$msg = isset( $body['error_description'] ) ? $body['error_description'] : __( 'Could not obtain a Google access token.', 'external-portal' );
			return new WP_Error( 'exp_gcal_token', $msg );
		}

		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 3000;
		set_transient( $cache_key, $body['access_token'], $ttl );
		return $body['access_token'];
	}

	/**
	 * Build and sign the JWT assertion.
	 *
	 * @return string|WP_Error
	 */
	protected function build_assertion() {
		$now    = time();
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		$claims = array(
			'iss'   => $this->client_email,
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);
		if ( '' !== $this->subject ) {
			$claims['sub'] = $this->subject;
		}

		$segments = array(
			self::b64url( wp_json_encode( $header ) ),
			self::b64url( wp_json_encode( $claims ) ),
		);
		$signing_input = implode( '.', $segments );

		$signature = '';
		$ok        = openssl_sign( $signing_input, $signature, $this->private_key, 'sha256WithRSAEncryption' );
		if ( ! $ok ) {
			return new WP_Error( 'exp_gcal_sign', __( 'Could not sign the Google authentication request. Check the private key.', 'external-portal' ) );
		}
		$segments[] = self::b64url( $signature );
		return implode( '.', $segments );
	}

	/**
	 * URL-safe base64 with no padding.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	protected static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Perform an authenticated API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under API_BASE (already URL-encoded).
	 * @param array|null $body   JSON body.
	 * @return array|WP_Error Decoded response body, or error.
	 */
	protected function request( $method, $path, $body = null ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = '' !== $raw ? json_decode( $raw, true ) : array();

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf(
				/* translators: %d: HTTP status */
				__( 'Google API error (HTTP %d).', 'external-portal' ),
				$code
			);
			return new WP_Error( 'exp_gcal_api', $msg, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * List ACL rules for a calendar.
	 *
	 * @param string $calendar_id Calendar id.
	 * @return array|WP_Error Array of rules.
	 */
	public function list_acl( $calendar_id ) {
		$res = $this->request( 'GET', '/calendars/' . rawurlencode( $calendar_id ) . '/acl' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['items'] ) ? $res['items'] : array();
	}

	/**
	 * Share a calendar with a person at a role.
	 *
	 * @param string $calendar_id Calendar id.
	 * @param string $email       User email.
	 * @param string $role        reader|writer|owner|freeBusyReader.
	 * @return array|WP_Error
	 */
	public function insert_acl( $calendar_id, $email, $role ) {
		return $this->request(
			'POST',
			'/calendars/' . rawurlencode( $calendar_id ) . '/acl',
			array(
				'role'  => $role,
				'scope' => array(
					'type'  => 'user',
					'value' => $email,
				),
			)
		);
	}

	/**
	 * Change an existing rule's role.
	 *
	 * @param string $calendar_id Calendar id.
	 * @param string $rule_id     ACL rule id.
	 * @param string $role        New role.
	 * @return array|WP_Error
	 */
	public function update_acl_role( $calendar_id, $rule_id, $role ) {
		return $this->request(
			'PATCH',
			'/calendars/' . rawurlencode( $calendar_id ) . '/acl/' . rawurlencode( $rule_id ),
			array( 'role' => $role )
		);
	}

	/**
	 * Remove a rule (stop sharing with a person).
	 *
	 * @param string $calendar_id Calendar id.
	 * @param string $rule_id     ACL rule id.
	 * @return true|WP_Error
	 */
	public function delete_acl( $calendar_id, $rule_id ) {
		$res = $this->request( 'DELETE', '/calendars/' . rawurlencode( $calendar_id ) . '/acl/' . rawurlencode( $rule_id ) );
		return is_wp_error( $res ) ? $res : true;
	}
}
