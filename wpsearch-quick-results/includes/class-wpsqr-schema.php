<?php
/**
 * Table creation and upgrades.
 *
 * Two custom tables rather than transients. Transients in the options table
 * would work for the cache, but "show me the 50 most popular searches" and
 * "purge everything older than an hour" are queries, and the options table is
 * the wrong shape for both — plus a large transient set is exactly the thing
 * that bloats wp_options and slows every page load on the site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Schema {

	public static function cache_table() {
		global $wpdb;

		return $wpdb->prefix . 'wpsqr_cache';
	}

	public static function terms_table() {
		global $wpdb;

		return $wpdb->prefix . 'wpsqr_terms';
	}

	public static function activate() {
		self::install();
		WPSQR_Warmer::schedule();
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$cache   = self::cache_table();
		$terms   = self::terms_table();

		// post_ids holds a JSON array of ints. Storing the ordered list rather
		// than rendered HTML keeps entries small and lets the renderer stay
		// free to change without invalidating the cache.
		$sql = array();

		$sql[] = "CREATE TABLE {$cache} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cache_key CHAR(64) NOT NULL,
			term VARCHAR(191) NOT NULL DEFAULT '',
			viewer VARCHAR(20) NOT NULL DEFAULT 'visitor',
			page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			post_ids LONGTEXT NOT NULL,
			total INT UNSIGNED NOT NULL DEFAULT 0,
			build_ms INT UNSIGNED NOT NULL DEFAULT 0,
			hits INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY cache_key (cache_key),
			KEY expires_at (expires_at),
			KEY term (term)
		) {$charset};";

		$sql[] = "CREATE TABLE {$terms} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			term VARCHAR(191) NOT NULL,
			display VARCHAR(191) NOT NULL DEFAULT '',
			searches INT UNSIGNED NOT NULL DEFAULT 0,
			results INT UNSIGNED NOT NULL DEFAULT 0,
			results_known TINYINT(1) NOT NULL DEFAULT 1,
			observed_hits INT UNSIGNED NOT NULL DEFAULT 0,
			total_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
			uncached_hits INT UNSIGNED NOT NULL DEFAULT 0,
			cached_hits INT UNSIGNED NOT NULL DEFAULT 0,
			last_searched DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY term (term),
			KEY searches (searches),
			KEY last_searched (last_searched)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		WPSQR_Index::install();

		update_option( 'wpsqr_db_version', WPSQR_DB_VERSION );
	}

	/** Run the installer again if the schema version moved. */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'wpsqr_db_version' ) === WPSQR_DB_VERSION ) {
			return;
		}

		self::install();
	}

	public static function drop() {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::cache_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::terms_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . WPSQR_Index::table() );
		// phpcs:enable

		delete_option( 'wpsqr_db_version' );
	}
}
