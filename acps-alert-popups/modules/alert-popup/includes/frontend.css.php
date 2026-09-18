<?php
/**
 * Front end CSS for the Current Alert module.
 *
 * All of this styles the editing card, which only ever renders inside the
 * Beaver Builder editor. It lives here rather than in a site stylesheet so a
 * live page carries none of it in its markup.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;
?>
.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit {
	border: 2px dashed #b6c2d9;
	border-radius: 10px;
	padding: 18px;
	background: #f4f7fc;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit.is-live {
	border-color: #1b2f5e;
	background: #eef3fb;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit__flag {
	margin: 0 0 14px;
	font-size: 13px;
	font-weight: 700;
	letter-spacing: .02em;
	text-transform: uppercase;
	color: #46516b;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit__dot {
	display: inline-block;
	width: 10px;
	height: 10px;
	margin-right: 8px;
	border-radius: 50%;
	background: #aab4c8;
	vertical-align: baseline;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit__stage {
	padding: 26px 16px;
	border-radius: 8px;
	background: rgba(23, 33, 54, .55);
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit__dialog {
	position: relative;
	max-width: 520px;
	margin: 0 auto;
	padding: 26px 30px;
	border-top: 6px solid #1b2f5e;
	border-radius: 6px;
	background: #fff;
	box-shadow: 0 18px 40px rgba(0, 0, 0, .25);
	text-align: left;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit__close {
	position: absolute;
	top: 10px;
	right: 14px;
	font-size: 24px;
	line-height: 1;
	color: #8a93a6;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit__level {
	margin: 0 0 6px;
	font-size: 12px;
	font-weight: 800;
	letter-spacing: .08em;
	text-transform: uppercase;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit__heading {
	margin: 0 0 10px;
	font-size: 24px;
	line-height: 1.25;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit__text {
	margin: 0;
	font-size: 16px;
	line-height: 1.55;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit__text--empty {
	color: #8a93a6;
	font-style: italic;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-popup-edit__note {
	margin: 14px 0 0;
	font-size: 13px;
	line-height: 1.5;
	color: #46516b;
}
