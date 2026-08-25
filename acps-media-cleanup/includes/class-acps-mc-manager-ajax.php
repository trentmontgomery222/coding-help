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
			'rename_file',
			'rename_folder',
			'delete_folder',
			'pages',
		);
		foreach ( $actions as $a ) {
			add_action( 'wp_ajax_acps_mm_' . $a, array( $this, $a ) );
		}
	}

	/** Version counter — bumped on any change so grid caches invalidate. */
	protected function version() {
		return (int) get_option( 'acps_mm_version', 1 );
	}
	protected function bump_version() {
		update_option( 'acps_mm_version', $this->version() + 1, false );
		delete_transient( 'acps_mm_stemmap' );
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

		// "Used on page" filter: folder = "page:123".
		if ( 0 === strpos( $folder, 'page:' ) ) {
			return $this->attachment_ids_for_post( (int) substr( $folder, 5 ) );
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

		// Serve the heavy card list from a short-lived cache (rebuilt only when
		// something changes, via the version counter) so repeat loads are fast.
		$sig  = md5( wp_json_encode( array( $folder, $search, $type, $sort, $per, $this->version() ) ) );
		$ckey = 'acps_mm_q_' . $sig;
		$cache = get_transient( $ckey );

		if ( is_array( $cache ) && isset( $cache['items'], $cache['total'] ) ) {
			$items = $cache['items'];
			$total = (int) $cache['total'];
		} else {
			$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where_sql}" ); // phpcs:ignore
			$offset = ( $paged - 1 ) * $per;

			$rows = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where_sql} ORDER BY {$order} LIMIT {$per} OFFSET {$offset}" ); // phpcs:ignore
			$rows = array_map( 'intval', (array) $rows );

			if ( $rows ) {
				_prime_post_caches( $rows, false, true );
			}

			$items = array();
			foreach ( $rows as $id ) {
				$items[] = $this->card( (int) $id );
			}
			set_transient( $ckey, array( 'items' => $items, 'total' => $total ), 30 * MINUTE_IN_SECONDS );
		}

		// Overlay the used/unused colour fresh every time (cheap; reflects the
		// latest scan without rebuilding the whole card list).
		$used_map = $this->results_map();
		foreach ( $items as &$it ) {
			$id = (int) $it['id'];
			$it['state'] = array_key_exists( $id, $used_map ) ? ( $used_map[ $id ] ? 'used' : 'unused' ) : 'unknown';
		}
		unset( $it );

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
	 * Build a lightweight card (no used-state; that is overlaid per request).
	 *
	 * @param int $id Attachment ID.
	 */
	protected function card( $id ) {
		$file = get_post_meta( $id, '_wp_attached_file', true );
		return array(
			'id'       => $id,
			'title'    => get_the_title( $id ),
			'filename' => $file ? wp_basename( $file ) : '',
			'mime'     => get_post_mime_type( $id ),
			'isImage'  => wp_attachment_is_image( $id ),
			'thumb'    => $this->best_thumb( $id ),
			'url'      => wp_get_attachment_url( $id ),
			'state'    => 'unknown',
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
		$this->bump_version();
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
		$this->bump_version();
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
		$this->bump_version();
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
		$this->bump_version();
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

		$this->bump_version();
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
		$this->bump_version();
		wp_send_json_success(
			array(
				'id'       => $id,
				'url'      => wp_get_attachment_url( $id ),
				'filename' => wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
				'common'   => $folders->common_folders( 8 ),
				'folders'  => $this->folder_options( $folders ),
			)
		);
	}

	/**
	 * Rename an attachment's file on disk (keeps the same ID). Warns first if the
	 * file appears to be in use, because renaming breaks hard-coded links.
	 */
	public function rename_file() {
		$this->guard();
		global $wpdb;

		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$newbase = isset( $_POST['name'] ) ? (string) wp_unslash( $_POST['name'] ) : '';
		$confirm = ! empty( $_POST['confirm'] );

		if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'acps-media-cleanup' ) ) );
		}

		// Clean the requested base name; the extension is preserved from the file.
		$newbase = sanitize_file_name( $newbase );
		$newbase = preg_replace( '/\.[^.]+$/', '', $newbase ); // drop any typed extension
		$newbase = trim( $newbase );
		if ( '' === $newbase ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid file name.', 'acps-media-cleanup' ) ) );
		}

		// Usage warning.
		if ( ! $confirm ) {
			$locs = ACPS_MC_Usage::for_attachment( $id );
			if ( ! empty( $locs ) ) {
				wp_send_json_success( array( 'needs_confirm' => true, 'count' => count( $locs ) ) );
			}
		}

		$old = get_attached_file( $id );
		if ( ! $old || ! file_exists( $old ) ) {
			wp_send_json_error( array( 'message' => __( 'Original file not found.', 'acps-media-cleanup' ) ) );
		}
		$dir      = trailingslashit( dirname( $old ) );
		$ext      = pathinfo( $old, PATHINFO_EXTENSION );
		$new_name = wp_unique_filename( dirname( $old ), $newbase . ( $ext ? '.' . $ext : '' ) );
		$new_path = $dir . $new_name;

		if ( ! @rename( $old, $new_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_send_json_error( array( 'message' => __( 'Could not rename the file on disk.', 'acps-media-cleanup' ) ) );
		}

		// Remove the now-orphaned old size files and original.
		$meta_old = wp_get_attachment_metadata( $id );
		if ( is_array( $meta_old ) ) {
			if ( ! empty( $meta_old['sizes'] ) ) {
				foreach ( $meta_old['sizes'] as $s ) {
					if ( ! empty( $s['file'] ) && file_exists( $dir . $s['file'] ) ) {
						@unlink( $dir . $s['file'] ); // phpcs:ignore
					}
				}
			}
			if ( ! empty( $meta_old['original_image'] ) && file_exists( $dir . $meta_old['original_image'] ) ) {
				@unlink( $dir . $meta_old['original_image'] ); // phpcs:ignore
			}
		}

		$uploads = wp_get_upload_dir();
		$rel     = ltrim( str_replace( $uploads['basedir'], '', $new_path ), '/\\' );
		update_post_meta( $id, '_wp_attached_file', $rel );
		$wpdb->update( $wpdb->posts, array( 'guid' => trailingslashit( $uploads['baseurl'] ) . $rel ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$md = wp_generate_attachment_metadata( $id, $new_path );
		if ( is_array( $md ) ) {
			wp_update_attachment_metadata( $id, $md );
		}
		clean_post_cache( $id );

		$this->bump_version();
		wp_send_json_success(
			array(
				'id'       => $id,
				'filename' => $new_name,
				'url'      => wp_get_attachment_url( $id ),
				'thumb'    => $this->best_thumb( $id ),
			)
		);
	}

	public function rename_folder() {
		$this->guard();
		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$res  = $this->folders_obj()->rename_folder( $id, $name );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$this->bump_version();
		wp_send_json_success( array( 'id' => $id, 'name' => $name ) );
	}

	public function delete_folder() {
		$this->guard();
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$res = $this->folders_obj()->delete_folder( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$this->bump_version();
		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * List pages/posts for the "used on page" filter.
	 */
	public function pages() {
		$this->guard();
		$posts = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 400,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$out = array();
		foreach ( (array) $posts as $pid ) {
			$out[] = array( 'id' => (int) $pid, 'title' => get_the_title( $pid ) );
		}
		wp_send_json_success( array( 'pages' => $out ) );
	}

	/**
	 * Map of filename-stem => array of attachment ids (cached, version-busted).
	 *
	 * @return array
	 */
	protected function stem_map() {
		$cached = get_transient( 'acps_mm_stemmap' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$scanner = new ACPS_MC_Scanner();
		$rows    = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'", ARRAY_A );
		$map     = array();
		foreach ( (array) $rows as $r ) {
			$stem = $scanner->stem( wp_basename( (string) $r['meta_value'] ) );
			if ( '' !== $stem ) {
				$map[ $stem ][] = (int) $r['post_id'];
			}
		}
		set_transient( 'acps_mm_stemmap', $map, 30 * MINUTE_IN_SECONDS );
		return $map;
	}

	/**
	 * Attachment ids referenced by a given post (content + meta).
	 *
	 * @param int $post_id Post id.
	 * @return int[]
	 */
	protected function attachment_ids_for_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		global $wpdb;

		$text  = (string) $post->post_content . ' ' . (string) $post->post_excerpt;
		$metas = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')",
				$post_id
			)
		);
		foreach ( (array) $metas as $mv ) {
			$text .= ' ' . (string) $mv;
		}
		// Featured image id.
		$thumb = get_post_thumbnail_id( $post_id );

		$scanner = new ACPS_MC_Scanner();
		$refs    = $scanner->extract_refs( $text );

		$ids = array();
		if ( $thumb ) {
			$ids[ (int) $thumb ] = true;
		}
		foreach ( $refs['ids'] as $rid ) {
			$ids[ (int) $rid ] = true;
		}
		if ( ! empty( $refs['urls'] ) ) {
			$stem_map = $this->stem_map();
			foreach ( $refs['urls'] as $stem ) {
				if ( isset( $stem_map[ $stem ] ) ) {
					foreach ( $stem_map[ $stem ] as $aid ) {
						$ids[ (int) $aid ] = true;
					}
				}
			}
		}

		// Only real, non-trashed attachments.
		$ids = array_keys( $ids );
		if ( empty( $ids ) ) {
			return array();
		}
		$in    = implode( ',', array_map( 'intval', $ids ) );
		$valid = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status<>'trash' AND ID IN ($in)" ); // phpcs:ignore
		return array_map( 'intval', (array) $valid );
	}
}
