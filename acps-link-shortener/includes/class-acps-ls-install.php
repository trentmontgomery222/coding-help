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

		// Clean up any leftover event from the removed Google Sheet sync.
		wp_clear_scheduled_hook( ACPS_LS_CRON_HOOK );
	}

	/**
	 * Run on deactivation. Data + table are preserved.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( ACPS_LS_CRON_HOOK );
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
