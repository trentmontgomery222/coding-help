<?php
/**
 * Front-end render template for the WPCode Value module.
 *
 * This is the one place a *user-authored* WPCode snippet actually
 * executes, so it is the highest-risk spot in the plugin. Everything
 * here runs inside a try/catch(\Throwable) so a bug in that snippet
 * (undefined function/class, type error, etc.) can never take down the
 * rest of the page - PHP7+ makes those catchable as \Error, and this
 * plugin catches them. Regular visitors simply see nothing render for
 * this one block; logged-in admins get a small on-page message so the
 * problem is easy to find, and full details always go to the PHP error
 * log either way.
 *
 * @var WPCodeBB_Value_Module $module
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $module ) || ! is_object( $module ) ) {
	return;
}

try {
	$data = $module->get_render_data();

	if ( empty( $data['tag'] ) ) {
		if ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active() ) {
			echo '<div class="wpcodebb-placeholder">' . esc_html__( 'WPCode Value: choose a Configuration in the module settings.', 'wpcode-bb-bridge' ) . '</div>';
		}
		return;
	}

	$shortcode = '[' . $data['tag'];

	foreach ( (array) $data['atts'] as $key => $value ) {
		$key = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key );

		if ( '' === $key ) {
			continue;
		}

		$value      = str_replace( '"', '&quot;', (string) $value );
		$shortcode .= ' ' . $key . '="' . $value . '"';
	}

	$shortcode .= ']';

	// Make the configured values available to snippets that prefer
	// reading a global instead of shortcode attributes. Always restore
	// whatever was there before, even if rendering below throws.
	$previous_global              = isset( $GLOBALS['wpcode_bb_values'] ) ? $GLOBALS['wpcode_bb_values'] : null;
	$GLOBALS['wpcode_bb_values'] = (array) $data['values'];

	// Buffer the snippet's output rather than echoing it straight out.
	// Beaver Builder saves and refreshes a layout over AJAX and expects a
	// clean JSON response; a PHP notice or a stray echo from the snippet
	// would otherwise land in the middle of that response and show up as
	// Beaver Builder's "detected a plugin conflict" error. Buffering keeps
	// anything the snippet emits inside this module's own markup, and
	// discards it entirely if the snippet throws part way through.
	$rendered = '';

	ob_start();

	try {
		echo do_shortcode( $shortcode );
		$rendered = ob_get_clean();
	} catch ( \Throwable $e ) {
		ob_end_clean();
		throw $e;
	} finally {
		if ( null === $previous_global ) {
			unset( $GLOBALS['wpcode_bb_values'] );
		} else {
			$GLOBALS['wpcode_bb_values'] = $previous_global;
		}
	}

	echo $rendered;
} catch ( \Throwable $e ) {
	if ( function_exists( 'wpcodebb_log_error' ) ) {
		wpcodebb_log_error( 'frontend render', $e );
	} elseif ( function_exists( 'error_log' ) ) {
		error_log( '[WPCode BB Bridge] frontend render: ' . $e->getMessage() );
	}

	if ( current_user_can( 'manage_options' ) ) {
		echo '<div class="wpcodebb-placeholder wpcodebb-error">'
			. esc_html__( 'WPCode Value: this snippet hit an error and was not rendered (visible to admins only). Check the PHP error log for details.', 'wpcode-bb-bridge' )
			. '</div>';
	}
	// Regular visitors see nothing here - the rest of the page renders normally.
}
