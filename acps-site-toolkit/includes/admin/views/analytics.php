<?php
/**
 * Analytics dashboard (spec §6). Default sort is the feedback/traffic overlay
 * (spec §6.4). Charts are always paired with an accessible data table — a
 * visualization alone is not an accessible presentation of data (spec §8.3).
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Analytics;
use ACPS\SiteToolkit\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Which cards to render. A card shows only when its data is being collected AND
// its "show on dashboard" toggle is on. Turning a card off also skips the
// queries that build it (Settings → Analytics → Show on dashboard).
$has_pages     = (bool) Settings::get( 'track_pageviews' );
$show_live     = $has_pages && Settings::get( 'show_live' );
$show_uu       = Settings::get( 'track_visitors' ) && Settings::get( 'show_unique_users' );
$show_pages    = $has_pages && Settings::get( 'show_pages' );
$show_devices  = $has_pages && Settings::get( 'show_devices' );
$show_journeys = $has_pages && Settings::get( 'show_journeys' );
$show_trend    = $has_pages && Settings::get( 'show_trend' );

$pages       = $show_pages ? Analytics::top_pages( array( 'limit' => 50 ) ) : array();
$transitions = $show_journeys ? Analytics::common_transitions( 15 ) : array();
$dead_ends   = $show_journeys ? Analytics::dead_ends( 10 ) : array();
$trend       = $show_trend ? Analytics::trend( 30 ) : array();
$devices     = $show_devices ? Analytics::device_breakdown() : array();
$ua          = $show_devices ? Analytics::ua_breakdown() : array( 'browsers' => array(), 'os' => array() );

// Path drill-down for a selected page.
$focus = isset( $_GET['focus'] ) ? absint( $_GET['focus'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$paths = ( $focus && $show_journeys ) ? Analytics::path_analysis( $focus, 10 ) : null;

$fmt_time = function ( $seconds ) {
	$seconds = (int) $seconds;
	if ( $seconds <= 0 ) {
		return '—';
	}
	if ( $seconds < 60 ) {
		return $seconds . 's';
	}
	return floor( $seconds / 60 ) . 'm ' . ( $seconds % 60 ) . 's';
};
?>
<div class="wrap acps-admin acps-analytics">
	<h1><?php esc_html_e( 'Analytics', 'acps-site-toolkit' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Sorted by the feedback/traffic overlay: pages with heavy traffic and clustered feedback rise to the top of the fix list.', 'acps-site-toolkit' ); ?></p>
	<p class="description"><?php esc_html_e( 'Choose which of these cards appear under Settings → Analytics → Show on dashboard.', 'acps-site-toolkit' ); ?></p>

	<?php
	if ( $show_live ) :
		$active_pages = Analytics::active_pages( 5 );
		$active_total = Analytics::active_count( 5 );
		$active_staff = \ACPS\SiteToolkit\Presence::active( 5 );
		?>
	<div class="acps-card acps-live" id="acps-live">
		<h2>
			<span class="acps-live-dot" aria-hidden="true"></span>
			<?php esc_html_e( 'Who’s on the site now', 'acps-site-toolkit' ); ?>
			<span class="acps-live-count" data-acps-live-total><?php echo esc_html( $active_total ); ?></span>
		</h2>
		<p class="description"><?php esc_html_e( 'Live view of pages being read right now (excludes logged-in admins). Handy before you edit a page someone is on. Updates automatically.', 'acps-site-toolkit' ); ?></p>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php esc_html_e( 'Pages currently being viewed', 'acps-site-toolkit' ); ?></caption>
			<thead><tr><th scope="col"><?php esc_html_e( 'Page', 'acps-site-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Viewers', 'acps-site-toolkit' ); ?></th></tr></thead>
			<tbody data-acps-live-body>
				<?php if ( ! $active_pages ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No active visitors right now.', 'acps-site-toolkit' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $active_pages as $p ) : ?>
						<tr><td><?php echo esc_html( $p['title'] ); ?></td><td><?php echo esc_html( $p['count'] ); ?></td></tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Last updated:', 'acps-site-toolkit' ); ?> <span data-acps-live-time><?php echo esc_html( date_i18n( get_option( 'time_format' ) ) ); ?></span></p>
	</div>

	<div class="acps-card acps-live" id="acps-live-staff">
		<h2>
			<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
			<?php esc_html_e( 'Staff on the site now', 'acps-site-toolkit' ); ?>
		</h2>
		<p class="description"><?php esc_html_e( 'Logged-in admins and the page each is currently on. Handy for spotting when a colleague is on a page you’re about to edit. Updates automatically.', 'acps-site-toolkit' ); ?></p>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php esc_html_e( 'Staff currently on the site', 'acps-site-toolkit' ); ?></caption>
			<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'acps-site-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Currently viewing', 'acps-site-toolkit' ); ?></th></tr></thead>
			<tbody data-acps-staff-body>
				<?php if ( ! $active_staff ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No other staff on the front of the site right now.', 'acps-site-toolkit' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $active_staff as $p ) : ?>
						<tr><td><?php echo esc_html( $p['name'] ); ?></td><td><?php echo esc_html( $p['title'] ? $p['title'] : $p['url'] ); ?></td></tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<script>
	( function () {
		var cfg = window.ACPS_ST_ADMIN || {};
		if ( ! cfg.ajaxUrl ) { return; }
		var body = document.querySelector( '[data-acps-live-body]' );
		var staffBody = document.querySelector( '[data-acps-staff-body]' );
		var total = document.querySelector( '[data-acps-live-total]' );
		var timeEl = document.querySelector( '[data-acps-live-time]' );
		function esc( s ) { var d = document.createElement( 'div' ); d.textContent = s == null ? '' : String( s ); return d.innerHTML; }
		function refresh() {
			var url = cfg.ajaxUrl + '?action=acps_st_active&nonce=' + encodeURIComponent( cfg.nonce );
			fetch( url, { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) { return; }
					var d = res.data;
					if ( total ) { total.textContent = d.total; }
					if ( timeEl ) { timeEl.textContent = d.time; }
					if ( body ) {
						if ( ! d.pages.length ) {
							body.innerHTML = '<tr><td colspan="2"><?php echo esc_js( __( 'No active visitors right now.', 'acps-site-toolkit' ) ); ?></td></tr>';
						} else {
							body.innerHTML = d.pages.map( function ( p ) {
								return '<tr><td>' + esc( p.title ) + '</td><td>' + esc( p.count ) + '</td></tr>';
							} ).join( '' );
						}
					}
					if ( staffBody ) {
						var staff = d.staff || [];
						if ( ! staff.length ) {
							staffBody.innerHTML = '<tr><td colspan="2"><?php echo esc_js( __( 'No other staff on the front of the site right now.', 'acps-site-toolkit' ) ); ?></td></tr>';
						} else {
							staffBody.innerHTML = staff.map( function ( p ) {
								return '<tr><td>' + esc( p.name ) + '</td><td>' + esc( p.page ) + '</td></tr>';
							} ).join( '' );
						}
					}
				} )
				.catch( function () {} );
		}
		setInterval( refresh, 20000 );
	} )();
	</script>
	<?php endif; // $show_live ?>

	<?php if ( $focus && $paths ) : ?>
		<div class="acps-card">
			<h2><?php /* translators: %s: page title */ printf( esc_html__( 'Paths for: %s', 'acps-site-toolkit' ), esc_html( get_the_title( $focus ) ) ); ?></h2>
			<div class="acps-two-col">
				<div>
					<h3><?php esc_html_e( 'Came from', 'acps-site-toolkit' ); ?></h3>
					<table class="widefat striped">
						<thead><tr><th scope="col"><?php esc_html_e( 'Page', 'acps-site-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Visits', 'acps-site-toolkit' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $paths['from'] as $p ) : ?>
							<tr><td><?php echo esc_html( $p['title'] ); ?></td><td><?php echo esc_html( $p['count'] ); ?></td></tr>
						<?php endforeach; ?>
						<?php if ( ! $paths['from'] ) : ?><tr><td colspan="2"><?php esc_html_e( 'No data', 'acps-site-toolkit' ); ?></td></tr><?php endif; ?>
						</tbody>
					</table>
				</div>
				<div>
					<h3><?php esc_html_e( 'Went to', 'acps-site-toolkit' ); ?></h3>
					<table class="widefat striped">
						<thead><tr><th scope="col"><?php esc_html_e( 'Page', 'acps-site-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Visits', 'acps-site-toolkit' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $paths['to'] as $p ) : ?>
							<tr><td><?php echo esc_html( $p['title'] ); ?></td><td><?php echo esc_html( $p['count'] ); ?></td></tr>
						<?php endforeach; ?>
						<?php if ( ! $paths['to'] ) : ?><tr><td colspan="2"><?php esc_html_e( 'No data', 'acps-site-toolkit' ); ?></td></tr><?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-analytics' ) ); ?>">&larr; <?php esc_html_e( 'Back to overview', 'acps-site-toolkit' ); ?></a></p>
		</div>
	<?php endif; ?>

	<?php if ( $show_uu ) :
		$uu_total   = \ACPS\SiteToolkit\Visitors::total();
		$uu_today   = \ACPS\SiteToolkit\Visitors::new_since( date_i18n( 'Y-m-d' ) );
		$uu_new_30  = \ACPS\SiteToolkit\Visitors::new_since( gmdate( 'Y-m-d', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) );
		$uu_active  = \ACPS\SiteToolkit\Visitors::active_within( 30 );
		$uu_trend   = \ACPS\SiteToolkit\Visitors::new_trend( 30 );
		?>
	<div class="acps-card">
		<h2><?php esc_html_e( 'Unique users', 'acps-site-toolkit' ); ?></h2>
		<p class="description"><?php esc_html_e( 'A unique user is identified by anonymised IP + browser (the same signal the spam filter uses), computed on the server — so clearing cookies or cache can’t create a new one. People on the same network + browser may count as one. Logged-in admins are excluded.', 'acps-site-toolkit' ); ?></p>
		<div class="acps-stat-row">
			<div class="acps-stat"><span class="acps-stat-num"><?php echo esc_html( number_format_i18n( $uu_total ) ); ?></span><span class="acps-stat-lbl"><?php esc_html_e( 'Total unique users', 'acps-site-toolkit' ); ?></span></div>
			<div class="acps-stat"><span class="acps-stat-num"><?php echo esc_html( number_format_i18n( $uu_new_30 ) ); ?></span><span class="acps-stat-lbl"><?php esc_html_e( 'New in last 30 days', 'acps-site-toolkit' ); ?></span></div>
			<div class="acps-stat"><span class="acps-stat-num"><?php echo esc_html( number_format_i18n( $uu_active ) ); ?></span><span class="acps-stat-lbl"><?php esc_html_e( 'Active in last 30 days', 'acps-site-toolkit' ); ?></span></div>
			<div class="acps-stat"><span class="acps-stat-num"><?php echo esc_html( number_format_i18n( $uu_today ) ); ?></span><span class="acps-stat-lbl"><?php esc_html_e( 'New today', 'acps-site-toolkit' ); ?></span></div>
		</div>
		<details>
			<summary><?php esc_html_e( 'New users per day (last 30 days)', 'acps-site-toolkit' ); ?></summary>
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php esc_html_e( 'New unique users per day', 'acps-site-toolkit' ); ?></caption>
				<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'acps-site-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'New users', 'acps-site-toolkit' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $uu_trend ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No data yet.', 'acps-site-toolkit' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $uu_trend as $t ) : ?>
						<tr><td><?php echo esc_html( $t['d'] ); ?></td><td><?php echo esc_html( $t['c'] ); ?></td></tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</details>
	</div>
	<?php endif; // $show_uu ?>

	<?php if ( $show_pages ) : ?>
	<div class="acps-card">
		<h2><?php esc_html_e( 'Pages — traffic & feedback overlay', 'acps-site-toolkit' ); ?></h2>
		<table class="widefat striped acps-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Per-page metrics with feedback counts', 'acps-site-toolkit' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page', 'acps-site-toolkit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Views', 'acps-site-toolkit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sessions', 'acps-site-toolkit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Avg time', 'acps-site-toolkit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Entries', 'acps-site-toolkit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Exits', 'acps-site-toolkit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Feedback', 'acps-site-toolkit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Paths', 'acps-site-toolkit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $pages ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No traffic recorded yet. Once the beacon starts firing on live (cached) pages, data will appear here.', 'acps-site-toolkit' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $pages as $p ) : ?>
						<tr<?php echo $p['feedback_count'] > 0 && $p['views'] > 0 ? ' class="acps-flag"' : ''; ?>>
							<th scope="row"><?php echo esc_html( $p['title'] ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $p['views'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $p['sessions'] ) ); ?></td>
							<td><?php echo esc_html( $fmt_time( $p['avg_time'] ) ); ?></td>
							<td><?php echo esc_html( $p['entries'] ); ?></td>
							<td><?php echo esc_html( $p['exits'] ); ?></td>
							<td><strong><?php echo esc_html( $p['feedback_count'] ); ?></strong></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-analytics&focus=' . $p['post_id'] ) ); ?>"><?php esc_html_e( 'View', 'acps-site-toolkit' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php endif; // $show_pages ?>

	<?php if ( $show_devices ) : ?>
	<div class="acps-card">
		<h2><?php esc_html_e( 'Devices, browsers & operating systems', 'acps-site-toolkit' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Sessions, page views, and average time on page, broken down by how visitors reached the site.', 'acps-site-toolkit' ); ?></p>
		<div class="acps-three-col">
			<?php
			$acps_bd_tables = array(
				__( 'Device', 'acps-site-toolkit' )           => $devices,
				__( 'Browser', 'acps-site-toolkit' )          => $ua['browsers'],
				__( 'Operating system', 'acps-site-toolkit' ) => $ua['os'],
			);
			foreach ( $acps_bd_tables as $bd_title => $bd_rows ) :
				?>
				<div>
					<h3><?php echo esc_html( $bd_title ); ?></h3>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html( $bd_title ); ?></th>
								<th scope="col"><?php esc_html_e( 'Sessions', 'acps-site-toolkit' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Views', 'acps-site-toolkit' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Avg time', 'acps-site-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! $bd_rows ) : ?>
								<tr><td colspan="4"><?php esc_html_e( 'No data', 'acps-site-toolkit' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $bd_rows as $bd ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $bd['label'] ); ?></th>
										<td><?php echo esc_html( number_format_i18n( $bd['sessions'] ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $bd['views'] ) ); ?></td>
										<td><?php echo esc_html( $fmt_time( $bd['avg_time'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; // $show_devices ?>

	<?php if ( $show_journeys ) : ?>
	<div class="acps-two-col">
		<div class="acps-card">
			<h2><?php esc_html_e( 'Most common paths', 'acps-site-toolkit' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'From', 'acps-site-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'To', 'acps-site-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Count', 'acps-site-toolkit' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $transitions as $t ) : ?>
					<tr><td><?php echo esc_html( $t['from'] ); ?></td><td><?php echo esc_html( $t['to'] ); ?></td><td><?php echo esc_html( $t['count'] ); ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $transitions ) : ?><tr><td colspan="3"><?php esc_html_e( 'No data', 'acps-site-toolkit' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>

		<div class="acps-card">
			<h2><?php esc_html_e( 'Possible dead ends', 'acps-site-toolkit' ); ?></h2>
			<p class="description"><?php esc_html_e( 'High exit rate on pages that are not intended endpoints.', 'acps-site-toolkit' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Page', 'acps-site-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Exit rate', 'acps-site-toolkit' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $dead_ends as $d ) : ?>
					<tr><td><?php echo esc_html( $d['title'] ); ?></td><td><?php echo esc_html( $d['exit_rate'] ); ?>%</td></tr>
				<?php endforeach; ?>
				<?php if ( ! $dead_ends ) : ?><tr><td colspan="2"><?php esc_html_e( 'No data', 'acps-site-toolkit' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php endif; // $show_journeys ?>

	<?php if ( $show_trend ) : ?>
	<div class="acps-card">
		<h2><?php esc_html_e( 'Views — last 30 days', 'acps-site-toolkit' ); ?></h2>
		<?php // Accessible sparkline: a table IS the accessible equivalent (spec §8.3). ?>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php esc_html_e( 'Daily page views over the last 30 days', 'acps-site-toolkit' ); ?></caption>
			<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'acps-site-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Views', 'acps-site-toolkit' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $trend as $t ) : ?>
				<tr><td><?php echo esc_html( $t['d'] ); ?></td><td><?php echo esc_html( $t['c'] ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( ! $trend ) : ?><tr><td colspan="2"><?php esc_html_e( 'No data', 'acps-site-toolkit' ); ?></td></tr><?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php endif; // $show_trend ?>

	<?php if ( ! $show_live && ! $show_uu && ! $show_pages && ! $show_devices && ! $show_journeys && ! $show_trend ) : ?>
	<div class="acps-card">
		<p><?php esc_html_e( 'Every dashboard card is turned off. Enable the ones you want under Settings → Analytics → Show on dashboard.', 'acps-site-toolkit' ); ?></p>
	</div>
	<?php endif; ?>
</div>
