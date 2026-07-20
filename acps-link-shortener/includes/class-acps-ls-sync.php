<?php
/**
 * Google Sheet sync.
 *
 * Every 3 minutes this fetches a JSON feed produced by a Google Apps Script web
 * app (see google-apps-script/Code.gs) that reads a spreadsheet, then creates a
 * shortened link for each NEW row. The slug ("shortened link name") is taken
 * from a column in the sheet, so operators fully control it per row.
 *
 * This is the intended, transparent version of the "connect to a Google web
 * app" request: it PULLS rows from an endpoint the operator configures. It does
 * NOT write to any hardcoded Google Doc and does NOT run arbitrary remote
 * actions. The remote script only returns data; all link creation happens here,
 * under the same validation/sanitization rules as the manual admin form.
 *
 * Expected JSON shape from the web app:
 *   { "rows": [
 *       { "slug": "open-house", "destination": "https://…", "title": "Open House",
 *         "redirect_type": 301, "active": true },
 *       …
 *   ] }
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Cron driven importer.
 */
class ACPS_LS_Sync {

	/**
	 * Hook the cron callback.
	 */
	public function register() {
		add_action( ACPS_LS_CRON_HOOK, array( $this, 'run' ) );
	}

	/**
	 * Read current settings.
	 *
	 * @return array
	 */
	private function settings() {
		$defaults = array(
			'sync_enabled'  => 0,
			'sheet_url'     => '',
			'sheet_secret'  => '',
			'default_type'  => 302,
		);
		$saved = get_option( ACPS_LS_OPT_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Cron entry point. Fetches the feed and imports new rows.
	 *
	 * @return array|WP_Error Summary [created, skipped, errors] or WP_Error.
	 */
	public function run() {
		$settings = $this->settings();

		if ( empty( $settings['sync_enabled'] ) || empty( $settings['sheet_url'] ) ) {
			return new WP_Error( 'acps_ls_sync_disabled', __( 'Sheet sync is not configured.', 'acps-link-shortener' ) );
		}

		$rows = $this->fetch_rows( $settings['sheet_url'], $settings['sheet_secret'] );
		if ( is_wp_error( $rows ) ) {
			$this->log( 'Fetch failed: ' . $rows->get_error_message() );
			return $rows;
		}

		$summary = array(
			'created' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		foreach ( $rows as $row ) {
			$result = $this->import_row( $row, (int) $settings['default_type'] );

			if ( is_wp_error( $result ) ) {
				$summary['errors'][] = $result->get_error_message();
			} elseif ( 'created' === $result ) {
				$summary['created']++;
			} else {
				$summary['skipped']++;
			}
		}

		update_option(
			'acps_ls_last_sync',
			array(
				'time'    => current_time( 'mysql' ),
				'created' => $summary['created'],
				'skipped' => $summary['skipped'],
				'errors'  => count( $summary['errors'] ),
			)
		);

		return $summary;
	}

	/**
	 * Fetch and decode the JSON feed from the Apps Script web app.
	 *
	 * @param string $url    Web app URL.
	 * @param string $secret Optional shared secret sent as a query arg + header.
	 * @return array|WP_Error Array of row arrays.
	 */
	private function fetch_rows( $url, $secret ) {
		$request_url = esc_url_raw( $url, array( 'https' ) );
		if ( '' === $request_url ) {
			return new WP_Error( 'acps_ls_bad_url', __( 'The Sheet web app URL must be an https URL.', 'acps-link-shortener' ) );
		}

		$args = array(
			'timeout'     => 15,
			'redirection' => 5,
			'headers'     => array( 'Accept' => 'application/json' ),
		);

		if ( '' !== $secret ) {
			$request_url = add_query_arg( 'token', rawurlencode( $secret ), $request_url );
			$args['headers']['X-ACPS-Token'] = $secret;
		}

		$response = wp_remote_get( $request_url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'acps_ls_http', sprintf( /* translators: %d: HTTP status code. */ __( 'Sheet endpoint returned HTTP %d.', 'acps-link-shortener' ), $code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data || ! isset( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
			return new WP_Error( 'acps_ls_bad_json', __( 'Sheet endpoint did not return the expected JSON (a "rows" array).', 'acps-link-shortener' ) );
		}

		return $data['rows'];
	}

	/**
	 * Import a single row. Existing slugs are skipped (idempotent — only NEW
	 * rows create links). Runs the same validation as the manual form.
	 *
	 * @param array $row          Row from the feed.
	 * @param int   $default_type Default redirect type.
	 * @return string|WP_Error 'created', 'skipped', or WP_Error.
	 */
	private function import_row( $row, $default_type ) {
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'acps_ls_row_shape', __( 'Malformed sheet row.', 'acps-link-shortener' ) );
		}

		$slug = isset( $row['slug'] ) ? sanitize_title( $row['slug'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error( 'acps_ls_row_slug', __( 'Row is missing a slug (shortened link name).', 'acps-link-shortener' ) );
		}

		// Idempotency: if a link with this slug already exists, do nothing.
		if ( ACPS_LS_DB::get_by_slug( $slug ) ) {
			return 'skipped';
		}

		$slug_check = ACPS_LS_DB::validate_slug( $slug );
		if ( is_wp_error( $slug_check ) ) {
			return $slug_check;
		}

		$destination = ACPS_LS_DB::validate_destination( isset( $row['destination'] ) ? $row['destination'] : '' );
		if ( is_wp_error( $destination ) ) {
			return $destination;
		}

		$redirect_type = isset( $row['redirect_type'] ) ? (int) $row['redirect_type'] : $default_type;
		if ( ! acps_ls_allow_permanent() ) {
			$redirect_type = 302; // Permanent disabled: never store a 301 from the sheet.
		}
		$is_active     = isset( $row['active'] ) ? (int) filter_var( $row['active'], FILTER_VALIDATE_BOOLEAN ) : 1;

		$created = ACPS_LS_DB::create(
			array(
				'slug'          => $slug,
				'destination'   => $destination,
				'title'         => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '',
				'redirect_type' => $redirect_type,
				'is_active'     => $is_active,
				'source'        => 'sheet',
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return 'created';
	}

	/**
	 * Lightweight logger (only when WP_DEBUG is on).
	 *
	 * @param string $message Message.
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ACPS Link Shortener] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
