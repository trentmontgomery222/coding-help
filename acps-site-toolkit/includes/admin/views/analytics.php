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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pages       = Analytics::top_pages( array( 'limit' => 50 ) );
$transitions = Analytics::common_transitions( 15 );
$dead_ends   = Analytics::dead_ends( 10 );
$trend       = Analytics::trend( 30 );

// Path drill-down for a selected page.
$focus = isset( $_GET['focus'] ) ? absint( $_GET['focus'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$paths = $focus ? Analytics::path_analysis( $focus, 10 ) : null;

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
</div>
