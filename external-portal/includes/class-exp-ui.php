<?php
/**
 * Accessible markup helpers (spec Section 1 & 7 — WCAG 2.2 AA / Section 508).
 *
 * Every custom UI the portal renders is built here or through these helpers so
 * accessibility is consistent and can't be silently broken by a module.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reusable accessible components.
 */
class EXP_UI {

	/**
	 * Render a status/alert message. Status is conveyed by TEXT + role, never
	 * by colour alone (WCAG 1.4.1). Errors use role="alert" (assertive);
	 * success/info use role="status" (polite).
	 *
	 * @param string $type    error|success|info|warning.
	 * @param string $message Human-readable message (already translated).
	 * @return string HTML.
	 */
	public static function notice( $type, $message ) {
		$type   = in_array( $type, array( 'error', 'success', 'info', 'warning' ), true ) ? $type : 'info';
		$role   = ( 'error' === $type || 'warning' === $type ) ? 'alert' : 'status';
		$labels = array(
			'error'   => __( 'Error:', 'external-portal' ),
			'warning' => __( 'Warning:', 'external-portal' ),
			'success' => __( 'Success:', 'external-portal' ),
			'info'    => __( 'Note:', 'external-portal' ),
		);

		return sprintf(
			'<div class="exp-notice exp-notice--%1$s" role="%2$s"><span class="exp-notice__label">%3$s</span> %4$s</div>',
			esc_attr( $type ),
			esc_attr( $role ),
			esc_html( $labels[ $type ] ),
			esc_html( $message )
		);
	}

	/**
	 * Render multiple notices.
	 *
	 * @param array $notices Array of ['type'=>, 'text'=>].
	 * @return string
	 */
	public static function notices( array $notices ) {
		$out = '';
		foreach ( $notices as $n ) {
			if ( isset( $n['text'] ) ) {
				$out .= self::notice( isset( $n['type'] ) ? $n['type'] : 'info', $n['text'] );
			}
		}
		return $out;
	}

	/**
	 * A labelled text-like input with optional error, wired with aria-describedby.
	 *
	 * @param array $args name, label, type, value, required, autocomplete, error, help, inputmode, id.
	 * @return string
	 */
	public static function field( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'name'         => '',
				'label'        => '',
				'type'         => 'text',
				'value'        => '',
				'required'     => false,
				'autocomplete' => '',
				'inputmode'    => '',
				'error'        => '',
				'help'         => '',
				'id'           => '',
			)
		);
		$id       = $args['id'] ? $args['id'] : 'exp-' . sanitize_html_class( $args['name'] );
		$help_id  = $id . '-help';
		$err_id   = $id . '-error';
		$describe = array();
		if ( '' !== $args['help'] ) {
			$describe[] = $help_id;
		}
		if ( '' !== $args['error'] ) {
			$describe[] = $err_id;
		}

		$html  = '<div class="exp-field' . ( $args['error'] ? ' exp-field--error' : '' ) . '">';
		$html .= '<label class="exp-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $args['label'] );
		if ( $args['required'] ) {
			$html .= ' <span class="exp-field__req" aria-hidden="true">*</span><span class="screen-reader-text"> ' . esc_html__( '(required)', 'external-portal' ) . '</span>';
		}
		$html .= '</label>';

		if ( '' !== $args['help'] ) {
			$html .= '<p class="exp-field__help" id="' . esc_attr( $help_id ) . '">' . esc_html( $args['help'] ) . '</p>';
		}

		$html .= sprintf(
			'<input class="exp-field__input" type="%1$s" name="%2$s" id="%3$s" value="%4$s"%5$s%6$s%7$s%8$s />',
			esc_attr( $args['type'] ),
			esc_attr( $args['name'] ),
			esc_attr( $id ),
			esc_attr( $args['value'] ),
			$args['required'] ? ' required aria-required="true"' : '',
			$args['autocomplete'] ? ' autocomplete="' . esc_attr( $args['autocomplete'] ) . '"' : '',
			$args['inputmode'] ? ' inputmode="' . esc_attr( $args['inputmode'] ) . '"' : '',
			$describe ? ' aria-describedby="' . esc_attr( implode( ' ', $describe ) ) . '"' . ( $args['error'] ? ' aria-invalid="true"' : '' ) : ''
		);

		if ( '' !== $args['error'] ) {
			$html .= '<p class="exp-field__error" id="' . esc_attr( $err_id ) . '">' . esc_html( $args['error'] ) . '</p>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Hidden fields every module form needs: routes the POST to the module
	 * dispatcher and carries the session CSRF token.
	 *
	 * @param array $ctx Module context (expects 'slug' and 'csrf').
	 * @return string
	 */
	public static function module_hidden_fields( array $ctx ) {
		return '<input type="hidden" name="exp_action" value="module" />'
			. '<input type="hidden" name="exp_module" value="' . esc_attr( $ctx['slug'] ) . '" />'
			. '<input type="hidden" name="exp_csrf" value="' . esc_attr( $ctx['csrf'] ) . '" />';
	}

	/**
	 * Wrap third-party module output in a consistent, accessible container so a
	 * registering plugin can't break the portal's markup contract (spec Section 7).
	 *
	 * @param string $slug  Menu item slug.
	 * @param string $title Panel title.
	 * @param string $html  Raw module output.
	 * @return string
	 */
	public static function wrap_module( $slug, $title, $html ) {
		$heading_id = 'exp-panel-' . sanitize_html_class( $slug ) . '-title';
		return sprintf(
			'<section class="exp-panel" aria-labelledby="%1$s" tabindex="-1" id="exp-panel-%2$s"><h2 class="exp-panel__title" id="%1$s">%3$s</h2><div class="exp-panel__body">%4$s</div></section>',
			esc_attr( $heading_id ),
			esc_attr( sanitize_html_class( $slug ) ),
			esc_html( $title ),
			$html // Module output; modules are responsible for escaping their own content.
		);
	}
}
