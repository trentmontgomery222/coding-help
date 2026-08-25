<?php
/**
 * HEIC / HEIF → JPEG conversion.
 *
 * HEIC files (typical iPhone photos) don't display in browsers, so we convert
 * them to JPEG. Conversion uses Imagick when the server was built with HEIC
 * support; when it isn't, everything degrades gracefully and the UI says so.
 *
 * Conversion keeps the SAME attachment ID (so any links keep working after the
 * new sizes generate); only the file extension and generated sizes change.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Heic {

	/**
	 * Wire the automatic-on-upload conversion.
	 */
	public function __construct() {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_metadata' ), 8, 2 );
	}

	/**
	 * Is the server able to convert HEIC?
	 *
	 * @return bool
	 */
	public static function supported() {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		try {
			$formats = Imagick::queryFormats( 'HEI*' );
		} catch ( Exception $e ) {
			return false;
		}
		return ! empty( $formats );
	}

	/**
	 * Is this attachment a HEIC/HEIF file?
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function is_heic( $id ) {
		$mime = strtolower( (string) get_post_mime_type( $id ) );
		if ( in_array( $mime, array( 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence' ), true ) ) {
			return true;
		}
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'heic', 'heif' ), true );
	}

	/**
	 * Auto-convert freshly uploaded HEIC files.
	 *
	 * @param array $metadata Generated metadata.
	 * @param int   $id       Attachment ID.
	 * @return array
	 */
	public static function on_generate_metadata( $metadata, $id ) {
		if ( ! ACPS_MC_Settings::get( 'convert_heic_on_upload' ) ) {
			return $metadata;
		}
		if ( ! self::supported() || ! self::is_heic( $id ) ) {
			return $metadata;
		}
		$res = self::convert( $id );
		if ( ! is_wp_error( $res ) ) {
			$new = wp_get_attachment_metadata( $id );
			if ( is_array( $new ) ) {
				return $new;
			}
		}
		return $metadata;
	}

	/**
	 * Convert an attachment's HEIC file to JPEG in place (same ID).
	 *
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public static function convert( $id ) {
		global $wpdb;

		if ( ! self::supported() ) {
			return new WP_Error( 'unsupported', __( 'HEIC conversion is not available on this server.', 'acps-media-cleanup' ) );
		}

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'missing', __( 'Original file not found.', 'acps-media-cleanup' ) );
		}

		$info     = pathinfo( $file );
		$dir      = $info['dirname'];
		$new_base = wp_unique_filename( $dir, $info['filename'] . '.jpg' );
		$new_path = trailingslashit( $dir ) . $new_base;

		try {
			$im = new Imagick();
			$im->readImage( $file );
			// HEIC can hold multiple frames; keep the first / flatten.
			if ( method_exists( $im, 'setIteratorIndex' ) ) {
				$im->setIteratorIndex( 0 );
			}
			$im->setImageFormat( 'jpeg' );
			$im->setImageCompressionQuality( 90 );
			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			}
			$im->writeImage( $new_path );
			$im->clear();
			$im->destroy();
		} catch ( Exception $e ) {
			return new WP_Error( 'convert_failed', $e->getMessage() );
		}

		if ( ! file_exists( $new_path ) ) {
			return new WP_Error( 'convert_failed', __( 'The converted file could not be written.', 'acps-media-cleanup' ) );
		}

		$uploads = wp_get_upload_dir();
		$rel     = ltrim( str_replace( $uploads['basedir'], '', $new_path ), '/\\' );
		$new_url = trailingslashit( $uploads['baseurl'] ) . $rel;
		$old     = $file;

		// Point the attachment at the JPEG BEFORE regenerating metadata so the
		// on_generate_metadata filter sees it as an image (no recursion).
		update_post_meta( $id, '_wp_attached_file', $rel );
		wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/jpeg' ) );
		$wpdb->update( $wpdb->posts, array( 'guid' => $new_url ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $id, $new_path );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $id, $metadata );
		}

		// Remove the original HEIC file (its sizes, if any, are replaced).
		if ( $old !== $new_path && file_exists( $old ) ) {
			@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		clean_post_cache( $id );
		return true;
	}
}
