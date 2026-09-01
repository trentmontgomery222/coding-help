<?php
/**
 * Visitors admin: search and browse unique visitors, name them, add notes, and
 * see all of a visitor's form submissions.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Visitors;
use ACPS\SiteToolkit\Entries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view_uid = isset( $_GET['visitor'] ) ? sanitize_text_field( wp_unslash( $_GET['visitor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

/* -------------------------------------------------------------------------
 * Single visitor
 * ---------------------------------------------------------------------- */
if ( '' !== $view_uid ) {
	$visitor = Visitors::get( $view_uid );
	if ( ! $visitor ) {
		echo '<div class="wrap"><p>' . esc_html__( 'Visitor not found.', 'acps-site-toolkit' ) . '</p></div>';
		return;
	}
	$result  = Entries::query( array( 'visitor' => $view_uid, 'status' => '', 'per_page' => 200 ) );
	$entries = $result['rows'];
	$saved   = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<div class="wrap acps-admin">
		<h1><?php esc_html_e( 'Visitor', 'acps-site-toolkit' ); ?>: <?php echo esc_html( $visitor->name ? $visitor->name : $visitor->uid ); ?></h1>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-visitors' ) ); ?>">&larr; <?php esc_html_e( 'Back to visitors', 'acps-site-toolkit' ); ?></a></p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible" role="status"><p><?php esc_html_e( 'Saved.', 'acps-site-toolkit' ); ?></p></div>
		<?php endif; ?>

		<div class="acps-detail-grid">
			<div class="acps-detail-main">
				<h2><?php esc_html_e( 'Details', 'acps-site-toolkit' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr><th scope="row"><?php esc_html_e( 'Visitor ID', 'acps-site-toolkit' ); ?></th><td><code><?php echo esc_html( $visitor->uid ); ?></code></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Name', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( $visitor->name ? $visitor->name : '—' ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Last IP address', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( ! empty( $visitor->last_ip ) ? $visitor->last_ip : '—' ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Logged-in account', 'acps-site-toolkit' ); ?></th>
						<td>
							<?php
							$wp_user = ! empty( $visitor->user_id ) ? get_userdata( (int) $visitor->user_id ) : false;
							if ( $wp_user ) {
								$edit = get_edit_user_link( $wp_user->ID );
								printf(
									'<a href="%s">%s</a> (%s)',
									esc_url( $edit ),
									esc_html( $wp_user->display_name ? $wp_user->display_name : $wp_user->user_login ),
									esc_html( $wp_user->user_email )
								);
							} else {
								esc_html_e( 'Not signed in', 'acps-site-toolkit' );
							}
							?>
						</td></tr>
						<?php $latest = Visitors::latest_session( $view_uid ); ?>
						<tr><th scope="row"><?php esc_html_e( 'Device', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( $latest && $latest['device_type'] ? $latest['device_type'] : '—' ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Browser / OS', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( $latest && $latest['user_agent_summary'] ? $latest['user_agent_summary'] : '—' ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Window size', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( $latest && $latest['viewport'] ? $latest['viewport'] : '—' ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Entry page', 'acps-site-toolkit' ); ?></th><td><?php echo $latest && $latest['entry_url'] ? '<a href="' . esc_url( $latest['entry_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $latest['entry_url'] ) . '</a>' : '—'; ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Came from (referrer)', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( $latest && $latest['referrer'] ? $latest['referrer'] : '—' ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'First seen', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( $visitor->first_seen ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Last seen', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( $visitor->last_seen ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Submissions', 'acps-site-toolkit' ); ?></th><td><?php echo esc_html( count( $entries ) ); ?></td></tr>
					</tbody>
				</table>

				<?php $nav = Visitors::navigation( $view_uid, 300 ); ?>
				<h2><?php esc_html_e( 'Navigation (pages visited)', 'acps-site-toolkit' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Every page this visitor viewed, newest first, across all their sessions. Requires page-view tracking to be on.', 'acps-site-toolkit' ); ?></p>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'When', 'acps-site-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Page', 'acps-site-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Time on page', 'acps-site-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( ! $nav ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'No page views recorded for this visitor.', 'acps-site-toolkit' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $nav as $p ) :
								$title = $p['title'] ? $p['title'] : $p['url'];
								$secs  = (int) $p['time_on_page'];
								$ontime = $secs > 0 ? ( $secs < 60 ? $secs . 's' : floor( $secs / 60 ) . 'm ' . ( $secs % 60 ) . 's' ) : '—';
								?>
								<tr>
									<td><?php echo esc_html( $p['visited_at'] ); ?></td>
									<td><?php echo $p['url'] ? '<a href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a>' : esc_html( $title ); ?></td>
									<td><?php echo esc_html( $ontime ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<?php $vsessions = Visitors::sessions( $view_uid, 100 ); ?>
				<h2><?php esc_html_e( 'Sessions (device & environment)', 'acps-site-toolkit' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Each visit session, with the same device details a form submission records.', 'acps-site-toolkit' ); ?></p>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Started', 'acps-site-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Device', 'acps-site-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Browser / OS', 'acps-site-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Window', 'acps-site-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'IP', 'acps-site-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Came from', 'acps-site-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( ! $vsessions ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'No sessions recorded for this visitor.', 'acps-site-toolkit' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $vsessions as $ss ) : ?>
								<tr>
									<td><?php echo esc_html( $ss['started_at'] ); ?></td>
									<td><?php echo esc_html( $ss['device_type'] ? $ss['device_type'] : '—' ); ?></td>
									<td><?php echo esc_html( $ss['user_agent_summary'] ? $ss['user_agent_summary'] : '—' ); ?></td>
									<td><?php echo esc_html( $ss['viewport'] ? $ss['viewport'] : '—' ); ?></td>
									<td><?php echo esc_html( $ss['ip_anon'] ? $ss['ip_anon'] : '—' ); ?></td>
									<td><?php echo esc_html( $ss['referrer'] ? $ss['referrer'] : '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Submissions', 'acps-site-toolkit' ); ?></h2>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'ID', 'acps-site-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Form', 'acps-site-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'acps-site-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Submitted', 'acps-site-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( ! $entries ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No submissions from this visitor.', 'acps-site-toolkit' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $entries as $e ) :
								$form = \ACPS\SiteToolkit\Form::find( (int) $e->form_id );
								$link = admin_url( 'admin.php?page=acps-st-entries&entry=' . $e->id );
								?>
								<tr>
									<td><a href="<?php echo esc_url( $link ); ?>">#<?php echo esc_html( $e->id ); ?></a></td>
									<td><?php echo esc_html( $form ? $form->title : ( '#' . $e->form_id ) ); ?></td>
									<td><?php echo esc_html( $e->status ); ?></td>
									<td><?php echo esc_html( $e->submitted_at ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="acps-detail-side">
				<h2><?php esc_html_e( 'Edit visitor', 'acps-site-toolkit' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'acps_st_visitor_action' ); ?>
					<input type="hidden" name="action" value="acps_st_visitor_action">
					<input type="hidden" name="uid" value="<?php echo esc_attr( $visitor->uid ); ?>">
					<p>
						<label for="acps-visitor-name"><?php esc_html_e( 'Name', 'acps-site-toolkit' ); ?></label><br>
						<input type="text" id="acps-visitor-name" name="name" value="<?php echo esc_attr( $visitor->name ); ?>" class="widefat">
						<span class="description"><?php esc_html_e( 'Set automatically from an "accname" form field, or edit here.', 'acps-site-toolkit' ); ?></span>
					</p>
					<p>
						<label for="acps-visitor-notes"><?php esc_html_e( 'Internal notes', 'acps-site-toolkit' ); ?></label><br>
						<textarea id="acps-visitor-notes" name="notes" rows="5" class="widefat"><?php echo esc_textarea( $visitor->notes ); ?></textarea>
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'acps-site-toolkit' ); ?></button></p>
				</form>
			</div>
		</div>
	</div>
	<?php
	return;
}

/* -------------------------------------------------------------------------
 * List
 * ---------------------------------------------------------------------- */
$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore
$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'last_seen'; // phpcs:ignore WordPress.Security.NonceVerification
$order   = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification
$result  = Visitors::query( array( 'search' => $search, 'paged' => $paged, 'per_page' => 50, 'orderby' => $orderby, 'order' => $order ) );
$rows    = $result['rows'];
$total   = $result['total'];

$visits_total = \ACPS\SiteToolkit\Analytics::requests_summary();

/**
 * Build a sortable-column header link. Clicking toggles asc/desc for that
 * column; the active column shows the WordPress sorted arrow.
 */
$sort_th = function ( $col, $label ) use ( $orderby, $order, $search ) {
	$is_active = ( $orderby === $col );
	$new_order = ( $is_active && 'asc' === $order ) ? 'desc' : 'asc';
	$class     = 'manage-column sortable ' . ( $is_active ? $order : 'desc' );
	if ( $is_active ) {
		$class .= ' sorted';
	}
	$url = add_query_arg(
		array(
			'page'    => 'acps-st-visitors',
			's'       => $search,
			'orderby' => $col,
			'order'   => $new_order,
		),
		admin_url( 'admin.php' )
	);
	$aria = $is_active ? ( 'asc' === $order ? 'ascending' : 'descending' ) : 'none';
	return '<th scope="col" class="' . esc_attr( $class ) . '" aria-sort="' . esc_attr( $aria ) . '">'
		. '<a href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span><span class="sorting-indicator"></span></a></th>';
};
?>
<div class="wrap acps-admin">
	<h1><?php esc_html_e( 'Visitors', 'acps-site-toolkit' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Every unique visitor (by first-party fingerprint). Search by ID, name or IP address. Names are set automatically when a form has an "accname" field. Open a visitor to see their IP and full page navigation. Click a column heading to sort.', 'acps-site-toolkit' ); ?></p>

	<div class="acps-stat-row">
		<div class="acps-stat"><span class="acps-stat-num"><?php echo esc_html( number_format_i18n( $total ) ); ?></span><span class="acps-stat-lbl"><?php esc_html_e( 'Unique visitors', 'acps-site-toolkit' ); ?></span></div>
		<div class="acps-stat"><span class="acps-stat-num"><?php echo esc_html( number_format_i18n( $visits_total['total'] ) ); ?></span><span class="acps-stat-lbl"><?php esc_html_e( 'Website total visits', 'acps-site-toolkit' ); ?></span></div>
		<div class="acps-stat"><span class="acps-stat-num"><?php echo esc_html( number_format_i18n( $visits_total['today'] ) ); ?></span><span class="acps-stat-lbl"><?php esc_html_e( 'Visits today', 'acps-site-toolkit' ); ?></span></div>
	</div>

	<form method="get" class="acps-filters">
		<input type="hidden" name="page" value="acps-st-visitors">
		<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
		<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
		<label for="acps-visitor-search" class="screen-reader-text"><?php esc_html_e( 'Search visitors', 'acps-site-toolkit' ); ?></label>
		<input type="search" id="acps-visitor-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search ID or name…', 'acps-site-toolkit' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Search', 'acps-site-toolkit' ); ?></button>
	</form>

	<table class="widefat striped acps-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'Unique visitors', 'acps-site-toolkit' ); ?></caption>
		<thead><tr>
			<?php
			echo $sort_th( 'name', __( 'Name', 'acps-site-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			?>
			<th scope="col"><?php esc_html_e( 'Visitor ID', 'acps-site-toolkit' ); ?></th>
			<?php
			echo $sort_th( 'last_ip', __( 'Last IP', 'acps-site-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $sort_th( 'entry_count', __( 'Submissions', 'acps-site-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $sort_th( 'first_seen', __( 'First seen', 'acps-site-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $sort_th( 'last_seen', __( 'Last seen', 'acps-site-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			?>
		</tr></thead>
		<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No visitors yet.', 'acps-site-toolkit' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $v ) :
					$link = admin_url( 'admin.php?page=acps-st-visitors&visitor=' . rawurlencode( $v->uid ) );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $link ); ?>"><strong><?php echo esc_html( $v->name ? $v->name : __( '(unnamed)', 'acps-site-toolkit' ) ); ?></strong></a></td>
						<td><code><?php echo esc_html( $v->uid ); ?></code></td>
						<td><?php echo esc_html( ! empty( $v->last_ip ) ? $v->last_ip : '—' ); ?></td>
						<td><?php echo esc_html( (int) $v->entry_count ); ?></td>
						<td><?php echo esc_html( $v->first_seen ); ?></td>
						<td><?php echo esc_html( $v->last_seen ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php
	$total_pages = (int) ceil( $total / 50 );
	if ( $total_pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $total_pages ) ) );
		echo '</div></div>';
	}
	?>
</div>
