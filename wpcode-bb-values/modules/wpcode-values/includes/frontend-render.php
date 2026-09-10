<?php
/**
 * Front-end template.
 *
 * This is the only place a snippet written by someone else actually
 * runs, so it is the only genuinely risky spot in the plugin, and
 * everything here exists to contain that risk:
 *
 *  - The whole template is wrapped in try/catch( \Throwable ), which on
 *    PHP 7+ also catches fatals such as calling an undefined function.
 *  - The snippet's output is buffered rather than echoed straight out.
 *    Beaver Builder saves and refreshes layouts over AJAX and expects a
 *    clean JSON response; a PHP notice from the snippet would otherwise
 *    land in the middle of it and surface as Beaver Builder's
 *    "detected a plugin conflict" error.
 *  - If the snippet throws part way through, its half-written output is
 *    discarded rather than shown.
 *
 * @var WPCodeBBV_Module $module
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $module ) || ! is_object( $module ) || ! method_exists( $module, 'get_shortcode' ) ) {
	return;
}

try {
	$shortcode = $module->get_shortcode();

	// Everything editorial below is gated on this. It is false on the
	// live page and in Beaver Builder's own preview, and false for anyone
	// not logged in and able to edit - so a visitor never sees any of it.
	$editing = function_exists( 'wpcodebbv_is_editing' ) && wpcodebbv_is_editing();

	if ( '' === $shortcode ) {
		// Nothing configured yet. Say so while editing; show visitors nothing.
		if ( $editing ) {
			echo '<div class="wpcodebbv-placeholder">'
				. esc_html__( 'WPCode Values: pick your snippet on the Setup tab of this module\'s settings.', 'wpcode-bb-values' )
				. '</div>';
		}

		return;
	}

	$overrides = $module->get_overrides();

	// Snippets that are PHP can read this instead.
	$had_global      = isset( $GLOBALS['wpcode_bb_values'] );
	$previous_global = $had_global ? $GLOBALS['wpcode_bb_values'] : null;

	$GLOBALS['wpcode_bb_values'] = $overrides;

	$rendered = '';

	ob_start();

	try {
		echo do_shortcode( $shortcode );
		$rendered = ob_get_clean();
	} catch ( \Throwable $e ) {
		ob_end_clean();
		throw $e;
	} finally {
		if ( $had_global ) {
			$GLOBALS['wpcode_bb_values'] = $previous_global;
		} else {
			unset( $GLOBALS['wpcode_bb_values'] );
		}
	}

	// Rewrite the values inside the snippet's own "configurations"
	// array. This is what actually makes a JavaScript snippet
	// configurable per page: the values are literals in the script the
	// snippet just printed, so they are edited there rather than passed
	// in. If the array cannot be found or parsed, the output is returned
	// exactly as the snippet produced it.
	if ( ! empty( $overrides ) && class_exists( 'WPCodeBBV_Scanner' ) ) {
		try {
			$rendered = WPCodeBBV_Scanner::apply( $rendered, $overrides );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wpcodebbv_log' ) ) {
				wpcodebbv_log( 'could not apply overrides: ' . $e->getMessage() );
			}
		}
	}

	// A note naming the snippet this module runs and what has been
	// changed on it, so a page full of these is readable while editing.
	// Printed only for the editor, never on the live page.
	if ( $editing && function_exists( 'wpcodebbv_editor_note' ) ) {
		echo wpcodebbv_editor_note( $module->get_snippet_id(), $overrides ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in wpcodebbv_editor_note().
	}

	echo $rendered;
} catch ( \Throwable $e ) {
	if ( function_exists( 'wpcodebbv_log' ) ) {
		wpcodebbv_log( 'snippet render failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
	}

	// Visitors see nothing and the rest of the page renders normally.
	if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
		echo '<div class="wpcodebbv-placeholder">'
			. esc_html__( 'WPCode Values: this snippet hit an error and was not rendered. This message is only shown to administrators; check the PHP error log for details.', 'wpcode-bb-values' )
			. '</div>';
	}
}
