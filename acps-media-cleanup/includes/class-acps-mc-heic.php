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

		// Without these two filters, WordPress core rejects .heic/.heif files
		// during upload ("Sorry, this file type is not permitted for security
		// reasons") before the conversion code above ever gets a chance to run.
		add_filter( 'upload_mimes', array( __CLASS__, 'allow_heic_mime' ) );
		add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'fix_heic_filetype' ), 10, 4 );

		// The actual conversion is scheduled (see on_generate_metadata()) rather
		// than run inline, so it doesn't hold up the upload response — this is
		// the callback WP-Cron fires a moment later to do the real work.
		add_action( 'acps_mc_heic_convert', array( __CLASS__, 'convert' ) );
	}

	/**
	 * Allow .heic / .heif extensions through the upload allow-list.
	 *
	 * Registered unconditionally (not gated on `supported()`), so that even on
	 * a server without HEIC-capable Imagick the file can still land in the
	 * media library — it just won't preview in-browser and conversion will be
	 * skipped, same graceful-degradation behaviour as the rest of this class.
	 *
	 * @param array $mimes Ext => mime map.
	 * @return array
	 */
	public static function allow_heic_mime( $mimes ) {
		$mimes['heic'] = 'image/heic';
		$mimes['heif'] = 'image/heif';
		return $mimes;
	}

	/**
	 * WordPress double-checks the upload against the real file content
	 * (finfo/getimagesize) in wp_check_filetype_and_ext(), and that sniffing
	 * commonly fails to recognise HEIC containers even once the extension is
	 * allow-listed above, which would otherwise still block the upload with
	 * an "ext_type_mismatch" error. Trust the extension for .heic/.heif.
	 *
	 * @param array  $data     ext/type/proper_filename.
	 * @param string $file     Full path to the uploaded file.
	 * @param string $filename The original filename.
	 * @param array  $mimes    Allowed mimes.
	 * @return array
	 */
	public static function fix_heic_filetype( $data, $file, $filename, $mimes ) {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'heic', 'heif' ), true ) ) {
			$data['ext']  = $ext;
			$data['type'] = ( 'heic' === $ext ) ? 'image/heic' : 'image/heif';
		}
		return $data;
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
	 * Doesn't convert inline: the Imagick decode/encode plus a full second pass
	 * of thumbnail generation is real work (often 1-3+ seconds per photo), and
	 * running it here would hold up *this file's* upload response — which,
	 * since the uploader processes one file at a time, directly slows down
	 * every file queued after it too. Scheduling it instead lets the upload
	 * finish immediately; WP-Cron runs the conversion moments later (the same
	 * mechanism this plugin already uses for its nightly scan). The manual
	 * "Convert to JPEG" button on the file still works immediately if you
	 * don't want to wait for that.
	 *
	 * @param array $metadata Generated metadata.
	 * @param int   $id       Attachment ID.
	 * @return array
	 */
	public static function on_generate_metadata( $metadata, $id ) {
		// This runs during every attachment's metadata generation. Never let an
		// error here break the upload pipeline — return the original metadata.
		try {
			if ( ! class_exists( 'ACPS_MC_Settings' ) || ! ACPS_MC_Settings::get( 'convert_heic_on_upload' ) ) {
				return $metadata;
			}
			if ( ! self::supported() || ! self::is_heic( $id ) ) {
				return $metadata;
			}
			// Convert immediately so the file is a usable JPEG the moment the upload
			// finishes (WP-Cron isn't guaranteed to run promptly on every host).
			// convert() points the attachment at the JPEG and sets its mime BEFORE
			// it regenerates metadata, so this filter re-firing sees an image, not a
			// HEIC — no infinite recursion.
			$res = self::convert( $id );
			if ( ! is_wp_error( $res ) ) {
				$new = wp_get_attachment_metadata( $id );
				if ( is_array( $new ) ) {
					return $new;
				}
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'acps_mc_log' ) ) {
				acps_mc_log( 'HEIC on_generate_metadata error: ' . $e->getMessage() );
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
			// Best-effort: raise ImageMagick's resource limits before decoding.
			//
			// Modern iPhone HEICs bundle several auxiliary images (HDR gain maps,
			// depth maps, thumbnails). ImageMagick counts those references and
			// rejects the file with "Too many auxiliary image references" once the
			// count passes its ListLength resource limit — which hardened hosts
			// (e.g. WP Engine's policy.xml) set very low. Raising it here can let
			// the file through. This can only ever *raise* the limit within what
			// the server's policy allows; a policy hard-cap still wins, and we
			// fall back to a clear message below in that case. Guarded so it does
			// nothing on Imagick builds without these constants.
			if ( defined( 'Imagick::RESOURCETYPE_LIST_LENGTH' ) ) {
				@Imagick::setResourceLimit( constant( 'Imagick::RESOURCETYPE_LIST_LENGTH' ), 100000 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			if ( defined( 'Imagick::RESOURCETYPE_TIME' ) ) {
				@Imagick::setResourceLimit( constant( 'Imagick::RESOURCETYPE_TIME' ), 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$im = new Imagick();

			// Reading just the primary image ("[0]") walks far fewer auxiliary
			// references than reading the whole container, so try it first; fall
			// back to a plain read on any server where the scene selector isn't
			// supported.
			try {
				$im->readImage( $file . '[0]' );
			} catch ( Exception $inner ) {
				$im->clear();
				$im->readImage( $file );
			}

			// If a multi-image container still came through, keep the first frame.
			if ( $im->getNumberImages() > 1 ) {
				$im->setIteratorIndex( 0 );
				$im = $im->getImage();
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
			$msg = $e->getMessage();
			// Give a specific, actionable message for the auxiliary-image-limit
			// case, which is a server (ImageMagick/libheif) restriction we can
			// only try to work around, not fully fix in PHP.
			if ( false !== stripos( $msg, 'auxiliary image' ) ) {
				$msg = __( 'This iPhone photo bundles extra images (HDR/depth data) that your server\'s image library refuses to open ("too many auxiliary image references"). The file was uploaded, but auto-conversion is blocked by a server limit. Ask your host to raise ImageMagick\'s "list-length" policy or update libheif, or set the iPhone to shoot "Most Compatible" (Settings ▸ Camera ▸ Formats) so photos arrive as JPEG.', 'acps-media-cleanup' );
			}
			return new WP_Error( 'convert_failed', $msg );
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

		// Bump the Media Manager's grid cache so the file's new thumbnail/mime
		// shows up next time the grid loads — needed here (not just on the
		// manual "Convert to JPEG" button) now that conversion also happens in
		// the background via WP-Cron, with nothing else to invalidate it.
		update_option( 'acps_mm_version', (int) get_option( 'acps_mm_version', 1 ) + 1, false );
		delete_transient( 'acps_mm_stemmap' );

		return true;
	}
}
