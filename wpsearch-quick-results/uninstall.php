<?php
/**
 * Removes everything this plugin created.
 *
 * Only runs on an explicit Delete from the plugins screen, never on
 * deactivation — deactivating to troubleshoot should not throw away your
 * settings or your search history.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpsqr_cache' ); // phpcs:ignore
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpsqr_terms' ); // phpcs:ignore

delete_option( 'wpsqr_settings' );
delete_option( 'wpsqr_db_version' );
delete_option( 'wpsqr_last_warm' );
delete_option( 'wpsqr_cache_salt' );
delete_transient( 'wpsqr_hidden_map' );

wp_clear_scheduled_hook( 'wpsqr_warm_cache' );
wp_clear_scheduled_hook( 'wpsqr_sweep_cache' );
