<?php
/**
 * View Selector Button Template
 *
 * Override: Replaces aria-description with aria-label for better accessibility support.
 *
 * @version 6.0.0
 *
 * @var string $view_selector_class       Classes for the view selector container.
 * @var string $view_selector_label       Label for the current view.
 * @var bool   $view_selector_is_expanded Whether the selector is expanded.
 */

if ( empty( $view_selector_label ) ) {
	return;
}
?>
<button
	class="tribe-events-c-view-selector__button tribe-common-c-btn__clear"
	data-js="tribe-events-view-selector-button"
	aria-current="true"
	aria-label="<?php esc_attr_e( 'Select Calendar View', 'the-events-calendar' ); ?>"
	tabindex="0"
	aria-expanded="<?php echo $view_selector_is_expanded ? 'true' : 'false'; ?>"
	aria-controls="tribe-events-view-selector-content"
>
	<span class="tribe-events-c-view-selector__button-icon tribe-common-svgicon"></span>
	<span class="tribe-events-c-view-selector__button-text">
		<?php echo esc_html( $view_selector_label ); ?>
	</span>
</button>
