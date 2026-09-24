<?php
/**
 * Front end markup for the Alert Trigger module.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

// Beaver Builder includes this file directly, with no hook of ours on the
// stack to catch a failure. So it re-runs itself under the failsafe: a throw
// anywhere below draws nothing for this module instead of breaking the page.
if ( empty( $acps_guarded ) && class_exists( 'ACPS_Alerts_Failsafe' ) && method_exists( 'ACPS_Alerts_Failsafe', 'render_template' ) ) {
	echo ACPS_Alerts_Failsafe::render_template( __FILE__, get_defined_vars(), 'module/alert-trigger' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes its own output.

	return;
}

// A layout saved by an older version of the module may not carry every
// setting, and the classes it needs may be absent if the plugin is half
// loaded. Check before touching anything rather than fatal inside a row.
if ( ! isset( $settings ) || ! is_object( $settings ) || ! class_exists( 'ACPS_Alerts_Source' ) ) {
	return;
}

$acps_alert_id = isset( $settings->alert_id ) ? absint( $settings->alert_id ) : 0;
$acps_style    = isset( $settings->style ) ? $settings->style : 'button';
$acps_text     = isset( $settings->text ) ? $settings->text : __( 'Read the alert', 'acps-alert-popups' );
$acps_align    = isset( $settings->alignment ) ? (string) $settings->alignment : 'left';
$acps_align    = in_array( $acps_align, array( 'left', 'center', 'right' ), true ) ? $acps_align : 'left';

if ( ! $acps_alert_id || ! ACPS_Alerts_Source::is_popup( $acps_alert_id ) ) {
	if ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active() ) {
		echo '<p>' . esc_html__( 'Choose an alert for this trigger.', 'acps-alert-popups' ) . '</p>';
	}

	return;
}

$acps_classes = array( 'acps-alert-open', 'acps-alert-trigger' );

if ( 'link' === $acps_style ) {
	$acps_classes[] = 'acps-alert-trigger--link';
} else {
	$acps_classes[] = 'acps-alert-trigger--button';
	$acps_classes[] = 'fl-button';
}

if ( ! empty( $settings->css_class ) ) {
	$acps_classes[] = sanitize_html_class( $settings->css_class );
}
?>
<div class="acps-alert-trigger-wrap" style="text-align:<?php echo esc_attr( $acps_align ); ?>">
	<button type="button" class="<?php echo esc_attr( implode( ' ', $acps_classes ) ); ?>" data-alert="<?php echo esc_attr( $acps_alert_id ); ?>">
		<?php echo esc_html( $acps_text ); ?>
	</button>
</div>
