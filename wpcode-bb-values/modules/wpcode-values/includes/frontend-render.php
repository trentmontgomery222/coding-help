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

	/*
	 * Recursion guard.
	 *
	 * This runs a shortcode, and nothing stops the snippet behind it
	 * from containing this module's own shortcode - by accident, or by
	 * a snippet that renders the page it is on. Without a guard that is
	 * an unbounded loop that ends in exhausted memory, which is a fatal
	 * no try/catch can catch. One snippet is never rendered inside
	 * itself; the inner attempt simply produces nothing.
	 */
	if ( ! isset( $GLOBALS['wpcodebbv_rendering'] ) || ! is_array( $GLOBALS['wpcodebbv_rendering'] ) ) {
		$GLOBALS['wpcodebbv_rendering'] = array();
	}

	$wpcodebbv_snippet_id = (int) $module->get_snippet_id();

	if ( isset( $GLOBALS['wpcodebbv_rendering'][ $wpcodebbv_snippet_id ] ) ) {
		if ( function_exists( 'wpcodebbv_log' ) ) {
			wpcodebbv_log( 'snippet ' . $wpcodebbv_snippet_id . ' tried to render inside itself; stopped' );
		}

		return;
	}

	$GLOBALS['wpcodebbv_rendering'][ $wpcodebbv_snippet_id ] = true;

	$overrides = $module->get_overrides();

	// Snippets that are PHP can read this instead.
	$had_global      = isset( $GLOBALS['wpcode_bb_values'] );
	$previous_global = $had_global ? $GLOBALS['wpcode_bb_values'] : null;

	$GLOBALS['wpcode_bb_values'] = $overrides;

	$rendered = '';

	// Remember how deep the buffers are before handing control to
	// somebody else's code. A snippet that calls ob_end_clean() one time
	// too many would otherwise eat OUR buffer, and the ob_get_clean()
	// below would then close a buffer belonging to WordPress or the
	// theme - swallowing part of the page. Comparing levels afterwards
	// makes that recoverable instead.
	$wpcodebbv_level = ob_get_level();

	ob_start();

	try {
		echo do_shortcode( $shortcode );

		$rendered = ob_get_level() > $wpcodebbv_level ? ob_get_clean() : '';
	} catch ( \Throwable $e ) {
		if ( ob_get_level() > $wpcodebbv_level ) {
			ob_end_clean();
		}

		throw $e;
	} finally {
		// Put back any buffer the snippet opened and forgot to close,
		// so the rest of the page is not rendered inside it.
		while ( ob_get_level() > $wpcodebbv_level ) {
			ob_end_clean();
		}

		unset( $GLOBALS['wpcodebbv_rendering'][ $wpcodebbv_snippet_id ] );

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

	/*
	 * In the editor the module is rendered again on every save, without
	 * a page reload, so its scripts run more than once in the same page.
	 * Scoping them keeps a snippet that uses const or let at the top
	 * level from dying on the second run. Visitors get the snippet's
	 * output exactly as written unless the front-end filter says
	 * otherwise.
	 */
	$scope = $editing
		? apply_filters( 'wpcodebbv_scope_scripts', true )
		: apply_filters( 'wpcodebbv_scope_scripts_on_front', false );

	if ( $scope && function_exists( 'wpcodebbv_scope_scripts' ) ) {
		$rendered = wpcodebbv_scope_scripts( $rendered );
	}

	// A note naming the snippet this module runs and what has been
	// changed on it, so a page full of these is readable while editing.
	// Printed only for the editor, never on the live page.
	if ( $editing && function_exists( 'wpcodebbv_editor_note' ) ) {
		echo wpcodebbv_editor_note( $module->get_snippet_id(), $overrides ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in wpcodebbv_editor_note().
	}

	echo $rendered;
} catch ( \Throwable $e ) {
	if ( isset( $wpcodebbv_snippet_id, $GLOBALS['wpcodebbv_rendering'][ $wpcodebbv_snippet_id ] ) ) {
		unset( $GLOBALS['wpcodebbv_rendering'][ $wpcodebbv_snippet_id ] );
	}

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
