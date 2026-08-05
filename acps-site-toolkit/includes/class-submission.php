<?php
/**
 * Submission handler: validation, spam gating, storage, file handling, and
 * notifications. Shared by every form including the feedback form (spec §2).
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission.
 */
class Submission {

	/**
	 * Process a submission.
	 *
	 * @param Form  $form    Target form.
	 * @param array $request Sanitizable request bag (fields, tokens…).
	 * @param array $files   $_FILES-shaped array for uploads.
	 * @return array Result: success bool, entry_id, errors[], summary[], confirmation.
	 */
	public static function process( Form $form, $request, $files = array() ) {
		/**
		 * Fire before validation so extensions can short-circuit (spec §10 hooks).
		 *
		 * @param array $request Request.
		 * @param Form  $form    Form.
		 */
		do_action( 'acps_st_before_submission', $request, $form );

		// --- Spam gate (before expensive work). -----------------------------
		$spam = Spam::check( $request, $form );
		if ( $spam['spam'] ) {
			if ( $spam['silent'] ) {
				// Pretend success, store nothing (spec §7.4). Bots get no signal.
				return array(
					'success'      => true,
					'entry_id'     => 0,
					'confirmation' => self::confirmation( $form ),
					'discarded'    => true,
				);
			}
			return array(
				'success' => false,
				'errors'  => array(),
				'summary' => array( array( 'field' => '', 'message' => $spam['message'] ) ),
			);
		}

		// --- Response limits (before validation, like the spam gate). --------
		$limit_error = self::check_limits( $form );
		if ( '' !== $limit_error ) {
			return array(
				'success' => false,
				'errors'  => array(),
				'summary' => array( array( 'field' => '', 'message' => $limit_error ) ),
			);
		}

		// --- Validation. ----------------------------------------------------
		$fields   = Field_Types::normalize_list( $form->fields );
		$submitted = isset( $request['fields'] ) && is_array( $request['fields'] ) ? $request['fields'] : array();
		$values   = array();
		$errors   = array();
		$summary  = array();

		foreach ( $fields as $field ) {
			// Skip fields the visitor couldn't see (conditional logic). A hidden
			// field is never required and its value isn't stored.
			if ( Field_Types::is_input( $field['type'] ) && 'hidden' !== $field['type'] && ! Field_Types::conditional_visible( $field, $submitted ) ) {
				continue;
			}
			if ( ! Field_Types::is_input( $field['type'] ) || 'hidden' === $field['type'] ) {
				// Still capture hidden field values.
				if ( 'hidden' === $field['type'] && isset( $submitted[ $field['key'] ] ) ) {
					$values[ $field['key'] ] = sanitize_text_field( $submitted[ $field['key'] ] );
				}
				continue;
			}
			if ( 'file' === $field['type'] ) {
				$res = self::handle_file( $field, $files );
				if ( is_wp_error( $res ) ) {
					self::add_error( $errors, $summary, $field, $res->get_error_message() );
				} elseif ( '' !== $res ) {
					$values[ $field['key'] ] = $res;
				} elseif ( $field['required'] ) {
					self::add_error( $errors, $summary, $field, self::required_message( $field ) );
				}
				continue;
			}

			$raw   = isset( $submitted[ $field['key'] ] ) ? $submitted[ $field['key'] ] : '';
			$clean = self::sanitize_value( $field, $raw );

			// Required check.
			$empty = is_array( $clean ) ? ( 0 === count( $clean ) ) : ( '' === trim( (string) $clean ) );
			if ( $field['required'] && $empty ) {
				self::add_error( $errors, $summary, $field, self::required_message( $field ) );
				continue;
			}
			if ( $empty ) {
				continue; // optional + empty.
			}

			// Type/format validation.
			$verr = self::validate_value( $field, $clean );
			if ( $verr ) {
				self::add_error( $errors, $summary, $field, $verr );
				continue;
			}

			$values[ $field['key'] ] = $clean;
		}

		/**
		 * Allow extensions to add validation errors (spec §10).
		 *
		 * @param array $errors  Field errors.
		 * @param array $values  Clean values.
		 * @param Form  $form    Form.
		 */
		$errors = apply_filters( 'acps_st_validation_errors', $errors, $values, $form );

		if ( ! empty( $errors ) ) {
			// Rebuild summary from (possibly filtered) errors, preserving order.
			$summary = array();
			foreach ( $fields as $field ) {
				if ( isset( $errors[ $field['key'] ] ) ) {
					$summary[] = array( 'field' => $field['key'], 'message' => $errors[ $field['key'] ] );
				}
			}
			return array( 'success' => false, 'errors' => $errors, 'summary' => $summary );
		}

		// --- Persist. -------------------------------------------------------
		$session_id = null;
		if ( ! empty( $request['acps_session'] ) ) {
			// Lookup-only: link to the visitor's existing journey if there is one,
			// but never fabricate an empty session just because a form was sent.
			$session_id = Session::lookup( $request['acps_session'] );
		}

		// Visitor identity is the server-side IP + user-agent fingerprint (same
		// as the spam guard) — attach it to the entry, register the visitor, and
		// if the form carries an "accname" field use it as the visitor's name.
		$visitor_uid = '';
		if ( Settings::get( 'analytics_enabled' ) && Settings::get( 'track_visitors' ) ) {
			$visitor_uid = Visitors::fingerprint();
			Visitors::record( $visitor_uid );
			$accname = self::accname_value( $fields, $values );
			if ( '' !== $accname ) {
				Visitors::set_name( $visitor_uid, $accname );
			}
		}

		$entry_id = Entries::create(
			array(
				'form_id'     => $form->id,
				'session_id'  => $session_id,
				'visitor_uid' => $visitor_uid,
				'page_id'     => isset( $request['acps_page_id'] ) ? absint( $request['acps_page_id'] ) : 0,
				'page_url'    => isset( $request['acps_page_url'] ) ? $request['acps_page_url'] : '',
				'status'      => $form->is_feedback ? 'new' : 'new',
			),
			$values
		);

		/**
		 * Fired after an entry is stored (spec §10).
		 *
		 * @param int   $entry_id Entry id.
		 * @param array $values   Clean values.
		 * @param Form  $form     Form.
		 */
		do_action( 'acps_st_after_submission', $entry_id, $values, $form );

		// --- Notify. --------------------------------------------------------
		Notifications::send( $form, $entry_id, $values, $fields );

		return array(
			'success'      => true,
			'entry_id'     => $entry_id,
			'confirmation' => self::confirmation( $form ),
		);
	}

