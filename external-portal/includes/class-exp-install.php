<?php
/**
 * Installation, database schema and upgrades.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the portal's own database tables (spec Section 4).
 *
 * These tables are entirely separate from wp_users. Portal users live only here.
 */
class EXP_Install {

	/**
	 * Fully-qualified table names, keyed by short name.
	 *
	 * @return array<string,string>
	 */
	public static function tables() {
		global $wpdb;
		$p = $wpdb->prefix . 'portal_';
		return array(
			'users'      => $p . 'users',
			'otp'        => $p . 'otp_codes',
			'sessions'   => $p . 'sessions',
			'grants'     => $p . 'grants',
			'queue'      => $p . 'queue',
			'audit'      => $p . 'audit',
			'extensions' => $p . 'extensions',
		);
	}

	/**
	 * Convenience accessor for a single table name.
	 *
	 * @param string $key Short table key.
	 * @return string
	 */
	public static function table( $key ) {
		$tables = self::tables();
		return isset( $tables[ $key ] ) ? $tables[ $key ] : '';
	}

	/**
	 * Run on activation.
	 */
	public static function activate() {
		self::create_tables();
		self::seed_default_settings();
		update_option( EXP_OPT_DB_VERSION, EXP_DB_VERSION );

		// Housekeeping cron for expired sessions / codes.
		if ( ! wp_next_scheduled( 'exp_cron_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'exp_cron_cleanup' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Run on deactivation. Intentionally non-destructive (keeps data).
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'exp_cron_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'exp_cron_cleanup' );
		}
		flush_rewrite_rules();
	}

	/**
	 * Upgrade the schema if the stored DB version is behind.
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( EXP_OPT_DB_VERSION, 0 );
		if ( $installed < EXP_DB_VERSION ) {
			self::create_tables();
			self::seed_default_settings();
			update_option( EXP_OPT_DB_VERSION, EXP_DB_VERSION );
		}
	}

	/**
	 * Create/upgrade all tables via dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$t               = self::tables();

		// 1. Portal Users.
		$sql = "CREATE TABLE {$t['users']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			display_name VARCHAR(190) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'invited',
			password_hash VARCHAR(255) NULL DEFAULT NULL,
			auth_mode VARCHAR(20) NOT NULL DEFAULT 'otp',
			failed_logins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			locked_until DATETIME NULL DEFAULT NULL,
			last_login_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status (status)
		) {$charset_collate};";
		dbDelta( $sql );

		// 2. Portal OTP Codes.
		$sql = "CREATE TABLE {$t['otp']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			code_hash VARCHAR(64) NOT NULL,
			purpose VARCHAR(30) NOT NULL DEFAULT 'login',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			used TINYINT(1) NOT NULL DEFAULT 0,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";
		dbDelta( $sql );

		// 3. Portal Sessions.
		$sql = "CREATE TABLE {$t['sessions']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			token_hash VARCHAR(64) NOT NULL,
			csrf_token VARCHAR(64) NOT NULL DEFAULT '',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			revoked TINYINT(1) NOT NULL DEFAULT 0,
			last_activity_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";
		dbDelta( $sql );

		// 4. Portal Permissions / Grants. Target is structured text (nullable/global caps allowed).
		$sql = "CREATE TABLE {$t['grants']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			capability VARCHAR(100) NOT NULL,
			target VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_cap_target (user_id, capability, target),
			KEY capability (capability)
		) {$charset_collate};";
		dbDelta( $sql );

		// 5. Content Update Queue (shared by every module + third-party plugins).
		$sql = "CREATE TABLE {$t['queue']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(50) NOT NULL,
			content_ref VARCHAR(191) NOT NULL DEFAULT '',
			payload LONGTEXT NULL,
			submitted_by BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			admin_notes TEXT NULL,
			reviewed_by BIGINT UNSIGNED NULL DEFAULT NULL,
			reviewed_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY status (status),
			KEY submitted_by (submitted_by)
		) {$charset_collate};";
		dbDelta( $sql );

		// Audit log (spec Section 8 Q6).
		$sql = "CREATE TABLE {$t['audit']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_type VARCHAR(20) NOT NULL DEFAULT 'portal',
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event VARCHAR(60) NOT NULL,
			object_ref VARCHAR(191) NOT NULL DEFAULT '',
			detail TEXT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event (event),
			KEY actor (actor_type, actor_id),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $sql );

		// Approved-extensions registry (spec Section 7, approval-gate decision).
		$sql = "CREATE TABLE {$t['extensions']} (
			slug VARCHAR(100) NOT NULL,
			approved TINYINT(1) NOT NULL DEFAULT 0,
			label VARCHAR(191) NOT NULL DEFAULT '',
			source_plugin VARCHAR(191) NOT NULL DEFAULT '',
			first_seen_at DATETIME NOT NULL,
			approved_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (slug)
		) {$charset_collate};";
		dbDelta( $sql );
	}

	/**
	 * Populate default settings without overwriting existing ones.
	 */
	public static function seed_default_settings() {
		$existing = get_option( EXP_OPT_SETTINGS, array() );
		$defaults = self::default_settings();
		update_option( EXP_OPT_SETTINGS, wp_parse_args( $existing, $defaults ) );
	}

	/**
	 * Default plugin settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings() {
		return array(
			// Auth / session.
			'otp_length'                 => 6,
			'otp_ttl_minutes'            => 10,
			'otp_max_attempts'           => 5,
			'session_idle_minutes'       => 30,
			'session_absolute_hours'     => 12,
			'session_warn_seconds'       => 120,
			'login_lockout_threshold'    => 5,
			'login_lockout_minutes'      => 15,
			'password_min_length'        => 12,

			// Pages that host the shortcodes (page IDs) — used for redirects.
			'login_page_id'              => 0,
			'dashboard_page_id'          => 0,

			// Notifications.
			'admin_notify_email'         => get_option( 'admin_email' ),
			'notify_on_new_queue_item'   => 1,
			'email_from_name'            => get_bloginfo( 'name' ),

			// Governance decisions (Section 8).
			'calendar_requires_approval' => 0, // Q1: default LIVE.
			'extensions_require_approval' => 1, // Q3: default approval-gated.

			// Google integration (credentials stored separately/encrypted-at-rest-ish).
			'google_service_account'     => '', // JSON, base64 at rest.
			'google_impersonate_user'    => '', // Optional domain-wide delegation subject.
			'google_calendar_whitelist'  => array(), // [ ['id'=>..., 'label'=>...], ... ].
		);
	}
}
