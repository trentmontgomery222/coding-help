<?php
/**
 * AJAX endpoints for the Media Manager and the modal enhancements.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Manager_Ajax {

	public function __construct() {
		$actions = array(
			'folders',
			'query',
			'detail',
			'update_meta',
			'move',
			'create_folder',
			'bulk_alt',
			'delete',
			'where_used',
			'upload_saved',
			'convert_heic',
		);
		foreach ( $actions as $a ) {
			add_action( 'wp_ajax_acps_mm_' . $a, array( $this, $a ) );
		}
	}

	protected function guard() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'acps-media-cleanup' ) ), 403 );
		}
		check_ajax_referer( 'acps_mm', 'nonce' );
	}

	protected function folders_obj() {
		return new ACPS_MC_Folders();
	}

	/* --------------------------------------------------------------- */

	public function folders() {
		$this->guard();
		$folders = $this->folders_obj();
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status<>'trash'" );

		$scan   = get_option( ACPS_MC_OPT_SCANMETA, array() );
		$unused = ( is_array( $scan ) && isset( $scan['counts']['unused'] ) ) ? (int) $scan['counts']['unused'] : 0;

		wp_send_json_success(
			array(
				'writable'  => $folders->is_writable(),
				'backend'   => $folders->backend_label(),
				'total'     => $total,
				'tree'      => $folders->tree_all_counts(),
				'common'    => $folders->common_folders( 8 ),
				'hasScan'   => ! empty( $scan['time'] ),
				'scanTime'  => ! empty( $scan['time'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $scan['time'] ) : '',
				'unused'    => $unused,
			)
		);
	}

	/**
	 * Map of attachment id => used(bool) from the last scan (empty if no scan).
	 *
	 * @return array
	 */
	protected function results_map() {
		$results = get_option( ACPS_MC_OPT_RESULTS, array() );
		$map     = array();
		if ( is_array( $results ) ) {
			foreach ( $results as $id => $row ) {
				$map[ (int) $id ] = ! empty( $row['used'] );
			}
		}
		return $map;
	}

	/**
	 * Resolve the attachment IDs that belong to a folder selection.
	 *
	 * @param string $folder    'all' | 'unfiled' | numeric id
	 * @param bool   $recursive Include sub-folders.
	 * @return array|null Null means "no folder filter" (all).
	 */
	protected function ids_for_folder( $folder, $recursive = true ) {
		$folders = $this->folders_obj();
		$map     = $folders->attachment_folder_map();

		if ( 'all' === $folder || '' === $folder ) {
			return null;
		}
		if ( 'unfiled' === $folder ) {
			// Attachments with no real folder.
			global $wpdb;
			$all    = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status<>'trash'" );
			$out    = array();
			foreach ( (array) $all as $id ) {
				$fid = isset( $map[ (int) $id ] ) ? (int) $map[ (int) $id ] : ACPS_MC_Folders::UNCATEGORIZED;
				if ( ACPS_MC_Folders::UNCATEGORIZED === $fid ) {
					$out[] = (int) $id;
				}
			}
			return $out;
		}

		// Cleanup smart views (from the last scan).
		if ( 'unused' === $folder || 'used' === $folder ) {
			$want = ( 'unused' === $folder );
			$out  = array();
			foreach ( $this->results_map() as $id => $is_used ) {
				if ( $is_used !== $want ) {
					$out[] = (int) $id;
				}
			}
			return $out;
		}

		$target = $recursive ? $folders->descendants( (int) $folder ) : array( (int) $folder );
		$target = array_map( 'intval', $target );
		$out    = array();
		foreach ( $map as $id => $fid ) {
			if ( in_array( (int) $fid, $target, true ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	public function query() {
		$this->guard();
		global $wpdb;

		$folder = isset( $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : 'all';
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$type   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$sort   = isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : 'date';
		$paged  = max( 1, isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1 );
		// The manager loads everything at once; keep a generous hard cap so a
		// giant library cannot exhaust memory in a single response.
		$per    = min( 20000, max( 10, isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 5000 ) );

		$where = array( "p.post_type = 'attachment'", "p.post_status <> 'trash'" );

		// Folder filter.
		$ids = $this->ids_for_folder( $folder );
		if ( is_array( $ids ) ) {
			if ( empty( $ids ) ) {
				wp_send_json_success( array( 'items' => array(), 'total' => 0, 'returned' => 0, 'capped' => false ) );
			}
			$where[] = 'p.ID IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}

		// Mime-type filter.
		if ( $type ) {
			if ( false !== strpos( $type, '/' ) ) {
				$where[] = $wpdb->prepare( 'p.post_mime_type = %s', $type );
			} else {
				$where[] = $wpdb->prepare( 'p.post_mime_type LIKE %s', $wpdb->esc_like( $type ) . '/%' );
			}
		}

		// Search: title OR filename.
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare(
				"(p.post_title LIKE %s OR p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value LIKE %s))",
				$like,
				$like
			);
		}

		$where_sql = implode( ' AND ', $where );

		switch ( $sort ) {
			case 'date_asc':
				$order = 'p.post_date ASC';
				break;
			case 'title':
				$order = 'p.post_title ASC';
				break;
			default:
				$order = 'p.post_date DESC';
		}

		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where_sql}" ); // phpcs:ignore
		$offset = ( $paged - 1 ) * $per;

		$rows = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where_sql} ORDER BY {$order} LIMIT {$per} OFFSET {$offset}" ); // phpcs:ignore
		$rows = array_map( 'intval', (array) $rows );

		// Warm caches so per-card lookups don't each hit the DB.
		if ( $rows ) {
			_prime_post_caches( $rows, false, true );
		}

		$used_map = $this->results_map();
		$items    = array();
		foreach ( $rows as $id ) {
			$items[] = $this->card( (int) $id, $used_map );
		}

		wp_send_json_success(
			array(
				'items'    => $items,
				'total'    => $total,
				'returned' => count( $items ),
				'capped'   => ( $total > count( $items ) ),
			)
		);
	}

	/**
	 * @param int   $id       Attachment ID.
	 * @param array $used_map id => used(bool) from last scan.
	 */
	protected function card( $id, $used_map = array() ) {
		$mime  = get_post_mime_type( $id );
		$thumb = $this->best_thumb( $id );
		$file  = get_post_meta( $id, '_wp_attached_file', true );

		// Used state colour: used | unused | unknown (not in last scan).
		if ( array_key_exists( $id, $used_map ) ) {
			$state = $used_map[ $id ] ? 'used' : 'unused';
		} else {
			$state = 'unknown';
		}

		return array(
			'id'       => $id,
			'title'    => get_the_title( $id ),
			'filename' => $file ? wp_basename( $file ) : '',
			'mime'     => $mime,
			'isImage'  => wp_attachment_is_image( $id ),
			'thumb'    => $thumb,
			'url'      => wp_get_attachment_url( $id ),
			'state'    => $state,
		);
	}

	/**
	 * Best available square-ish preview URL for any attachment.
	 * Returns the image itself, a generated PDF/video preview, or a mime icon.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	protected function best_thumb( $id ) {
		// Works for images AND for PDFs/video that have generated sub-sizes.
		$src = wp_get_attachment_image_src( $id, array( 300, 300 ) );
		if ( $src && ! empty( $src[0] ) && ! $src[3] ) {
			// $src[3] (is_intermediate) false for icons; ensure it's a real image.
		}
		if ( $src && ! empty( $src[0] ) ) {
			// Skip the generic WP media icons (they live in wp-includes/images/media).
			if ( false === strpos( $src[0], '/wp-includes/images/media/' ) ) {
				return $src[0];
			}
		}
		$icon = wp_mime_type_icon( $id );
		return $icon ? $icon : '';
	}

	public function detail() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error();
		}

		$meta   = wp_get_attachment_metadata( $id );
		$post   = get_post( $id );
		$folders = $this->folders_obj();

		// Sizes with URLs.
		$sizes = array();
		$full  = wp_get_attachment_image_src( $id, 'full' );
		if ( $full ) {
			$sizes[] = array( 'name' => 'full', 'w' => $full[1], 'h' => $full[2], 'url' => $full[0] );
		}
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $s ) {
				$src = wp_get_attachment_image_src( $id, $name );
				if ( $src ) {
					$sizes[] = array( 'name' => $name, 'w' => $src[1], 'h' => $src[2], 'url' => $src[0] );
				}
			}
		}

		$file = get_attached_file( $id );
		$bytes = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;

		wp_send_json_success(
			array(
				'id'          => $id,
				'title'       => $post->post_title,
				'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'caption'     => $post->post_excerpt,
				'description' => $post->post_content,
				'filename'    => wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
				'url'         => wp_get_attachment_url( $id ),
				'mime'        => get_post_mime_type( $id ),
				'date'        => get_the_date( '', $id ),
				'sizeH'       => $bytes ? size_format( $bytes, 1 ) : '',
				'isImage'     => wp_attachment_is_image( $id ),
				'thumb'       => $this->best_thumb( $id ),
				'sizes'       => $sizes,
				'writable'    => $folders->is_writable(),
				'folderId'    => $folders->folder_for( $id ),
				'folders'     => $this->folder_options( $folders ),
				'editUrl'     => get_edit_post_link( $id, 'raw' ),
				'imageEdit'   => wp_attachment_is_image( $id ) ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : '',
				'isHeic'      => ACPS_MC_Heic::is_heic( $id ),
				'heicSupport' => ACPS_MC_Heic::supported(),
			)
		);
	}

	public function convert_heic() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'acps-media-cleanup' ) ) );
		}
		if ( ! ACPS_MC_Heic::supported() ) {
			wp_send_json_error( array( 'message' => __( 'HEIC conversion is not available on this server (Imagick without HEIC support).', 'acps-media-cleanup' ) ) );
		}
		$res = ACPS_MC_Heic::convert( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'id'    => $id,
				'url'   => wp_get_attachment_url( $id ),
				'thumb' => $this->best_thumb( $id ),
			)
		);
	}

	protected function folder_options( $folders ) {
		$out = array( array( 'id' => 0, 'name' => __( '— Unfiled —', 'acps-media-cleanup' ), 'depth' => 0 ) );
		foreach ( $folders->flat_tree() as $f ) {
			$out[] = array( 'id' => (int) $f['id'], 'name' => $f['name'], 'depth' => (int) $f['depth'] );
		}
		return $out;
	}

	public function update_meta() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error();
		}

		$post = array( 'ID' => $id );
		if ( isset( $_POST['title'] ) ) {
			$post['post_title'] = sanitize_text_field( wp_unslash( $_POST['title'] ) );
		}
		if ( isset( $_POST['caption'] ) ) {
			$post['post_excerpt'] = sanitize_textarea_field( wp_unslash( $_POST['caption'] ) );
		}
		if ( isset( $_POST['description'] ) ) {
			$post['post_content'] = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );
		}
		if ( count( $post ) > 1 ) {
			wp_update_post( $post );
		}
		if ( isset( $_POST['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( wp_unslash( $_POST['alt'] ) ) );
		}
		wp_send_json_success( array( 'id' => $id ) );
	}

	public function move() {
		$this->guard();
		$ids       = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$folder_id = isset( $_POST['folder_id'] ) ? (int) $_POST['folder_id'] : 0;
		$folders   = $this->folders_obj();

		if ( ! $folders->is_writable() ) {
			wp_send_json_error( array( 'message' => __( 'Folders are read-only for this setup.', 'acps-media-cleanup' ) ) );
		}
		$done = 0;
		foreach ( $ids as $id ) {
			if ( current_user_can( 'edit_post', $id ) && $folders->assign( $id, $folder_id ) ) {
				$done++;
			}
		}
		if ( $folder_id > 0 ) {
			$folders->remember_recent( $folder_id );
		}
		wp_send_json_success( array( 'moved' => $done ) );
	}

	public function create_folder() {
		$this->guard();
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$parent  = isset( $_POST['parent'] ) ? (int) $_POST['parent'] : 0;
		$folders = $this->folders_obj();

		$res = $folders->create_folder( $name, $parent );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'id' => (int) $res, 'name' => $name ) );
	}

	public function bulk_alt() {
		$this->guard();
		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$alt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
		$n   = 0;
		foreach ( $ids as $id ) {
			if ( current_user_can( 'edit_post', $id ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
				$n++;
			}
		}
		wp_send_json_success( array( 'updated' => $n ) );
	}

	public function where_used() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		wp_send_json_success( array( 'id' => $id, 'locations' => ACPS_MC_Usage::for_attachment( $id ) ) );
	}

	/**
	 * Delete (to Trash) selected files. Warns if any are in use unless confirmed.
	 */
	public function delete() {
		$this->guard();
		$ids     = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$confirm = ! empty( $_POST['confirm'] );
		$settings = ACPS_MC_Settings::all();
		$excluded = array_map( 'intval', (array) $settings['excluded_ids'] );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing selected.', 'acps-media-cleanup' ) ) );
		}

		// Safety pre-check: which selected files look used?
		if ( ! $confirm ) {
			$used = array();
			foreach ( $ids as $id ) {
				$locs = ACPS_MC_Usage::for_attachment( $id );
				if ( ! empty( $locs ) ) {
					$used[] = array(
						'id'       => $id,
						'filename' => wp_basename( (string) get_attached_file( $id ) ),
						'count'    => count( $locs ),
					);
				}
			}
			if ( ! empty( $used ) ) {
				wp_send_json_success( array( 'needs_confirm' => true, 'used' => $used ) );
			}
		}

		$folders = $this->folders_obj();
		$map     = $folders->attachment_folder_map();
		$all     = $folders->folders();

		$deleted = 0;
		$items   = array();
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
				$items[ $id ] = array( 'ok' => false );
				continue;
			}
			if ( in_array( $id, $excluded, true ) ) {
				$items[ $id ] = array( 'ok' => false, 'reason' => __( 'Protected file.', 'acps-media-cleanup' ) );
				continue;
			}
			$filename = wp_basename( (string) get_attached_file( $id ) );
			$fid      = isset( $map[ $id ] ) ? (int) $map[ $id ] : ACPS_MC_Folders::UNCATEGORIZED;
			$res      = wp_trash_post( $id );
			if ( false !== $res && null !== $res ) {
				$deleted++;
				$items[ $id ] = array( 'ok' => true );
				ACPS_MC_Logger::record(
					array(
						'attachment_id' => $id,
						'action'        => 'trash',
						'filename'      => $filename,
						'folder_name'   => isset( $all[ $fid ]['name'] ) ? $all[ $fid ]['name'] : '',
						'restorable'    => 1,
						'details'       => array( 'via' => 'media-manager' ),
					)
				);
			} else {
				$items[ $id ] = array( 'ok' => false );
			}
		}

		wp_send_json_success( array( 'deleted' => $deleted, 'items' => $items ) );
	}

	/**
	 * After a file is uploaded through the manager, optionally file it and return
	 * fresh data for the "placed" popup.
	 */
	public function upload_saved() {
		$this->guard();
		$id        = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$folder_id = isset( $_POST['folder_id'] ) ? (int) $_POST['folder_id'] : 0;
		if ( 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error();
		}
		$folders = $this->folders_obj();
		if ( $folder_id > 0 && $folders->is_writable() && current_user_can( 'edit_post', $id ) ) {
			$folders->assign( $id, $folder_id );
			$folders->remember_recent( $folder_id );
		}
		wp_send_json_success(
			array(
				'id'      => $id,
				'url'     => wp_get_attachment_url( $id ),
				'common'  => $folders->common_folders( 8 ),
				'folders' => $this->folder_options( $folders ),
			)
		);
	}
}
