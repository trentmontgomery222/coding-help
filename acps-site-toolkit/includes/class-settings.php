<?php
/**
 * Settings model + WordPress Settings API registration.
 *
 * All options live in a single associative array stored in the site options
 * table under ACPS_ST_OPT_SETTINGS (spec §9.2). No multisite / network options.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings.
 */
class Settings {

	const GROUP     = 'acps_st_settings_group';
	const CAP_READ  = 'acps_st_read_reports';

	/**
	 * Default settings. Also the canonical list of recognised keys.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Feature master switches — turn whole parts of the plugin off.
			// (Analytics has its own master switch, 'analytics_enabled', below.)
			'qa_enabled'            => 1, // Q&A / help widget + [acps_qa] shortcode.
			'restricted_forms_enabled' => 1, // password-gated + secret-link forms.

			// Feedback trigger.
			'feedback_enabled'      => 1,
			'trigger_display'       => 'all',      // all | include | exclude.
			'trigger_pages'         => array(),    // post IDs for include/exclude.
			'trigger_position'      => 'bottom-right', // bottom-right | bottom-left | edge-right | edge-left.
			'trigger_label'         => 'Chat with us',
			'trigger_icon_url'      => 'https://acpsmdprod.wpengine.com/wp-content/uploads/2026/08/Untitled-design-1.png',
			'trigger_icon_hover_url' => '', // shown on hover / focus / while open.
			'trigger_size'          => 64, // circle diameter (desktop/laptop) in px.
			'trigger_size_tablet'   => 60, // circle diameter on tablets.
			'trigger_size_mobile'   => 52, // circle diameter on phones.
			'trigger_bg'            => '', // circle background; blank = accent colour.
			'trigger_transparent'   => 0,  // transparent background (no circle/ring/shadow).
			'modal_max_width'       => 1200, // popup max width on laptop/desktop.
			'custom_css'            => '', // full editable stylesheet (overrides base).
			'feedback_categories'   => array(
				"Something's broken",
				'Hard to use',
				'Content is wrong or missing',
				'Accessibility issue',
				'Compliment',
				'Other',
			),
			'recent_pages_count'    => 3,
			'feedback_allow_screenshot' => 1,

			// Notifications.
			'notify_recipients'     => '', // comma-separated; empty falls back to admin_email.

			// Master switch for ALL analytics/visitor/page tracking. Individual
			// features below can be toggled independently.
			'analytics_enabled'     => 1,
			'track_pageviews'       => 1, // page/journey tracking + device stats.
			'track_visitors'        => 1, // unique users (server IP+UA fingerprint).
			'track_presence'        => 0, // admin "who's on the site now" (extra request; off).
			'analytics_sample_rate' => 100, // % of pageviews that send a beacon (lower = far less origin load).

			// Journey tracking.
			'tracking_enabled'      => 1,
			'consent_mode'          => 0,
			'session_idle_minutes'  => 30,
			'retention_months'      => 12,
			'store_full_user_agent' => 0,

			// Spam prevention (spec §7.4).
			'spam_honeypot'         => 1,
			'spam_time_trap'        => 1,
			'spam_time_threshold'   => 3, // seconds.
			'spam_rate_limit'       => 10, // submissions per window.
			'spam_rate_window'      => 60, // minutes.
			'spam_blocklist'        => '', // newline/comma separated keywords.
			'spam_challenge_enable' => 0,
			'spam_challenge_q'      => '',
			'spam_challenge_a'      => '',

			// Help.
			'help_guide_url'        => '', // optional external help guide link.

			// Capabilities.
			'editors_view_reports'  => 0, // grant read-only feedback/analytics to editors (spec §9.1).

			// Uninstall.
			'preserve_data'         => 1, // ON by default (spec §3.8).
		);
	}

	/**
	 * Fetch the merged settings array (stored over defaults).
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( ACPS_ST_OPT_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Fetch a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if unset.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Register the setting with the Settings API. The rendering of fields lives
	 * in Admin\Settings_Page; here we just register storage + sanitize.
	 */
	public function register() {
		register_setting(
			self::GROUP,
			ACPS_ST_OPT_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize the entire settings array on save.
	 *
	 * @param array $input Raw $_POST values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$defaults = self::defaults();
		$out      = self::all();
		$input    = is_array( $input ) ? $input : array();

		$checkboxes = array(
			'qa_enabled',
			'restricted_forms_enabled',
			'feedback_enabled',
			'feedback_allow_screenshot',
			'trigger_transparent',
			'analytics_enabled',
			'track_pageviews',
			'track_visitors',
			'track_presence',
			'tracking_enabled',
			'consent_mode',
			'store_full_user_agent',
			'spam_honeypot',
			'spam_time_trap',
			'spam_challenge_enable',
			'editors_view_reports',
			'preserve_data',
		);
		foreach ( $checkboxes as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		if ( isset( $input['trigger_display'] ) && in_array( $input['trigger_display'], array( 'all', 'include', 'exclude' ), true ) ) {
			$out['trigger_display'] = $input['trigger_display'];
		}
		if ( isset( $input['trigger_position'] ) && in_array( $input['trigger_position'], array( 'bottom-right', 'bottom-left', 'edge-right', 'edge-left' ), true ) ) {
			$out['trigger_position'] = $input['trigger_position'];
		}

		$out['trigger_label'] = isset( $input['trigger_label'] ) ? sanitize_text_field( $input['trigger_label'] ) : $defaults['trigger_label'];

		// Trigger appearance.
		$out['trigger_icon_url']       = isset( $input['trigger_icon_url'] ) ? esc_url_raw( trim( $input['trigger_icon_url'] ) ) : '';
		$out['trigger_icon_hover_url'] = isset( $input['trigger_icon_hover_url'] ) ? esc_url_raw( trim( $input['trigger_icon_hover_url'] ) ) : '';
		$out['trigger_size']        = max( 24, min( 200, absint( $input['trigger_size'] ?? $defaults['trigger_size'] ) ) );
		$out['trigger_size_tablet'] = max( 24, min( 200, absint( $input['trigger_size_tablet'] ?? $defaults['trigger_size_tablet'] ) ) );
		$out['trigger_size_mobile'] = max( 24, min( 200, absint( $input['trigger_size_mobile'] ?? $defaults['trigger_size_mobile'] ) ) );
		$out['modal_max_width']     = max( 320, min( 2000, absint( $input['modal_max_width'] ?? $defaults['modal_max_width'] ) ) );
		$out['trigger_bg']          = isset( $input['trigger_bg'] ) ? ( sanitize_hex_color( $input['trigger_bg'] ) ?: '' ) : '';

		// Custom CSS: strip any tags so it can't break out of the <style> block.
		$out['custom_css'] = isset( $input['custom_css'] ) ? wp_strip_all_tags( (string) $input['custom_css'] ) : '';

		$out['help_guide_url'] = isset( $input['help_guide_url'] ) ? esc_url_raw( trim( $input['help_guide_url'] ) ) : '';

		// Page ID lists.
		foreach ( array( 'trigger_pages' ) as $key ) {
			$out[ $key ] = array();
			if ( ! empty( $input[ $key ] ) ) {
				$raw         = is_array( $input[ $key ] ) ? $input[ $key ] : preg_split( '/[\s,]+/', (string) $input[ $key ] );
				$out[ $key ] = array_values( array_filter( array_map( 'absint', $raw ) ) );
			}
		}

		// Category list — textarea, one per line.
		if ( isset( $input['feedback_categories'] ) ) {
			$cats = is_array( $input['feedback_categories'] )
				? $input['feedback_categories']
				: preg_split( '/\r\n|\r|\n/', (string) $input['feedback_categories'] );
			$cats = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', $cats ) ) ) );
			$out['feedback_categories'] = $cats ? $cats : $defaults['feedback_categories'];
		}

		// Integers with sane floors.
		$out['recent_pages_count']   = max( 1, min( 10, absint( $input['recent_pages_count'] ?? $defaults['recent_pages_count'] ) ) );
		$out['session_idle_minutes'] = max( 5, absint( $input['session_idle_minutes'] ?? $defaults['session_idle_minutes'] ) );
		$out['retention_months']     = max( 0, absint( $input['retention_months'] ?? $defaults['retention_months'] ) );
		$out['spam_time_threshold']  = max( 0, absint( $input['spam_time_threshold'] ?? $defaults['spam_time_threshold'] ) );
		$out['spam_rate_limit']      = max( 0, absint( $input['spam_rate_limit'] ?? $defaults['spam_rate_limit'] ) );
		$out['spam_rate_window']     = max( 1, absint( $input['spam_rate_window'] ?? $defaults['spam_rate_window'] ) );
		$out['analytics_sample_rate'] = max( 1, min( 100, absint( $input['analytics_sample_rate'] ?? $defaults['analytics_sample_rate'] ) ) );

		// Notification recipients — comma-separated emails.
		$out['notify_recipients'] = '';
		if ( ! empty( $input['notify_recipients'] ) ) {
			$emails = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,]+/', (string) $input['notify_recipients'] ) ), 'is_email' );
			$out['notify_recipients'] = implode( ', ', $emails );
		}

		$out['spam_blocklist'] = isset( $input['spam_blocklist'] ) ? sanitize_textarea_field( $input['spam_blocklist'] ) : '';
		$out['spam_challenge_q'] = isset( $input['spam_challenge_q'] ) ? sanitize_text_field( $input['spam_challenge_q'] ) : '';
		$out['spam_challenge_a'] = isset( $input['spam_challenge_a'] ) ? sanitize_text_field( $input['spam_challenge_a'] ) : '';

		return $out;
	}

	/**
	 * Which post IDs should show the feedback trigger, evaluated for the given
	 * post. Returns true/false.
	 *
	 * @param int $post_id Current post/page ID.
	 * @return bool
	 */
	public static function should_show_trigger( $post_id ) {
		if ( ! self::get( 'feedback_enabled' ) ) {
			return false;
		}
		$mode  = self::get( 'trigger_display' );
		$pages = (array) self::get( 'trigger_pages' );

		if ( 'include' === $mode ) {
			return in_array( (int) $post_id, array_map( 'intval', $pages ), true );
		}
		if ( 'exclude' === $mode ) {
			return ! in_array( (int) $post_id, array_map( 'intval', $pages ), true );
		}
		return true; // 'all'.
	}
}
