<?php
/**
 * Uninstall handler — removes all plugin data.
 *
 * Runs only when the plugin is deleted from wp-admin. Single-site scoped.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only wipe data if the admin opted in (constant or option). Default: wipe.
$wipe = apply_filters( 'exp_uninstall_delete_data', true );
if ( ! $wipe ) {
	return;
}

global $wpdb;

$p      = $wpdb->prefix . 'portal_';
$tables = array(
	$p . 'users',
	$p . 'otp_codes',
	$p . 'sessions',
	$p . 'grants',
	$p . 'queue',
	$p . 'audit',
	$p . 'extensions',
);
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

delete_option( 'exp_settings' );
delete_option( 'exp_db_version' );

// Clear scheduled cron.
$timestamp = wp_next_scheduled( 'exp_cron_cleanup' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'exp_cron_cleanup' );
}
