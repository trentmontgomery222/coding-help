<?php
/**
 * Admin view: Audit Log (spec Section 8, Q6).
 *
 * @var EXP_Admin $this
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$event_f  = isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
$per_page = 30;

$result = EXP_Audit::query(
	array(
		'event'    => $event_f,
		'per_page' => $per_page,
		'page'     => $paged,
	)
);
?>
<h2><?php esc_html_e( 'Audit Log', 'external-portal' ); ?></h2>
<p class="description"><?php esc_html_e( 'Logins, permission changes, queue reviews, calendar changes and session revocations are recorded here.', 'external-portal' ); ?></p>

<form method="get" class="exp-admin-filter">
	<input type="hidden" name="page" value="<?php echo esc_attr( EXP_Admin::PAGE ); ?>" />
	<input type="hidden" name="tab" value="audit" />
	<label class="screen-reader-text" for="exp-audit-event"><?php esc_html_e( 'Filter by event', 'external-portal' ); ?></label>
	<input type="text" id="exp-audit-event" name="event" value="<?php echo esc_attr( $event_f ); ?>" placeholder="<?php esc_attr_e( 'e.g. login.success', 'external-portal' ); ?>" />
	<?php submit_button( __( 'Filter', 'external-portal' ), 'secondary', '', false ); ?>
</form>

<table class="wp-list-table widefat fixed striped">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'When (UTC)', 'external-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Event', 'external-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Actor', 'external-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Object', 'external-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'IP', 'external-portal' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Detail', 'external-portal' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( empty( $result['rows'] ) ) : ?>
		<tr><td colspan="6"><?php esc_html_e( 'No audit entries.', 'external-portal' ); ?></td></tr>
	<?php else : ?>
		<?php foreach ( $result['rows'] as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row->created_at ); ?></td>
				<td><code><?php echo esc_html( $row->event ); ?></code></td>
				<td><?php echo esc_html( $row->actor_type . ':' . $row->actor_id ); ?></td>
				<td><?php echo esc_html( $row->object_ref ); ?></td>
				<td><?php echo esc_html( $row->ip ); ?></td>
				<td><?php echo esc_html( $row->detail ); ?></td>
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
				'base'    => add_query_arg( 'paged', '%#%', $this->page_url( array( 'tab' => 'audit', 'event' => $event_f ) ) ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			)
		)
	);
	echo '</div></div>';
}
