<?php
/**
 * Activation routine: create tables, seed default settings, create the
 * feedback form template, register the feedback page, schedule the purge cron.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activator.
 */
class Activator {

	/**
	 * Runs on register_activation_hook. Table creation lives here — the plugin
	 * must be *activated* from the Plugins screen after a file-manager upload;
	 * mere presence on disk does nothing (spec §11).
	 */
	public static function activate() {
		Schema::install();

		// Seed default settings without clobbering existing ones.
		$existing = get_option( ACPS_ST_OPT_SETTINGS );
		if ( ! is_array( $existing ) ) {
			update_option( ACPS_ST_OPT_SETTINGS, Settings::defaults() );
		} else {
			// Fill in any keys added by a plugin upgrade.
			update_option( ACPS_ST_OPT_SETTINGS, wp_parse_args( $existing, Settings::defaults() ) );
		}

		// Ensure the built-in feedback + contact form templates exist.
		Feedback::ensure_feedback_form();
		Help::ensure_contact_form();
		Help::ensure_media_request_form();

		// Schedule the daily retention purge (spec §4.5).
		if ( ! wp_next_scheduled( Privacy::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Privacy::PURGE_HOOK );
		}

		// Rewrite rules may need refreshing if we register any endpoints.
		flush_rewrite_rules();
	}
}
