<?php
/**
 * Database schema: table names, DDL, and version-checked upgrades.
 *
 * All tables use the site's own $wpdb->prefix (spec §3). This is a single-site
 * plugin — there are deliberately NO blog_id columns anywhere.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema manager.
 */
class Schema {

	/**
	 * Fully-qualified table name for a logical table key.
	 *
	 * @param string $key One of: sessions, visits, forms, entries, entry_values, entry_notes.
	 * @return string
	 */
	public static function table( $key ) {
		global $wpdb;
		return $wpdb->prefix . 'acps_' . $key;
	}

	/**
	 * Run dbDelta for every table. Safe to call repeatedly — dbDelta only
	 * applies differences, so this doubles as the upgrade routine.
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::table_ddl( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		// Belt-and-braces: dbDelta occasionally won't add a new column to an
		// existing table. Add the newer columns explicitly if still missing, so
		// submissions can never fail on an "unknown column".
		self::ensure_columns();

		update_option( ACPS_ST_OPT_SCHEMA, ACPS_ST_SCHEMA_VERSION );
	}

	/**
	 * Ensure newer columns exist on already-created tables (explicit ALTERs,
	 * independent of dbDelta).
	 */
	private static function ensure_columns() {
		self::add_column_if_missing( self::table( 'entries' ), 'visitor_uid', 'CHAR(36) DEFAULT NULL' );
		self::add_column_if_missing( self::table( 'visitors' ), 'name', 'VARCHAR(191) DEFAULT NULL' );
		self::add_column_if_missing( self::table( 'visitors' ), 'notes', 'TEXT DEFAULT NULL' );
		self::add_column_if_missing( self::table( 'visitors' ), 'last_ip', 'VARCHAR(64) DEFAULT NULL' );
		self::add_column_if_missing( self::table( 'visitors' ), 'user_id', 'BIGINT UNSIGNED DEFAULT NULL' );
		self::add_column_if_missing( self::table( 'sessions' ), 'visitor_uid', 'CHAR(36) DEFAULT NULL' );
	}

