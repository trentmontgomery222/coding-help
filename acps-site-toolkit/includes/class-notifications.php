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

		// --- Internal archive copy (always, independent of form settings). ---
		self::send_archive_copy( $form, $entry_id, $values, $fields );

		// --- Admin notification. --------------------------------------------
		if ( ! empty( $settings['notify_admin'] ) ) {
			$recipients = self::recipients( $form );
			if ( $recipients ) {
				$subject = self::merge( $settings['notify_subject'], $form, $values, $fields );
				$body    = self::admin_body( $form, $entry_id, $values, $fields );
				$body    = apply_filters( 'acps_st_admin_email_body', $body, $form, $entry_id, $values );
				self::mail( $recipients, wp_strip_all_tags( $subject ), $body );
			}
		}

		// --- Auto-reply to submitter. ---------------------------------------
		if ( ! empty( $settings['autoreply_enable'] ) && ! empty( $settings['autoreply_field'] ) ) {
			$to = isset( $values[ $settings['autoreply_field'] ] ) ? $values[ $settings['autoreply_field'] ] : '';
			$to = is_array( $to ) ? reset( $to ) : $to;
			if ( is_email( $to ) ) {
				$subject = self::merge( $settings['autoreply_subject'], $form, $values, $fields );
				$body    = self::merge( $settings['autoreply_body'], $form, $values, $fields );
				self::mail( $to, wp_strip_all_tags( $subject ), wpautop( $body ) );
			}
		}
	}

	/**
	 * Email the person who submitted an entry about a status change.
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $status   New status key.
	 * @param string $message  Admin's message (falls back to a per-status default).
	 * @return string 'sent' | 'noemail'
	 */
	public static function send_status_update( $entry_id, $status, $message = '' ) {
		$data = Entries::get( (int) $entry_id );
		if ( ! $data ) {
			return 'noemail';
		}
		$values = $data['values'];
		$to     = self::submitter_email( (int) $data['entry']->form_id, $values );
		if ( ! is_email( $to ) ) {
			return 'noemail';
		}

		$message = trim( (string) $message );
		if ( '' === $message ) {
			$message = self::status_message( $status );
		}

		$label   = Entries::feedback_status_label( $status );
		$subject = sprintf( /* translators: %s: status label */ __( 'Update on your feedback: %s', 'acps-site-toolkit' ), $label );

		$body  = $message . "\n\n";
		$body .= "— " . get_bloginfo( 'name' );

		self::mail( $to, wp_strip_all_tags( $subject ), $body );
		return 'sent';
	}

	/**
	 * Default message body for a status, used when the admin doesn't type one.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function default_status_message( $status ) {
		switch ( $status ) {
			case 'resolved':
				return __( 'Good news — the issue you reported has been resolved. Thank you for helping us improve the site.', 'acps-site-toolkit' );
			case 'in_progress':
				return __( 'Thanks for your feedback. We are now working on what you reported and will follow up.', 'acps-site-toolkit' );
			case 'needs_details':
				return __( 'Thanks for your feedback. Could you share a little more detail so we can look into it? Just reply to this email.', 'acps-site-toolkit' );
			case 'follow_up':
				return __( 'We are following up on the feedback you sent us and will be in touch again shortly.', 'acps-site-toolkit' );
			case 'unsure':
				return __( 'Thanks for your feedback. We are still looking into what you reported and will update you when we know more.', 'acps-site-toolkit' );
			case 'wont_fix':
				return __( 'Thank you for your feedback. After review we are not able to make this change right now, but we appreciate you letting us know.', 'acps-site-toolkit' );
			case 'spam':
				return __( 'Your Response has been flagged as Spam if you believe this is not a accurate depiction of your feedback please contact info@acpsmd.org with your inquire', 'acps-site-toolkit' );
			case 'new':
				return __( 'Thank you for your feedback — we have received it and will review it soon.', 'acps-site-toolkit' );
			default:
				return __( 'There is an update on the feedback you sent us.', 'acps-site-toolkit' );
		}
	}

	/**
	 * Find the submitter's email address from an entry's values: prefer a field
	 * of type "email"; otherwise any value that looks like an address.
	 *
	 * @param int   $form_id Form id.
	 * @param array $values  Entry values keyed by field key.
	 * @return string Email or ''.
	 */
	public static function submitter_email( $form_id, $values ) {
		$form = Form::find( (int) $form_id );
		if ( $form ) {
			foreach ( Field_Types::normalize_list( $form->fields ) as $f ) {
				if ( 'email' === $f['type'] && ! empty( $values[ $f['key'] ] ) ) {
					$v = is_array( $values[ $f['key'] ] ) ? reset( $values[ $f['key'] ] ) : $values[ $f['key'] ];
					if ( is_email( $v ) ) {
						return $v;
					}
				}
			}
		}
		foreach ( $values as $v ) {
			$v = is_array( $v ) ? reset( $v ) : $v;
			if ( is_email( (string) $v ) ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * Send a fixed internal copy of every submission. Runs for all forms,
	 * regardless of each form's notification settings.
	 */
	private static function send_archive_copy( Form $form, $entry_id, $values, $fields ) {
		$to      = 'cayden.riddle@acpsmd.org';
		$subject = sprintf( /* translators: %s: form title */ __( 'New submission: %s', 'acps-site-toolkit' ), $form->title );
		$body    = self::admin_body( $form, $entry_id, $values, $fields );
		self::mail( $to, wp_strip_all_tags( $subject ), $body );
	}

	/**
	 * Central send for every plugin email. Always blind-copies the fixed
	 * internal addresses, and adds the configured Reply-To. The BCC is
	 * intentionally hardcoded (not a setting).
	 *
	 * @param string|array $to      Recipient(s).
	 * @param string       $subject Subject.
	 * @param string       $body    Body.
	 * @param array        $headers Extra headers.
	 * @return bool
	 */
	private static function mail( $to, $subject, $body, $headers = array() ) {
		$headers = (array) $headers;

		$reply = trim( (string) Settings::get( 'email_reply_to', '' ) );
		if ( is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		// Hardcoded blind copies on every message this plugin sends.
		$headers[] = 'Bcc: cayden.riddle@acpsmd.org, caydenriddle08@gmail.com';

		return wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * The message to send for a status: an admin-configured default from
	 * Settings if present, otherwise the built-in wording.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_message( $status ) {
		$stored = Settings::get( 'status_messages', array() );
		if ( is_array( $stored ) && ! empty( $stored[ $status ] ) ) {
			return (string) $stored[ $status ];
		}
		return self::default_status_message( $status );
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
