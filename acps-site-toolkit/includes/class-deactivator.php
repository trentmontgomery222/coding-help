<?php
/**
 * Deactivation routine. Deliberately conservative: it clears the scheduled
 * purge but NEVER touches data. The plugin is deactivated/reactivated often
 * during development and data must survive that (spec §3.8).
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivator.
 */
class Deactivator {

	/**
	 * Runs on register_deactivation_hook.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( Privacy::PURGE_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Privacy::PURGE_HOOK );
		}
		flush_rewrite_rules();
	}
}
