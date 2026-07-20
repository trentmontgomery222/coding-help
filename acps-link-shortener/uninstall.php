<?php
/**
 * Uninstall routine.
 *
 * By default we PRESERVE the links table and options so that deleting/replacing
 * the plugin never destroys live short links (build brief, Open Question #4).
 *
 * To opt into a full teardown on uninstall, define this in wp-config.php:
 *
 *     define( 'ACPS_LS_DROP_DATA_ON_UNINSTALL', true );
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ACPS_LS_DROP_DATA_ON_UNINSTALL' ) || ! ACPS_LS_DROP_DATA_ON_UNINSTALL ) {
	// Preserve everything. Do nothing.
	return;
}

global $wpdb;

$table = $wpdb->base_prefix . 'acps_links';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_site_option( 'acps_ls_db_version' );
delete_site_option( 'acps_ls_settings' );
delete_site_option( 'acps_ls_last_sync' );

// Clear any scheduled sync.
wp_clear_scheduled_hook( 'acps_ls_sheet_sync' );
