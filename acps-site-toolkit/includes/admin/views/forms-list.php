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

$forms    = Form::all();
$gf_active = class_exists( '\\ACPS\\SiteToolkit\\Gravity_Forms' ) && \ACPS\SiteToolkit\Gravity_Forms::is_active();
$gf_forms  = $gf_active ? \ACPS\SiteToolkit\Gravity_Forms::forms() : array();
?>
<div class="wrap acps-admin">
	<h1 class="wp-heading-inline"><?php echo $gf_active ? esc_html__( 'All forms', 'acps-site-toolkit' ) : esc_html__( 'Forms', 'acps-site-toolkit' ); ?></h1>
	<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-forms&action=new' ) ); ?>"><?php esc_html_e( 'Add New', 'acps-site-toolkit' ); ?></a>
	<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-forms&action=import' ) ); ?>"><?php esc_html_e( 'Import Google Form', 'acps-site-toolkit' ); ?></a>
	<?php if ( $gf_active ) : ?>
		<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=gf_new_form' ) ); ?>"><?php esc_html_e( 'New Gravity Form', 'acps-site-toolkit' ); ?></a>
	<?php endif; ?>
	<a class="page-title-action" href="<?php echo esc_url( add_query_arg( 'acps_tour', 'build-form', admin_url( 'admin.php?page=acps-st-forms&action=new' ) ) ); ?>"><span class="dashicons dashicons-welcome-learn-more" aria-hidden="true" style="vertical-align:text-bottom"></span> <?php esc_html_e( 'Show me how', 'acps-site-toolkit' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( $gf_active ) : ?>
		<p class="description"><?php esc_html_e( 'Gravity Forms is active, so this list shows both Gravity Forms’ forms and this plugin’s forms together. Gravity Forms rows open in Gravity Forms; the rest open in the built-in builder.', 'acps-site-toolkit' ); ?></p>
	<?php endif; ?>

	<table class="widefat striped acps-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'All forms', 'acps-site-toolkit' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Title', 'acps-site-toolkit' ); ?></th>
				<?php if ( $gf_active ) : ?><th scope="col"><?php esc_html_e( 'Source', 'acps-site-toolkit' ); ?></th><?php endif; ?>
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
					<?php if ( $gf_active ) : ?><td><?php esc_html_e( 'Built-in', 'acps-site-toolkit' ); ?></td><?php endif; ?>
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
							<?php
							$confirm = $form->is_feedback
								? __( 'Delete the Site Feedback form and all its entries? The floating feedback button will stop working until you re-create it.', 'acps-site-toolkit' )
								: __( 'Delete this form and all its entries?', 'acps-site-toolkit' );
							?>
							<button type="submit" name="do" value="delete" class="button-link acps-danger" onclick="return confirm('<?php echo esc_js( $confirm ); ?>');"><?php esc_html_e( 'Delete', 'acps-site-toolkit' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php foreach ( $gf_forms as $g ) : ?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( $g['edit_url'] ); ?>"><?php echo esc_html( $g['title'] ? $g['title'] : __( '(untitled form)', 'acps-site-toolkit' ) ); ?></a></strong>
						<span class="acps-badge" style="background:#5b3fb0"><?php esc_html_e( 'Gravity', 'acps-site-toolkit' ); ?></span>
					</td>
					<td><?php esc_html_e( 'Gravity Forms', 'acps-site-toolkit' ); ?></td>
					<td>&mdash;</td>
					<td><?php echo esc_html( number_format_i18n( $g['total'] ) ); ?> <?php esc_html_e( 'entries', 'acps-site-toolkit' ); ?></td>
					<td><code>[gravityform id="<?php echo esc_html( $g['id'] ); ?>"]</code></td>
					<td>
						<a href="<?php echo esc_url( $g['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'acps-site-toolkit' ); ?></a> |
						<a href="<?php echo esc_url( $g['entries_url'] ); ?>"><?php esc_html_e( 'Entries', 'acps-site-toolkit' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
