<?php
/**
 * Three-pane form builder (spec §7.7): field-type list, canvas, settings panel.
 *
 * Drag-and-drop is NOT the only way to do anything — every field has Up/Down
 * buttons and a "move to position" input (WCAG 2.2 SC 2.5.7). The whole builder
 * is a Section 508 surface, so it must be operable by keyboard alone.
 *
 * The canvas state is mirrored into a hidden JSON field on submit by
 * admin-builder.js.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Form;
use ACPS\SiteToolkit\Field_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$form_id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$form    = $form_id ? Form::find( $form_id ) : new Form();
if ( ! $form ) {
	$form = new Form();
}
$types      = Field_Types::all();
$fields_json = wp_json_encode( array_values( Field_Types::normalize_list( $form->fields ) ) );
$saved       = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification
?>
<div class="wrap acps-admin acps-builder">
	<h1><?php echo $form->id ? esc_html__( 'Edit form', 'acps-site-toolkit' ) : esc_html__( 'New form', 'acps-site-toolkit' ); ?></h1>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible" role="status"><p><?php esc_html_e( 'Form saved.', 'acps-site-toolkit' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="acps-builder-form">
		<?php wp_nonce_field( 'acps_st_save_form' ); ?>
		<input type="hidden" name="action" value="acps_st_save_form">
		<input type="hidden" name="form_id" value="<?php echo esc_attr( $form->id ); ?>">
		<input type="hidden" name="fields_json" id="acps-fields-json" value="<?php echo esc_attr( $fields_json ); ?>">

		<div class="acps-builder-topbar">
			<p>
				<label for="acps-form-title"><?php esc_html_e( 'Form title', 'acps-site-toolkit' ); ?></label><br>
				<input type="text" id="acps-form-title" name="title" class="regular-text" value="<?php echo esc_attr( $form->title ); ?>" required>
			</p>
			<p>
				<label for="acps-form-status"><?php esc_html_e( 'Status', 'acps-site-toolkit' ); ?></label><br>
				<select id="acps-form-status" name="status">
					<option value="draft" <?php selected( $form->status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'acps-site-toolkit' ); ?></option>
					<option value="published" <?php selected( $form->status, 'published' ); ?>><?php esc_html_e( 'Published', 'acps-site-toolkit' ); ?></option>
				</select>
			</p>
			<p class="acps-builder-actions">
				<button type="button" class="button" id="acps-preview-toggle" aria-pressed="false"><?php esc_html_e( 'Preview', 'acps-site-toolkit' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save form', 'acps-site-toolkit' ); ?></button>
			</p>
		</div>

		<div class="acps-builder-panes">
			<!-- Left: field type list. -->
			<section class="acps-pane acps-pane--types" aria-labelledby="acps-types-h">
				<h2 id="acps-types-h"><?php esc_html_e( 'Add a field', 'acps-site-toolkit' ); ?></h2>
				<ul class="acps-type-list">
					<?php foreach ( $types as $slug => $meta ) : ?>
						<li>
							<button type="button" class="button acps-add-field" data-type="<?php echo esc_attr( $slug ); ?>">
								+ <?php echo esc_html( $meta['label'] ); ?>
							</button>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<!-- Center: canvas. -->
			<section class="acps-pane acps-pane--canvas" aria-labelledby="acps-canvas-h">
				<h2 id="acps-canvas-h"><?php esc_html_e( 'Form fields', 'acps-site-toolkit' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Use the Up/Down buttons or the position box to reorder — no dragging required.', 'acps-site-toolkit' ); ?></p>
				<ol class="acps-canvas" id="acps-canvas" aria-live="polite"></ol>
				<div class="acps-preview" id="acps-preview" hidden aria-live="polite"></div>
			</section>

			<!-- Right: settings for selected field. -->
			<section class="acps-pane acps-pane--settings" aria-labelledby="acps-settings-h">
				<h2 id="acps-settings-h"><?php esc_html_e( 'Field settings', 'acps-site-toolkit' ); ?></h2>
				<div id="acps-field-settings">
					<p class="acps-settings-empty"><?php esc_html_e( 'Select a field to edit its settings.', 'acps-site-toolkit' ); ?></p>
				</div>
			</section>
		</div>

		<!-- Form-level settings. -->
		<div class="acps-builder-formsettings">
			<h2><?php esc_html_e( 'Form settings', 'acps-site-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="acps-submit-label"><?php esc_html_e( 'Submit button label', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="text" id="acps-submit-label" name="settings[submit_label]" value="<?php echo esc_attr( $form->settings['submit_label'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-confirm-type"><?php esc_html_e( 'On submit', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<select id="acps-confirm-type" name="settings[confirmation_type]">
							<option value="message" <?php selected( $form->settings['confirmation_type'], 'message' ); ?>><?php esc_html_e( 'Show a message', 'acps-site-toolkit' ); ?></option>
							<option value="redirect" <?php selected( $form->settings['confirmation_type'], 'redirect' ); ?>><?php esc_html_e( 'Redirect to a URL', 'acps-site-toolkit' ); ?></option>
							<option value="both" <?php selected( $form->settings['confirmation_type'], 'both' ); ?>><?php esc_html_e( 'Message, then redirect', 'acps-site-toolkit' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-confirm-msg"><?php esc_html_e( 'Confirmation message', 'acps-site-toolkit' ); ?></label></th>
					<td><textarea id="acps-confirm-msg" name="settings[confirmation_message]" rows="2" class="large-text"><?php echo esc_textarea( $form->settings['confirmation_message'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-confirm-redirect"><?php esc_html_e( 'Redirect URL', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="url" id="acps-confirm-redirect" name="settings[confirmation_redirect]" value="<?php echo esc_attr( $form->settings['confirmation_redirect'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Multi-page', 'acps-site-toolkit' ); ?></th>
					<td><label><input type="checkbox" name="settings[multipage]" value="1" <?php checked( $form->settings['multipage'] ); ?>> <?php esc_html_e( 'Split into pages using each field\'s page number, with a "Step X of Y" indicator', 'acps-site-toolkit' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Admin notification', 'acps-site-toolkit' ); ?></th>
					<td><label><input type="checkbox" name="settings[notify_admin]" value="1" <?php checked( $form->settings['notify_admin'] ); ?>> <?php esc_html_e( 'Email an admin on each submission', 'acps-site-toolkit' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-notify-to"><?php esc_html_e( 'Notification recipients', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="text" id="acps-notify-to" name="settings[notify_recipients]" value="<?php echo esc_attr( $form->settings['notify_recipients'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><p class="description"><?php esc_html_e( 'Comma-separated. Leave blank to use the site default.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-reply', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="settings[autoreply_enable]" value="1" <?php checked( $form->settings['autoreply_enable'] ); ?>> <?php esc_html_e( 'Send an auto-reply to the submitter', 'acps-site-toolkit' ); ?></label>
						<p><label for="acps-autoreply-field"><?php esc_html_e( 'Email field key', 'acps-site-toolkit' ); ?></label>
						<input type="text" id="acps-autoreply-field" name="settings[autoreply_field]" value="<?php echo esc_attr( $form->settings['autoreply_field'] ); ?>" class="regular-text" placeholder="contact_email"></p>
						<p><label for="acps-autoreply-subject"><?php esc_html_e( 'Subject', 'acps-site-toolkit' ); ?></label>
						<input type="text" id="acps-autoreply-subject" name="settings[autoreply_subject]" value="<?php echo esc_attr( $form->settings['autoreply_subject'] ); ?>" class="regular-text"></p>
						<p><label for="acps-autoreply-body"><?php esc_html_e( 'Body (merge tags: {form_title}, {field:key})', 'acps-site-toolkit' ); ?></label>
						<textarea id="acps-autoreply-body" name="settings[autoreply_body]" rows="3" class="large-text"><?php echo esc_textarea( $form->settings['autoreply_body'] ); ?></textarea></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-accent"><?php esc_html_e( 'Accent colour', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="text" id="acps-accent" name="settings[style_accent]" value="<?php echo esc_attr( $form->settings['style']['accent'] ); ?>" placeholder="#0b5fa5"><p class="description"><?php esc_html_e( 'Leave blank to inherit the theme.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save form', 'acps-site-toolkit' ); ?></button></p>
		</div>
	</form>
</div>
