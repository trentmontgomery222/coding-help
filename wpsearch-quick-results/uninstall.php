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
delete_option( 'wpsqr_safe_mode' );
delete_option( 'wpsqr_last_health' );
delete_option( 'wpsqr_rc_key' );
delete_option( 'wpsqr_rc_pwhash' );
delete_option( 'wpsqr_rc_rate' );
delete_option( 'wpsqr_rc_last_edit' );
delete_option( 'wpsqr_rc_last_update' );
delete_option( 'wpsqr_rollback' );
delete_option( 'wpsqr_last_rollback' );
delete_option( 'wpsqr_should_be_active' );
delete_option( 'wpsqr_post_update_check' );
delete_option( 'wpsqr_last_update_check' );
delete_transient( 'wpsqr_hidden_map' );
delete_transient( 'wpsqr_update_manifest' );

wp_clear_scheduled_hook( 'wpsqr_warm_cache' );
wp_clear_scheduled_hook( 'wpsqr_sweep_cache' );
