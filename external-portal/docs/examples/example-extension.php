<?php
/**
 * Plugin Name: External Portal — Example Extension
 * Description: A minimal, working example of extending External Portal. Registers a
 *              capability, a dashboard menu item, a queue type and an activity
 *              formatter. Copy this as a starting point. See docs/EXTENSION-API.md.
 * Version:     1.0.0
 * License:     GPL-2.0-or-later
 *
 * This file is a REFERENCE EXAMPLE, not part of the External Portal plugin itself.
 * Drop it in wp-content/plugins/ (in its own folder) to try it.
 *
 * @package ExternalPortalExample
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'exp_register_extensions',
	function () {
		// If the portal is deactivated, its API functions won't exist — bail safely.
		if ( ! function_exists( 'exp_register_menu_item' ) ) {
			return;
		}

		// 1. A capability. Appears automatically on the admin Permissions screen.
		exp_register_capability(
			array(
				'key'         => 'submit_feedback',
				'label'       => 'Submit feedback',
				'description' => 'Send a short feedback note for admin review.',
				'target_type' => 'none',
				'source'      => 'Example Extension',
			)
		);

		// 2. A dashboard menu item (hidden until an admin approves it, by default).
		exp_register_menu_item(
			array(
				'slug'       => 'example_feedback',
				'label'      => 'Send Feedback',
				'icon'       => 'megaphone',
				'capability' => 'submit_feedback',
				'render'     => 'expx_render',
				'handle'     => 'expx_handle',
				'position'   => 70,
				'source'     => 'Example Extension',
			)
		);

		// 3. A queue type so submissions appear in the shared review screen.
		exp_register_queue_type(
			array(
				'type'            => 'example_feedback',
				'label'           => 'Feedback',
				'review_renderer' => 'expx_review',
				'applier'         => null, // Nothing to auto-apply; admin reads and marks approved.
				'source'          => 'Example Extension',
			)
		);

		// 4. An activity formatter for the "My Activity" panel.
		exp_register_activity_formatter(
			'example_feedback',
			function ( $item ) {
				return 'Feedback: ' . esc_html( $item->payload_data['subject'] ?? '' );
			}
		);
	}
);

/**
 * Render the panel body. Return HTML — do not echo.
 *
 * @param array $ctx Portal render context.
 * @return string
 */
function expx_render( array $ctx ) {
	$html  = '<p>' . esc_html__( 'Share feedback with the site team.', 'external-portal-example' ) . '</p>';
	$html .= '<form method="post" action="' . esc_url( $ctx['form_action'] ) . '">';
	$html .= exp_module_form_fields( $ctx ); // Required hidden fields incl. CSRF.

	$html .= '<div class="exp-field"><label class="exp-field__label" for="expx-subject">' . esc_html__( 'Subject', 'external-portal-example' ) . '</label>';
	$html .= '<input class="exp-field__input" type="text" id="expx-subject" name="expx_subject" required aria-required="true" /></div>';

	$html .= '<div class="exp-field"><label class="exp-field__label" for="expx-body">' . esc_html__( 'Your feedback', 'external-portal-example' ) . '</label>';
	$html .= '<textarea class="exp-field__input" id="expx-body" name="expx_body" rows="6" required aria-required="true"></textarea></div>';

	$html .= '<button type="submit" class="exp-button">' . esc_html__( 'Send', 'external-portal-example' ) . '</button>';
	$html .= '</form>';
	return $html;
}

/**
 * Handle the POST. Runs after auth + CSRF + capability checks. Return notices.
 *
 * @param array $ctx Portal context.
 * @return array
 */
function expx_handle( array $ctx ) {
	$subject = isset( $_POST['expx_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['expx_subject'] ) ) : '';
	$body    = isset( $_POST['expx_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['expx_body'] ) ) : '';

	if ( '' === $subject || '' === $body ) {
		return array( array( 'type' => 'error', 'text' => __( 'Please fill in both fields.', 'external-portal-example' ) ) );
	}

	$id = exp_queue_submit(
		array(
			'type'         => 'example_feedback',
			'submitted_by' => $ctx['user']->id,
			'content_ref'  => 'feedback',
			'payload'      => array(
				'subject' => $subject,
				'body'    => $body,
			),
		)
	);
	if ( is_wp_error( $id ) ) {
		return array( array( 'type' => 'error', 'text' => $id->get_error_message() ) );
	}
	return array( array( 'type' => 'success', 'text' => __( 'Thanks — your feedback was submitted.', 'external-portal-example' ) ) );
}

/**
 * Admin review preview for a queued feedback item.
 *
 * @param object $item Queue row with ->payload_data.
 * @return string
 */
function expx_review( $item ) {
	$d = $item->payload_data;
	return '<p><strong>' . esc_html__( 'Subject:', 'external-portal-example' ) . '</strong> ' . esc_html( $d['subject'] ?? '' ) . '</p>'
		. '<p>' . nl2br( esc_html( $d['body'] ?? '' ) ) . '</p>';
}
