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

// Always work from a complete, well-typed settings array so a form saved by an
// older version (or with unexpected data) can never fatal the editor on a
// missing key or a non-array value.
$settings = wp_parse_args( is_array( $form->settings ) ? $form->settings : array(), Form::default_settings() );
if ( ! is_array( $settings['style'] ) ) {
	$settings['style'] = array( 'accent' => '', 'width' => 'full' );
}
$settings['style'] = wp_parse_args( $settings['style'], array( 'accent' => '', 'width' => 'full' ) );
?>
<div class="wrap acps-admin acps-builder">
	<h1><?php echo $form->id ? esc_html__( 'Edit form', 'acps-site-toolkit' ) : esc_html__( 'New form', 'acps-site-toolkit' ); ?></h1>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible" role="status"><p><?php esc_html_e( 'Form saved.', 'acps-site-toolkit' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success is-dismissible" role="status"><p><?php esc_html_e( 'Imported from Google Forms. Review the fields below, then set Status to Published and Save.', 'acps-site-toolkit' ); ?></p></div>
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
				<?php
				$type_icons = array(
					'short_text'  => 'edit', 'long_text' => 'editor-paragraph', 'email' => 'email',
					'number'      => 'calculator', 'dropdown' => 'arrow-down-alt2', 'radio' => 'marker',
					'checkbox'    => 'yes', 'chips' => 'tag', 'date' => 'calendar-alt', 'time' => 'clock',
					'file'        => 'paperclip', 'scale' => 'chart-bar', 'rating' => 'star-filled',
					'page_picker' => 'admin-page', 'section' => 'minus', 'heading' => 'heading', 'hidden' => 'hidden',
				);
				?>
				<ul class="acps-type-list">
					<?php foreach ( $types as $slug => $meta ) : ?>
						<li>
							<button type="button" class="button acps-add-field" data-type="<?php echo esc_attr( $slug ); ?>">
								<span class="dashicons dashicons-<?php echo esc_attr( isset( $type_icons[ $slug ] ) ? $type_icons[ $slug ] : 'plus-alt2' ); ?>" aria-hidden="true"></span>
								<span><?php echo esc_html( $meta['label'] ); ?></span>
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
					<td><input type="text" id="acps-submit-label" name="settings[submit_label]" value="<?php echo esc_attr( $settings['submit_label'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-confirm-type"><?php esc_html_e( 'On submit', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<select id="acps-confirm-type" name="settings[confirmation_type]">
							<option value="message" <?php selected( $settings['confirmation_type'], 'message' ); ?>><?php esc_html_e( 'Show a message', 'acps-site-toolkit' ); ?></option>
							<option value="redirect" <?php selected( $settings['confirmation_type'], 'redirect' ); ?>><?php esc_html_e( 'Redirect to a URL', 'acps-site-toolkit' ); ?></option>
							<option value="both" <?php selected( $settings['confirmation_type'], 'both' ); ?>><?php esc_html_e( 'Message, then redirect', 'acps-site-toolkit' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-confirm-msg"><?php esc_html_e( 'Confirmation message', 'acps-site-toolkit' ); ?></label></th>
					<td><textarea id="acps-confirm-msg" name="settings[confirmation_message]" rows="2" class="large-text"><?php echo esc_textarea( $settings['confirmation_message'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-confirm-redirect"><?php esc_html_e( 'Redirect URL', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="url" id="acps-confirm-redirect" name="settings[confirmation_redirect]" value="<?php echo esc_attr( $settings['confirmation_redirect'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Multi-page', 'acps-site-toolkit' ); ?></th>
					<td><label><input type="checkbox" name="settings[multipage]" value="1" <?php checked( $settings['multipage'] ); ?>> <?php esc_html_e( 'Split into pages using each field\'s page number, with a "Step X of Y" indicator', 'acps-site-toolkit' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Response limits', 'acps-site-toolkit' ); ?></th>
					<td>
						<p>
							<label for="acps-limit-device"><?php esc_html_e( 'Max responses per device', 'acps-site-toolkit' ); ?></label>
							<input type="number" id="acps-limit-device" name="settings[limit_per_device]" value="<?php echo esc_attr( $settings['limit_per_device'] ); ?>" min="0" class="small-text">
						</p>
						<p>
							<label for="acps-limit-total"><?php esc_html_e( 'Max total responses', 'acps-site-toolkit' ); ?></label>
							<input type="number" id="acps-limit-total" name="settings[limit_total]" value="<?php echo esc_attr( $settings['limit_total'] ); ?>" min="0" class="small-text">
						</p>
						<p class="description"><?php esc_html_e( '0 = unlimited. “Per device” recognises a visitor by their anonymised IP + browser (the same signal the spam protection uses), so it’s a strong deterrent rather than a hard identity check.', 'acps-site-toolkit' ); ?></p>
						<p>
							<label for="acps-limit-msg"><?php esc_html_e( 'Message when the limit is reached', 'acps-site-toolkit' ); ?></label><br>
							<input type="text" id="acps-limit-msg" name="settings[limit_message]" value="<?php echo esc_attr( $settings['limit_message'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'This form is no longer accepting responses.', 'acps-site-toolkit' ); ?>">
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Admin notification', 'acps-site-toolkit' ); ?></th>
					<td><label><input type="checkbox" name="settings[notify_admin]" value="1" <?php checked( $settings['notify_admin'] ); ?>> <?php esc_html_e( 'Email an admin on each submission', 'acps-site-toolkit' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-notify-to"><?php esc_html_e( 'Notification recipients', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="text" id="acps-notify-to" name="settings[notify_recipients]" value="<?php echo esc_attr( $settings['notify_recipients'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><p class="description"><?php esc_html_e( 'Comma-separated. Leave blank to use the site default.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-reply', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="settings[autoreply_enable]" value="1" <?php checked( $settings['autoreply_enable'] ); ?>> <?php esc_html_e( 'Send an auto-reply to the submitter', 'acps-site-toolkit' ); ?></label>
						<p><label for="acps-autoreply-field"><?php esc_html_e( 'Email field key', 'acps-site-toolkit' ); ?></label>
						<input type="text" id="acps-autoreply-field" name="settings[autoreply_field]" value="<?php echo esc_attr( $settings['autoreply_field'] ); ?>" class="regular-text" placeholder="contact_email"></p>
						<p><label for="acps-autoreply-subject"><?php esc_html_e( 'Subject', 'acps-site-toolkit' ); ?></label>
						<input type="text" id="acps-autoreply-subject" name="settings[autoreply_subject]" value="<?php echo esc_attr( $settings['autoreply_subject'] ); ?>" class="regular-text"></p>
						<p><label for="acps-autoreply-body"><?php esc_html_e( 'Body (merge tags: {form_title}, {field:key})', 'acps-site-toolkit' ); ?></label>
						<textarea id="acps-autoreply-body" name="settings[autoreply_body]" rows="3" class="large-text"><?php echo esc_textarea( $settings['autoreply_body'] ); ?></textarea></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-accent"><?php esc_html_e( 'Accent colour', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="text" id="acps-accent" name="settings[style_accent]" value="<?php echo esc_attr( $settings['style']['accent'] ); ?>" placeholder="#0b5fa5"><p class="description"><?php esc_html_e( 'Leave blank to inherit the theme.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Access & sharing', 'acps-site-toolkit' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Restrict who can open this form. You can combine methods — the form shows only when every enabled check passes.', 'acps-site-toolkit' ); ?></p>
			<?php $access = \ACPS\SiteToolkit\Access::config( $form ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Require login', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="settings[access][require_login]" value="1" <?php checked( ! empty( $access['require_login'] ) ); ?>> <?php esc_html_e( 'Only logged-in users can access this form', 'acps-site-toolkit' ); ?></label>
						<fieldset style="margin-top:.5rem">
							<legend class="screen-reader-text"><?php esc_html_e( 'Allowed roles', 'acps-site-toolkit' ); ?></legend>
							<p class="description"><?php esc_html_e( 'Limit to these roles (leave all unchecked to allow any logged-in user):', 'acps-site-toolkit' ); ?></p>
							<?php foreach ( get_editable_roles() as $role_key => $role ) : ?>
								<label style="display:inline-block;margin-right:1rem">
									<input type="checkbox" name="settings[access][roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $access['roles'], true ) ); ?>>
									<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Password', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="settings[access][require_password]" value="1" <?php checked( ! empty( $access['require_password'] ) ); ?>> <?php esc_html_e( 'Require a password', 'acps-site-toolkit' ); ?></label>
						<p>
							<label for="acps-access-pw"><?php echo $access['password_hash'] ? esc_html__( 'Set a new password (leave blank to keep the current one)', 'acps-site-toolkit' ) : esc_html__( 'Password', 'acps-site-toolkit' ); ?></label><br>
							<input type="text" id="acps-access-pw" name="settings[access][password]" value="" autocomplete="off" class="regular-text">
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Secret link', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="settings[access][require_token]" value="1" <?php checked( ! empty( $access['require_token'] ) ); ?>> <?php esc_html_e( 'Share via a private link', 'acps-site-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'Anyone who opens the link sees this form pop up automatically — no need to place it on a page. By default the link opens on your home page.', 'acps-site-toolkit' ); ?></p>

						<p style="margin-top:.6rem">
							<label for="acps-access-page"><?php esc_html_e( 'Open the popup on (optional)', 'acps-site-toolkit' ); ?></label><br>
							<?php
							wp_dropdown_pages(
								array(
									'id'                => 'acps-access-page',
									'name'              => 'settings[access][page_id]',
									'selected'          => (int) $access['page_id'],
									'show_option_none'  => __( 'Home page (default)', 'acps-site-toolkit' ),
									'option_none_value' => 0,
								)
							);
							?>
							<span class="description"><?php esc_html_e( 'The page the link lands on before the popup opens. Leave as Home page unless you want a specific one.', 'acps-site-toolkit' ); ?></span>
						</p>

						<?php if ( ! empty( $access['require_token'] ) && ! empty( $access['token'] ) ) : ?>
							<?php $secret_link = \ACPS\SiteToolkit\Access::secret_link( $form ); ?>
							<p class="description"><strong><?php esc_html_e( 'Share this link:', 'acps-site-toolkit' ); ?></strong></p>
							<input type="text" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_attr( $secret_link ); ?>">
							<p><label><input type="checkbox" name="settings[access][regenerate_token]" value="1"> <?php esc_html_e( 'Regenerate the link on save (invalidates the old one)', 'acps-site-toolkit' ); ?></label></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'A private link is generated when you save with this enabled.', 'acps-site-toolkit' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-denied-msg"><?php esc_html_e( 'Denied message', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="text" id="acps-denied-msg" name="settings[access][denied_message]" value="<?php echo esc_attr( $access['denied_message'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'You do not have access to this form.', 'acps-site-toolkit' ); ?>"></td>
				</tr>
			</table>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save form', 'acps-site-toolkit' ); ?></button></p>
		</div>
	</form>
</div>
