<?php
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's options and log table when the plugin is deleted from
 * the WordPress admin. Media files themselves are never touched here.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$options = array(
	'acps_media_cleanup_settings',
	'acps_media_cleanup_results',
	'acps_media_cleanup_scan_meta',
	'acps_mc_usage_index',
	'acps_media_cleanup_activated',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}

foreach ( array( 'acps_mc_log', 'acps_mc_index' ) as $t ) {
	$table = $wpdb->prefix . $t;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'acps_mc_daily_scan' );
wp_clear_scheduled_hook( 'acps_mc_continue_scan' );

// Remove per-user recent-folder memory.
delete_metadata( 'user', 0, 'acps_mm_recent_folders', '', true );

// Remove the file-content hashes used for duplicate detection. Only this
// plugin's own bookkeeping meta key is touched — the attachments and files
// themselves are never affected.
delete_metadata( 'post', 0, '_acps_mc_filehash', '', true );
delete_option( 'acps_mm_version' );
