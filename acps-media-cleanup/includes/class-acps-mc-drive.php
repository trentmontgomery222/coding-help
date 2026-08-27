<?php
/**
 * Google Drive drip-importer.
 *
 * Two front-ends feed one shared ingest pipeline so a big dump of photos in a
 * Drive folder trickles into the media library over time (slow by day, faster
 * at night) instead of all at once:
 *
 *   1. PULL  — WordPress lists + downloads files from a shared Drive folder on a
 *              schedule, using a Google service account (Drive API). Requires a
 *              service-account JSON key and the folder shared with it.
 *   2. PUSH  — a Google Apps Script (running in the user's own Google account)
 *              posts files to a REST endpoint here, authenticated with a shared
 *              token. See google-apps-script/Code.gs.
 *
 * HEIC/HEIF files are intentionally skipped by this importer: there is no
 * browser here to convert them (that only happens for interactive uploads in
 * FileMedia), and the server can't convert them either. Skipped files are moved
 * aside (pull) or reported back (push) so they never block the queue.
 *
 * Everything is gated behind explicit settings; if Drive import is off or
 * unconfigured, this class does nothing.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Drive {

	const TICK_HOOK      = 'acps_mc_drive_tick';
	const SCHEDULE       = 'acps_mc_5min';
	const REST_NS        = 'acps-mc/v1';
	const IMPORTED_NAME  = 'Imported to WordPress';
	const SKIPPED_NAME   = 'Skipped (not imported)';
	const TOKEN_TRANSIENT = 'acps_mc_drive_token';

	public function __construct() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::TICK_HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Keep the schedule in sync with the setting whenever admin loads.
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Scheduling
	 * ------------------------------------------------------------------ */

	public static function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (ACPS Drive import)', 'acps-media-cleanup' ),
		);
		return $schedules;
	}

	/** Schedule the tick when pull is enabled, clear it when it isn't. */
	public static function maybe_schedule() {
		$on = (bool) ACPS_MC_Settings::get( 'drive_pull_enabled' );
		$scheduled = wp_next_scheduled( self::TICK_HOOK );
		if ( $on && ! $scheduled ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::TICK_HOOK );
		} elseif ( ! $on && $scheduled ) {
			self::unschedule();
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::TICK_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::TICK_HOOK );
			$ts = wp_next_scheduled( self::TICK_HOOK );
		}
	}

	/* ------------------------------------------------------------------ *
	 * REST push endpoint (Apps Script → WordPress)
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/ingest',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_ingest' ),
				'permission_callback' => array( __CLASS__, 'rest_auth' ),
			)
		);
		// A tiny health/ping so the script can verify the URL + token.
		register_rest_route(
			self::REST_NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return array( 'ok' => true, 'plugin' => 'acps-media-cleanup' );
				},
				'permission_callback' => array( __CLASS__, 'rest_auth' ),
			)
		);
	}

	/**
	 * Constant-time token check. The token is sent in the X-ACPS-Token header
	 * (or a `token` field for simplicity from Apps Script).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return bool
	 */
	public static function rest_auth( $req ) {
		$secret = (string) ACPS_MC_Settings::get( 'drive_push_token' );
		if ( '' === $secret ) {
			return false; // Push disabled until a token is set.
		}
		$given = (string) $req->get_header( 'x-acps-token' );
		if ( '' === $given ) {
			$given = (string) $req->get_param( 'token' );
		}
		return hash_equals( $secret, $given );
	}

	/**
	 * Receive one file from the Apps Script and ingest it.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|array
	 */
	public static function rest_ingest( $req ) {
		try {
			$files = $req->get_file_params();
			if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
				return new WP_REST_Response( array( 'status' => 'error', 'message' => 'No file received.' ), 400 );
			}
			$upload   = $files['file'];
			$filename = ! empty( $upload['name'] ) ? $upload['name'] : ( $req->get_param( 'filename' ) ? (string) $req->get_param( 'filename' ) : 'drive-file' );
			$folder   = self::target_folder();

			$res = self::ingest_path( $upload['tmp_name'], $filename, $folder, true );
			if ( is_wp_error( $res ) ) {
				$code = $res->get_error_code();
				// "skipped"/"duplicate" are normal outcomes, not failures: report 200
				// with a status the script can act on (it moves the Drive file aside).
				if ( in_array( $code, array( 'skipped_heic', 'duplicate', 'bad_type' ), true ) ) {
					return array( 'status' => 'skipped', 'reason' => $code, 'message' => $res->get_error_message() );
				}
				return new WP_REST_Response( array( 'status' => 'error', 'reason' => $code, 'message' => $res->get_error_message() ), 500 );
			}
			return array( 'status' => 'ok', 'id' => (int) $res, 'url' => wp_get_attachment_url( (int) $res ) );
		} catch ( \Throwable $e ) {
			self::log( 'ingest', 'REST ingest error: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Ingest failed.' ), 500 );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Shared ingest pipeline
	 * ------------------------------------------------------------------ */

	/**
	 * Turn a file already on disk into a media-library attachment.
	 *
	 * @param string $path       Absolute path to the file on disk.
	 * @param string $filename   Desired filename (with extension).
	 * @param int    $folder_id  FileBird folder to file it into (0 = Uncategorized).
	 * @param bool   $skip_dupes Skip byte-for-byte duplicates of existing files.
	 * @return int|WP_Error Attachment ID, or WP_Error (codes: skipped_heic,
	 *                      duplicate, bad_type, or a sideload error).
	 */
	public static function ingest_path( $path, $filename, $folder_id = 0, $skip_dupes = true ) {
		$filename = sanitize_file_name( wp_basename( (string) $filename ) );
		$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, array( 'heic', 'heif' ), true ) ) {
			return new WP_Error( 'skipped_heic', __( 'HEIC/HEIF is skipped by the Drive importer (no browser to convert it).', 'acps-media-cleanup' ) );
		}
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'missing', __( 'File not found on disk.', 'acps-media-cleanup' ) );
		}

		$check = wp_check_filetype( $filename );
		if ( empty( $check['type'] ) ) {
			return new WP_Error( 'bad_type', __( 'File type is not permitted.', 'acps-media-cleanup' ) );
		}

		if ( $skip_dupes ) {
			$hash = @md5_file( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $hash && self::hash_exists( $hash ) ) {
				return new WP_Error( 'duplicate', __( 'A byte-for-byte copy of this file is already in the library.', 'acps-media-cleanup' ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// media_handle_sideload consumes (moves) the temp file into the uploads
		// dir, so copy first — callers may still need the original.
		$tmp = wp_tempnam( $filename );
		if ( ! $tmp || ! @copy( $path, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'copy_failed', __( 'Could not stage the file for import.', 'acps-media-cleanup' ) );
		}

		// media_handle_sideload() already passes test_form=false to
		// wp_handle_sideload() internally; its 4th arg is post data, not overrides.
		$id = media_handle_sideload( array( 'name' => $filename, 'tmp_name' => $tmp ), 0 );
		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			return $id;
		}

		$folders = new ACPS_MC_Folders();
		if ( $folders->is_writable() ) {
			$folders->assign( (int) $id, $folder_id > 0 ? (int) $folder_id : 0 );
		}

		// Record the content hash so future imports/uploads can detect duplicates.
		if ( class_exists( 'ACPS_MC_Duplicates' ) ) {
			ACPS_MC_Duplicates::hash_file( (int) $id );
		}

		// Invalidate the FileMedia grid cache so the new file shows up.
		update_option( 'acps_mm_version', (int) get_option( 'acps_mm_version', 1 ) + 1, false );

		return (int) $id;
	}

	/** Does any existing attachment already have this content hash? */
	protected static function hash_exists( $hash ) {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_acps_mc_filehash' AND meta_value = %s LIMIT 1", $hash ) );
		return ! empty( $id );
	}

	/** FileBird folder new Drive imports are filed into (0 = Uncategorized). */
	protected static function target_folder() {
		return (int) ACPS_MC_Settings::get( 'drive_target_folder', 0 );
	}

	/* ------------------------------------------------------------------ *
	 * PULL: WordPress downloads from Drive on a schedule
	 * ------------------------------------------------------------------ */

	/** Cron tick: import up to the current rate's worth of files. */
	public static function tick() {
		// Cron context — an unhandled error here must not break the cron run.
		try {
			if ( ! class_exists( 'ACPS_MC_Settings' ) ) {
				return;
			}
			$s = ACPS_MC_Settings::all();
			if ( empty( $s['drive_pull_enabled'] ) || empty( $s['drive_folder_id'] ) ) {
				return;
			}
			$rate = self::current_rate( $s );
			if ( $rate <= 0 ) {
				return;
			}
			self::pull_batch( (int) $s['drive_folder_id'], $rate );
		} catch ( \Throwable $e ) {
			self::log( 'tick', 'Drive tick error: ' . $e->getMessage() );
		}
	}

	/** Files-per-tick right now, from the day/night schedule. */
	protected static function current_rate( $s ) {
		$day_start   = isset( $s['drive_day_start'] ) ? (int) $s['drive_day_start'] : 7;   // 7am
		$night_start = isset( $s['drive_night_start'] ) ? (int) $s['drive_night_start'] : 20; // 8pm
		$day_rate    = isset( $s['drive_day_rate'] ) ? max( 0, (int) $s['drive_day_rate'] ) : 3;
		$night_rate  = isset( $s['drive_night_rate'] ) ? max( 0, (int) $s['drive_night_rate'] ) : 40;

		$hour = (int) wp_date( 'G' ); // site-timezone hour 0-23
		$is_day = ( $day_start <= $night_start )
			? ( $hour >= $day_start && $hour < $night_start )
			: ( $hour >= $day_start || $hour < $night_start ); // wrap past midnight
		return $is_day ? $day_rate : $night_rate;
	}

	/**
	 * List the source folder and import up to $limit files, moving each aside
	 * (Imported / Skipped) so it is not seen again.
	 *
	 * @param string $folder Drive source folder ID.
	 * @param int    $limit  Max files to process this tick.
	 * @return array|WP_Error Summary counts, or WP_Error on an auth/network fault.
	 */
	public static function pull_batch( $folder, $limit ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			self::log( 'auth', $token->get_error_message() );
			return $token;
		}

		$files = self::drive_list( $folder, $token, max( $limit, 10 ) );
		if ( is_wp_error( $files ) ) {
			self::log( 'list', $files->get_error_message() );
			return $files;
		}

		$imported_id = self::ensure_subfolder( $folder, self::IMPORTED_NAME, $token );
		$skipped_id  = self::ensure_subfolder( $folder, self::SKIPPED_NAME, $token );
		$target      = self::target_folder();

		// Without the aside sub-folders we can't move processed files out of the
		// source, so they'd be re-listed and re-downloaded every tick. Bail with a
		// clear message — this almost always means the folder was shared with the
		// service account as Viewer instead of Editor.
		if ( ! $imported_id ) {
			self::log( 'perm', __( 'Could not create the "Imported to WordPress" sub-folder. Share the Drive folder with the service account as Editor.', 'acps-media-cleanup' ) );
			return new WP_Error( 'no_write', 'No write access to the Drive folder.' );
		}

		$done = array( 'imported' => 0, 'skipped' => 0, 'errors' => 0 );
		$n    = 0;
		foreach ( $files as $f ) {
			if ( $n >= $limit ) {
				break;
			}
			// Skip the aside-subfolders themselves and any nested folders.
			if ( 'application/vnd.google-apps.folder' === $f['mimeType'] ) {
				continue;
			}
			$n++;

			$tmp = self::drive_download( $f['id'], $f['name'], $token );
			if ( is_wp_error( $tmp ) ) {
				// A download fault is usually transient (token/network) — stop this
				// tick and leave the file in place to retry next time.
				self::log( 'download', $f['name'] . ': ' . $tmp->get_error_message() );
				$done['errors']++;
				break;
			}

			$res = self::ingest_path( $tmp, $f['name'], $target, true );
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			if ( is_wp_error( $res ) ) {
				// HEIC / duplicate / bad type / bad file — move aside so it doesn't
				// re-list forever, and record why on the Drive file's description.
				self::drive_move( $f['id'], $folder, $skipped_id, $token );
				self::log( 'skip', $f['name'] . ': ' . $res->get_error_code() );
				$done['skipped']++;
			} else {
				self::drive_move( $f['id'], $folder, $imported_id, $token );
				$done['imported']++;
			}
		}

		self::set_status( $done );
		return $done;
	}

	/* ------------------------------------------------------------------ *
	 * Minimal Google Drive API client (service account, no SDK)
	 * ------------------------------------------------------------------ */

	/** Decoded service-account JSON, or null. */
	protected static function service_account() {
		$raw = (string) ACPS_MC_Settings::get( 'drive_service_account' );
		if ( '' === trim( $raw ) ) {
			return null;
		}
		$sa = json_decode( $raw, true );
		return ( is_array( $sa ) && ! empty( $sa['client_email'] ) && ! empty( $sa['private_key'] ) ) ? $sa : null;
	}

	protected static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/** Get (and cache) an OAuth2 access token for the Drive scope. */
	protected static function access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}
		$sa = self::service_account();
		if ( ! $sa ) {
			return new WP_Error( 'no_sa', __( 'No valid Google service-account key is configured.', 'acps-media-cleanup' ) );
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'no_openssl', __( 'PHP OpenSSL is required to sign the Google token but is not available.', 'acps-media-cleanup' ) );
		}

		$now    = time();
		$header = self::b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claim  = self::b64url(
			wp_json_encode(
				array(
					'iss'   => $sa['client_email'],
					'scope' => 'https://www.googleapis.com/auth/drive',
					'aud'   => 'https://oauth2.googleapis.com/token',
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$signing_input = $header . '.' . $claim;
		$signature     = '';
		if ( ! openssl_sign( $signing_input, $signature, $sa['private_key'], 'sha256WithRSAEncryption' ) ) {
			return new WP_Error( 'sign_failed', __( 'Could not sign the Google token — check the service-account key.', 'acps-media-cleanup' ) );
		}
		$jwt = $signing_input . '.' . self::b64url( $signature );

		$resp = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 25,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg = isset( $data['error_description'] ) ? $data['error_description'] : wp_remote_retrieve_body( $resp );
			return new WP_Error( 'token_failed', __( 'Google refused the token request: ', 'acps-media-cleanup' ) . $msg );
		}
		$ttl = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		set_transient( self::TOKEN_TRANSIENT, $data['access_token'], max( 60, $ttl - 60 ) );
		return $data['access_token'];
	}

	/** GET helper against the Drive API returning decoded JSON. */
	protected static function drive_get_json( $url, $token ) {
		$resp = wp_remote_get(
			$url,
			array( 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) )
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'drive_http', $msg );
		}
		return is_array( $body ) ? $body : array();
	}

	/** List non-trashed files directly in $folder (a page). */
	protected static function drive_list( $folder, $token, $page_size = 50 ) {
		$q   = "'" . str_replace( "'", "\\'", $folder ) . "' in parents and trashed = false";
		$url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query(
			array(
				'q'                         => $q,
				'fields'                    => 'files(id,name,mimeType,size)',
				'pageSize'                  => min( 200, max( 1, (int) $page_size ) ),
				'orderBy'                   => 'createdTime',
				'supportsAllDrives'         => 'true',
				'includeItemsFromAllDrives' => 'true',
			)
		);
		$body = self::drive_get_json( $url, $token );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return isset( $body['files'] ) ? $body['files'] : array();
	}

	/** Download a Drive file to a temp path. */
	protected static function drive_download( $id, $name, $token ) {
		$url  = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $id ) . '?alt=media&supportsAllDrives=true';
		$tmp  = wp_tempnam( $name ? $name : $id );
		if ( ! $tmp ) {
			return new WP_Error( 'tmp', __( 'Could not create a temp file.', 'acps-media-cleanup' ) );
		}
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $tmp,
				'headers'  => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			if ( file_exists( $tmp ) ) { @unlink( $tmp ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			if ( file_exists( $tmp ) ) { @unlink( $tmp ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'drive_dl', __( 'Drive download failed: HTTP ', 'acps-media-cleanup' ) . $code );
		}
		return $tmp;
	}

	/** Find or create a sub-folder by name under $parent. Returns its ID or ''. */
	protected static function ensure_subfolder( $parent, $name, $token ) {
		$safe = str_replace( array( "'", '\\' ), array( "\\'", '\\\\' ), $name );
		$q    = "name = '" . $safe . "' and mimeType = 'application/vnd.google-apps.folder' and '" . str_replace( "'", "\\'", $parent ) . "' in parents and trashed = false";
		$url  = 'https://www.googleapis.com/drive/v3/files?' . http_build_query( array( 'q' => $q, 'fields' => 'files(id,name)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true' ) );
		$body = self::drive_get_json( $url, $token );
		if ( ! is_wp_error( $body ) && ! empty( $body['files'][0]['id'] ) ) {
			return $body['files'][0]['id'];
		}
		// Create it.
		$resp = wp_remote_post(
			'https://www.googleapis.com/drive/v3/files?supportsAllDrives=true',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'name'     => $name,
						'mimeType' => 'application/vnd.google-apps.folder',
						'parents'  => array( $parent ),
					)
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return '';
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		return isset( $data['id'] ) ? $data['id'] : '';
	}

	/** Move a Drive file from $from folder into $to folder. */
	protected static function drive_move( $id, $from, $to, $token ) {
		if ( ! $to ) {
			return; // No aside-folder available; leave in place.
		}
		$url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $id ) . '?' . http_build_query(
			array(
				'addParents'        => $to,
				'removeParents'     => $from,
				'fields'            => 'id,parents',
				'supportsAllDrives' => 'true',
			)
		);
		wp_remote_request(
			$url,
			array(
				'method'  => 'PATCH',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => '{}',
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Status / log (small, for the settings screen)
	 * ------------------------------------------------------------------ */

	protected static function set_status( $counts ) {
		$status = get_option( 'acps_mc_drive_status', array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		$status['last_run']      = time();
		$status['last_imported'] = (int) $counts['imported'];
		$status['last_skipped']  = (int) $counts['skipped'];
		$status['total_imported'] = (int) ( isset( $status['total_imported'] ) ? $status['total_imported'] : 0 ) + (int) $counts['imported'];
		update_option( 'acps_mc_drive_status', $status, false );
	}

	public static function get_status() {
		$s = get_option( 'acps_mc_drive_status', array() );
		return is_array( $s ) ? $s : array();
	}

	protected static function log( $type, $message ) {
		$log = get_option( 'acps_mc_drive_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, array( 't' => time(), 'type' => $type, 'msg' => (string) $message ) );
		$log = array_slice( $log, 0, 30 ); // keep the last 30 lines only
		update_option( 'acps_mc_drive_log', $log, false );
	}

	public static function get_log() {
		$l = get_option( 'acps_mc_drive_log', array() );
		return is_array( $l ) ? $l : array();
	}
}
