<?php
/**
 * Audit log of every trash / delete / restore action, with enough info to
 * understand (and, for trashed items, reverse) each change.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Logger {

	/**
	 * Log table name (with prefix).
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'acps_mc_log';
	}

	/**
	 * Create the log table on activation.
	 */
	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(20) NOT NULL DEFAULT '',
			filename VARCHAR(255) NOT NULL DEFAULT '',
			file_path TEXT NULL,
			folder_name VARCHAR(255) NOT NULL DEFAULT '',
			size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			restorable TINYINT(1) NOT NULL DEFAULT 0,
			details LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY attachment_id (attachment_id),
			KEY action (action),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Record an action.
	 *
	 * @param array $args {
	 *     @type int    $attachment_id
	 *     @type string $action        trash|delete|restore|delete_from_trash
	 *     @type string $filename
	 *     @type string $file_path
	 *     @type string $folder_name
	 *     @type int    $size_bytes
	 *     @type bool   $restorable
	 *     @type array  $details
	 * }
	 * @return int|false Inserted row id.
	 */
	public static function record( $args ) {
		global $wpdb;

		$defaults = array(
			'attachment_id' => 0,
			'action'        => '',
			'filename'      => '',
			'file_path'     => '',
			'folder_name'   => '',
			'size_bytes'    => 0,
			'restorable'    => 0,
			'details'       => array(),
		);
		$args = wp_parse_args( $args, $defaults );

		$ok = $wpdb->insert(
			self::table(),
			array(
				'attachment_id' => absint( $args['attachment_id'] ),
				'action'        => substr( (string) $args['action'], 0, 20 ),
				'filename'      => substr( (string) $args['filename'], 0, 255 ),
				'file_path'     => (string) $args['file_path'],
				'folder_name'   => substr( (string) $args['folder_name'], 0, 255 ),
				'size_bytes'    => absint( $args['size_bytes'] ),
				'user_id'       => get_current_user_id(),
				'restorable'    => $args['restorable'] ? 1 : 0,
				'details'       => wp_json_encode( $args['details'] ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Fetch recent log rows.
	 *
	 * @param int    $limit  Rows.
	 * @param string $action Optional action filter.
	 * @return array
	 */
	public static function recent( $limit = 200, $action = '' ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, absint( $limit ) );

		if ( $action ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT %d", $action, $limit ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
				ARRAY_A
			);
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark a previously-restorable row as no longer restorable (e.g. after the
	 * trashed attachment is permanently deleted or restored).
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function mark_unrestorable( $attachment_id ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array( 'restorable' => 0 ),
			array( 'attachment_id' => absint( $attachment_id ), 'restorable' => 1 ),
			array( '%d' ),
			array( '%d', '%d' )
		);
	}
}
