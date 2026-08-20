<?php
/**
 * Installation / upgrade routines.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and migrates the single global links table.
 */
class ACPS_LS_Install {

	/**
	 * Run on activation.
	 */
	public static function activate() {
		self::create_table();
		update_option( ACPS_LS_OPT_DB_VERSION, ACPS_LS_DB_VERSION );

		// In prefixed mode, add the rewrite rule directly (init has already
		// fired during activation, so hooking it would be too late) then flush
		// ONCE so /{prefix}/{slug} works immediately. In bare mode there is no
		// rule; we still flush to clear any stale prefixed rule.
		if ( '' !== ACPS_LS_SLUG_PREFIX ) {
			$rewrite = new ACPS_LS_Rewrite();
			$rewrite->add_rewrite_rule();
		}
		flush_rewrite_rules();

		// Schedule the 3-minute Sheet sync (the handler no-ops unless enabled).
		if ( ! wp_next_scheduled( ACPS_LS_CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, ACPS_LS_CRON_INTERVAL, ACPS_LS_CRON_HOOK );
		}

		// Schedule the link checker (scan + HTTP checks) every 10 minutes; it
		// works in small batches and no-ops unless enabled.
		if ( ! wp_next_scheduled( ACPS_LS_CHECK_HOOK ) ) {
			wp_schedule_event( time() + ( 2 * MINUTE_IN_SECONDS ), ACPS_LS_CHECK_INTERVAL, ACPS_LS_CHECK_HOOK );
		}
	}

	/**
	 * Run on deactivation. Data + table are preserved.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( ACPS_LS_CRON_HOOK );
		wp_clear_scheduled_hook( ACPS_LS_CHECK_HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Create the global table via dbDelta (idempotent).
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = acps_ls_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// Note: dbDelta is picky about formatting (two spaces after PRIMARY KEY,
		// KEY definitions on their own lines, etc.).
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(190) NOT NULL,
			destination TEXT NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			redirect_type SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			source VARCHAR(50) NOT NULL DEFAULT 'manual',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			creator_label VARCHAR(100) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_clicked_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY is_active (is_active)
		) {$charset_collate};";

		dbDelta( $sql );

		self::create_checker_tables();
	}

	/**
	 * Create the link-checker tables (deduped URLs + occurrences).
	 */
	public static function create_checker_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$urls            = acps_ls_urls_table();
		$occ             = acps_ls_occ_table();

		// One row per unique URL (checked once, however many places it appears).
		$sql_urls = "CREATE TABLE {$urls} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url_hash CHAR(32) NOT NULL,
			url TEXT NOT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'unchecked',
			http_code SMALLINT NOT NULL DEFAULT 0,
			final_url TEXT NULL,
			status_text VARCHAR(191) NOT NULL DEFAULT '',
			fail_count INT UNSIGNED NOT NULL DEFAULT 0,
			dismissed TINYINT(1) NOT NULL DEFAULT 0,
			false_positive TINYINT(1) NOT NULL DEFAULT 0,
			notified TINYINT(1) NOT NULL DEFAULT 0,
			first_failure DATETIME NULL DEFAULT NULL,
			last_checked DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY state (state),
			KEY last_checked (last_checked)
		) {$charset_collate};";
		dbDelta( $sql_urls );

		// Where each URL was found (post/comment/shortener), deduped by occ_hash.
		$sql_occ = "CREATE TABLE {$occ} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			occ_hash CHAR(32) NOT NULL,
			url_hash CHAR(32) NOT NULL,
			source_type VARCHAR(20) NOT NULL DEFAULT '',
			source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_field VARCHAR(40) NOT NULL DEFAULT '',
			link_type VARCHAR(20) NOT NULL DEFAULT 'link',
			anchor VARCHAR(255) NOT NULL DEFAULT '',
			seen_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY occ_hash (occ_hash),
			KEY url_hash (url_hash),
			KEY source (source_type, source_id)
		) {$charset_collate};";
		dbDelta( $sql_occ );
	}

	/**
	 * Run migrations when the stored DB version trails the code version.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( ACPS_LS_OPT_DB_VERSION );

		if ( ACPS_LS_DB_VERSION === $installed ) {
			return;
		}

		// dbDelta is safe to re-run and will add any missing columns/indexes.
		self::create_table();
		update_option( ACPS_LS_OPT_DB_VERSION, ACPS_LS_DB_VERSION );
	}
}