	/**
	 * Enforce per-form response limits. Returns an error message to show, or ''
	 * when the submission is allowed. Device identity uses the same anonymized
	 * IP + browser fingerprint as the spam rate-limiter.
	 *
	 * @param Form $form Form.
	 * @return string
	 */
	private static function check_limits( Form $form ) {
		$total = (int) ( $form->settings['limit_total'] ?? 0 );
		$per   = (int) ( $form->settings['limit_per_device'] ?? 0 );
		if ( $total <= 0 && $per <= 0 ) {
			return '';
		}

		$custom = trim( (string) ( $form->settings['limit_message'] ?? '' ) );

		if ( $total > 0 && Entries::count_for_form( $form->id ) >= $total ) {
			return $custom ? $custom : __( 'This form is no longer accepting responses.', 'acps-site-toolkit' );
		}

		if ( $per > 0 ) {
			$ip = Session::anonymize_ip( Session::client_ip() );
			$ua = Session::user_agent_summary();
			if ( Entries::count_by_fingerprint( $form->id, $ip, $ua ) >= $per ) {
				return $custom ? $custom : __( 'You have already submitted this form the maximum number of times.', 'acps-site-toolkit' );
			}
		}

		return '';
	}

	/**
	 * Find the value of an "accname" field (by field key or label), used to name
	 * the visitor. Returns '' when there is no such field.
	 *
	 * @param array $fields Normalized fields.
	 * @param array $values Clean values keyed by field key.
	 * @return string
	 */
	private static function accname_value( $fields, $values ) {
		foreach ( $fields as $field ) {
			$key   = strtolower( $field['key'] );
			$label = strtolower( preg_replace( '/[^a-z0-9]/i', '', $field['label'] ) );
			if ( 'accname' === $key || 'accname' === $label ) {
				$v = isset( $values[ $field['key'] ] ) ? $values[ $field['key'] ] : '';
				$v = is_array( $v ) ? implode( ' ', $v ) : (string) $v;
				return trim( $v );
			}
		}
		return '';
	}

	/**
	 * Confirmation payload for the front end (spec §7.3).
	 */
	private static function confirmation( Form $form ) {
		$type = $form->settings['confirmation_type'];
		return array(
			'type'     => $type,
			'message'  => wp_kses_post( $form->settings['confirmation_message'] ),
			'redirect' => ( in_array( $type, array( 'redirect', 'both' ), true ) && $form->settings['confirmation_redirect'] )
				? esc_url_raw( $form->settings['confirmation_redirect'] )
				: '',
		);
	}

