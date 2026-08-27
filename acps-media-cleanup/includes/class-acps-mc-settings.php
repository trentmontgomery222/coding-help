<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Settings {

	/**
	 * Default settings. Every default is chosen to be the SAFEST option.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// 'trash' = reversible (wp_trash_post, files stay on disk).
			// 'permanent' = wp_delete_attachment( true ) removes files for good.
			'delete_mode'             => 'trash',

			// Never allow deletion of media uploaded within this many days.
			'protect_recent_days'     => 30,

			// Treat an attachment as USED if it is attached to a live post.
			'treat_attached_as_used'  => 1,

			// Treat a custom-field value that is exactly an attachment ID as USED
			// (covers ACF and similar "return ID" image fields).
			'treat_id_meta_as_used'   => 1,

			// Also scan active theme + child theme files for filename references
			// (catches images hard-coded in templates / CSS).
			'scan_theme_files'        => 1,

			// Also scan the Beaver Builder CSS cache folder in uploads.
			'scan_builder_cache'      => 1,

			// Attachment IDs that must never be deleted.
			'excluded_ids'            => array(),

			// Folder IDs whose files must never be deleted.
			'excluded_folders'        => array(),

			// File extensions (lowercase, no dot) that must never be deleted.
			'excluded_extensions'     => array(),

			// How many attachments to process per AJAX request.
			'batch_size'              => 40,

			// Require the "I have a backup" acknowledgement before deleting.
			'require_backup_ack'      => 1,

			// Make the Media Manager the default screen for the Media menu.
			'replace_media_screen'    => 1,

			// Run the usage scan automatically once a day (around 2am).
			'auto_nightly_scan'       => 1,

			// Convert HEIC/HEIF uploads to JPEG automatically (if supported).
			'convert_heic_on_upload'  => 1,

			// --- Google Drive drip-importer (all off / empty by default) ---
			// PULL: WordPress downloads from a shared Drive folder on a schedule.
			'drive_pull_enabled'      => 0,
			'drive_folder_id'         => '',   // Drive source folder ID
			'drive_service_account'   => '',   // service-account JSON key (pull auth)
			// PUSH: a Google Apps Script posts files to our REST endpoint.
			'drive_push_token'        => '',   // shared secret for the push endpoint
			// Common:
			'drive_target_folder'     => 0,    // FileBird folder to file imports into
			'drive_skip_duplicates'   => 1,
			// Throttle (files per 5-minute tick, for the PULL path):
			'drive_day_rate'          => 3,
			'drive_night_rate'        => 40,
			'drive_day_start'         => 7,    // day window start hour (0-23)
			'drive_night_start'       => 20,   // night window start hour (0-23)
		);
	}

	/**
	 * Get all settings, merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( ACPS_MC_OPT_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Seed defaults on activation without clobbering existing settings.
	 */
	public static function install_defaults() {
		if ( false === get_option( ACPS_MC_OPT_SETTINGS, false ) ) {
			add_option( ACPS_MC_OPT_SETTINGS, self::defaults() );
		}
	}

	/**
	 * Sanitise a raw settings array (typically $_POST).
	 *
	 * @param array $input Raw input.
	 * @return array Clean settings ready to save.
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$clean    = self::all();

		$clean['delete_mode'] = ( isset( $input['delete_mode'] ) && 'permanent' === $input['delete_mode'] )
			? 'permanent'
			: 'trash';

		$clean['protect_recent_days'] = isset( $input['protect_recent_days'] )
			? max( 0, absint( $input['protect_recent_days'] ) )
			: $defaults['protect_recent_days'];

		$clean['batch_size'] = isset( $input['batch_size'] )
			? min( 200, max( 5, absint( $input['batch_size'] ) ) )
			: $defaults['batch_size'];

		foreach ( array(
			'treat_attached_as_used',
			'treat_id_meta_as_used',
			'scan_theme_files',
			'scan_builder_cache',
			'require_backup_ack',
			'replace_media_screen',
			'auto_nightly_scan',
			'convert_heic_on_upload',
			'drive_pull_enabled',
			'drive_skip_duplicates',
		) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] ) ? 1 : 0;
		}

		// --- Google Drive importer ---
		if ( isset( $input['drive_folder_id'] ) ) {
			// Accept a full Drive folder URL or a bare ID; extract the ID.
			$raw = trim( (string) $input['drive_folder_id'] );
			if ( preg_match( '#/folders/([A-Za-z0-9_-]+)#', $raw, $m ) ) {
				$raw = $m[1];
			}
			$clean['drive_folder_id'] = preg_replace( '/[^A-Za-z0-9_-]/', '', $raw );
		}
		if ( isset( $input['drive_service_account'] ) ) {
			// Store the JSON key as-is (validated on use); trim whitespace only.
			// The caller already unslashed $_POST, so do NOT unslash again here or
			// the private key's \n escape sequences would be mangled.
			$clean['drive_service_account'] = trim( (string) $input['drive_service_account'] );
		}
		if ( isset( $input['drive_push_token'] ) ) {
			$clean['drive_push_token'] = trim( sanitize_text_field( $input['drive_push_token'] ) );
		}
		if ( isset( $input['drive_target_folder'] ) ) {
			$clean['drive_target_folder'] = max( 0, absint( $input['drive_target_folder'] ) );
		}
		foreach ( array(
			'drive_day_rate'   => array( 0, 500 ),
			'drive_night_rate' => array( 0, 1000 ),
			'drive_day_start'  => array( 0, 23 ),
			'drive_night_start' => array( 0, 23 ),
		) as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = min( $range[1], max( $range[0], absint( $input[ $key ] ) ) );
			}
		}

		// Excluded extensions: comma / space separated -> array of clean tokens.
		if ( isset( $input['excluded_extensions'] ) ) {
			$raw   = is_array( $input['excluded_extensions'] ) ? implode( ',', $input['excluded_extensions'] ) : $input['excluded_extensions'];
			$parts = preg_split( '/[\s,]+/', strtolower( (string) $raw ), -1, PREG_SPLIT_NO_EMPTY );
			$exts  = array();
			foreach ( (array) $parts as $p ) {
				$p = ltrim( preg_replace( '/[^a-z0-9]/', '', $p ), '.' );
				if ( '' !== $p ) {
					$exts[] = $p;
				}
			}
			$clean['excluded_extensions'] = array_values( array_unique( $exts ) );
		}

		// Excluded folder IDs.
		if ( isset( $input['excluded_folders'] ) ) {
			$clean['excluded_folders'] = array_values( array_unique( array_map( 'intval', (array) $input['excluded_folders'] ) ) );
		}

		// excluded_ids are managed via the results screen, not this form; keep as-is.

		return $clean;
	}

	/**
	 * Add an attachment ID to the never-delete list.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function add_excluded_id( $id ) {
		$all = self::all();
		$id  = absint( $id );
		if ( $id && ! in_array( $id, $all['excluded_ids'], true ) ) {
			$all['excluded_ids'][] = $id;
			update_option( ACPS_MC_OPT_SETTINGS, $all );
		}
	}

	/**
	 * Remove an attachment ID from the never-delete list.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function remove_excluded_id( $id ) {
		$all = self::all();
		$id  = absint( $id );
		$all['excluded_ids'] = array_values( array_diff( $all['excluded_ids'], array( $id ) ) );
		update_option( ACPS_MC_OPT_SETTINGS, $all );
	}
}
