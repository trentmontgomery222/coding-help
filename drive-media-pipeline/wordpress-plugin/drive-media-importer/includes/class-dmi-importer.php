<?php
/**
 * Imports a single decoded file into a subsite's media library.
 *
 * Security posture (build brief §7):
 *  - MIME type is sniffed from the decoded BYTES, never trusted from the
 *    declared type or filename extension, and checked against an allowlist.
 *  - Filenames pass through sanitize_file_name(); WordPress assigns the
 *    final path via wp_upload_bits().
 *  - switch_to_blog()/restore_current_blog() wrap the whole import and the
 *    restore runs even on failure (try/finally).
 */

defined( 'ABSPATH' ) || exit;

class DMI_Importer {

	/** Allowed real MIME types → canonical extension. */
	const ALLOWED_TYPES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	);

	/**
	 * Import one row.
	 *
	 * @param array  $row   Row from action:pending (row_id, filename, alt_text, location, uploader, target_site).
	 * @param string $bytes Decoded binary image data.
	 * @return array Result for the ack call: row_id, status, wp_url, wp_attachment_id, error_message.
	 */
	public static function import_row( array $row, $bytes ) {
		$settings = DMI_Settings::get();

		$fail = function ( $message ) use ( $row ) {
			return array(
				'row_id'           => $row['row_id'],
				'status'           => 'error',
				'wp_url'           => '',
				'wp_attachment_id' => '',
				'error_message'    => $message,
			);
		};

		if ( strlen( $bytes ) > (int) $settings['max_file_bytes'] ) {
			return $fail( sprintf(
				'File is too large (%s; the limit is %s). Resize the image and re-queue it.',
				size_format( strlen( $bytes ) ),
				size_format( (int) $settings['max_file_bytes'] )
			) );
		}

		$mime = self::sniff_mime( $bytes );
		if ( ! isset( self::ALLOWED_TYPES[ $mime ] ) ) {
			return $fail( sprintf(
				'File content is not an allowed image type (detected: %s). Allowed: JPEG, PNG, GIF, WebP.',
				$mime ?: 'unknown'
			) );
		}

		$filename = self::normalize_filename( (string) $row['filename'], self::ALLOWED_TYPES[ $mime ] );

		$target_site = (int) $row['target_site'];
		if ( $target_site < 1 || ! get_site( $target_site ) ) {
			return $fail( sprintf( 'Target site "%s" does not exist on this network.', $row['target_site'] ) );
		}

		switch_to_blog( $target_site );
		try {
			$upload = wp_upload_bits( $filename, null, $bytes );
			if ( ! empty( $upload['error'] ) ) {
				return $fail( 'Could not save the file on the server: ' . $upload['error'] );
			}

			$attachment_id = wp_insert_attachment(
				array(
					'post_mime_type' => $mime,
					'post_title'     => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ),
					'post_content'   => '',
					// Location goes into the caption field so editors see it.
					'post_excerpt'   => sanitize_text_field( (string) ( $row['location'] ?? '' ) ),
					'post_status'    => 'inherit',
				),
				$upload['file'],
				0,
				true
			);
			if ( is_wp_error( $attachment_id ) ) {
				@unlink( $upload['file'] );
				return $fail( 'WordPress could not create the attachment: ' . $attachment_id->get_error_message() );
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			wp_update_attachment_metadata( $attachment_id, $metadata );

			// WCAG 1.1.1 — the whole reason alt text is required upstream.
			update_post_meta(
				$attachment_id,
				'_wp_attachment_image_alt',
				sanitize_text_field( (string) $row['alt_text'] )
			);

			if ( ! empty( $row['uploader'] ) ) {
				update_post_meta( $attachment_id, '_dmi_uploader', sanitize_email( (string) $row['uploader'] ) );
			}
			update_post_meta( $attachment_id, '_dmi_row_id', sanitize_text_field( (string) $row['row_id'] ) );

			return array(
				'row_id'           => $row['row_id'],
				'status'           => 'done',
				'wp_url'           => wp_get_attachment_url( $attachment_id ),
				'wp_attachment_id' => (string) $attachment_id,
				'error_message'    => '',
			);
		} catch ( \Throwable $e ) {
			return $fail( 'Unexpected error during import: ' . $e->getMessage() );
		} finally {
			restore_current_blog();
		}
	}

	/** Sniff the real MIME type from bytes (finfo, getimagesize fallback). */
	private static function sniff_mime( $bytes ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = finfo_buffer( $finfo, $bytes );
				finfo_close( $finfo );
				if ( $mime ) {
					return $mime;
				}
			}
		}
		if ( function_exists( 'getimagesizefromstring' ) ) {
			$info = @getimagesizefromstring( $bytes );
			if ( $info && ! empty( $info['mime'] ) ) {
				return $info['mime'];
			}
		}
		return '';
	}

	/** Sanitize the incoming filename and force the extension to match the sniffed type. */
	private static function normalize_filename( $filename, $canonical_ext ) {
		$filename = sanitize_file_name( $filename );
		$base     = pathinfo( $filename, PATHINFO_FILENAME );
		if ( '' === $base ) {
			$base = 'drive-import-' . time();
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'jpeg' === $ext ) {
			$ext = 'jpg';
		}
		if ( $ext !== $canonical_ext ) {
			$ext = $canonical_ext;
		}
		return $base . '.' . $ext;
	}
}
