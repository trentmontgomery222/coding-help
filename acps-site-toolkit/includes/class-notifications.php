<?php
/**
 * Email notifications: admin notice on submit + optional submitter auto-reply,
 * with merge tags that pull field values into subject/body (spec §7.3).
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notifications.
 */
class Notifications {

	/**
	 * Send configured notifications for a new entry.
	 *
	 * @param Form  $form     Form.
	 * @param int   $entry_id Entry id.
	 * @param array $values   Clean values keyed by field key.
	 * @param array $fields   Normalized field list (for labels).
	 */
	public static function send( Form $form, $entry_id, $values, $fields ) {
		$settings = $form->settings;

		// --- Admin notification. --------------------------------------------
		if ( ! empty( $settings['notify_admin'] ) ) {
			$recipients = self::recipients( $form );
			if ( $recipients ) {
				$subject = self::merge( $settings['notify_subject'], $form, $values, $fields );
				$body    = self::admin_body( $form, $entry_id, $values, $fields );
				$body    = apply_filters( 'acps_st_admin_email_body', $body, $form, $entry_id, $values );
				wp_mail( $recipients, wp_strip_all_tags( $subject ), $body );
			}
		}

		// --- Auto-reply to submitter. ---------------------------------------
		if ( ! empty( $settings['autoreply_enable'] ) && ! empty( $settings['autoreply_field'] ) ) {
			$to = isset( $values[ $settings['autoreply_field'] ] ) ? $values[ $settings['autoreply_field'] ] : '';
			$to = is_array( $to ) ? reset( $to ) : $to;
			if ( is_email( $to ) ) {
				$subject = self::merge( $settings['autoreply_subject'], $form, $values, $fields );
				$body    = self::merge( $settings['autoreply_body'], $form, $values, $fields );
				wp_mail( $to, wp_strip_all_tags( $subject ), wpautop( $body ) );
			}
		}
	}

	/**
	 * Resolve recipients: form override → global setting → site admin email.
	 */
	private static function recipients( Form $form ) {
		$raw = trim( (string) $form->settings['notify_recipients'] );
		if ( '' === $raw ) {
			$raw = trim( (string) Settings::get( 'notify_recipients', '' ) );
		}
		if ( '' === $raw ) {
			return array( get_option( 'admin_email' ) );
		}
		$emails = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,]+/', $raw ) ), 'is_email' );
		return $emails ? $emails : array( get_option( 'admin_email' ) );
	}

	/**
	 * Build the admin email body, including the journey path (spec §5.6).
	 */
	private static function admin_body( Form $form, $entry_id, $values, $fields ) {
		$lines   = array();
		$lines[] = sprintf( /* translators: %s: form title */ __( 'New submission on: %s', 'acps-site-toolkit' ), $form->title );
		$lines[] = '';

		$labels = wp_list_pluck( $fields, 'label', 'key' );
		foreach ( $values as $key => $val ) {
			$label = isset( $labels[ $key ] ) && $labels[ $key ] ? $labels[ $key ] : $key;
			$out   = is_array( $val ) ? implode( ', ', $val ) : $val;
			$lines[] = $label . ': ' . $out;
		}

		$data = Entries::get( $entry_id );
		if ( $data && $data['entry']->session_id ) {
			$path = Analytics::session_path( (int) $data['entry']->session_id );
			if ( $path ) {
				$lines[] = '';
				$lines[] = __( 'Visitor path before submitting:', 'acps-site-toolkit' );
				$lines[] = implode( ' → ', $path );
			}
		}

		$lines[] = '';
		$lines[] = __( 'View in admin:', 'acps-site-toolkit' ) . ' ' . admin_url( 'admin.php?page=acps-st-entries&entry=' . $entry_id );

		return implode( "\n", $lines );
	}

	/**
	 * Replace merge tags in a template string.
	 *
	 * Supported: {form_title}, {site_name}, {entry_date}, and {field:key}.
	 */
	private static function merge( $template, Form $form, $values, $fields ) {
		$replacements = array(
			'{form_title}' => $form->title,
			'{site_name}'  => get_bloginfo( 'name' ),
			'{entry_date}' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		);
		$out = strtr( (string) $template, $replacements );

		// {field:key} tokens.
		$out = preg_replace_callback(
			'/\{field:([a-z0-9_\-]+)\}/i',
			function ( $m ) use ( $values ) {
				$k = sanitize_key( $m[1] );
				if ( ! isset( $values[ $k ] ) ) {
					return '';
				}
				return is_array( $values[ $k ] ) ? implode( ', ', $values[ $k ] ) : $values[ $k ];
			},
			$out
		);

		return $out;
	}
}
