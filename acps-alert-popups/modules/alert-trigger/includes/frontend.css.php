<?php
/**
 * Front end CSS for the Alert Trigger module.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;
?>
.fl-node-<?php echo esc_html( $id ); ?> .acps-alert-trigger {
	cursor: pointer;
}

.fl-node-<?php echo esc_html( $id ); ?> .acps-alert-trigger--link {
	background: none;
	border: 0;
	padding: 0;
	text-decoration: underline;
}
