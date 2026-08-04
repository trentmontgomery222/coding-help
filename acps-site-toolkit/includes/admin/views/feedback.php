<?php
/**
 * Feedback inbox view (spec §5.6). Accessible data table with status workflow,
 * assignment, notes, filters, and a link to the visitor's journey path.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Form;
use ACPS\SiteToolkit\Entries;
use ACPS\SiteToolkit\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$feedback_form = Form::feedback_form();
// The triage inbox defaults to the feedback form but can show any form's
// entries (with the same assign / status / notes workflow).
$form_id     = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : ( $feedback_form ? $feedback_form->id : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
$is_feedback = $feedback_form && $form_id === (int) $feedback_form->id;
$all_forms   = Form::all();

// Single-item view?
$view_id = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$can_edit = current_user_can( 'manage_options' );

if ( $view_id ) {
	$data = Entries::get( $view_id );
	if ( ! $data ) {
		echo '<div class="wrap"><p>' . esc_html__( 'Feedback item not found.', 'acps-site-toolkit' ) . '</p></div>';
		return;
	}
	$entry  = $data['entry'];
	$values = $data['values'];
	$notes  = Entries::notes( $view_id );
	$path   = $entry->session_id ? Analytics::session_path( (int) $entry->session_id ) : array();
	$list_url = admin_url( 'admin.php?page=acps-st&form_id=' . (int) $entry->form_id );
	$return = add_query_arg( 'entry', $view_id, $list_url );
	$statuses = array(
		'new'         => __( 'New', 'acps-site-toolkit' ),
		'in_progress' => __( 'In progress', 'acps-site-toolkit' ),
		'resolved'    => __( 'Resolved', 'acps-site-toolkit' ),
		'wont_fix'    => __( "Won't fix", 'acps-site-toolkit' ),
		'spam'        => __( 'Spam', 'acps-site-toolkit' ),
	);
	?>
	<div class="wrap acps-admin">
		<h1><?php esc_html_e( 'Feedback item', 'acps-site-toolkit' ); ?> #<?php echo esc_html( $view_id ); ?></h1>
		<p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to list', 'acps-site-toolkit' ); ?></a></p>

		<div class="acps-detail-grid">
			<div class="acps-detail-main">
				<h2><?php esc_html_e( 'Submission', 'acps-site-toolkit' ); ?></h2>
				<table class="widefat striped">
					<tbody>
					<?php foreach ( $values as $key => $val ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $key ); ?></th>
							<td><?php echo is_array( $val ) ? esc_html( implode( ', ', $val ) ) : wp_kses_post( nl2br( esc_html( $val ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
						<tr><th scope="row"><?php esc_html_e( 'Submitted', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( $entry->submitted_at ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Page', 'acps-site-toolkit' ); ?></th><td><?php echo $entry->page_id ? esc_html( get_the_title( (int) $entry->page_id ) ) : esc_html( $entry->page_url ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Browser', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( $entry->user_agent_summary ); ?></td></tr>
					</tbody>
				</table>

				<?php if ( $path ) : ?>
					<h2><?php esc_html_e( 'Visitor journey before submitting', 'acps-site-toolkit' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The complaint may name the last page, but the problem often started earlier.', 'acps-site-toolkit' ); ?></p>
					<ol class="acps-path">
						<?php foreach ( $path as $step ) : ?>
							<li><?php echo esc_html( $step ); ?></li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</div>

			<div class="acps-detail-side">
				<?php if ( $can_edit ) : ?>
				<h2><?php esc_html_e( 'Triage', 'acps-site-toolkit' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'acps_st_entry_action' ); ?>
					<input type="hidden" name="action" value="acps_st_entry_action">
					<input type="hidden" name="do" value="status">
					<input type="hidden" name="entry_id" value="<?php echo esc_attr( $view_id ); ?>">
					<input type="hidden" name="return" value="<?php echo esc_attr( $return ); ?>">
					<p>
						<label for="acps-status"><?php esc_html_e( 'Status', 'acps-site-toolkit' ); ?></label><br>
						<select id="acps-status" name="status">
							<?php foreach ( $statuses as $val => $lbl ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $entry->status, $val ); ?>><?php echo esc_html( $lbl ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Update status', 'acps-site-toolkit' ); ?></button></p>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'acps_st_entry_action' ); ?>
					<input type="hidden" name="action" value="acps_st_entry_action">
					<input type="hidden" name="do" value="assign">
					<input type="hidden" name="entry_id" value="<?php echo esc_attr( $view_id ); ?>">
					<input type="hidden" name="return" value="<?php echo esc_attr( $return ); ?>">
					<p>
						<label for="acps-assign"><?php esc_html_e( 'Assign to', 'acps-site-toolkit' ); ?></label><br>
						<?php
						wp_dropdown_users(
							array(
								'id'               => 'acps-assign',
								'name'             => 'assigned_to',
								'selected'         => (int) $entry->assigned_to,
								'show_option_none' => __( 'Unassigned', 'acps-site-toolkit' ),
								'option_none_value' => 0,
							)
						);
						?>
					</p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Assign', 'acps-site-toolkit' ); ?></button></p>
				</form>

				<h2><?php esc_html_e( 'Internal notes', 'acps-site-toolkit' ); ?></h2>
				<ul class="acps-notes">
					<?php foreach ( $notes as $note ) : ?>
						<li>
							<span class="acps-note-meta"><?php echo esc_html( get_the_author_meta( 'display_name', $note->author_id ) ); ?> — <?php echo esc_html( $note->created_at ); ?></span>
							<div class="acps-note-body"><?php echo wp_kses_post( wpautop( $note->note ) ); ?></div>
						</li>
					<?php endforeach; ?>
				</ul>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'acps_st_entry_action' ); ?>
					<input type="hidden" name="action" value="acps_st_entry_action">
					<input type="hidden" name="do" value="note">
					<input type="hidden" name="entry_id" value="<?php echo esc_attr( $view_id ); ?>">
					<input type="hidden" name="return" value="<?php echo esc_attr( $return ); ?>">
					<p>
						<label for="acps-note"><?php esc_html_e( 'Add a note', 'acps-site-toolkit' ); ?></label><br>
						<textarea id="acps-note" name="note" rows="3" class="large-text"></textarea>
					</p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Add note', 'acps-site-toolkit' ); ?></button></p>
				</form>

				<h2><?php esc_html_e( 'Danger zone', 'acps-site-toolkit' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'acps_st_entry_action' ); ?>
					<input type="hidden" name="action" value="acps_st_entry_action">
					<input type="hidden" name="entry_id" value="<?php echo esc_attr( $view_id ); ?>">
					<input type="hidden" name="return" value="<?php echo esc_url( $list_url ); ?>">
					<p>
						<button type="submit" name="do" value="delete" class="button acps-danger" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this entry? This cannot be undone.', 'acps-site-toolkit' ) ); ?>');"><?php esc_html_e( 'Delete permanently', 'acps-site-toolkit' ); ?></button>
					</p>
				</form>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	return;
}

// --- List view. ----------------------------------------------------------
$filter_status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$paged         = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore
$result        = Entries::query(
	array(
		'form_id'  => $form_id,
		'status'   => $filter_status,
		'paged'    => $paged,
		'per_page' => 25,
	)
);
$rows  = $result['rows'];
$total = $result['total'];
?>
<?php
$selected_form  = $form_id ? Form::find( $form_id ) : null;
$selected_fields = ( $selected_form && ! $is_feedback ) ? \ACPS\SiteToolkit\Field_Types::normalize_list( $selected_form->fields ) : array();
?>
<div class="wrap acps-admin">
	<h1 class="wp-heading-inline"><?php echo $is_feedback ? esc_html__( 'Feedback', 'acps-site-toolkit' ) : esc_html__( 'Triage', 'acps-site-toolkit' ); ?></h1>
	<?php if ( current_user_can( 'manage_options' ) && $form_id ) : ?>
		<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acps_st_export&form_id=' . $form_id ), 'acps_st_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'acps-site-toolkit' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<form method="get" class="acps-filters">
		<input type="hidden" name="page" value="acps-st">
		<label for="acps-inbox-form"><?php esc_html_e( 'Form', 'acps-site-toolkit' ); ?></label>
		<select id="acps-inbox-form" name="form_id" onchange="this.form.submit()">
			<?php foreach ( $all_forms as $f ) : ?>
				<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $form_id, $f->id ); ?>>
					<?php echo esc_html( $f->title ); ?><?php echo $f->is_feedback ? ' ' . esc_html__( '(feedback)', 'acps-site-toolkit' ) : ''; ?>
				</option>
			<?php endforeach; ?>
		</select>
		<label for="acps-filter-status" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'acps-site-toolkit' ); ?></label>
		<select id="acps-filter-status" name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'acps-site-toolkit' ); ?></option>
			<?php
			$labels = array(
				'new'         => __( 'New', 'acps-site-toolkit' ),
				'in_progress' => __( 'In progress', 'acps-site-toolkit' ),
				'resolved'    => __( 'Resolved', 'acps-site-toolkit' ),
				'wont_fix'    => __( "Won't fix", 'acps-site-toolkit' ),
				'spam'        => __( 'Spam', 'acps-site-toolkit' ),
			);
			foreach ( $labels as $val => $lbl ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $filter_status, $val, false ), esc_html( $lbl ) );
			}
			?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'acps-site-toolkit' ); ?></button>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'acps_st_entry_action' ); ?>
	<input type="hidden" name="action" value="acps_st_entry_action">
	<input type="hidden" name="return" value="<?php echo esc_attr( admin_url( 'admin.php?page=acps-st&form_id=' . $form_id . ( $filter_status ? '&status=' . $filter_status : '' ) ) ); ?>">

	<?php
	// Columns preview: feedback form shows Type + Comment; any other form shows
	// its first few field values.
	$preview_fields = array();
	if ( ! $is_feedback ) {
		$shown = 0;
		foreach ( $selected_fields as $pf ) {
			if ( \ACPS\SiteToolkit\Field_Types::is_input( $pf['type'] ) && 'hidden' !== $pf['type'] && $shown < 3 ) {
				$preview_fields[] = $pf;
				$shown++;
			}
		}
	}
	$col_count = ( current_user_can( 'manage_options' ) ? 1 : 0 ) + 3 + ( $is_feedback ? 2 : count( $preview_fields ) );
	?>

	<?php if ( current_user_can( 'manage_options' ) && $rows ) : ?>
	<div class="tablenav top">
		<label for="acps-bulk" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'acps-site-toolkit' ); ?></label>
		<select id="acps-bulk" name="do">
			<option value=""><?php esc_html_e( 'Bulk actions', 'acps-site-toolkit' ); ?></option>
			<option value="bulk_trash"><?php esc_html_e( 'Move to Trash', 'acps-site-toolkit' ); ?></option>
			<option value="bulk_delete"><?php esc_html_e( 'Delete permanently', 'acps-site-toolkit' ); ?></option>
		</select>
		<button type="submit" class="button" onclick="return acpsConfirmBulk(this.form);"><?php esc_html_e( 'Apply', 'acps-site-toolkit' ); ?></button>
	</div>
	<script>
	function acpsConfirmBulk( form ) {
		if ( form.do && form.do.value === 'bulk_delete' ) {
			return confirm( <?php echo wp_json_encode( __( 'Permanently delete the selected entries? This cannot be undone.', 'acps-site-toolkit' ) ); ?> );
		}
		return true;
	}
	</script>
	<?php endif; ?>

	<table class="widefat striped acps-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'Submissions', 'acps-site-toolkit' ); ?></caption>
		<thead>
			<tr>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<td class="check-column"><label for="acps-check-all" class="screen-reader-text"><?php esc_html_e( 'Select all', 'acps-site-toolkit' ); ?></label><input type="checkbox" id="acps-check-all" onclick="var b=this.form.querySelectorAll('input[name=\'entry_ids[]\']');for(var i=0;i<b.length;i++){b[i].checked=this.checked;}"></td>
				<?php endif; ?>
				<th scope="col"><?php esc_html_e( 'ID', 'acps-site-toolkit' ); ?></th>
				<?php if ( $is_feedback ) : ?>
					<th scope="col"><?php esc_html_e( 'Type', 'acps-site-toolkit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Comment', 'acps-site-toolkit' ); ?></th>
				<?php else : ?>
					<?php foreach ( $preview_fields as $pf ) : ?>
						<th scope="col"><?php echo esc_html( $pf['label'] ? $pf['label'] : $pf['key'] ); ?></th>
					<?php endforeach; ?>
				<?php endif; ?>
				<th scope="col"><?php esc_html_e( 'Page', 'acps-site-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'acps-site-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Submitted', 'acps-site-toolkit' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="<?php echo esc_attr( $col_count ); ?>"><?php esc_html_e( 'Nothing here yet.', 'acps-site-toolkit' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) :
					$d      = Entries::get( (int) $row->id );
					$vals   = $d ? $d['values'] : array();
					$type   = isset( $vals['feedback_type'] ) ? ( is_array( $vals['feedback_type'] ) ? implode( ', ', $vals['feedback_type'] ) : $vals['feedback_type'] ) : '';
					$comment = isset( $vals['comment'] ) ? wp_trim_words( (string) $vals['comment'], 16 ) : '';
					$link   = admin_url( 'admin.php?page=acps-st&form_id=' . $form_id . '&entry=' . $row->id );
					?>
					<tr>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<th scope="row" class="check-column"><label class="screen-reader-text" for="acps-cb-<?php echo esc_attr( $row->id ); ?>"><?php printf( esc_html__( 'Select entry %d', 'acps-site-toolkit' ), (int) $row->id ); ?></label><input type="checkbox" id="acps-cb-<?php echo esc_attr( $row->id ); ?>" name="entry_ids[]" value="<?php echo esc_attr( $row->id ); ?>"></th>
						<?php endif; ?>
						<td><a href="<?php echo esc_url( $link ); ?>">#<?php echo esc_html( $row->id ); ?></a></td>
						<?php if ( $is_feedback ) : ?>
							<td><?php echo esc_html( $type ); ?></td>
							<td><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $comment ); ?></a></td>
						<?php else : ?>
							<?php foreach ( $preview_fields as $pf ) :
								$v = isset( $vals[ $pf['key'] ] ) ? $vals[ $pf['key'] ] : '';
								$v = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
								?>
								<td><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( wp_trim_words( $v, 12 ) ); ?></a></td>
							<?php endforeach; ?>
						<?php endif; ?>
						<td><?php echo $row->page_id ? esc_html( get_the_title( (int) $row->page_id ) ) : esc_html( $row->page_url ); ?></td>
						<td><?php echo esc_html( $row->status ); ?></td>
						<td><?php echo esc_html( $row->submitted_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</form>

	<?php
	$total_pages = (int) ceil( $total / 25 );
	if ( $total_pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => __( '&laquo; Previous', 'acps-site-toolkit' ),
					'next_text' => __( 'Next &raquo;', 'acps-site-toolkit' ),
				)
			)
		);
		echo '</div></div>';
	}
	?>
</div>
