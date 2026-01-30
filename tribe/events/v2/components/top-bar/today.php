<?php
/**
 * View: Top Bar - Today Button
 *
 * Override: Replaces aria-description with aria-label for better accessibility support.
 *
 * @version 6.0.0
 *
 * @var string $today_url      URL for the today button.
 * @var string $today_label    Label for the today button.
 * @var string $today_title    Title/description for the today button.
 * @var bool   $show_today     Whether to show the today button.
 */

if ( empty( $show_today ) ) {
	return;
}

$classes = [
	'tribe-common-c-btn-border-small',
	'tribe-events-c-top-bar__today-button',
];

if ( empty( $today_url ) ) {
	$classes[] = 'tribe-common-a11y-hidden';
}
?>
<a
	href="<?php echo esc_url( $today_url ); ?>"
	class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
	data-js="tribe-events-view-link"
	aria-label="<?php esc_attr_e( 'Click to select the current month', 'the-events-calendar' ); ?>"
>
	<?php echo esc_html( $today_label ); ?>
</a>
