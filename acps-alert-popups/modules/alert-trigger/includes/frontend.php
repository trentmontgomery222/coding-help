<?php
/**
 * Front end markup for the Alert Trigger module.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

$acps_alert_id = absint( $settings->alert_id );

if ( ! $acps_alert_id || ! ACPS_Alerts_Source::is_popup( $acps_alert_id ) ) {
	if ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active() ) {
		echo '<p>' . esc_html__( 'Choose an alert for this trigger.', 'acps-alert-popups' ) . '</p>';
	}

	return;
}

$acps_classes = array( 'acps-alert-open', 'acps-alert-trigger' );

if ( 'link' === $settings->style ) {
	$acps_classes[] = 'acps-alert-trigger--link';
} else {
	$acps_classes[] = 'acps-alert-trigger--button';
	$acps_classes[] = 'fl-button';
}

if ( ! empty( $settings->css_class ) ) {
	$acps_classes[] = sanitize_html_class( $settings->css_class );
}
?>
<div class="acps-alert-trigger-wrap" style="text-align:<?php echo esc_attr( $settings->alignment ); ?>">
	<button type="button" class="<?php echo esc_attr( implode( ' ', $acps_classes ) ); ?>" data-alert="<?php echo esc_attr( $acps_alert_id ); ?>">
		<?php echo esc_html( $settings->text ); ?>
	</button>
</div>
