<?php
/**
 * Reference form template (spec §10).
 *
 * This is a starting point for a child-theme override. It is NOT loaded from
 * the plugin's own templates directory — copy it to
 * `your-child-theme/acps-site-toolkit/form.php` to activate it.
 *
 * It reuses Form_Renderer::render_field() for each field so the per-field
 * accessibility (labels, fieldset/legend, aria-describedby, error slots) stays
 * correct even in your override. You are free to change the wrapper layout, but
 * keep the token slots and honeypot — the forms.js runtime depends on them.
 *
 * Available: $acps_form (Form), $acps_fields (array), $acps_args (array).
 *
 * @package ACPS\SiteToolkit
 */

use ACPS\SiteToolkit\Form_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uid     = 'acps-form-' . $acps_form->id . '-' . wp_rand( 1000, 9999 );
$post_id = isset( $acps_args['post_id'] ) ? absint( $acps_args['post_id'] ) : ( get_the_ID() ?: 0 );
?>
<div class="acps-form-wrap">
	<form class="acps-form" id="<?php echo esc_attr( $uid ); ?>"
		data-acps-form="<?php echo esc_attr( $acps_form->id ); ?>"
		data-acps-page="<?php echo esc_attr( $post_id ); ?>"
		data-multipage="0" novalidate>

		<div class="acps-error-summary" id="<?php echo esc_attr( $uid ); ?>-errors" role="alert" tabindex="-1" hidden>
			<h2 class="acps-error-summary__title"><?php esc_html_e( 'There is a problem', 'acps-site-toolkit' ); ?></h2>
			<ul class="acps-error-summary__list"></ul>
		</div>

		<div class="acps-page" data-page="1">
			<?php foreach ( $acps_fields as $acps_field ) : ?>
				<?php echo Form_Renderer::render_field( $acps_field, $uid ); // phpcs:ignore ?>
			<?php endforeach; ?>
		</div>

		<div class="acps-hp" aria-hidden="true">
			<label>Leave this field blank
				<input type="text" data-acps-hp="1" tabindex="-1" autocomplete="off">
			</label>
		</div>

		<input type="hidden" name="acps_nonce" value="">
		<input type="hidden" name="acps_ts" value="">
		<input type="hidden" name="acps_session" value="" data-acps-session-slot="1">
		<input type="hidden" name="acps_form_id" value="<?php echo esc_attr( $acps_form->id ); ?>">
		<input type="hidden" name="acps_page_id" value="<?php echo esc_attr( $post_id ); ?>">

		<div class="acps-actions">
			<button type="submit" class="acps-btn acps-submit">
				<?php echo esc_html( $acps_form->settings['submit_label'] ?: __( 'Submit', 'acps-site-toolkit' ) ); ?>
			</button>
		</div>

		<div class="acps-status" role="status" aria-live="polite"></div>
	</form>
</div>
