<?php
/**
 * Programmatic event logging from page JavaScript.
 *
 * Similar in spirit to the 404 auto-log, but general purpose: when enabled, a
 * tiny global `window.acpsLog()` function is available on every front-end page,
 * and whatever fields you pass it are POSTed to an uncached REST endpoint and
 * stored as an entry under a built-in "Event log" form. The caller controls the
 * data entirely; the server adds session/device context.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log.
 */
class Log {

	const SLUG = 'event-log';

	/** Hard caps so a page (or a bot) can't flood the DB with one call. */
	const MAX_FIELDS      = 40;
	const MAX_VALUE_CHARS = 5000;

	/**
	 * Create the built-in event-log form if it doesn't exist. It has no fixed
	 * fields — entries store whatever keys the JS sends.
	 *
	 * @return Form|null
	 */
	public static function ensure_form() {
		$form = Form::find_by_slug( self::SLUG );
		if ( $form ) {
			return $form;
		}
		$form           = new Form();
		$form->title    = __( 'Event log (JS)', 'acps-site-toolkit' );
		$form->slug     = self::SLUG;
		$form->status   = 'published';
		$form->fields   = array(); // free-form: whatever window.acpsLog() sends.
		$form->settings = wp_parse_args(
			array(
				'notify_admin'         => 0,
				'confirmation_message' => __( 'Logged.', 'acps-site-toolkit' ),
			),
			Form::default_settings()
		);
		$form->save();
		return $form;
	}

	/**
	 * Record one logged event from the beacon payload.
	 *
	 * @param array $params { fields: {k:v,...}, form?: slug, url?, session?, post_id? }
	 * @return int Entry id, or 0.
	 */
	public static function record( $params ) {
		$params = is_array( $params ) ? $params : array();

		// Allow a caller to target another (published) form by slug; else the
		// built-in event log.
		$form = null;
		if ( ! empty( $params['form'] ) ) {
			$form = Form::find_by_slug( sanitize_title( (string) $params['form'] ) );
			if ( $form && 'published' !== $form->status ) {
				$form = null;
			}
		}
		if ( ! $form ) {
			$form = self::ensure_form();
		}
		if ( ! $form ) {
			return 0;
		}

		if ( ! self::allow() ) {
			return 0;
		}

		// Sanitize the caller-supplied fields.
		$raw    = ( isset( $params['fields'] ) && is_array( $params['fields'] ) ) ? $params['fields'] : array();
		$values = array();
		$count  = 0;
		foreach ( $raw as $key => $value ) {
			if ( $count >= self::MAX_FIELDS ) {
				break;
			}
			$k = sanitize_key( (string) $key );
			if ( '' === $k ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = array_map( 'strval', $value );
				$value = array_map(
					function ( $v ) {
						return mb_substr( sanitize_text_field( $v ), 0, self::MAX_VALUE_CHARS );
					},
					$value
				);
			} else {
				$value = mb_substr( sanitize_textarea_field( (string) $value ), 0, self::MAX_VALUE_CHARS );
			}
			$values[ $k ] = $value;
			$count++;
		}

		$url = isset( $params['url'] ) ? esc_url_raw( (string) $params['url'] ) : '';

		$visitor_uid = '';
		if ( Settings::get( 'analytics_enabled' ) && Settings::get( 'track_visitors' ) ) {
			$visitor_uid = Visitors::fingerprint();
		}

		return Entries::create(
			array(
				'form_id'     => $form->id,
				'session_id'  => Session::lookup( isset( $params['session'] ) ? (string) $params['session'] : '' ),
				'visitor_uid' => $visitor_uid,
				'page_id'     => isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0,
				'page_url'    => $url,
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
		$key = 'acps_st_log_' . $fp;
		$n   = (int) get_transient( $key );
		if ( $n >= 120 ) { // generous — this is meant to be called often.
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}
}
