<?php
/**
 * AJAX endpoints.
 *
 * Every endpoint verifies the nonce and the manage_options capability before
 * doing anything.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Ajax {

	const MAX_FILES_PER_FOLDER = 800;

	public function __construct() {
		$actions = array(
			'scan_start',
			'scan_step',
			'state',
			'folder_files',
			'delete',
			'exclude',
			'restore',
			'purge',
		);
		foreach ( $actions as $a ) {
			add_action( 'wp_ajax_acps_mc_' . $a, array( $this, $a ) );
		}
	}

	protected function guard() {
		if ( ! current_user_can( ACPS_MC_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'acps-media-cleanup' ) ), 403 );
		}
		check_ajax_referer( 'acps_mc', 'nonce' );
	}

	/* --------------------------------------------------------------- */

	public function scan_start() {
		$this->guard();
		$scanner = new ACPS_MC_Scanner();
		$resume  = ! empty( $_POST['resume'] );

		if ( $resume ) {
			$point = $scanner->resume_point();
			if ( $point ) {
				wp_send_json_success(
					array(
						'step'     => $point['step'],
						'offset'   => $point['offset'],
						'resumed'  => true,
					)
				);
			}
		}

		$meta = $scanner->start();
		wp_send_json_success(
			array(
				'step'    => ACPS_MC_Scanner::STEPS[0],
				'offset'  => 0,
				'grand'   => isset( $meta['grand_total'] ) ? (int) $meta['grand_total'] : 0,
				'resumed' => false,
			)
		);
	}

	public function scan_step() {
		$this->guard();
		$step   = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : ACPS_MC_Scanner::STEPS[0];
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$scanner = new ACPS_MC_Scanner();
		$result  = $scanner->run_step( $step, $offset );
		wp_send_json_success( $result );
	}

	public function state() {
		$this->guard();
		$meta    = get_option( ACPS_MC_OPT_SCANMETA, array() );
		$results = get_option( ACPS_MC_OPT_RESULTS, array() );
		$folders = new ACPS_MC_Folders();

		ob_start();
		ACPS_MC_Admin::render_summary( $meta );
		$summary_html = ob_get_clean();

		wp_send_json_success(
			array(
				'has_scan' => ! empty( $meta['time'] ),
				'counts'   => isset( $meta['counts'] ) ? $meta['counts'] : array(),
				'tree'     => $folders->tree_with_counts( is_array( $results ) ? $results : array() ),
				'summary'  => $summary_html,
			)
		);
	}

	public function folder_files() {
		$this->guard();
		$folder_id   = isset( $_POST['folder_id'] ) ? (int) $_POST['folder_id'] : 0;
		$include_sub = ! empty( $_POST['include_sub'] );
		$show_used   = ! empty( $_POST['show_used'] );

		$results = get_option( ACPS_MC_OPT_RESULTS, array() );
		if ( ! is_array( $results ) ) {
			$results = array();
		}
		$folders  = new ACPS_MC_Folders();
		$map      = $folders->attachment_folder_map();
		$settings = ACPS_MC_Settings::all();
		$excluded = array_map( 'intval', (array) $settings['excluded_ids'] );

		$target = $include_sub ? $folders->descendants( $folder_id ) : array( $folder_id );
		$target = array_map( 'intval', $target );

		$files   = array();
		$total   = 0;
		$capped  = false;
		foreach ( $results as $id => $row ) {
			$id  = (int) $id;
			$fid = isset( $map[ $id ] ) ? (int) $map[ $id ] : ACPS_MC_Folders::UNCATEGORIZED;
			if ( ! in_array( $fid, $target, true ) ) {
				continue;
			}
			if ( empty( $row['used'] ) || $show_used ) {
				$total++;
				if ( count( $files ) >= self::MAX_FILES_PER_FOLDER ) {
					$capped = true;
					continue;
				}
				$files[] = $this->file_payload( $id, $row, in_array( $id, $excluded, true ) );
			}
		}

		// Unused first, then largest first.
		usort( $files, function( $a, $b ) {
			if ( $a['used'] !== $b['used'] ) {
				return $a['used'] ? 1 : -1;
			}
			return $b['size'] - $a['size'];
		} );

		wp_send_json_success(
			array(
				'files'  => $files,
				'total'  => $total,
				'capped' => $capped,
				'cap'    => self::MAX_FILES_PER_FOLDER,
			)
		);
	}

	protected function file_payload( $id, $row, $is_excluded ) {
		$thumb = '';
		if ( wp_attachment_is_image( $id ) ) {
			$src = wp_get_attachment_image_src( $id, array( 80, 80 ) );
			if ( $src ) {
				$thumb = $src[0];
			}
		}
		$mime = isset( $row['mime'] ) ? $row['mime'] : get_post_mime_type( $id );
		if ( '' === $thumb ) {
			$icon = wp_mime_type_icon( $id );
			if ( $icon ) {
				$thumb = $icon;
			}
		}

		return array(
			'id'       => $id,
			'filename' => isset( $row['filename'] ) ? $row['filename'] : '',
			'title'    => isset( $row['title'] ) ? $row['title'] : '',
			'url'      => isset( $row['url'] ) ? $row['url'] : wp_get_attachment_url( $id ),
			'edit'     => get_edit_post_link( $id, 'raw' ),
			'mime'     => $mime,
			'ext'      => isset( $row['ext'] ) ? $row['ext'] : '',
			'date'     => isset( $row['date'] ) ? $row['date'] : '',
			'size'      => isset( $row['size'] ) ? (int) $row['size'] : 0,
			'size_h'    => size_format( isset( $row['size'] ) ? (int) $row['size'] : 0, 1 ),
			'used'      => ! empty( $row['used'] ),
			'locations' => isset( $row['locations'] ) && is_array( $row['locations'] ) ? $row['locations'] : array(),
			'thumb'     => $thumb,
			'excluded'  => (bool) $is_excluded,
		);
	}

	public function delete() {
		$this->guard();
		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ack = ! empty( $_POST['ack'] );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No files selected.', 'acps-media-cleanup' ) ) );
		}

		$deleter = new ACPS_MC_Deleter();
		$result  = $deleter->delete_ids( $ids, $ack );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		$meta = get_option( ACPS_MC_OPT_SCANMETA, array() );
		$result['counts'] = isset( $meta['counts'] ) ? $meta['counts'] : array();
		wp_send_json_success( $result );
	}

	public function exclude() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$on = ! empty( $_POST['on'] );
		if ( ! $id ) {
			wp_send_json_error();
		}
		if ( $on ) {
			ACPS_MC_Settings::add_excluded_id( $id );
		} else {
			ACPS_MC_Settings::remove_excluded_id( $id );
		}
		wp_send_json_success( array( 'id' => $id, 'excluded' => $on ) );
	}

	public function restore() {
		$this->guard();
		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$deleter = new ACPS_MC_Deleter();
		if ( $deleter->restore( $id ) ) {
			wp_send_json_success( array( 'id' => $id ) );
		}
		wp_send_json_error( array( 'message' => __( 'Could not restore this file.', 'acps-media-cleanup' ) ) );
	}

	public function purge() {
		$this->guard();
		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$deleter = new ACPS_MC_Deleter();
		if ( $deleter->delete_trashed( $id ) ) {
			wp_send_json_success( array( 'id' => $id ) );
		}
		wp_send_json_error( array( 'message' => __( 'Could not delete this file.', 'acps-media-cleanup' ) ) );
	}
}
