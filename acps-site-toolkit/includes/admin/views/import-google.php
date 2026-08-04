<?php
/**
 * Import a Google Form into a new draft form.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$error = isset( $_GET['import_error'] ) ? sanitize_text_field( wp_unslash( $_GET['import_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
?>
<div class="wrap acps-admin">
	<h1><?php esc_html_e( 'Import a Google Form', 'acps-site-toolkit' ); ?></h1>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-forms' ) ); ?>">&larr; <?php esc_html_e( 'Back to forms', 'acps-site-toolkit' ); ?></a></p>

	<?php if ( $error ) : ?>
		<div class="notice notice-error" role="alert"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<div class="acps-card" style="max-width:48rem">
		<p><?php esc_html_e( 'Paste the link to a published Google Form (the address people fill out, ending in /viewform). We’ll read its questions and create a matching draft form here for you to review and publish.', 'acps-site-toolkit' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'acps_st_import_google' ); ?>
			<input type="hidden" name="action" value="acps_st_import_google">

			<p>
				<label for="acps-gform-url"><strong><?php esc_html_e( 'Google Form URL', 'acps-site-toolkit' ); ?></strong></label><br>
				<input type="url" id="acps-gform-url" name="gform_url" class="large-text code" placeholder="https://docs.google.com/forms/d/e/…/viewform">
			</p>

			<details style="margin:1rem 0">
				<summary><?php esc_html_e( 'The form is private, or importing by URL failed? Paste the page source instead', 'acps-site-toolkit' ); ?></summary>
				<p class="description"><?php esc_html_e( 'Open the form in your browser, view the page source (Ctrl/Cmd+U), select all, copy, and paste it below.', 'acps-site-toolkit' ); ?></p>
				<textarea name="gform_html" rows="6" class="large-text code" placeholder="<!doctype html>…"></textarea>
			</details>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import form', 'acps-site-toolkit' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'What gets imported', 'acps-site-toolkit' ); ?></h2>
		<ul class="ul-disc" style="list-style:disc;margin-left:1.5rem">
			<li><?php esc_html_e( 'Short answer, paragraph, multiple choice, checkboxes, dropdown, linear scale, date, time, and file upload questions.', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Question text, help text, options, required flags, and section page-breaks (becomes a multi-page form).', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Grids, images and videos can’t be recreated automatically — a note is left where they were so you can rebuild them.', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'The result is saved as a draft so you can check it before publishing.', 'acps-site-toolkit' ); ?></li>
		</ul>
	</div>
</div>
