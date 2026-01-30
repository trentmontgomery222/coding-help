<?php
/**
 * View: Top Bar - Datepicker Button
 *
 * Override: Replaces aria-description with aria-label for better accessibility support.
 *
 * @version 6.0.0
 *
 * @var string $datepicker_date   The current datepicker date.
 * @var bool   $datepicker_toggle Whether the datepicker is toggled/expanded.
 */
?>
<button
	class="tribe-common-c-btn__clear tribe-common-h3 tribe-common-h--alt tribe-events-c-top-bar__datepicker-button"
	data-js="tribe-events-top-bar-datepicker-button"
	type="button"
	aria-label="<?php esc_attr_e( 'Click to toggle datepicker', 'the-events-calendar' ); ?>"
	aria-expanded="<?php echo $datepicker_toggle ? 'true' : 'false'; ?>"
>
	<time
		datetime="<?php echo esc_attr( $datepicker_date ); ?>"
		class="tribe-events-c-top-bar__datepicker-time"
	>
		<?php echo esc_html( $datepicker_date ); ?>
	</time>
	<span class="tribe-events-c-top-bar__datepicker-icon tribe-common-svgicon tribe-common-svgicon--caret-down"></span>
</button>
