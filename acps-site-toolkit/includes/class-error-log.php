<?php
/**
 * Auto-logged error diagnostics (e.g. 404 pages).
 *
 * When enabled, a client-side beacon fires on page load and POSTs the visitor's
 * device/session context to an uncached REST endpoint, which records it as an
 * entry under a built-in "Site error log" form. This is cache-safe: the page
 * HTML can be edge-cached, but the beacon runs per load and the diagnostic data
 * is gathered either in the browser or on the (uncached) REST request, never
 * baked into cached markup.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Error_Log.
 */
class Error_Log {

	const SLUG = 'site-error-log';

	/**
	 * Create the built-in error-log form if it doesn't exist.
	 *
	 * @return Form|null
	 */
	public static function ensure_form() {
		$form = Form::find_by_slug( self::SLUG );
		if ( $form ) {
			return $form;
		}

		$form         = new Form();
		$form->title  = __( 'Site error log (404s)', 'acps-site-toolkit' );
		$form->slug   = self::SLUG;
		$form->status = 'published';
		$form->fields = array(
			array( 'key' => 'requested_url', 'type' => 'long_text', 'label' => __( 'Requested URL (that 404’d)', 'acps-site-toolkit' ), 'required' => false ),
			array( 'key' => 'referrer', 'type' => 'long_text', 'label' => __( 'Came from (referrer)', 'acps-site-toolkit' ), 'required' => false ),
			array( 'key' => 'device', 'type' => 'short_text', 'label' => __( 'Device type', 'acps-site-toolkit' ), 'required' => false ),
			array( 'key' => 'full_user_agent', 'type' => 'long_text', 'label' => __( 'Browser (full user-agent)', 'acps-site-toolkit' ), 'required' => false ),
			array( 'key' => 'viewport', 'type' => 'short_text', 'label' => __( 'Window size', 'acps-site-toolkit' ), 'required' => false ),
			array( 'key' => 'screen', 'type' => 'short_text', 'label' => __( 'Screen size', 'acps-site-toolkit' ), 'required' => false ),
			array( 'key' => 'language', 'type' => 'short_text', 'label' => __( 'Language', 'acps-site-toolkit' ), 'required' => false ),
			array( 'key' => 'timezone', 'type' => 'short_text', 'label' => __( 'Time zone', 'acps-site-toolkit' ), 'required' => false ),
			array( 'key' => 'platform', 'type' => 'short_text', 'label' => __( 'Platform', 'acps-site-toolkit' ), 'required' => false ),
		);
		$form->settings = wp_parse_args(
			array(
				// No emails for error logs — they're viewed in Entries, not mailed.
				'notify_admin'         => 0,
				'confirmation_message' => __( 'Logged.', 'acps-site-toolkit' ),
			),
			Form::default_settings()
		);
		$form->save();
		return $form;
	}

	/**
	 * Record one diagnostic entry from the beacon payload. Merges browser-supplied
	 * context with accurate server-side signals taken from THIS (uncached) request,
	 * so nothing depends on cached markup.
	 *
	 * @param array $params Beacon payload.
	 * @return int Entry id, or 0.
	 */
	public static function record( $params ) {
		$form = self::ensure_form();
		if ( ! $form ) {
			return 0;
		}
		// Flood protection: cap auto-logs per device fingerprint per hour.
		if ( ! self::allow() ) {
			return 0;
		}

		$params = is_array( $params ) ? $params : array();
		$get    = function ( $k ) use ( $params ) {
			return isset( $params[ $k ] ) ? (string) $params[ $k ] : '';
		};

		$values = array(
			'requested_url'   => esc_url_raw( $get( 'url' ) ),
			'referrer'        => esc_url_raw( $get( 'referrer' ) ),
			// Device parsed from the REAL request UA (accurate, uncached).
			'device'          => Session::device_type(),
			'full_user_agent' => sanitize_text_field( $get( 'ua' ) ),
			'viewport'        => sanitize_text_field( $get( 'viewport' ) ),
			'screen'          => sanitize_text_field( $get( 'screen' ) ),
			'language'        => sanitize_text_field( $get( 'language' ) ),
			'timezone'        => sanitize_text_field( $get( 'timezone' ) ),
			'platform'        => sanitize_text_field( $get( 'platform' ) ),
		);
		$values = array_filter( $values, function ( $v ) { return '' !== $v; } );

		$visitor_uid = '';
		if ( Settings::get( 'analytics_enabled' ) && Settings::get( 'track_visitors' ) ) {
			$visitor_uid = Visitors::fingerprint();
		}

		return Entries::create(
			array(
				'form_id'     => $form->id,
				'session_id'  => Session::lookup( $get( 'session' ) ),
				'visitor_uid' => $visitor_uid,
				'page_id'     => absint( $get( 'post_id' ) ),
				'page_url'    => esc_url_raw( $get( 'url' ) ),
				'status'      => 'new',
			),
			$values
		);
	}

	/**
	 * Per-fingerprint rate limit so a loop or a bot can't flood the log.
	 *
	 * @return bool
	 */
	private static function allow() {
		$fp  = md5( Session::anonymize_ip( Session::client_ip() ) . '|' . Session::user_agent_summary() );
		$key = 'acps_st_al_' . $fp;
		$n   = (int) get_transient( $key );
		if ( $n >= 30 ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}
}
