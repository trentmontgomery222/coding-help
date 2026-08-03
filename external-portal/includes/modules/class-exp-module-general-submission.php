<?php
/**
 * Module: General Content Update Queue Submission (spec Section 5.4).
 *
 * A catch-all form ("replace this PDF", "update this section") for requests with
 * no dedicated module. Lands in the shared queue tagged 'general_update'. There is
 * no automatic applier — an admin actions it manually and marks it approved.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * General submission module.
 */
class EXP_Module_General_Submission {

	const CAP  = 'submit_general_update';
	const SLUG = 'general_submission';
	const TYPE = 'general_update';

	/**
	 * Register.
	 *
	 * @param EXP_Registry $r Registry.
	 */
	public static function register( $r ) {
		$r->register_capability(
			array(
				'key'         => self::CAP,
				'label'       => __( 'Submit general update requests', 'external-portal' ),
				'description' => __( 'Submit free-form change requests (e.g. replace a PDF) for admin review.', 'external-portal' ),
				'target_type' => 'none',
				'module'      => self::SLUG,
				'core'        => true,
			)
		);
		$r->register_menu_item(
			array(
				'slug'       => self::SLUG,
				'label'      => __( 'Request an Update', 'external-portal' ),
				'icon'       => 'upload',
				'capability' => self::CAP,
				'render'     => array( __CLASS__, 'render' ),
				'handle'     => array( __CLASS__, 'handle' ),
				'position'   => 30,
				'core'       => true,
			)
		);
		$r->register_queue_type(
			array(
				'type'            => self::TYPE,
				'label'           => __( 'General request', 'external-portal' ),
				'review_renderer' => array( __CLASS__, 'review' ),
				'applier'         => null, // Actioned manually by an admin.
				'core'            => true,
			)
		);
		$r->register_activity_formatter( self::TYPE, array( __CLASS__, 'activity' ) );
	}

	/**
	 * Render.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	public static function render( array $ctx ) {
		$html  = '<p>' . esc_html__( 'Describe the change you need. An administrator will review and action it.', 'external-portal' ) . '</p>';
		$html .= '<form class="exp-form" method="post">';
		$html .= EXP_UI::module_hidden_fields( $ctx );
		$html .= EXP_UI::field(
			array(
				'name'     => 'exp_subject',
				'label'    => __( 'Subject', 'external-portal' ),
				'required' => true,
			)
		);
		$html .= EXP_UI::field(
			array(
				'name'  => 'exp_reference',
				'label' => __( 'Related page or file (optional)', 'external-portal' ),
				'help'  => __( 'A URL or a description of what this relates to.', 'external-portal' ),
			)
		);
		$ta_id = 'exp-general-desc';
		$html .= '<div class="exp-field"><label class="exp-field__label" for="' . esc_attr( $ta_id ) . '">' . esc_html__( 'Details', 'external-portal' ) . ' <span class="exp-field__req" aria-hidden="true">*</span></label>';
		$html .= '<textarea class="exp-field__input exp-textarea" id="' . esc_attr( $ta_id ) . '" name="exp_description" rows="8" required aria-required="true"></textarea></div>';
		$html .= '<button type="submit" class="exp-button">' . esc_html__( 'Submit request', 'external-portal' ) . '</button>';
		$html .= '</form>';
		return $html;
	}

	/**
	 * Handle.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function handle( array $ctx ) {
		$user    = $ctx['user'];
		$subject = isset( $_POST['exp_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_subject'] ) ) : '';
		$desc    = isset( $_POST['exp_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exp_description'] ) ) : '';

		if ( '' === $subject || '' === $desc ) {
			return array( array( 'type' => 'error', 'text' => __( 'Please provide a subject and details.', 'external-portal' ) ) );
		}

		$id = EXP_Queue::submit(
			array(
				'type'         => self::TYPE,
				'submitted_by' => $user->id,
				'content_ref'  => 'general',
				'payload'      => array(
					'subject'     => $subject,
					'reference'   => isset( $_POST['exp_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_reference'] ) ) : '',
					'description' => $desc,
				),
			)
		);
		if ( is_wp_error( $id ) ) {
			return array( array( 'type' => 'error', 'text' => $id->get_error_message() ) );
		}
		return array( array( 'type' => 'success', 'text' => __( 'Your request was submitted.', 'external-portal' ) ) );
	}

	/**
	 * Review preview.
	 *
	 * @param object $item Queue item.
	 * @return string
	 */
	public static function review( $item ) {
		$data = $item->payload_data;
		$html = '<p><strong>' . esc_html__( 'Subject:', 'external-portal' ) . '</strong> ' . esc_html( $data['subject'] ?? '' ) . '</p>';
		if ( ! empty( $data['reference'] ) ) {
			$html .= '<p><strong>' . esc_html__( 'Reference:', 'external-portal' ) . '</strong> ' . esc_html( $data['reference'] ) . '</p>';
		}
		$html .= '<p>' . nl2br( esc_html( $data['description'] ?? '' ) ) . '</p>';
		return $html;
	}

	/**
	 * My Activity line.
	 *
	 * @param object $item Queue item.
	 * @return string
	 */
	public static function activity( $item ) {
		$data = $item->payload_data;
		return sprintf(
			/* translators: %s: subject */
			esc_html__( 'Request: %s', 'external-portal' ),
			esc_html( $data['subject'] ?? '' )
		);
	}
}
