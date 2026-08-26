<?php
/**
 * Duplicate-file detection.
 *
 * Identifies attachments whose *file contents* are identical — not just
 * similar names — by hashing each file and comparing hashes. This catches
 * the common cases: the same photo uploaded twice with different filenames,
 * or WordPress auto-suffixing a re-upload of the same file as "photo-1.jpg",
 * "photo-2.jpg", etc.
 *
 * Every newly uploaded file is hashed automatically. Files that already
 * existed before this feature are hashed lazily, in small batches, when a
 * duplicate scan is run (see backfill_batch()).
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Duplicates {

	const META_KEY = '_acps_mc_filehash';

	/**
	 * Hash every new upload immediately, so newly-added files are always
	 * duplicate-checkable without waiting on a backfill pass.
	 */
	public function __construct() {
		add_action( 'add_attachment', array( __CLASS__, 'hash_on_upload' ) );
	}

	/**
	 * @param int $id Newly-inserted attachment ID.
	 */
	public static function hash_on_upload( $id ) {
		if ( 'attachment' !== get_post_type( $id ) ) {
			return;
		}
		self::hash_file( (int) $id );
	}

	/**
	 * Compute and store the content hash for one attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return string|false The hash, or false if it couldn't be computed
	 *                       (missing/unreadable/too-large file).
	 */
	public static function hash_file( $id ) {
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
			return false;
		}
		// Skip anything huge rather than tying up a request hashing it —
		// duplicate video/archive files are rare and can be scanned later
		// once file-hashing is moved to a background job, if ever needed.
		if ( filesize( $file ) > 200 * MB_IN_BYTES ) {
			return false;
		}
		$hash = md5_file( $file );
		if ( ! $hash ) {
			return false;
		}
		update_post_meta( $id, self::META_KEY, $hash );
		return $hash;
	}

	/**
	 * Hash a batch of not-yet-hashed attachments (pre-existing files that
	 * predate this feature, or anything hash_file() previously skipped).
	 *
	 * @param int $batch_size Max attachments to hash in this call.
	 * @return array { hashed: int, more: bool }
	 */
	public static function backfill_batch( $batch_size = 300 ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE p.post_type = 'attachment' AND p.post_status != 'trash' AND pm.meta_id IS NULL
				 LIMIT %d",
				self::META_KEY,
				$batch_size
			)
		);
		$hashed = 0;
		foreach ( $ids as $id ) {
			if ( false !== self::hash_file( (int) $id ) ) {
				++$hashed;
			}
		}
		return array(
			'hashed' => $hashed,
			'more'   => count( $ids ) === (int) $batch_size,
		);
	}

	/**
	 * Find an existing attachment with the same content hash as $id, if any.
	 *
	 * @param int    $id   The attachment to check (excluded from its own result).
	 * @param string $hash Its content hash.
	 * @return int Existing attachment ID, or 0 if none found.
	 */
	public static function find_duplicate( $id, $hash ) {
		global $wpdb;
		if ( ! $hash ) {
			return 0;
		}
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value = %s AND pm.post_id != %d
				   AND p.post_type = 'attachment' AND p.post_status != 'trash'
				 ORDER BY p.post_date ASC LIMIT 1",
				self::META_KEY,
				$hash,
				$id
			)
		);
		return $existing ? (int) $existing : 0;
	}

	/**
	 * All groups of 2+ attachments that share the same content hash.
	 *
	 * @param int $limit Max number of groups to return.
	 * @return array[] Each item: { hash: string, ids: int[] } — oldest file first.
	 */
	public static function groups( $limit = 200 ) {
		global $wpdb;
		$hashes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND p.post_type = 'attachment' AND p.post_status != 'trash'
				 GROUP BY pm.meta_value
				 HAVING COUNT(*) > 1
				 ORDER BY MIN(p.ID) DESC
				 LIMIT %d",
				self::META_KEY,
				$limit
			)
		);

		$groups = array();
		foreach ( $hashes as $hash ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT pm.post_id FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = %s AND pm.meta_value = %s
					   AND p.post_type = 'attachment' AND p.post_status != 'trash'
					 ORDER BY p.post_date ASC",
					self::META_KEY,
					$hash
				)
			);
			$ids = array_map( 'intval', $ids );
			if ( count( $ids ) < 2 ) {
				continue; // one of the copies was trashed since the hash was grouped
			}
			$groups[] = array( 'hash' => $hash, 'ids' => $ids );
		}
		return $groups;
	}

	/**
	 * Re-validate a "keep this one, trash the rest" request entirely on the
	 * server: every id involved must actually share the given hash. This is
	 * the same "never trust the browser" rule the rest of the plugin follows
	 * for deletion — it stops a tampered request from trashing unrelated
	 * files by claiming they're part of a duplicate group.
	 *
	 * @param string $hash  Expected shared content hash.
	 * @param int[]  $ids   Attachment ids the client claims share that hash.
	 * @return int[] The subset of $ids that actually match — safe to trash.
	 */
	public static function verify_group( $hash, $ids ) {
		global $wpdb;
		if ( ! $hash || empty( $ids ) ) {
			return array();
		}
		$ids  = array_map( 'intval', $ids );
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args = array_merge( array( self::META_KEY, $hash ), $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $in is a safe placeholder list, values are passed through prepare().
		$valid = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 WHERE pm.meta_key = %s AND pm.meta_value = %s AND pm.post_id IN ($in)",
				$args
			)
		);
		return array_map( 'intval', $valid );
	}
}
