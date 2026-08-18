<?php
/**
 * Uninstall cleanup: drop the plugin's tables and options.
 *
 * Runs only when the plugin is deleted from wp-admin.
 *
 * @package mcm
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'mcm_blocks',
	$wpdb->prefix . 'mcm_editors',
	$wpdb->prefix . 'mcm_sessions',
);

foreach ( $tables as $table ) {
	// Table name is built from the trusted prefix + literal string.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'mcm_settings' );
delete_option( 'mcm_db_version' );

wp_clear_scheduled_hook( 'mcm_gc_sessions' );
