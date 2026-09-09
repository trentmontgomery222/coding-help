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
	$mode = isset( $data['mode'] ) ? $data['mode'] : null;

	if ( empty( $mode ) ) {
		if ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active() ) {
			echo '<div class="wpcodebb-placeholder">' . esc_html__( 'WPCode Value: choose a Configuration in the module settings.', 'wpcode-bb-bridge' ) . '</div>';
		}
		return;
	}

	if ( 'js_array' === $mode ) {
		$varname = isset( $data['varname'] ) ? (string) $data['varname'] : 'configurations';
		$js      = isset( $data['js'] ) ? (string) $data['js'] : 'null';
		// Defense in depth against breaking out of the <script> tag, even
		// though our own serializer already escapes string content.
		$js      = str_ireplace( '</script', '<\/script', $js );

		echo '<script>var ' . $varname . ' = ' . $js . ";</script>\n";
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

	try {
		echo do_shortcode( $shortcode );
	} finally {
		if ( null === $previous_global ) {
			unset( $GLOBALS['wpcode_bb_values'] );
		} else {
			$GLOBALS['wpcode_bb_values'] = $previous_global;
		}
	}
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
