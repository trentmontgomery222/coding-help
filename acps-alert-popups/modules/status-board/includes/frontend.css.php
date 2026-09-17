<?php
/**
 * Front end CSS for the Status Board module.
 *
 * Per-instance values only (the colours picked in the module). The structural
 * styling lives in assets/css/board.css so it is not repeated for every module
 * on the page.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

$acps_bg   = ! empty( $settings->banner_color ) ? $settings->banner_color : '1b2f5e';
$acps_text = ! empty( $settings->text_color ) ? $settings->text_color : 'ffffff';

// Beaver Builder colour fields store a bare hex, but can also hold an rgba()
// string; only prefix a # when it really is a hex value.
$acps_bg   = ( preg_match( '/^[0-9a-f]{3,8}$/i', $acps_bg ) ) ? '#' . $acps_bg : $acps_bg;
$acps_text = ( preg_match( '/^[0-9a-f]{3,8}$/i', $acps_text ) ) ? '#' . $acps_text : $acps_text;
?>
.fl-node-<?php echo esc_html( $id ); ?> .acps-board__banner--custom {
	background: <?php echo esc_html( $acps_bg ); ?>;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-board__banner {
	color: <?php echo esc_html( $acps_text ); ?>;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-board__banner .acps-board__title,
.fl-node-<?php echo esc_html( $id ); ?> .acps-board__banner .acps-board__headline,
.fl-node-<?php echo esc_html( $id ); ?> .acps-board__banner .acps-board__message {
	color: inherit;
}
