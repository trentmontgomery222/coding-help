<?php
/**
 * Q&A / Help admin: manage the pre-set question → answer pairs shown by the
 * [acps_qa] widget.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Help;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$items = Help::qa_items();
$saved = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification

// Always render at least one empty row to type into.
if ( ! $items ) {
	$items = array( array( 'q' => '', 'a' => '' ) );
}
?>
<div class="wrap acps-admin">
	<h1><?php esc_html_e( 'Q&A / Help', 'acps-site-toolkit' ); ?></h1>
	<p class="description"><?php esc_html_e( 'These question-and-answer pairs power the self-service help widget. Place it on a page with the shortcode below, or inside a Beaver Builder / block layout.', 'acps-site-toolkit' ); ?></p>
	<p><code>[acps_qa]</code> &nbsp; <?php esc_html_e( 'or, without the "ask a question" contact form:', 'acps-site-toolkit' ); ?> &nbsp; <code>[acps_qa show_contact="0"]</code></p>
	<p><?php esc_html_e( 'The "Contact us" message form on its own:', 'acps-site-toolkit' ); ?> &nbsp; <code>[acps_contact]</code></p>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible" role="status"><p><?php esc_html_e( 'Saved.', 'acps-site-toolkit' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="acps-qa-form">
		<?php wp_nonce_field( 'acps_st_save_qa' ); ?>
		<input type="hidden" name="action" value="acps_st_save_qa">

		<div id="acps-qa-rows">
			<?php foreach ( $items as $i => $item ) : ?>
				<fieldset class="acps-qa-row">
					<legend class="screen-reader-text"><?php printf( esc_html__( 'Question %d', 'acps-site-toolkit' ), (int) $i + 1 ); ?></legend>
					<p>
						<label for="acps-q-<?php echo esc_attr( $i ); ?>"><strong><?php esc_html_e( 'Question', 'acps-site-toolkit' ); ?></strong></label><br>
						<input type="text" id="acps-q-<?php echo esc_attr( $i ); ?>" name="q[]" value="<?php echo esc_attr( $item['q'] ); ?>" class="large-text">
					</p>
					<p>
						<label for="acps-a-<?php echo esc_attr( $i ); ?>"><strong><?php esc_html_e( 'Answer', 'acps-site-toolkit' ); ?></strong></label><br>
						<textarea id="acps-a-<?php echo esc_attr( $i ); ?>" name="a[]" rows="3" class="large-text"><?php echo esc_textarea( $item['a'] ); ?></textarea>
					</p>
					<p><button type="button" class="button-link acps-danger acps-qa-remove"><?php esc_html_e( 'Remove this question', 'acps-site-toolkit' ); ?></button></p>
				</fieldset>
			<?php endforeach; ?>
		</div>

		<p>
			<button type="button" class="button" id="acps-qa-add"><?php esc_html_e( '+ Add question', 'acps-site-toolkit' ); ?></button>
		</p>

		<?php submit_button( __( 'Save Q&A', 'acps-site-toolkit' ) ); ?>
	</form>

	<template id="acps-qa-template">
		<fieldset class="acps-qa-row">
			<legend class="screen-reader-text"><?php esc_html_e( 'New question', 'acps-site-toolkit' ); ?></legend>
			<p><label><strong><?php esc_html_e( 'Question', 'acps-site-toolkit' ); ?></strong></label><br>
			<input type="text" name="q[]" value="" class="large-text"></p>
			<p><label><strong><?php esc_html_e( 'Answer', 'acps-site-toolkit' ); ?></strong></label><br>
			<textarea name="a[]" rows="3" class="large-text"></textarea></p>
			<p><button type="button" class="button-link acps-danger acps-qa-remove"><?php esc_html_e( 'Remove this question', 'acps-site-toolkit' ); ?></button></p>
		</fieldset>
	</template>

	<script>
	( function () {
		var add = document.getElementById( 'acps-qa-add' );
		var rows = document.getElementById( 'acps-qa-rows' );
		var tpl = document.getElementById( 'acps-qa-template' );
		if ( add && rows && tpl ) {
			add.addEventListener( 'click', function () {
				var node = tpl.content.cloneNode( true );
				rows.appendChild( node );
				var inputs = rows.querySelectorAll( '.acps-qa-row:last-child input' );
				if ( inputs.length ) { inputs[0].focus(); }
			} );
		}
		if ( rows ) {
			rows.addEventListener( 'click', function ( e ) {
				if ( e.target && e.target.classList.contains( 'acps-qa-remove' ) ) {
					var fs = e.target.closest( '.acps-qa-row' );
					if ( fs ) { fs.parentNode.removeChild( fs ); }
				}
			} );
		}
	} )();
	</script>
</div>
