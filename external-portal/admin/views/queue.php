<?php
/**
 * Admin view: Content Update Queue (spec Section 5 & 6).
 *
 * @var EXP_Admin $this
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$registry = EXP_Registry::instance();
$item_id  = isset( $_GET['item'] ) ? (int) $_GET['item'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

// ---- Single item detail. ----
if ( $item_id ) {
	$item = EXP_Queue::get( $item_id );
	if ( ! $item ) {
		echo '<p>' . esc_html__( 'Item not found.', 'external-portal' ) . '</p>';
		return;
	}
	$submitter = EXP_Users::get( $item->submitted_by );
	$type_def  = $registry->queue_type( $item->type );
	?>
	<p><a href="<?php echo esc_url( $this->page_url( array( 'tab' => 'queue' ) ) ); ?>">&larr; <?php esc_html_e( 'Back to queue', 'external-portal' ); ?></a></p>
	<h2><?php echo esc_html( sprintf( __( 'Review #%d', 'external-portal' ), $item->id ) ); ?></h2>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Type', 'external-portal' ); ?></th><td><?php echo esc_html( EXP_Queue::type_label( $item->type ) ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Submitted by', 'external-portal' ); ?></th><td><?php echo esc_html( $submitter ? $submitter->email : '#' . $item->submitted_by ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Status', 'external-portal' ); ?></th><td><?php echo esc_html( ucfirst( $item->status ) ); ?></td></tr>
	</table>

	<div class="exp-review-preview">
		<?php
		if ( $type_def && is_callable( $type_def['review_renderer'] ) ) {
			echo wp_kses_post( call_user_func( $type_def['review_renderer'], $item ) );
		} else {
			echo '<pre class="exp-pre">' . esc_html( wp_json_encode( $item->payload_data, JSON_PRETTY_PRINT ) ) . '</pre>';
		}
		?>
	</div>

	<?php if ( EXP_Queue::STATUS_PENDING === $item->status ) : ?>
		<form method="post">
			<?php echo $this->form_fields( 'queue_review', 'queue' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="queue_id" value="<?php echo esc_attr( $item->id ); ?>" />
			<p>
				<label for="exp-notes"><?php esc_html_e( 'Note for the submitter (optional):', 'external-portal' ); ?></label><br />
				<textarea id="exp-notes" name="admin_notes" rows="3" class="large-text"></textarea>
			</p>
			<button type="submit" name="op" value="approve" class="button button-primary"><?php esc_html_e( 'Approve & apply', 'external-portal' ); ?></button>
			<button type="submit" name="op" value="reject" class="button"><?php esc_html_e( 'Reject', 'external-portal' ); ?></button>
		</form>
	<?php else : ?>
		<?php if ( $item->admin_notes ) : ?>
			<p><strong><?php esc_html_e( 'Reviewer note:', 'external-portal' ); ?></strong> <?php echo esc_html( $item->admin_notes ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php
	return;
}

// ---- List. ----
$type_f    = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$status_f  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification
$sub_f     = isset( $_GET['submitter'] ) ? (int) $_GET['submitter'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
$per_page  = 20;

$result = EXP_Queue::query(
	array(
		'type'         => $type_f,
		'status'       => $status_f,
		'submitted_by' => $sub_f,
		'per_page'     => $per_page,
		'page'         => $paged,
	)
);
?>
<h2><?php esc_html_e( 'Content Update Queue', 'external-portal' ); ?></h2>

<form method="get" class="exp-admin-filter">
	<input type="hidden" name="page" value="<?php echo esc_attr( EXP_Admin::PAGE ); ?>" />
	<input type="hidden" name="tab" value="queue" />

	<label class="screen-reader-text" for="exp-q-type"><?php esc_html_e( 'Type', 'external-portal' ); ?></label>
	<select id="exp-q-type" name="type">
		<option value=""><?php esc_html_e( 'All types', 'external-portal' ); ?></option>
		<?php foreach ( $registry->queue_types() as $t => $def ) : ?>
			<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type_f, $t ); ?>><?php echo esc_html( $def['label'] ); ?></option>
		<?php endforeach; ?>
	</select>

	<label class="screen-reader-text" for="exp-q-status"><?php esc_html_e( 'Status', 'external-portal' ); ?></label>
	<select id="exp-q-status" name="status">
		<?php foreach ( array( '' => __( 'All statuses', 'external-portal' ), 'pending' => __( 'Pending', 'external-portal' ), 'approved' => __( 'Approved', 'external-portal' ), 'rejected' => __( 'Rejected', 'external-portal' ) ) as $val => $label ) : ?>
			<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status_f, $val ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>

	<label class="screen-reader-text" for="exp-q-sub"><?php esc_html_e( 'Submitter', 'external-portal' ); ?></label>
	<select id="exp-q-sub" name="submitter">
		<option value="0"><?php esc_html_e( 'All submitters', 'external-portal' ); ?></option>
		<?php
		$all = EXP_Users::query( array( 'per_page' => 500 ) );
		foreach ( $all['rows'] as $u ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $u->id, selected( $sub_f, $u->id, false ), esc_html( $u->email ) );
		}
		?>
	</select>
	<?php submit_button( __( 'Filter', 'external-portal' ), 'secondary', '', false ); ?>
</form>

<table class="wp-list-table widefat fixed striped">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'ID', 'external-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Type', 'external-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Submitter', 'external-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Submitted', 'external-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Status', 'external-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Action', 'external-portal' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( empty( $result['rows'] ) ) : ?>
		<tr><td colspan="6"><?php esc_html_e( 'No items match.', 'external-portal' ); ?></td></tr>
	<?php else : ?>
		<?php
		foreach ( $result['rows'] as $row ) :
			$sub  = EXP_Users::get( $row->submitted_by );
			$date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row->created_at . ' UTC' ) );
			?>
		<tr>
			<td>#<?php echo esc_html( $row->id ); ?></td>
			<td><?php echo esc_html( EXP_Queue::type_label( $row->type ) ); ?></td>
			<td><?php echo esc_html( $sub ? $sub->email : '#' . $row->submitted_by ); ?></td>
			<td><?php echo esc_html( $date ); ?></td>
			<td><?php echo esc_html( ucfirst( $row->status ) ); ?></td>
			<td><a class="button button-small" href="<?php echo esc_url( $this->page_url( array( 'tab' => 'queue', 'item' => $row->id ) ) ); ?>"><?php esc_html_e( 'Review', 'external-portal' ); ?></a></td>
		</tr>
		<?php endforeach; ?>
	<?php endif; ?>
	</tbody>
</table>

<?php
$pages = (int) ceil( (int) $result['total'] / $per_page );
if ( $pages > 1 ) {
	echo '<div class="tablenav"><div class="tablenav-pages">';
	echo wp_kses_post(
		paginate_links(
			array(
				'base'    => add_query_arg( 'paged', '%#%', $this->page_url( array( 'tab' => 'queue', 'type' => $type_f, 'status' => $status_f, 'submitter' => $sub_f ) ) ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			)
		)
	);
	echo '</div></div>';
}
