<?php
/**
 * Hidden remote photo API.
 *
 * A machine-to-machine REST API for uploading photos into the media library and
 * doing light management (list / move to folder / trash) from off-site — e.g. a
 * phone shortcut, a script, or another server. Like the self-hosted updater it
 * is deliberately UNADVERTISED: the routes are hidden from the public REST index
 * and there is no menu link to its settings. It is:
 *
 *   - OFF by default (master switch in the hidden settings page).
 *   - Password protected — every call must carry the secret key (seeded at
 *     activation) in the `X-ACPS-Key` header or a `key` field. Compared with a
 *     timing-safe hash_equals().
 *   - Rate limited per client and globally (per-minute + per-day caps).
 *   - Anti-spam / brute-force hardened — repeated bad keys from an IP get locked
 *     out for a cooldown; uploads must be real images within a size limit.
 *
 * Every handler is wrapped so a bad request can only ever return a clean JSON
 * error, never a fatal.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Remote_Api {

	/** REST namespace (shared with the rest of the plugin) and route base. */
	const REST_NS   = 'acps-mc/v1';
	const ROUTE_BASE = '/remote';

	/** Image types the upload endpoint accepts. Everything else is rejected. */
	const ALLOWED_MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'heic'         => 'image/heic',
		'heif'         => 'image/heif',
	);

	/** Failed-auth attempts from one IP before it is locked out. */
	const MAX_AUTH_FAILS = 12;

	/** How long a locked-out IP stays locked (seconds). */
	const LOCKOUT_TTL = 900; // 15 minutes.

	/**
	 * Register the REST routes — a no-op unless the remote API is switched on.
	 */
	public function register() {
		if ( ! ACPS_MC_Settings::get( 'remote_api_enabled' ) ) {
			return;
		}
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		try {
			$common = array(
				'permission_callback' => '__return_true', // Auth is enforced per-request by the key.
				'show_in_index'       => false,           // Keep the routes out of the public REST index.
			);

			register_rest_route(
				self::REST_NS,
				self::ROUTE_BASE . '/ping',
				array_merge( $common, array( 'methods' => 'GET', 'callback' => array( $this, 'handle_ping' ) ) )
			);
			register_rest_route(
				self::REST_NS,
				self::ROUTE_BASE . '/upload',
				array_merge( $common, array( 'methods' => 'POST', 'callback' => array( $this, 'handle_upload' ) ) )
			);
			register_rest_route(
				self::REST_NS,
				self::ROUTE_BASE . '/list',
				array_merge( $common, array( 'methods' => 'GET', 'callback' => array( $this, 'handle_list' ) ) )
			);
			register_rest_route(
				self::REST_NS,
				self::ROUTE_BASE . '/move',
				array_merge( $common, array( 'methods' => 'POST', 'callback' => array( $this, 'handle_move' ) ) )
			);
			register_rest_route(
				self::REST_NS,
				self::ROUTE_BASE . '/delete',
				array_merge( $common, array( 'methods' => 'POST', 'callback' => array( $this, 'handle_delete' ) ) )
			);
		} catch ( \Throwable $e ) {
			self::log( 'register_routes: ' . $e->getMessage() );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Handlers. Each one runs guard() first (auth + rate limit + lockout)
	 * and is wrapped so it can never fatal.
	 * ------------------------------------------------------------------ */

	public function handle_ping( $req ) {
		return $this->wrap( $req, function () {
			return $this->ok( array( 'pong' => true, 'version' => ACPS_MC_VERSION ) );
		} );
	}

	public function handle_upload( $req ) {
		return $this->wrap( $req, function () use ( $req ) {
			$max_bytes = max( 1, (int) ACPS_MC_Settings::get( 'remote_api_max_mb', 20 ) ) * MB_IN_BYTES;

			// Source can be a multipart file OR a base64 JSON body.
			$filename = '';
			$tmp      = '';
			$cleanup  = false;

			$files = $req->get_file_params();
			if ( ! empty( $files['file'] ) && ! empty( $files['file']['tmp_name'] ) && is_uploaded_file( $files['file']['tmp_name'] ) ) {
				$filename = isset( $files['file']['name'] ) ? $files['file']['name'] : 'upload';
				$tmp      = $files['file']['tmp_name'];
			} else {
				$b64 = (string) $req->get_param( 'content_base64' );
				if ( '' === $b64 ) {
					return $this->err( 'no_file', __( 'No file was provided.', 'acps-media-cleanup' ), 400 );
				}
				// Accept a data: URI prefix too.
				if ( false !== strpos( $b64, ',' ) && 0 === strpos( $b64, 'data:' ) ) {
					$b64 = substr( $b64, strpos( $b64, ',' ) + 1 );
				}
				$bytes = base64_decode( $b64, true );
				if ( false === $bytes || '' === $bytes ) {
					return $this->err( 'bad_base64', __( 'The file data could not be decoded.', 'acps-media-cleanup' ), 400 );
				}
				if ( strlen( $bytes ) > $max_bytes ) {
					return $this->err( 'too_large', __( 'The file is larger than the allowed size.', 'acps-media-cleanup' ), 413 );
				}
				$filename = (string) $req->get_param( 'filename' );
				if ( '' === $filename ) {
					$filename = 'upload.jpg';
				}
				$tmp = wp_tempnam( 'acps-mc-remote' );
				if ( ! $tmp ) {
					return $this->err( 'tmp_failed', __( 'Could not create a temporary file.', 'acps-media-cleanup' ), 500 );
				}
				if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					return $this->err( 'write_failed', __( 'Could not write the uploaded data.', 'acps-media-cleanup' ), 500 );
				}
				$cleanup = true;
			}

			// Size guard for the multipart path.
			$size = @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $size && $size > $max_bytes ) {
				if ( $cleanup ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return $this->err( 'too_large', __( 'The file is larger than the allowed size.', 'acps-media-cleanup' ), 413 );
			}

			$filename = sanitize_file_name( wp_basename( (string) $filename ) );
			if ( '' === $filename ) {
				$filename = 'upload.jpg';
			}

			// Validate it is genuinely one of the allowed image types.
			$check = wp_check_filetype_and_ext( $tmp, $filename, self::ALLOWED_MIMES );
			$type  = ! empty( $check['type'] ) ? $check['type'] : '';
			if ( ! empty( $check['proper_filename'] ) ) {
				$filename = $check['proper_filename'];
			}
			if ( '' === $type || ! in_array( $type, array_values( self::ALLOWED_MIMES ), true ) ) {
				if ( $cleanup ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return $this->err( 'bad_type', __( 'Only image files are accepted.', 'acps-media-cleanup' ), 415 );
			}
			// For raster types, confirm the bytes really are an image (blocks a
			// script renamed to .jpg). SVG etc. are not in the allowlist at all.
			if ( function_exists( 'getimagesize' ) && false === @getimagesize( $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				// HEIC/HEIF aren't understood by getimagesize on many hosts; allow
				// those through the extension/type check only.
				if ( ! in_array( $type, array( 'image/heic', 'image/heif' ), true ) ) {
					if ( $cleanup ) {
						@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					}
					return $this->err( 'not_image', __( 'The file does not appear to be a real image.', 'acps-media-cleanup' ), 415 );
				}
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$file_array = array( 'name' => $filename, 'tmp_name' => $tmp );
			$attach_id  = media_handle_sideload( $file_array, 0, null );

			if ( is_wp_error( $attach_id ) ) {
				if ( $cleanup ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return $this->err( 'sideload_failed', $attach_id->get_error_message(), 500 );
			}
			// media_handle_sideload moves (consumes) the temp file on success.

			// File into a folder: the request's folder_id, else the configured default.
			$folder_id = $req->has_param( 'folder_id' ) ? (int) $req->get_param( 'folder_id' ) : (int) ACPS_MC_Settings::get( 'remote_api_folder', 0 );
			$this->assign_folder( (int) $attach_id, $folder_id );

			$this->bump_daily();

			return $this->ok(
				array(
					'id'       => (int) $attach_id,
					'url'      => wp_get_attachment_url( $attach_id ),
					'filename' => $filename,
					'folder'  => $folder_id > 0 ? $folder_id : 0,
					'mime'    => $type,
				)
			);
		} );
	}

	public function handle_list( $req ) {
		return $this->wrap( $req, function () use ( $req ) {
			$limit = (int) $req->get_param( 'limit' );
			$limit = $limit > 0 ? min( 100, $limit ) : 20;
			$q     = new WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image',
					'posts_per_page' => $limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
					'fields'         => 'ids',
				)
			);
			$items = array();
			foreach ( (array) $q->posts as $id ) {
				$id      = (int) $id;
				$items[] = array(
					'id'       => $id,
					'url'      => wp_get_attachment_url( $id ),
					'filename' => wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
					'date'     => get_post_time( 'c', true, $id ),
					'mime'     => get_post_mime_type( $id ),
					'folder'   => $this->folder_of( $id ),
				);
			}
			return $this->ok( array( 'count' => count( $items ), 'items' => $items ) );
		} );
	}

	public function handle_move( $req ) {
		return $this->wrap( $req, function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				return $this->err( 'not_found', __( 'That media item was not found.', 'acps-media-cleanup' ), 404 );
			}
			$folder_id = (int) $req->get_param( 'folder_id' );
			$this->assign_folder( $id, $folder_id );
			return $this->ok( array( 'id' => $id, 'folder' => $folder_id > 0 ? $folder_id : 0 ) );
		} );
	}

	public function handle_delete( $req ) {
		return $this->wrap( $req, function () use ( $req ) {
			if ( ! ACPS_MC_Settings::get( 'remote_api_allow_delete' ) ) {
				return $this->err( 'delete_disabled', __( 'Deleting over the API is turned off.', 'acps-media-cleanup' ), 403 );
			}
			$id = (int) $req->get_param( 'id' );
			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				return $this->err( 'not_found', __( 'That media item was not found.', 'acps-media-cleanup' ), 404 );
			}
			// Trash-first (reversible) — never a hard delete over the API.
			$trashed = wp_trash_post( $id );
			if ( ! $trashed ) {
				return $this->err( 'delete_failed', __( 'Could not move that file to Trash.', 'acps-media-cleanup' ), 500 );
			}
			return $this->ok( array( 'id' => $id, 'trashed' => true ) );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Guard: auth + rate limiting + brute-force lockout.
	 * ------------------------------------------------------------------ */

	/**
	 * Run the shared guard, then the handler, catching everything.
	 *
	 * @param WP_REST_Request $req      Request.
	 * @param callable        $handler  Returns a WP_REST_Response.
	 * @return WP_REST_Response
	 */
	private function wrap( $req, $handler ) {
		try {
			$guard = $this->guard( $req );
			if ( true !== $guard ) {
				return $guard; // A WP_REST_Response error (auth/rate/lockout).
			}
			return call_user_func( $handler );
		} catch ( \Throwable $e ) {
			self::log( 'handler: ' . $e->getMessage() );
			return $this->err( 'server_error', __( 'Something went wrong handling the request.', 'acps-media-cleanup' ), 500 );
		}
	}

	/**
	 * @param WP_REST_Request $req Request.
	 * @return true|WP_REST_Response True to proceed, or an error response.
	 */
	private function guard( $req ) {
		$ip   = $this->client_ip();
		$iph  = substr( md5( 'acps-mc-ra|' . $ip ), 0, 16 );

		// Locked out for too many bad keys?
		if ( (int) get_transient( 'acps_mc_ra_lock_' . $iph ) ) {
			return $this->err( 'locked_out', __( 'Too many failed attempts. Try again later.', 'acps-media-cleanup' ), 429, array( 'Retry-After' => (string) self::LOCKOUT_TTL ) );
		}

		// Auth: key from header or param, timing-safe compare.
		$secret = trim( (string) ACPS_MC_Settings::get( 'remote_api_key' ) );
		$given  = (string) $req->get_header( 'x_acps_key' );
		if ( '' === $given ) {
			$given = (string) $req->get_param( 'key' );
		}
		if ( '' === $secret || '' === $given || ! hash_equals( $secret, $given ) ) {
			$this->note_auth_failure( $iph );
			return $this->err( 'unauthorized', __( 'Invalid or missing API key.', 'acps-media-cleanup' ), 401 );
		}

		// Per-minute rate limit (per IP).
		$per_min = max( 1, (int) ACPS_MC_Settings::get( 'remote_api_rate_per_min', 30 ) );
		$rk      = 'acps_mc_ra_rl_' . $iph . '_' . gmdate( 'YmdHi' );
		$count   = (int) get_transient( $rk );
		if ( $count >= $per_min ) {
			return $this->err( 'rate_limited', __( 'Rate limit reached. Slow down.', 'acps-media-cleanup' ), 429, array( 'Retry-After' => '60' ) );
		}
		set_transient( $rk, $count + 1, MINUTE_IN_SECONDS );

		// Per-day global cap (all clients).
		$daily_cap = (int) ACPS_MC_Settings::get( 'remote_api_daily_cap', 500 );
		if ( $daily_cap > 0 ) {
			$dk  = 'acps_mc_ra_day_' . gmdate( 'Ymd' );
			$day = (int) get_transient( $dk );
			if ( $day >= $daily_cap ) {
				return $this->err( 'daily_cap', __( 'The daily limit for this API has been reached.', 'acps-media-cleanup' ), 429, array( 'Retry-After' => '3600' ) );
			}
		}

		return true;
	}

	/**
	 * Record a bad-key attempt and lock the IP out once the threshold is hit.
	 *
	 * @param string $iph Hashed IP.
	 */
	private function note_auth_failure( $iph ) {
		$fk    = 'acps_mc_ra_fail_' . $iph;
		$fails = (int) get_transient( $fk ) + 1;
		set_transient( $fk, $fails, self::LOCKOUT_TTL );
		if ( $fails >= self::MAX_AUTH_FAILS ) {
			set_transient( 'acps_mc_ra_lock_' . $iph, 1, self::LOCKOUT_TTL );
			self::log( 'locked out an IP after ' . $fails . ' bad keys.' );
		}
	}

	/**
	 * Increment the per-day counter after a successful upload.
	 */
	private function bump_daily() {
		$dk  = 'acps_mc_ra_day_' . gmdate( 'Ymd' );
		$day = (int) get_transient( $dk );
		set_transient( $dk, $day + 1, DAY_IN_SECONDS );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Best-effort client IP for rate limiting. Behind a known reverse proxy the
	 * left-most X-Forwarded-For entry is used; otherwise REMOTE_ADDR. Only used
	 * for throttling (never for auth), so spoofing at worst loosens a client's
	 * own limit — the per-day global cap still bounds total abuse.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$first = trim( (string) $parts[0] );
			if ( '' !== $first ) {
				$ip = $first;
			}
		}
		$ip = preg_replace( '/[^0-9a-fA-F:\.]/', '', $ip );
		return $ip ? $ip : '0.0.0.0';
	}

	/**
	 * File an attachment into a FileBird folder (0/negative = root/Uncategorized).
	 *
	 * @param int $id        Attachment ID.
	 * @param int $folder_id Folder ID.
	 */
	private function assign_folder( $id, $folder_id ) {
		try {
			if ( ! class_exists( 'ACPS_MC_Folders' ) ) {
				return;
			}
			$folders = new ACPS_MC_Folders();
			if ( method_exists( $folders, 'assign' ) ) {
				$folders->assign( (int) $id, $folder_id > 0 ? (int) $folder_id : 0 );
			}
		} catch ( \Throwable $e ) {
			self::log( 'assign_folder: ' . $e->getMessage() );
		}
	}

	/**
	 * The folder id an attachment currently lives in, or 0.
	 *
	 * @param int $id Attachment ID.
	 * @return int
	 */
	private function folder_of( $id ) {
		try {
			if ( class_exists( 'ACPS_MC_Folders' ) ) {
				$folders = new ACPS_MC_Folders();
				if ( method_exists( $folders, 'folder_for' ) ) {
					return (int) $folders->folder_for( (int) $id );
				}
			}
		} catch ( \Throwable $e ) {
			self::log( 'folder_of: ' . $e->getMessage() );
		}
		return 0;
	}

	/**
	 * A successful JSON response.
	 *
	 * @param array $data Payload.
	 * @return WP_REST_Response
	 */
	private function ok( $data ) {
		$resp = new WP_REST_Response( array_merge( array( 'ok' => true ), $data ), 200 );
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}

	/**
	 * A JSON error response.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 * @param int    $status  HTTP status.
	 * @param array  $headers Extra headers.
	 * @return WP_REST_Response
	 */
	private function err( $code, $message, $status = 400, $headers = array() ) {
		$resp = new WP_REST_Response(
			array( 'ok' => false, 'code' => (string) $code, 'message' => (string) $message ),
			(int) $status
		);
		$resp->header( 'Cache-Control', 'no-store' );
		foreach ( (array) $headers as $k => $v ) {
			$resp->header( $k, (string) $v );
		}
		return $resp;
	}

	/**
	 * The base URL of the remote API, for display in the hidden settings page.
	 *
	 * @return string
	 */
	public static function base_url() {
		return rest_url( self::REST_NS . self::ROUTE_BASE );
	}

	/**
	 * Log without ever throwing (routes through the plugin logger, WP_DEBUG-gated).
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( function_exists( 'acps_mc_log' ) ) {
			acps_mc_log( 'RemoteAPI: ' . $message );
		}
	}
}
