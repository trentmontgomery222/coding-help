<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'acps_alerts_settings' );

global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_acps_alert_' ) . '%'
	)
);