	/**
	 * Add a column to a table if it isn't there. No-op if the table is absent.
	 *
	 * @param string $table Fully-qualified table name.
	 * @param string $col   Column name.
	 * @param string $def   Column definition SQL.
	 */
	private static function add_column_if_missing( $table, $col, $def ) {
		global $wpdb;
		// Confirm the table exists first.
		$exists_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
		if ( ! $exists_table ) {
			return;
		}
		$has = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $col ) ); // phpcs:ignore WordPress.DB
		if ( ! $has ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$def}" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Compare the stored schema version to the current one and upgrade on load
	 * if they differ. Called on every request (cheaply) so schema changes apply
	 * without a deactivate/reactivate cycle (spec §11).
	 */
	public static function maybe_upgrade() {
		$stored = get_option( ACPS_ST_OPT_SCHEMA );
		if ( ACPS_ST_SCHEMA_VERSION !== $stored ) {
			self::install();
		}
	}

	/**
	 * Drop every plugin table. Used by uninstall when data preservation is off.
	 */
	public static function drop_all() {
		global $wpdb;
		foreach ( array( 'entry_notes', 'entry_values', 'entries', 'forms', 'visits', 'sessions', 'visitors' ) as $key ) {
			$table = self::table( $key );
			// Table identifiers can't be parameterized; the name is built from a
			// fixed whitelist + the trusted site prefix, so this is safe.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * The CREATE TABLE statements, formatted for dbDelta.
	 *
	 * @param string $charset_collate Charset/collate clause.
	 * @return string[]
	 */
	private static function table_ddl( $charset_collate ) {
		$sessions     = self::table( 'sessions' );
		$visits       = self::table( 'visits' );
		$forms        = self::table( 'forms' );
		$entries      = self::table( 'entries' );
		$entry_values = self::table( 'entry_values' );
		$entry_notes  = self::table( 'entry_notes' );
		$visitors     = self::table( 'visitors' );

		$ddl = array();

		// --- Unique visitors ------------------------------------------------
		// One row per persistent first-party ID (per browser). UNIQUE(uid)
		// guarantees no duplicates; registered on first page load so nobody is
		// missed. Multi-browser counts as separate users by design.
		$ddl[] = "CREATE TABLE {$visitors} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uid CHAR(36) NOT NULL,
			name VARCHAR(191) DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			last_ip VARCHAR(64) DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uid (uid),
			KEY first_seen (first_seen),
			KEY name (name)
		) {$charset_collate};";

		// --- Sessions (spec §3.1) -------------------------------------------
		$ddl[] = "CREATE TABLE {$sessions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_token CHAR(40) NOT NULL,
			started_at DATETIME NOT NULL,
			last_activity_at DATETIME NOT NULL,
			ip_anon VARCHAR(64) DEFAULT NULL,
			user_agent_summary VARCHAR(191) DEFAULT NULL,
			device_type VARCHAR(20) DEFAULT NULL,
			viewport VARCHAR(20) DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			entry_page_id BIGINT UNSIGNED DEFAULT NULL,
			entry_url TEXT DEFAULT NULL,
			referrer TEXT DEFAULT NULL,
			consent TINYINT(1) NOT NULL DEFAULT 0,
			visitor_uid CHAR(36) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_token (session_token),
			KEY last_activity_at (last_activity_at),
			KEY user_id (user_id),
			KEY visitor_uid (visitor_uid)
		) {$charset_collate};";

		// --- Page visits (spec §3.2) ----------------------------------------
		$ddl[] = "CREATE TABLE {$visits} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			post_id BIGINT UNSIGNED DEFAULT NULL,
			url TEXT NOT NULL,
			title VARCHAR(255) DEFAULT NULL,
			visited_at DATETIME NOT NULL,
			seq_index INT UNSIGNED NOT NULL DEFAULT 1,
			prev_post_id BIGINT UNSIGNED DEFAULT NULL,
			time_on_page INT UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY post_id (post_id),
			KEY visited_at (visited_at)
		) {$charset_collate};";

		// --- Forms (spec §3.3 / §3.4) ---------------------------------------
		// Field definitions and settings are stored as JSON blobs (LONGTEXT).
		$ddl[] = "CREATE TABLE {$forms} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			slug VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			is_feedback TINYINT(1) NOT NULL DEFAULT 0,
			fields LONGTEXT DEFAULT NULL,
			settings LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			modified_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY status (status)
		) {$charset_collate};";

		// --- Entries (spec §3.5) --------------------------------------------
		$ddl[] = "CREATE TABLE {$entries} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT UNSIGNED NOT NULL,
			submitted_at DATETIME NOT NULL,
			session_id BIGINT UNSIGNED DEFAULT NULL,
			visitor_uid CHAR(36) DEFAULT NULL,
			page_id BIGINT UNSIGNED DEFAULT NULL,
			page_url TEXT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			assigned_to BIGINT UNSIGNED DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			ip_anon VARCHAR(64) DEFAULT NULL,
			user_agent_summary VARCHAR(191) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY session_id (session_id),
			KEY visitor_uid (visitor_uid),
			KEY status (status),
			KEY submitted_at (submitted_at)
		) {$charset_collate};";

		// --- Entry values (spec §3.6) ---------------------------------------
		$ddl[] = "CREATE TABLE {$entry_values} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id BIGINT UNSIGNED NOT NULL,
			field_key VARCHAR(191) NOT NULL,
			value LONGTEXT DEFAULT NULL,
			value_serialized LONGTEXT DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY entry_id (entry_id),
			KEY field_key (field_key)
		) {$charset_collate};";

		// --- Entry notes (spec §3.7) ----------------------------------------
		$ddl[] = "CREATE TABLE {$entry_notes} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id BIGINT UNSIGNED NOT NULL,
			author_id BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			note TEXT NOT NULL,
			PRIMARY KEY  (id),
			KEY entry_id (entry_id)
		) {$charset_collate};";

		return $ddl;
	}
}
