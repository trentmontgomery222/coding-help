<?php
/**
 * Safe deletion.
 *
 * Every deletion is re-validated on the server against the latest scan results
 * and the safety settings before anything is touched. Nothing marked "used" can
 * ever be deleted here, even if a stale request asks for it.
 *
 * Default mode is reversible Trash (wp_trash_post): the database row is set to
 * 'trash' and the files stay on disk, so anything can be restored. Permanent
 * mode physically removes the files via wp_delete_attachment().
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Deleter {

	/** @var ACPS_MC_Folders */
	protected $folders;

	public function __construct() {
		$this->folders = new ACPS_MC_Folders();
	}

	/**
	 * Validate whether an attachment may be deleted right now.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $reason Filled with the block reason on failure.
	 * @return bool
	 */
	public function can_delete( $id, &$reason = '' ) {
		$id       = (int) $id;
		$settings = ACPS_MC_Settings::all();
		$results  = get_option( ACPS_MC_OPT_RESULTS, array() );

		if ( 'attachment' !== get_post_type( $id ) ) {
			$reason = __( 'Not a media attachment.', 'acps-media-cleanup' );
			return false;
		}

		// Must be present in results AND marked unused. This is the core guard:
		// we only ever delete something the scanner has explicitly cleared.
		if ( ! isset( $results[ $id ] ) ) {
			$reason = __( 'Not in the latest scan results — run a scan first.', 'acps-media-cleanup' );
			return false;
		}
		if ( ! empty( $results[ $id ]['used'] ) ) {
			$reason = __( 'This file is used on the site.', 'acps-media-cleanup' );
			return false;
		}

		// Never-delete list.
		if ( in_array( $id, array_map( 'intval', (array) $settings['excluded_ids'] ), true ) ) {
			$reason = __( 'On the protected (never delete) list.', 'acps-media-cleanup' );
			return false;
		}

		// Excluded extension.
		$ext = isset( $results[ $id ]['ext'] ) ? $results[ $id ]['ext'] : '';
		if ( $ext && in_array( $ext, array_map( 'strtolower', (array) $settings['excluded_extensions'] ), true ) ) {
			$reason = sprintf(
				/* translators: %s: file extension */
				__( 'The .%s file type is excluded from deletion.', 'acps-media-cleanup' ),
				$ext
			);
			return false;
		}

		// Excluded folder (including descendants).
		if ( ! empty( $settings['excluded_folders'] ) ) {
			$protected = array();
			foreach ( (array) $settings['excluded_folders'] as $fid ) {
				$protected = array_merge( $protected, $this->folders->descendants( (int) $fid ) );
			}
			if ( in_array( $this->folders->folder_for( $id ), array_map( 'intval', $protected ), true ) ) {
				$reason = __( 'In a protected folder.', 'acps-media-cleanup' );
				return false;
			}
		}

		// Protect recently uploaded files.
		$days = (int) $settings['protect_recent_days'];
		if ( $days > 0 ) {
			$post = get_post( $id );
			if ( $post ) {
				$age_days = ( time() - get_post_time( 'U', true, $post ) ) / DAY_IN_SECONDS;
				if ( $age_days < $days ) {
					$reason = sprintf(
						/* translators: %d: number of days */
						__( 'Uploaded within the last %d days (protected).', 'acps-media-cleanup' ),
						$days
					);
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Delete (trash or permanently) a set of attachment IDs.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @param bool  $ack Backup acknowledgement supplied.
	 * @return array {
	 *     @type int   $deleted Count succeeded.
	 *     @type int   $skipped Count blocked.
	 *     @type array $items   Per-id outcome.
	 *     @type string $mode   'trash' | 'permanent'
	 * }
	 */
	public function delete_ids( $ids, $ack = false ) {
		$settings  = ACPS_MC_Settings::all();
		$mode      = ( 'permanent' === $settings['delete_mode'] ) ? 'permanent' : 'trash';
		$need_ack  = ! empty( $settings['require_backup_ack'] );

		$out = array(
			'deleted' => 0,
			'skipped' => 0,
			'items'   => array(),
			'mode'    => $mode,
		);

		if ( $need_ack && ! $ack ) {
			$out['error'] = __( 'Please confirm you have a recent backup before deleting.', 'acps-media-cleanup' );
			return $out;
		}

		$results = get_option( ACPS_MC_OPT_RESULTS, array() );
		$meta    = get_option( ACPS_MC_OPT_SCANMETA, array() );
		$map     = $this->folders->attachment_folder_map();
		$folders = $this->folders->folders();

		foreach ( (array) $ids as $id ) {
			$id     = (int) $id;
			$reason = '';
			if ( ! $this->can_delete( $id, $reason ) ) {
				$out['skipped']++;
				$out['items'][ $id ] = array( 'ok' => false, 'reason' => $reason );
				continue;
			}

			$row       = isset( $results[ $id ] ) ? $results[ $id ] : array();
			$fid       = isset( $map[ $id ] ) ? (int) $map[ $id ] : ACPS_MC_Folders::UNCATEGORIZED;
			$folder_nm = isset( $folders[ $fid ]['name'] ) ? $folders[ $fid ]['name'] : '';
			$size      = isset( $row['size'] ) ? (int) $row['size'] : 0;
			$filename  = isset( $row['filename'] ) ? $row['filename'] : '';
			$filepath  = get_attached_file( $id );

			$success = false;
			if ( 'permanent' === $mode ) {
				$res     = wp_delete_attachment( $id, true );
				$success = ( false !== $res && null !== $res );
			} else {
				$res     = wp_trash_post( $id );
				$success = ( false !== $res && null !== $res );
			}

			if ( $success ) {
				$out['deleted']++;
				$out['items'][ $id ] = array( 'ok' => true );

				ACPS_MC_Logger::record(
					array(
						'attachment_id' => $id,
						'action'        => ( 'permanent' === $mode ) ? 'delete' : 'trash',
						'filename'      => $filename,
						'file_path'     => (string) $filepath,
						'folder_name'   => $folder_nm,
						'size_bytes'    => $size,
						'restorable'    => ( 'trash' === $mode ) ? 1 : 0,
						'details'       => array(
							'url'    => isset( $row['url'] ) ? $row['url'] : '',
							'mime'   => isset( $row['mime'] ) ? $row['mime'] : '',
							'reason' => 'unused',
						),
					)
				);

				// Keep results / counts in sync.
				if ( isset( $results[ $id ] ) ) {
					unset( $results[ $id ] );
				}
				if ( isset( $meta['counts'] ) ) {
					$meta['counts']['unused']       = max( 0, (int) $meta['counts']['unused'] - 1 );
					$meta['counts']['unused_bytes'] = max( 0, (int) $meta['counts']['unused_bytes'] - $size );
					$meta['counts']['attachments']  = max( 0, (int) $meta['counts']['attachments'] - 1 );
				}
			} else {
				$out['skipped']++;
				$out['items'][ $id ] = array( 'ok' => false, 'reason' => __( 'WordPress could not delete this file.', 'acps-media-cleanup' ) );
			}
		}

		update_option( ACPS_MC_OPT_RESULTS, $results, false );
		update_option( ACPS_MC_OPT_SCANMETA, $meta, false );

		return $out;
	}

	/**
	 * Restore a trashed attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public function restore( $id ) {
		$id = (int) $id;
		if ( 'attachment' !== get_post_type( $id ) || 'trash' !== get_post_status( $id ) ) {
			return false;
		}
		$res = wp_untrash_post( $id );
		// wp_untrash_post may restore to 'draft'; attachments should be 'inherit'.
		if ( $res && 'inherit' !== get_post_status( $id ) ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => 'inherit' ) );
		}
		if ( $res ) {
			ACPS_MC_Logger::mark_unrestorable( $id );
			ACPS_MC_Logger::record(
				array(
					'attachment_id' => $id,
					'action'        => 'restore',
					'filename'      => wp_basename( (string) get_attached_file( $id ) ),
					'restorable'    => 0,
				)
			);
			return true;
		}
		return false;
	}

	/**
	 * Permanently delete a trashed attachment (empty from trash).
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public function delete_trashed( $id ) {
		$id = (int) $id;
		if ( 'attachment' !== get_post_type( $id ) || 'trash' !== get_post_status( $id ) ) {
			return false;
		}
		$filename = wp_basename( (string) get_attached_file( $id ) );
		$res      = wp_delete_attachment( $id, true );
		if ( false !== $res && null !== $res ) {
			ACPS_MC_Logger::mark_unrestorable( $id );
			ACPS_MC_Logger::record(
				array(
					'attachment_id' => $id,
					'action'        => 'delete_from_trash',
					'filename'      => $filename,
					'restorable'    => 0,
				)
			);
			return true;
		}
		return false;
	}

	/**
	 * List currently-trashed attachments (for the Trash tab).
	 *
	 * @return array
	 */
	public function trashed_items() {
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'trash',
				'posts_per_page' => 500,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'       => $p->ID,
				'filename' => wp_basename( (string) get_attached_file( $p->ID ) ),
				'title'    => $p->post_title,
				'mime'     => get_post_mime_type( $p->ID ),
				'date'     => get_the_modified_date( 'Y-m-d', $p ),
			);
		}
		return $out;
	}
}
