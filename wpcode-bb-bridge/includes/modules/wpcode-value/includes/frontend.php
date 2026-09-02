<?php
/**
 * Front-end render template for the WPCode Value module.
 *
 * @var WPCodeBB_Value_Module $module
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$data = $module->get_render_data();

if ( ! $data['tag'] ) {
	if ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active() ) {
		echo '<div class="wpcodebb-placeholder">' . esc_html__( 'WPCode Value: choose a Configuration in the module settings.', 'wpcode-bb-bridge' ) . '</div>';
	}
	return;
}

$shortcode = '[' . $data['tag'];

foreach ( $data['atts'] as $key => $value ) {
	$value      = str_replace( '"', '&quot;', (string) $value );
	$shortcode .= ' ' . $key . '="' . $value . '"';
}

$shortcode .= ']';

// Make the configured values available to snippets that prefer reading
// a global instead of shortcode attributes.
$GLOBALS['wpcode_bb_values'] = $data['values'];

echo do_shortcode( $shortcode );

unset( $GLOBALS['wpcode_bb_values'] );
