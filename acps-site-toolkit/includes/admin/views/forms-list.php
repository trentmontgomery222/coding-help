<?php
/**
 * Forms list view (spec §7). Lists all forms with edit/duplicate/delete and a
 * copy-ready shortcode.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Form;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$forms = Form::all();
?>
<div class="wrap acps-admin">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Forms', 'acps-site-toolkit' ); ?></h1>
	<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-forms&action=new' ) ); ?>"><?php esc_html_e( 'Add New', 'acps-site-toolkit' ); ?></a>
	<hr class="wp-header-end">

	<table class="widefat striped acps-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'All forms', 'acps-site-toolkit' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Title', 'acps-site-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'acps-site-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Fields', 'acps-site-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Shortcode', 'acps-site-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'acps-site-toolkit' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $forms as $form ) :
				$edit = admin_url( 'admin.php?page=acps-st-forms&action=edit&form=' . $form->id );
				?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $form->title ); ?></a></strong>
						<?php if ( $form->is_feedback ) : ?>
							<span class="acps-badge"><?php esc_html_e( 'Feedback', 'acps-site-toolkit' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $form->status ); ?></td>
					<td><?php echo esc_html( count( $form->fields ) ); ?></td>
					<td><code>[acps_form id="<?php echo esc_html( $form->id ); ?>"]</code></td>
					<td>
						<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'acps-site-toolkit' ); ?></a> |
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-entries&form_id=' . $form->id ) ); ?>"><?php esc_html_e( 'Entries', 'acps-site-toolkit' ); ?></a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="acps-inline-form">
							<?php wp_nonce_field( 'acps_st_form_action' ); ?>
							<input type="hidden" name="action" value="acps_st_form_action">
							<input type="hidden" name="form_id" value="<?php echo esc_attr( $form->id ); ?>">
							<button type="submit" name="do" value="duplicate" class="button-link"><?php esc_html_e( 'Duplicate', 'acps-site-toolkit' ); ?></button>
							<?php if ( ! $form->is_feedback ) : ?>
								<button type="submit" name="do" value="delete" class="button-link acps-danger" onclick="return confirm('<?php echo esc_js( __( 'Delete this form and all its entries?', 'acps-site-toolkit' ) ); ?>');"><?php esc_html_e( 'Delete', 'acps-site-toolkit' ); ?></button>
							<?php endif; ?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
