<?php
/**
 * Uninstall routine (spec §3.8 / §11).
 *
 * Runs only when the plugin is DELETED from the Plugins screen (not on
 * deactivation). It is governed by the "Preserve data on uninstall" setting,
 * which defaults to ON — so repeated deactivate/reactivate during development
 * never loses data, and even a delete keeps data unless the admin opted out.
 *
 * @package ACPS\SiteToolkit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings      = get_option( 'acps_st_settings' );
$preserve_data = is_array( $settings ) && isset( $settings['preserve_data'] )
	? (bool) $settings['preserve_data']
	: true; // default ON.

if ( $preserve_data ) {
	// Leave everything in place.
	return;
}

global $wpdb;

// Drop all plugin tables.
$tables = array( 'entry_notes', 'entry_values', 'entries', 'forms', 'visits', 'sessions', 'visitors' );
foreach ( $tables as $key ) {
	$table = $wpdb->prefix . 'acps_' . $key;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

// Remove options.
delete_option( 'acps_st_settings' );
delete_option( 'acps_st_schema_version' );
delete_option( 'acps_st_version' );
delete_option( 'acps_st_qa' );
delete_option( 'acps_st_admin_presence' );
delete_transient( 'acps_st_update_remote' );

// Clear the scheduled purge.
wp_clear_scheduled_hook( 'acps_st_daily_purge' );

// Remove the read-only reports capability from the Editor role.
$role = get_role( 'editor' );
if ( $role ) {
	$role->remove_cap( 'acps_st_read_reports' );
}