	/**
	 * Sanitize a raw value by field type.
	 */
	private static function sanitize_value( $field, $raw ) {
		switch ( $field['type'] ) {
			case 'checkbox':
			case 'chips':
				$arr = is_array( $raw ) ? $raw : array( $raw );
				return array_values( array_filter( array_map( 'sanitize_text_field', $arr ), 'strlen' ) );
			case 'long_text':
				return sanitize_textarea_field( is_array( $raw ) ? '' : $raw );
			case 'email':
				return sanitize_email( is_array( $raw ) ? '' : $raw );
			case 'number':
			case 'scale':
			case 'rating':
				return is_array( $raw ) ? '' : preg_replace( '/[^0-9.\-]/', '', (string) $raw );
			default:
				return sanitize_text_field( is_array( $raw ) ? '' : $raw );
		}
	}

	/**
	 * Validate a non-empty clean value. Return an error string or ''.
	 */
	private static function validate_value( $field, $clean ) {
		if ( 'email' === $field['type'] && ! is_email( $clean ) ) {
			return __( 'Please enter a valid email address.', 'acps-site-toolkit' );
		}
		if ( 'number' === $field['type'] && '' !== $clean && ! is_numeric( $clean ) ) {
			return __( 'Please enter a number.', 'acps-site-toolkit' );
		}

		// Choice fields: value must be one of the options.
		if ( in_array( $field['type'], array( 'dropdown', 'radio', 'checkbox', 'chips' ), true ) && ! empty( $field['options'] ) ) {
			$allowed = wp_list_pluck( $field['options'], 'value' );
			$vals    = is_array( $clean ) ? $clean : array( $clean );
			foreach ( $vals as $v ) {
				if ( ! in_array( $v, $allowed, true ) ) {
					return __( 'Please choose a valid option.', 'acps-site-toolkit' );
				}
			}
		}

		// Optional length rules.
		if ( ! empty( $field['validation']['maxlength'] ) && is_string( $clean ) && mb_strlen( $clean ) > (int) $field['validation']['maxlength'] ) {
			/* translators: %d: maximum characters */
			return sprintf( __( 'Please shorten this to %d characters or fewer.', 'acps-site-toolkit' ), (int) $field['validation']['maxlength'] );
		}
		return '';
	}

	/**
	 * Handle a single file upload with strict type/size limits.
	 *
	 * @return string|\WP_Error URL of stored file, '' if none, or error.
	 */
	private static function handle_file( $field, $files ) {
		$key = $field['key'];
		if ( empty( $files['fields']['name'][ $key ] ) ) {
			return '';
		}

		$file = array(
			'name'     => $files['fields']['name'][ $key ],
			'type'     => $files['fields']['type'][ $key ],
			'tmp_name' => $files['fields']['tmp_name'][ $key ],
			'error'    => $files['fields']['error'][ $key ],
			'size'     => $files['fields']['size'][ $key ],
		);

		if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return '';
		}

		$max = (int) apply_filters( 'acps_st_max_upload_bytes', 10 * MB_IN_BYTES, $field );
		if ( $file['size'] > $max ) {
			return new \WP_Error( 'too_big', __( 'That file is too large.', 'acps-site-toolkit' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$overrides = array(
			'test_form' => false,
			'mimes'     => apply_filters(
				'acps_st_allowed_upload_mimes',
				array(
					'jpg|jpeg' => 'image/jpeg',
					'png'      => 'image/png',
					'gif'      => 'image/gif',
					'webp'     => 'image/webp',
					'pdf'      => 'application/pdf',
					'doc'      => 'application/msword',
					'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					'txt'      => 'text/plain',
				),
				$field
			),
		);

		$moved = wp_handle_upload( $file, $overrides );
		if ( isset( $moved['error'] ) ) {
			return new \WP_Error( 'upload_failed', $moved['error'] );
		}
		return isset( $moved['url'] ) ? esc_url_raw( $moved['url'] ) : '';
	}

	/**
	 * Register a field error in both the field map and the summary list.
	 */
	private static function add_error( &$errors, &$summary, $field, $message ) {
		$errors[ $field['key'] ] = $message;
		$summary[]               = array( 'field' => $field['key'], 'message' => $message );
	}

	/**
	 * Required-field message including the field's label for clarity.
	 */
	private static function required_message( $field ) {
		$label = $field['label'] ? $field['label'] : __( 'This field', 'acps-site-toolkit' );
		/* translators: %s: field label */
		return sprintf( __( '%s is required.', 'acps-site-toolkit' ), $label );
	}
}
