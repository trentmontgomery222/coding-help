<?php
/**
 * Front end for the Status Dot module.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

// Beaver Builder includes this file directly, with no hook of ours on the
// stack to catch a failure. So it re-runs itself under the failsafe: a throw
// anywhere below draws nothing for this module instead of breaking the page.
if ( empty( $acps_guarded ) && class_exists( 'ACPS_Alerts_Failsafe' ) && method_exists( 'ACPS_Alerts_Failsafe', 'render_template' ) ) {
	echo ACPS_Alerts_Failsafe::render_template( __FILE__, get_defined_vars(), 'module/status-dot' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes its own output.

	return;
}

if ( ! class_exists( 'ACPS_Alerts_Shortcodes' ) ) {
	return;
}

$acps_level = isset( $settings->level ) ? (string) $settings->level : 'normal';
$acps_label = isset( $settings->label ) ? (string) $settings->label : '';
$acps_size  = isset( $settings->size ) ? (int) $settings->size : 14;
$acps_color = isset( $settings->color ) ? (string) $settings->color : '';
$acps_word  = isset( $settings->word ) && '1' === (string) $settings->word;

$acps_align = isset( $settings->alignment ) ? preg_replace( '/[^a-z]/', '', (string) $settings->alignment ) : 'left';
$acps_align = in_array( $acps_align, array( 'left', 'center', 'right' ), true ) ? $acps_align : 'left';

$acps_dot = ACPS_Alerts_Shortcodes::dot_markup( $acps_level, $acps_label, $acps_size, $acps_color, $acps_word );

if ( '' === $acps_dot ) {
	return;
}
?>
<div class="acps-dot-module" style="text-align:<?php echo esc_attr( $acps_align ); ?>">
	<?php echo $acps_dot; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built inline and escaped at source. ?>
</div>
