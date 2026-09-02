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

	if ( '' === $shortcode ) {
		// Nothing configured yet. Say so while editing; show visitors nothing.
		if ( class_exists( 'FLBuilderModel' ) && is_callable( array( 'FLBuilderModel', 'is_builder_active' ) ) && FLBuilderModel::is_builder_active() ) {
			echo '<div class="wpcodebbv-placeholder">'
				. esc_html__( 'WPCode Values: add your snippet\'s shortcode tag in this module\'s settings.', 'wpcode-bb-values' )
				. '</div>';
		}

		return;
	}

	$data = $module->get_values();

	// Also expose the values as a global, for snippets that would rather
	// read that than shortcode attributes. Whatever was there before is
	// always put back, including when the snippet throws.
	$had_global      = isset( $GLOBALS['wpcode_bb_values'] );
	$previous_global = $had_global ? $GLOBALS['wpcode_bb_values'] : null;

	$GLOBALS['wpcode_bb_values'] = $data['values'];

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
