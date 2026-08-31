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

			// Global form behaviour (per-form builder settings override where noted).
			'entry_store_ip'        => 1,  // store submitter's anonymized IP + browser on entries.
			'max_upload_mb'         => 10, // max size for a single file upload, in MB.
			'autolog_404'           => 0,  // auto-record a diagnostic entry on every 404 page.

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
			'email_reply_to'        => 'info@acpsmd.org', // Reply-To on all plugin emails.
			'status_messages'       => array(), // per-status feedback email bodies (empty = built-in wording).

			// Master switch for ALL analytics/visitor/page tracking. Individual
			// features below can be toggled independently.
			'analytics_enabled'     => 1,
			// What to COLLECT (each affects the beacon / stored data).
			'track_pageviews'       => 1, // page/journey tracking + device stats.
			'track_visitors'        => 1, // unique users (server IP+UA fingerprint).
			'track_time_on_page'    => 1, // backfill time-on-page from the next view.
			'track_referrers'       => 1, // store where visitors came from.
			'analytics_sample_rate' => 100, // % of pageviews that send a beacon (lower = far less origin load).
			// What to SHOW on the Analytics dashboard. Turning a card off also
			// skips the queries that build it, so these double as perf levers.
			'show_live'             => 1, // "who's on the site now".
			'show_unique_users'     => 1, // unique-users card.
			'show_pages'            => 1, // per-page traffic + feedback overlay.
			'show_devices'          => 1, // device / browser / OS breakdown.
			'show_journeys'         => 1, // common paths + dead ends.
			'show_trend'            => 1, // views over the last 30 days.

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

			// Self-update (see /Self-Update-System-Spec.md, PART A). Lets
			// "Update now" work without wordpress.org, from either a
			// self-hosted manifest or GitHub releases.
			'update_enabled'        => 1,  // master on/off for the whole updater.
			'update_auto'           => 0,  // install automatically in the background.
			'update_source'         => 'url', // 'url' | 'github'.
			'update_manifest'       => '', // manifest URL, for the 'url' source.
			'update_manifest_key'   => '', // optional shared secret, sent as ?key=.
			'gh_owner'              => '', // GitHub owner, for the 'github' source.
			'gh_repo'               => '', // GitHub repo, for the 'github' source.
			'gh_asset'              => 'acps-site-toolkit.zip', // release asset filename to download.
			'gh_token'              => '', // PAT for private repos; blank = public.
			'update_trigger'        => '', // secret word for the force-update URL; seeded randomly on activation.

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
			'entry_store_ip',
			'autolog_404',
			'feedback_enabled',
			'feedback_allow_screenshot',
			'trigger_transparent',
			'analytics_enabled',
			'track_pageviews',
			'track_visitors',
			'track_time_on_page',
			'track_referrers',
			'show_live',
			'show_unique_users',
			'show_pages',
			'show_devices',
			'show_journeys',
			'show_trend',
			'tracking_enabled',
			'consent_mode',
			'store_full_user_agent',
			'spam_honeypot',
			'spam_time_trap',
			'spam_challenge_enable',
			'editors_view_reports',
			'preserve_data',
			'update_enabled',
			'update_auto',
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

		// Self-update (spec Part A, §A3/§A8/§A9).
		if ( isset( $input['update_source'] ) && in_array( $input['update_source'], array( 'url', 'github' ), true ) ) {
			$out['update_source'] = $input['update_source'];
		}
		$out['update_manifest']     = isset( $input['update_manifest'] ) ? esc_url_raw( trim( $input['update_manifest'] ) ) : '';
		$out['update_manifest_key'] = isset( $input['update_manifest_key'] ) ? sanitize_text_field( $input['update_manifest_key'] ) : '';
		$out['gh_owner']            = isset( $input['gh_owner'] ) ? sanitize_text_field( trim( $input['gh_owner'] ) ) : '';
		$out['gh_repo']             = isset( $input['gh_repo'] ) ? sanitize_text_field( trim( $input['gh_repo'] ) ) : '';
		$out['gh_asset']            = ( isset( $input['gh_asset'] ) && '' !== trim( $input['gh_asset'] ) )
			? sanitize_file_name( $input['gh_asset'] )
			: $defaults['gh_asset'];

		// GitHub token: blank input keeps the existing value (it's never
		// redisplayed); an explicit "clear" checkbox removes it.
		if ( ! empty( $input['gh_token_clear'] ) ) {
			$out['gh_token'] = '';
		} elseif ( ! empty( $input['gh_token'] ) ) {
			$out['gh_token'] = sanitize_text_field( $input['gh_token'] );
		}

		// Force-update secret: "Regenerate" wins if checked; otherwise a
		// typed value is sanitized and used; otherwise the existing secret is
		// kept — it should never be silently blanked out.
		if ( ! empty( $input['update_trigger_regenerate'] ) ) {
			$out['update_trigger'] = sanitize_title( wp_generate_password( 24, false, false ) );
		} elseif ( isset( $input['update_trigger'] ) && '' !== trim( (string) $input['update_trigger'] ) ) {
			$out['update_trigger'] = sanitize_title( $input['update_trigger'] );
		}

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
		$out['max_upload_mb']         = max( 1, min( 100, absint( $input['max_upload_mb'] ?? $defaults['max_upload_mb'] ) ) );

		// Notification recipients — comma-separated emails.
		$out['notify_recipients'] = '';
		if ( ! empty( $input['notify_recipients'] ) ) {
			$emails = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,]+/', (string) $input['notify_recipients'] ) ), 'is_email' );
			$out['notify_recipients'] = implode( ', ', $emails );
		}

		// Email Reply-To (blank = don't add a Reply-To header).
		$out['email_reply_to'] = '';
		if ( ! empty( $input['email_reply_to'] ) ) {
			$maybe = sanitize_email( trim( (string) $input['email_reply_to'] ) );
			$out['email_reply_to'] = is_email( $maybe ) ? $maybe : '';
		}

		// Per-status feedback email default messages. Only store a message when
		// it is non-empty AND differs from the built-in wording, so leaving a box
		// at its default keeps using the built-in text (which can then evolve).
		$out['status_messages'] = array();
		if ( isset( $input['status_messages'] ) && is_array( $input['status_messages'] ) ) {
			foreach ( array_keys( \ACPS\SiteToolkit\Entries::feedback_status_labels() ) as $st ) {
				if ( ! isset( $input['status_messages'][ $st ] ) ) {
					continue;
				}
				$msg     = sanitize_textarea_field( (string) $input['status_messages'][ $st ] );
				$builtin = \ACPS\SiteToolkit\Notifications::default_status_message( $st );
				if ( '' !== trim( $msg ) && trim( $msg ) !== trim( $builtin ) ) {
					$out['status_messages'][ $st ] = $msg;
				}
			}
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
