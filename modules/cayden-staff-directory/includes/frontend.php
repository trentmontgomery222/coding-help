<?php
/**
 * Beaver Builder module front-end template.
 *
 * Beaver Builder loads this file when rendering the module, with $module and
 * $settings in scope. It simply hands the module's settings to the plugin's
 * shared renderer, which returns fully escaped markup and enqueues the assets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'CAYDENDIR_sd_render' ) ) {
	return;
}

$CAYDENDIR_atts = array(
	'heading' => isset( $settings->heading ) && '' !== $settings->heading ? $settings->heading : 'Staff Directory',
	'layout'  => isset( $settings->layout ) ? $settings->layout : '',
	'match'   => isset( $settings->match ) ? $settings->match : 'any',
);

// CAYDENDIR_sd_render() escapes everything it outputs.
echo CAYDENDIR_sd_render( $CAYDENDIR_atts ); // phpcs:ignore WordPress.Security.EscapeOutput -- renderer returns escaped markup
