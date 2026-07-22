<?php
/**
 * Two-way Google Sheet sync (WordPress -> Google).
 *
 * Every 3 minutes WordPress POSTs its current links to a Google Apps Script web
 * app (the deployed Code.gs). The script:
 *   1. Mirrors those links into the spreadsheet (so shortcode/admin links show up
 *      in the sheet), and
 *   2. Returns the sheet's current rows as the desired state.
 *
 * WordPress then reconciles the returned rows:
 *   - New sheet rows          -> create a link (source 'sheet').
 *   - Changed sheet rows      -> update the matching 'sheet' link.
 *   - Rows removed / flagged   -> delete the matching link ONLY if it originated
 *                                 from the sheet. Links made via the shortcode or
 *                                 in wp-admin are never auto-deleted.
 *
 * Direction is WordPress -> Google by request. If runs fail with a connection
 * error, the host likely blocks outbound HTTPS to script.google.com; use the
 * "Test connection" button on the settings screen to confirm.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Cron driven two-way importer/exporter.
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
			'sync_enabled' => 0,
			'sheet_url'    => '',
			'sheet_secret' => '',
		);
		$saved = get_option( ACPS_LS_OPT_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Cron entry point.
	 *
	 * @return array|WP_Error Summary or error.
	 */
	public function run() {
		$settings = $this->settings();

		if ( empty( $settings['sync_enabled'] ) || empty( $settings['sheet_url'] ) ) {
			return new WP_Error( 'acps_ls_sync_disabled', __( 'Sheet sync is not configured.', 'acps-link-shortener' ) );
		}

		$response = $this->exchange( $settings['sheet_url'], $settings['sheet_secret'] );
		if ( is_wp_error( $response ) ) {
			$this->record( array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$summary = $this->reconcile( $response );
		$this->record( $summary );
		return $summary;
	}

	/**
	 * POST the current WP links to the web app and decode the returned rows.
	 *
	 * @param string $url    Web app URL.
	 * @param string $secret Optional shared secret.
	 * @return array|WP_Error Array of sheet rows, or error.
	 */
	private function exchange( $url, $secret ) {
		$request_url = esc_url_raw( $url, array( 'https' ) );
		if ( '' === $request_url ) {
			return new WP_Error( 'acps_ls_bad_url', __( 'The web app URL must be an https URL.', 'acps-link-shortener' ) );
		}

		$payload = array(
			'secret' => (string) $secret,
			'links'  => $this->export_links(),
		);

		$response = wp_remote_post(
			$request_url,
			array(
				'timeout'     => 20,
				'redirection' => 5,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'acps_ls_http',
				sprintf( /* translators: %d: HTTP status. */ __( 'Web app returned HTTP %d.', 'acps-link-shortener' ), $code )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
			return new WP_Error( 'acps_ls_bad_json', __( 'Web app did not return the expected JSON (a "rows" array).', 'acps-link-shortener' ) );
		}

		return $data['rows'];
	}

	/**
	 * Build the export payload of every WP link.
	 *
	 * @return array[]
	 */
	private function export_links() {
		$out = array();
		foreach ( ACPS_LS_DB::all() as $link ) {
			$out[] = array(
				'slug'        => $link->slug,
				'destination' => $link->destination,
				'active'      => (int) $link->is_active ? true : false,
				'source'      => $link->source,
				'clicks'      => (int) $link->clicks,
				'short_url'   => acps_ls_short_url( $link->slug ),
			);
		}
		return $out;
	}

	/**
	 * Reconcile the sheet's rows into WordPress.
	 *
	 * @param array $rows Rows returned by the web app.
	 * @return array Summary.
	 */
	private function reconcile( $rows ) {
		$summary = array(
			'created' => 0,
			'updated' => 0,
			'deleted' => 0,
			'skipped' => 0,
			'errors'  => 0,
			'time'    => current_time( 'mysql' ),
		);

		$seen = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$summary['errors']++;
				continue;
			}

			$slug = ACPS_LS_DB::sanitize_slug_path( isset( $row['slug'] ) ? $row['slug'] : '' );
			if ( '' === $slug ) {
				$summary['errors']++;
				continue;
			}
			$seen[ $slug ] = true;

			$existing = ACPS_LS_DB::get_by_slug( $slug );

			// Explicit delete flag: only removes sheet-origin links.
			$delete = ! empty( $row['delete'] );
			if ( $delete ) {
				if ( $existing && 'sheet' === $existing->source ) {
					ACPS_LS_DB::delete( (int) $existing->id );
					$summary['deleted']++;
				} else {
					$summary['skipped']++;
				}
				continue;
			}

			$destination = ACPS_LS_DB::validate_destination( isset( $row['destination'] ) ? $row['destination'] : '' );
			if ( is_wp_error( $destination ) ) {
				$summary['errors']++;
				continue;
			}

			$active = isset( $row['active'] ) ? (int) filter_var( $row['active'], FILTER_VALIDATE_BOOLEAN ) : 1;

			if ( $existing ) {
				// Only sheet-origin links are altered by the sync; shortcode/admin
				// links are left exactly as they are (their destination is locked).
				if ( 'sheet' === $existing->source ) {
					ACPS_LS_DB::update(
						(int) $existing->id,
						array(
							'destination' => $destination,
							'is_active'   => $active,
						)
					);
					$summary['updated']++;
				} else {
					$summary['skipped']++;
				}
				continue;
			}

			$created = ACPS_LS_DB::create(
				array(
					'slug'          => $slug,
					'destination'   => $destination,
					'title'         => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '',
					'redirect_type' => 302,
					'is_active'     => $active,
					'source'        => 'sheet',
					'creator_label' => 'Google Sheet',
				)
			);
			if ( is_wp_error( $created ) ) {
				$summary['errors']++;
			} else {
				$summary['created']++;
			}
		}

		// Deletion by omission: a sheet-origin link whose row disappeared.
		foreach ( ACPS_LS_DB::all() as $link ) {
			if ( 'sheet' === $link->source && empty( $seen[ $link->slug ] ) ) {
				ACPS_LS_DB::delete( (int) $link->id );
				$summary['deleted']++;
			}
		}

		return $summary;
	}

	/**
	 * Store the last-sync summary for the settings screen.
	 *
	 * @param array $summary Summary.
	 */
	private function record( $summary ) {
		$summary['time'] = isset( $summary['time'] ) ? $summary['time'] : current_time( 'mysql' );
		update_option( 'acps_ls_last_sync', $summary );
	}

	/**
	 * Run a live connection test (used by the settings "Test connection" button).
	 *
	 * @return array { ok: bool, message: string }
	 */
	public function test_connection() {
		$settings = $this->settings();
		if ( empty( $settings['sheet_url'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Enter and save a web app URL first.', 'acps-link-shortener' ),
			);
		}

		$result = $this->exchange( $settings['sheet_url'], $settings['sheet_secret'] );
		if ( is_wp_error( $result ) ) {
			return array(
				'ok'      => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %d: number of rows returned. */
				__( 'Connected. The web app returned %d rows.', 'acps-link-shortener' ),
				count( $result )
			),
		);
	}
}
