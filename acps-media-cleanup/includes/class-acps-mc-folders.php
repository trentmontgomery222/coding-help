<?php
/**
 * Media folder adapter.
 *
 * Understands FileBird (custom tables in v4/v5, taxonomy in older versions).
 * Falls back gracefully to date-based "virtual" folders when no folder plugin
 * is present, so the folder layout always works.
 *
 * A folder is represented as:
 *   array( 'id' => int, 'name' => string, 'parent' => int )
 * Special virtual folders use negative IDs so they never collide with real ones:
 *   -1  => "Uncategorized" (attachments with no folder)
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Folders {

	const UNCATEGORIZED = -1;

	/** @var string One of: filebird_table, filebird_tax, dates */
	protected $backend;

	/** @var array|null Cached folders keyed by id. */
	protected $folders = null;

	/** @var array|null Cached attachment_id => folder_id map. */
	protected $map = null;

	public function __construct() {
		$this->backend = $this->detect_backend();
	}

	/**
	 * Human label for the detected backend (for the UI).
	 *
	 * @return string
	 */
	public function backend_label() {
		switch ( $this->backend ) {
			case 'filebird_table':
			case 'filebird_tax':
				return 'FileBird';
			default:
				return __( 'Upload date (no folder plugin detected)', 'acps-media-cleanup' );
		}
	}

	/**
	 * Detect which folder backend is available.
	 *
	 * @return string
	 */
	protected function detect_backend() {
		global $wpdb;

		$fbv = $wpdb->prefix . 'fbv';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fbv ) ) === $fbv ) {
			return 'filebird_table';
		}
		if ( taxonomy_exists( 'filebird_folder' ) ) {
			return 'filebird_tax';
		}
		return 'dates';
	}

	/**
	 * Get all folders keyed by id: array( id => array(id,name,parent) ).
	 * Always includes the Uncategorized bucket.
	 *
	 * @return array
	 */
	public function folders() {
		if ( null !== $this->folders ) {
			return $this->folders;
		}

		$folders = array();

		if ( 'filebird_table' === $this->backend ) {
			$folders = $this->folders_from_fbv_table();
		} elseif ( 'filebird_tax' === $this->backend ) {
			$folders = $this->folders_from_taxonomy();
		} else {
			$folders = $this->folders_from_dates();
		}

		// Uncategorized bucket (real folder backends only; date backend has its own buckets).
		if ( 'dates' !== $this->backend ) {
			$folders[ self::UNCATEGORIZED ] = array(
				'id'     => self::UNCATEGORIZED,
				'name'   => __( 'Uncategorized', 'acps-media-cleanup' ),
				'parent' => 0,
			);
		}

		$this->folders = $folders;
		return $folders;
	}

	/**
	 * FileBird v4/v5 custom-table folders.
	 *
	 * @return array
	 */
	protected function folders_from_fbv_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'fbv';
		$out   = array();

		// FileBird stores folders with type=0 (0 = attachment/media context).
		$rows = $wpdb->get_results( "SELECT id, name, parent FROM {$table}", ARRAY_A );
		if ( $rows ) {
			foreach ( $rows as $r ) {
				$id = (int) $r['id'];
				if ( $id <= 0 ) {
					continue;
				}
				$out[ $id ] = array(
					'id'     => $id,
					'name'   => (string) $r['name'],
					'parent' => max( 0, (int) $r['parent'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Older FileBird taxonomy folders.
	 *
	 * @return array
	 */
	protected function folders_from_taxonomy() {
		$out   = array();
		$terms = get_terms(
			array(
				'taxonomy'   => 'filebird_folder',
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[ (int) $t->term_id ] = array(
					'id'     => (int) $t->term_id,
					'name'   => $t->name,
					'parent' => (int) $t->parent,
				);
			}
		}
		return $out;
	}

	/**
	 * Virtual folders based on the upload year/month of each attachment.
	 * Folder IDs are synthetic (100000*year + month) so they stay stable.
	 *
	 * @return array
	 */
	protected function folders_from_dates() {
		global $wpdb;
		$out = array();

		$files = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
		);
		foreach ( (array) $files as $rel ) {
			if ( preg_match( '#^(\d{4})/(\d{2})/#', (string) $rel, $m ) ) {
				$year  = (int) $m[1];
				$month = (int) $m[2];
				$yid   = $year * 100000; // Year node.
				$mid   = $year * 100000 + $month;
				if ( ! isset( $out[ $yid ] ) ) {
					$out[ $yid ] = array( 'id' => $yid, 'name' => (string) $year, 'parent' => 0 );
				}
				if ( ! isset( $out[ $mid ] ) ) {
					$out[ $mid ] = array(
						'id'     => $mid,
						'name'   => date_i18n( 'F', mktime( 0, 0, 0, $month, 1 ) ),
						'parent' => $yid,
					);
				}
			}
		}
		// A bucket for anything without a recognisable date path.
		$out[ self::UNCATEGORIZED ] = array(
			'id'     => self::UNCATEGORIZED,
			'name'   => __( 'Other / undated', 'acps-media-cleanup' ),
			'parent' => 0,
		);
		return $out;
	}

	/**
	 * Map of attachment_id => folder_id for every attachment.
	 *
	 * @return array
	 */
	public function attachment_folder_map() {
		if ( null !== $this->map ) {
			return $this->map;
		}
		global $wpdb;
		$map = array();

		if ( 'filebird_table' === $this->backend ) {
			$rel = $wpdb->prefix . 'fbv_attachment_folder';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rel ) ) === $rel ) {
				$rows = $wpdb->get_results( "SELECT attachment_id, folder_id FROM {$rel}", ARRAY_A );
				foreach ( (array) $rows as $r ) {
					$map[ (int) $r['attachment_id'] ] = (int) $r['folder_id'];
				}
			}
		} elseif ( 'filebird_tax' === $this->backend ) {
			$rows = $wpdb->get_results(
				"SELECT tr.object_id, tt.term_id
				 FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.taxonomy = 'filebird_folder'",
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$map[ (int) $r['object_id'] ] = (int) $r['term_id'];
			}
		} else {
			$rows = $wpdb->get_results(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'",
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				if ( preg_match( '#^(\d{4})/(\d{2})/#', (string) $r['meta_value'], $m ) ) {
					$map[ (int) $r['post_id'] ] = ( (int) $m[1] ) * 100000 + (int) $m[2];
				} else {
					$map[ (int) $r['post_id'] ] = self::UNCATEGORIZED;
				}
			}
		}

		$this->map = $map;
		return $map;
	}

	/**
	 * Folder id for one attachment (defaults to Uncategorized).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	public function folder_for( $attachment_id ) {
		$map = $this->attachment_folder_map();
		return isset( $map[ $attachment_id ] ) ? (int) $map[ $attachment_id ] : self::UNCATEGORIZED;
	}

	/**
	 * All descendant folder ids of a folder (inclusive of the folder itself).
	 *
	 * @param int $folder_id Folder ID.
	 * @return int[]
	 */
	public function descendants( $folder_id ) {
		$folder_id = (int) $folder_id;
		$folders   = $this->folders();
		$children  = array();
		foreach ( $folders as $f ) {
			$children[ (int) $f['parent'] ][] = (int) $f['id'];
		}

		$out   = array( $folder_id );
		$stack = array( $folder_id );
		while ( $stack ) {
			$cur = array_pop( $stack );
			if ( isset( $children[ $cur ] ) ) {
				foreach ( $children[ $cur ] as $c ) {
					$out[]   = $c;
					$stack[] = $c;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Build a display tree: ordered list of folders with depth, each carrying
	 * aggregate counts computed from the supplied results set.
	 *
	 * @param array $results Scan results keyed by attachment id.
	 * @return array List of array( id, name, depth, total, unused, unused_bytes ).
	 */
	public function tree_with_counts( $results ) {
		$folders = $this->folders();
		$map     = $this->attachment_folder_map();

		// Direct (non-recursive) tallies per folder.
		$direct = array();
		foreach ( $folders as $fid => $f ) {
			$direct[ $fid ] = array( 'total' => 0, 'unused' => 0, 'unused_bytes' => 0 );
		}
		foreach ( $results as $id => $row ) {
			$fid = isset( $map[ $id ] ) ? (int) $map[ $id ] : self::UNCATEGORIZED;
			if ( ! isset( $direct[ $fid ] ) ) {
				// Attachment maps to a folder we don't know about; bucket it.
				$fid = self::UNCATEGORIZED;
				if ( ! isset( $direct[ $fid ] ) ) {
					$direct[ $fid ] = array( 'total' => 0, 'unused' => 0, 'unused_bytes' => 0 );
					$folders[ $fid ] = array( 'id' => $fid, 'name' => __( 'Uncategorized', 'acps-media-cleanup' ), 'parent' => 0 );
				}
			}
			$direct[ $fid ]['total']++;
			if ( empty( $row['used'] ) ) {
				$direct[ $fid ]['unused']++;
				$direct[ $fid ]['unused_bytes'] += isset( $row['size'] ) ? (int) $row['size'] : 0;
			}
		}

		// Recursive roll-up.
		$children = array();
		foreach ( $folders as $f ) {
			$children[ (int) $f['parent'] ][] = (int) $f['id'];
		}

		$agg = array();
		$compute = function( $fid ) use ( &$compute, &$agg, $direct, $children ) {
			$t = isset( $direct[ $fid ] ) ? $direct[ $fid ] : array( 'total' => 0, 'unused' => 0, 'unused_bytes' => 0 );
			if ( ! empty( $children[ $fid ] ) ) {
				foreach ( $children[ $fid ] as $c ) {
					$ct = $compute( $c );
					$t['total']        += $ct['total'];
					$t['unused']       += $ct['unused'];
					$t['unused_bytes'] += $ct['unused_bytes'];
				}
			}
			$agg[ $fid ] = $t;
			return $t;
		};

		// Ordered, depth-aware output starting from roots (parent 0).
		$out = array();
		$walk = function( $parent, $depth ) use ( &$walk, &$out, $children, $folders, &$agg, $compute ) {
			if ( empty( $children[ $parent ] ) ) {
				return;
			}
			// Sort siblings by name, but keep Uncategorized last.
			$sibs = $children[ $parent ];
			usort( $sibs, function( $a, $b ) use ( $folders ) {
				if ( ACPS_MC_Folders::UNCATEGORIZED === $a ) { return 1; }
				if ( ACPS_MC_Folders::UNCATEGORIZED === $b ) { return -1; }
				$na = isset( $folders[ $a ]['name'] ) ? $folders[ $a ]['name'] : '';
				$nb = isset( $folders[ $b ]['name'] ) ? $folders[ $b ]['name'] : '';
				return strcasecmp( $na, $nb );
			} );
			foreach ( $sibs as $fid ) {
				if ( ! isset( $agg[ $fid ] ) ) {
					$compute( $fid );
				}
				$out[] = array(
					'id'           => $fid,
					'name'         => isset( $folders[ $fid ]['name'] ) ? $folders[ $fid ]['name'] : ( '#' . $fid ),
					'depth'        => $depth,
					'total'        => $agg[ $fid ]['total'],
					'unused'       => $agg[ $fid ]['unused'],
					'unused_bytes' => $agg[ $fid ]['unused_bytes'],
				);
				$walk( $fid, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		return $out;
	}

	/**
	 * Which backend key is active (filebird_table | filebird_tax | dates).
	 *
	 * @return string
	 */
	public function backend() {
		return $this->backend;
	}

	/**
	 * True when folders can be written (files moved between them).
	 *
	 * @return bool
	 */
	public function is_writable() {
		return in_array( $this->backend, array( 'filebird_table', 'filebird_tax' ), true );
	}

	/**
	 * Clear cached folder/map data after a write.
	 */
	public function flush_cache() {
		$this->folders = null;
		$this->map     = null;
	}

	/**
	 * Build a depth-ordered folder tree with total attachment counts (all
	 * files, not just unused). Used by the media manager sidebar.
	 *
	 * @return array List of array( id, name, depth, total ).
	 */
	public function tree_all_counts() {
		global $wpdb;
		$folders = $this->folders();
		$map     = $this->attachment_folder_map();

		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash'"
		);

		$direct = array();
		foreach ( $folders as $fid => $f ) {
			$direct[ $fid ] = 0;
		}
		foreach ( (array) $ids as $id ) {
			$fid = isset( $map[ (int) $id ] ) ? (int) $map[ (int) $id ] : self::UNCATEGORIZED;
			if ( ! isset( $direct[ $fid ] ) ) {
				$fid = self::UNCATEGORIZED;
				if ( ! isset( $direct[ $fid ] ) ) {
					$direct[ $fid ]  = 0;
					$folders[ $fid ] = array( 'id' => $fid, 'name' => __( 'Uncategorized', 'acps-media-cleanup' ), 'parent' => 0 );
				}
			}
			$direct[ $fid ]++;
		}

		$children = array();
		foreach ( $folders as $f ) {
			$children[ (int) $f['parent'] ][] = (int) $f['id'];
		}

		$agg     = array();
		$compute = function( $fid ) use ( &$compute, &$agg, $direct, $children ) {
			$t = isset( $direct[ $fid ] ) ? $direct[ $fid ] : 0;
			if ( ! empty( $children[ $fid ] ) ) {
				foreach ( $children[ $fid ] as $c ) {
					$t += $compute( $c );
				}
			}
			$agg[ $fid ] = $t;
			return $t;
		};

		$out  = array();
		$walk = function( $parent, $depth ) use ( &$walk, &$out, $children, $folders, &$agg, $compute ) {
			if ( empty( $children[ $parent ] ) ) {
				return;
			}
			$sibs = $children[ $parent ];
			usort( $sibs, function( $a, $b ) use ( $folders ) {
				if ( ACPS_MC_Folders::UNCATEGORIZED === $a ) { return 1; }
				if ( ACPS_MC_Folders::UNCATEGORIZED === $b ) { return -1; }
				$na = isset( $folders[ $a ]['name'] ) ? $folders[ $a ]['name'] : '';
				$nb = isset( $folders[ $b ]['name'] ) ? $folders[ $b ]['name'] : '';
				return strcasecmp( $na, $nb );
			} );
			foreach ( $sibs as $fid ) {
				if ( ! isset( $agg[ $fid ] ) ) {
					$compute( $fid );
				}
				$out[] = array(
					'id'     => $fid,
					'name'   => isset( $folders[ $fid ]['name'] ) ? $folders[ $fid ]['name'] : ( '#' . $fid ),
					'depth'  => $depth,
					'parent' => isset( $folders[ $fid ]['parent'] ) ? (int) $folders[ $fid ]['parent'] : 0,
					'total'  => $agg[ $fid ],
				);
				$walk( $fid, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		return $out;
	}

	/**
	 * Depth-ordered folder list WITHOUT attachment counts (cheap; for dropdowns).
	 *
	 * @return array List of array( id, name, depth ).
	 */
	public function flat_tree() {
		$folders  = $this->folders();
		$children = array();
		foreach ( $folders as $f ) {
			$children[ (int) $f['parent'] ][] = (int) $f['id'];
		}
		$out  = array();
		$walk = function( $parent, $depth ) use ( &$walk, &$out, $children, $folders ) {
			if ( empty( $children[ $parent ] ) ) {
				return;
			}
			$sibs = $children[ $parent ];
			usort( $sibs, function( $a, $b ) use ( $folders ) {
				if ( ACPS_MC_Folders::UNCATEGORIZED === $a ) { return 1; }
				if ( ACPS_MC_Folders::UNCATEGORIZED === $b ) { return -1; }
				$na = isset( $folders[ $a ]['name'] ) ? $folders[ $a ]['name'] : '';
				$nb = isset( $folders[ $b ]['name'] ) ? $folders[ $b ]['name'] : '';
				return strcasecmp( $na, $nb );
			} );
			foreach ( $sibs as $fid ) {
				if ( ACPS_MC_Folders::UNCATEGORIZED === (int) $fid ) {
					continue;
				}
				$out[] = array(
					'id'    => (int) $fid,
					'name'  => isset( $folders[ $fid ]['name'] ) ? $folders[ $fid ]['name'] : ( '#' . $fid ),
					'depth' => $depth,
				);
				$walk( $fid, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $out;
	}

	/**
	 * Columns present on the FileBird folder table (cached).
	 *
	 * @return array
	 */
	protected function fbv_columns() {
		global $wpdb;
		static $cols = null;
		if ( null !== $cols ) {
			return $cols;
		}
		$cols = array();
		$rows = $wpdb->get_col( 'SHOW COLUMNS FROM ' . ( $wpdb->prefix . 'fbv' ) ); // phpcs:ignore
		if ( $rows ) {
			$cols = array_map( 'strval', $rows );
		}
		return $cols;
	}

	/**
	 * Create a folder. Returns the new folder id, or WP_Error.
	 *
	 * @param string $name   Folder name.
	 * @param int    $parent Parent folder id (0 for root).
	 * @return int|WP_Error
	 */
	public function create_folder( $name, $parent = 0 ) {
		$name   = trim( wp_strip_all_tags( (string) $name ) );
		$parent = max( 0, (int) $parent );
		if ( '' === $name ) {
			return new WP_Error( 'empty', __( 'Folder name is required.', 'acps-media-cleanup' ) );
		}

		if ( 'filebird_tax' === $this->backend ) {
			$res = wp_insert_term( $name, 'filebird_folder', array( 'parent' => $parent ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$this->flush_cache();
			return (int) $res['term_id'];
		}

		if ( 'filebird_table' === $this->backend ) {
			global $wpdb;
			$cols = $this->fbv_columns();
			$data = array( 'name' => $name, 'parent' => $parent );
			if ( in_array( 'type', $cols, true ) ) {
				$data['type'] = 0;
			}
			if ( in_array( 'ord', $cols, true ) ) {
				$data['ord'] = 0;
			}
			if ( in_array( 'created_by', $cols, true ) ) {
				$data['created_by'] = get_current_user_id();
			}
			$ok = $wpdb->insert( $wpdb->prefix . 'fbv', $data );
			if ( ! $ok ) {
				return new WP_Error( 'db', __( 'Could not create the folder.', 'acps-media-cleanup' ) );
			}
			$this->flush_cache();
			return (int) $wpdb->insert_id;
		}

		return new WP_Error( 'unsupported', __( 'Folders are read-only for this setup.', 'acps-media-cleanup' ) );
	}

	/**
	 * Assign an attachment to a folder (0 / UNCATEGORIZED = remove from folders).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $folder_id     Folder ID (0 or -1 to unfile).
	 * @return bool
	 */
	public function assign( $attachment_id, $folder_id ) {
		$attachment_id = (int) $attachment_id;
		$folder_id     = (int) $folder_id;
		if ( $attachment_id <= 0 ) {
			return false;
		}
		$to_root = ( $folder_id <= 0 );

		if ( 'filebird_tax' === $this->backend ) {
			wp_set_object_terms( $attachment_id, $to_root ? array() : array( $folder_id ), 'filebird_folder', false );
			$this->flush_cache();
			return true;
		}

		if ( 'filebird_table' === $this->backend ) {
			global $wpdb;
			$rel = $wpdb->prefix . 'fbv_attachment_folder';
			$wpdb->delete( $rel, array( 'attachment_id' => $attachment_id ), array( '%d' ) );
			if ( ! $to_root ) {
				$wpdb->insert( $rel, array( 'folder_id' => $folder_id, 'attachment_id' => $attachment_id ), array( '%d', '%d' ) );
			}
			$this->flush_cache();
			return true;
		}

		return false;
	}

	/**
	 * Rename a folder.
	 *
	 * @param int    $id   Folder id.
	 * @param string $name New name.
	 * @return true|WP_Error
	 */
	public function rename_folder( $id, $name ) {
		$id   = (int) $id;
		$name = trim( wp_strip_all_tags( (string) $name ) );
		if ( $id <= 0 || '' === $name ) {
			return new WP_Error( 'invalid', __( 'A folder and a name are required.', 'acps-media-cleanup' ) );
		}
		if ( 'filebird_tax' === $this->backend ) {
			$res = wp_update_term( $id, 'filebird_folder', array( 'name' => $name ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$this->flush_cache();
			return true;
		}
		if ( 'filebird_table' === $this->backend ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'fbv', array( 'name' => $name ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			$this->flush_cache();
			return true;
		}
		return new WP_Error( 'unsupported', __( 'Folders are read-only for this setup.', 'acps-media-cleanup' ) );
	}

	/**
	 * Delete a folder. Files are NEVER deleted: attachments and any child
	 * folders are moved up to the folder's parent (or unfiled at the root).
	 *
	 * @param int $id Folder id.
	 * @return true|WP_Error
	 */
	public function delete_folder( $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid', __( 'A folder is required.', 'acps-media-cleanup' ) );
		}
		$folders = $this->folders();
		$parent  = isset( $folders[ $id ]['parent'] ) ? (int) $folders[ $id ]['parent'] : 0;

		if ( 'filebird_tax' === $this->backend ) {
			// wp_delete_term reparents child terms and drops object relationships
			// (so attachments simply become unfiled).
			$res = wp_delete_term( $id, 'filebird_folder' );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$this->flush_cache();
			return true;
		}

		if ( 'filebird_table' === $this->backend ) {
			global $wpdb;
			$fbv = $wpdb->prefix . 'fbv';
			$rel = $wpdb->prefix . 'fbv_attachment_folder';

			// Re-parent child folders.
			$wpdb->update( $fbv, array( 'parent' => $parent ), array( 'parent' => $id ), array( '%d' ), array( '%d' ) );

			// Move the folder's files up (or unfile them if it was a root folder).
			if ( $parent > 0 ) {
				$wpdb->update( $rel, array( 'folder_id' => $parent ), array( 'folder_id' => $id ), array( '%d' ), array( '%d' ) );
			} else {
				$wpdb->delete( $rel, array( 'folder_id' => $id ), array( '%d' ) );
			}

			$wpdb->delete( $fbv, array( 'id' => $id ), array( '%d' ) );
			$this->flush_cache();
			return true;
		}

		return new WP_Error( 'unsupported', __( 'Folders are read-only for this setup.', 'acps-media-cleanup' ) );
	}

	/**
	 * Remember a folder as recently used (per-user).
	 *
	 * @param int $folder_id Folder ID.
	 */
	public function remember_recent( $folder_id ) {
		$folder_id = (int) $folder_id;
		if ( $folder_id <= 0 ) {
			return;
		}
		$uid    = get_current_user_id();
		$recent = get_user_meta( $uid, 'acps_mm_recent_folders', true );
		$recent = is_array( $recent ) ? $recent : array();
		$recent = array_values( array_unique( array_merge( array( $folder_id ), $recent ) ) );
		$recent = array_slice( $recent, 0, 8 );
		update_user_meta( $uid, 'acps_mm_recent_folders', $recent );
	}

	/**
	 * Folders to surface as "common": recently used first, then most-populated.
	 *
	 * @param int $limit Max folders.
	 * @return array List of array( id, name ).
	 */
	public function common_folders( $limit = 8 ) {
		$folders = $this->folders();
		$order   = array();

		$recent = get_user_meta( get_current_user_id(), 'acps_mm_recent_folders', true );
		foreach ( (array) $recent as $fid ) {
			if ( isset( $folders[ (int) $fid ] ) && self::UNCATEGORIZED !== (int) $fid ) {
				$order[ (int) $fid ] = true;
			}
		}

		// Most-populated next.
		$map    = $this->attachment_folder_map();
		$counts = array();
		foreach ( $map as $fid ) {
			$counts[ (int) $fid ] = ( isset( $counts[ (int) $fid ] ) ? $counts[ (int) $fid ] : 0 ) + 1;
		}
		arsort( $counts );
		foreach ( array_keys( $counts ) as $fid ) {
			if ( self::UNCATEGORIZED !== (int) $fid && isset( $folders[ (int) $fid ] ) ) {
				$order[ (int) $fid ] = true;
			}
		}

		$out = array();
		foreach ( array_keys( $order ) as $fid ) {
			$out[] = array( 'id' => (int) $fid, 'name' => $folders[ (int) $fid ]['name'] );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}
