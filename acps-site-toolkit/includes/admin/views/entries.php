<?php
/**
 * Entries view (spec §7.6): per-form submissions, filterable/searchable, with a
 * single-entry detail showing all values + metadata + journey.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Form;
use ACPS\SiteToolkit\Entries;
use ACPS\SiteToolkit\Field_Types;
use ACPS\SiteToolkit\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$forms   = Form::all();
$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : ( $forms ? $forms[0]->id : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
$view_id = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0; // phpcs:ignore

if ( $view_id ) {
	$data = Entries::get( $view_id );
	if ( ! $data ) {
		echo '<div class="wrap"><p>' . esc_html__( 'Entry not found.', 'acps-site-toolkit' ) . '</p></div>';
		return;
	}
	$entry  = $data['entry'];
	$values = $data['values'];
	$path   = $entry->session_id ? Analytics::session_path( (int) $entry->session_id ) : array();
	?>
	<div class="wrap acps-admin">
		<h1><?php esc_html_e( 'Entry', 'acps-site-toolkit' ); ?> #<?php echo esc_html( $view_id ); ?></h1>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-entries&form_id=' . $entry->form_id ) ); ?>">&larr; <?php esc_html_e( 'Back to entries', 'acps-site-toolkit' ); ?></a></p>
		<table class="widefat striped">
			<tbody>
			<?php foreach ( $values as $key => $val ) : ?>
				<tr><th scope="row"><?php echo esc_html( $key ); ?></th><td><?php echo is_array( $val ) ? esc_html( implode( ', ', $val ) ) : wp_kses_post( nl2br( esc_html( $val ) ) ); ?></td></tr>
			<?php endforeach; ?>
				<tr><th scope="row"><?php esc_html_e( 'Submitted', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( $entry->submitted_at ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Page', 'acps-site-toolkit' ); ?></th><td><?php echo $entry->page_id ? esc_html( get_the_title( (int) $entry->page_id ) ) : esc_html( $entry->page_url ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Status', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( $entry->status ); ?></td></tr>
			</tbody>
		</table>
		<?php if ( $path ) : ?>
			<h2><?php esc_html_e( 'Visitor journey', 'acps-site-toolkit' ); ?></h2>
			<ol class="acps-path"><?php foreach ( $path as $step ) : ?><li><?php echo esc_html( $step ); ?></li><?php endforeach; ?></ol>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem">
			<?php wp_nonce_field( 'acps_st_entry_action' ); ?>
			<input type="hidden" name="action" value="acps_st_entry_action">
			<input type="hidden" name="entry_id" value="<?php echo esc_attr( $view_id ); ?>">
			<input type="hidden" name="return" value="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-entries&form_id=' . $entry->form_id ) ); ?>">
			<button type="submit" name="do" value="delete" class="button acps-danger" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this entry?', 'acps-site-toolkit' ) ); ?>');"><?php esc_html_e( 'Delete permanently', 'acps-site-toolkit' ); ?></button>
		</form>
	</div>
	<?php
	return;
}

$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore
$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore
$result = Entries::query( array( 'form_id' => $form_id, 'search' => $search, 'paged' => $paged, 'per_page' => 25 ) );
$rows   = $result['rows'];
$total  = $result['total'];
$form   = $form_id ? Form::find( $form_id ) : null;
$fields = $form ? Field_Types::normalize_list( $form->fields ) : array();
?>
<div class="wrap acps-admin">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Entries', 'acps-site-toolkit' ); ?></h1>
	<?php if ( $form_id ) : ?>
		<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acps_st_export&form_id=' . $form_id ), 'acps_st_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'acps-site-toolkit' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<form method="get" class="acps-filters">
		<input type="hidden" name="page" value="acps-st-entries">
		<label for="acps-form-select" class="screen-reader-text"><?php esc_html_e( 'Choose form', 'acps-site-toolkit' ); ?></label>
		<select id="acps-form-select" name="form_id" onchange="this.form.submit()">
			<?php foreach ( $forms as $f ) : ?>
				<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $form_id, $f->id ); ?>><?php echo esc_html( $f->title ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="acps-search" class="screen-reader-text"><?php esc_html_e( 'Search entries', 'acps-site-toolkit' ); ?></label>
		<input type="search" id="acps-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'acps-site-toolkit' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Search', 'acps-site-toolkit' ); ?></button>
	</form>

	<table class="widefat striped acps-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'Form entries', 'acps-site-toolkit' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'ID', 'acps-site-toolkit' ); ?></th>
				<?php
				$shown = 0;
				foreach ( $fields as $f ) {
					if ( Field_Types::is_input( $f['type'] ) && 'hidden' !== $f['type'] && $shown < 3 ) {
						echo '<th scope="col">' . esc_html( $f['label'] ? $f['label'] : $f['key'] ) . '</th>';
						$shown++;
					}
				}
				?>
				<th scope="col"><?php esc_html_e( 'Status', 'acps-site-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Submitted', 'acps-site-toolkit' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No entries yet.', 'acps-site-toolkit' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) :
					$d    = Entries::get( (int) $row->id );
					$vals = $d ? $d['values'] : array();
					$link = admin_url( 'admin.php?page=acps-st-entries&entry=' . $row->id );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $link ); ?>">#<?php echo esc_html( $row->id ); ?></a></td>
						<?php
						$shown = 0;
						foreach ( $fields as $f ) {
							if ( Field_Types::is_input( $f['type'] ) && 'hidden' !== $f['type'] && $shown < 3 ) {
								$v = isset( $vals[ $f['key'] ] ) ? $vals[ $f['key'] ] : '';
								echo '<td>' . esc_html( wp_trim_words( is_array( $v ) ? implode( ', ', $v ) : (string) $v, 10 ) ) . '</td>';
								$shown++;
							}
						}
						?>
						<td><?php echo esc_html( $row->status ); ?></td>
						<td><?php echo esc_html( $row->submitted_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php
	$total_pages = (int) ceil( $total / 25 );
	if ( $total_pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $total_pages ) ) );
		echo '</div></div>';
	}
	?>
</div>
