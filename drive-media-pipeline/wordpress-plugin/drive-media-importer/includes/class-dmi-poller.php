<?php
/**
 * The poll cycle (build brief §5c):
 * enable check → working-hours gate → transient lock → claim pending →
 * per-row fetch/decode/sniff/import → one batched ack → release lock.
 *
 * Each row is wrapped individually so one bad file never aborts the batch,
 * and every failure produces an error_message a non-technical uploader
 * can act on (the sheet is the notification mechanism).
 */

defined( 'ABSPATH' ) || exit;

class DMI_Poller {

	const LOCK_TTL  = 10 * MINUTE_IN_SECONDS;
	const LOG_OPTION = 'dmi_recent_log';

	/**
	 * Run one poll cycle.
	 *
	 * @param bool $manual True when triggered by the admin button or WP-CLI
	 *                     (bypasses the enable toggle and hours gate).
	 * @return string Human-readable summary of the run.
	 */
	public static function run( $manual = false ) {
		$settings = DMI_Settings::get();

		if ( ! $manual && empty( $settings['enabled'] ) ) {
			return 'Skipped: polling is disabled.';
		}

		if ( ! $manual && ! self::within_working_hours( $settings ) ) {
			return 'Skipped: outside the working-hours window.';
		}

		// Transient lock prevents overlapping runs.
		if ( false !== get_site_transient( DMI_LOCK_TRANSIENT ) ) {
			return self::log( 'Skipped: another poll is already running.' );
		}
		set_site_transient( DMI_LOCK_TRANSIENT, time(), self::LOCK_TTL );

		try {
			return self::log( self::poll( $settings ) );
		} finally {
			delete_site_transient( DMI_LOCK_TRANSIENT );
		}
	}

	private static function poll( array $settings ) {
		$rows = DMI_Client::fetch_pending( $settings['batch_size'] );
		if ( is_wp_error( $rows ) ) {
			return 'Poll failed: ' . $rows->get_error_message();
		}
		if ( empty( $rows ) ) {
			return 'No pending rows.';
		}

		$results = array();
		foreach ( $rows as $row ) {
			$results[] = self::process_row( $row, $settings );
		}

		// One batched ack per cycle.
		$ack = DMI_Client::ack( $results );
		$ack_note = is_wp_error( $ack )
			? ' Ack FAILED (' . $ack->get_error_message() . ') — rows will be re-queued by the stale sweep.'
			: '';

		$done   = count( array_filter( $results, fn( $r ) => 'done' === $r['status'] ) );
		$errors = count( $results ) - $done;

		return sprintf( 'Processed %d row(s): %d imported, %d failed.%s', count( $results ), $done, $errors, $ack_note );
	}

	/** Process one claimed row; never throws. */
	private static function process_row( array $row, array $settings ) {
		$row = wp_parse_args( $row, array(
			'row_id'      => '',
			'file_id'     => '',
			'filename'    => '',
			'alt_text'    => '',
			'location'    => '',
			'uploader'    => '',
			'target_site' => '',
		) );

		$fail = function ( $message ) use ( $row ) {
			return array(
				'row_id'           => $row['row_id'],
				'status'           => 'error',
				'wp_url'           => '',
				'wp_attachment_id' => '',
				'error_message'    => $message,
			);
		};

		try {
			// Defense in depth: the card enforces this, but reject any row
			// that slipped through without alt text (WCAG 1.1.1).
			if ( '' === trim( (string) $row['alt_text'] ) ) {
				return $fail( 'Row has no alt text. Alt text is required — re-queue the image with a description.' );
			}

			$file = DMI_Client::fetch_file( $row['row_id'], $row['file_id'] );
			if ( is_wp_error( $file ) ) {
				return $fail( 'Could not fetch the file from Google Drive: ' . $file->get_error_message() );
			}

			$bytes = base64_decode( (string) ( $file['data_base64'] ?? '' ), true );
			if ( false === $bytes || '' === $bytes ) {
				return $fail( 'The file data received from Google was empty or corrupted. Try re-queuing the image.' );
			}
			if ( isset( $file['byte_length'] ) && strlen( $bytes ) !== (int) $file['byte_length'] ) {
				return $fail( 'The file arrived incomplete (size mismatch). Try re-queuing the image.' );
			}

			return DMI_Importer::import_row( $row, $bytes );
		} catch ( \Throwable $e ) {
			return $fail( 'Unexpected error: ' . $e->getMessage() );
		}
	}

	/**
	 * Working-hours gate. Uses wp_timezone() — never bare date(), which
	 * would run on the server's UTC clock and shift the window.
	 */
	public static function within_working_hours( array $settings ) {
		if ( empty( $settings['hours_enabled'] ) ) {
			return true;
		}

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$day = (int) $now->format( 'N' ); // 1 = Mon … 7 = Sun
		if ( ! in_array( $day, array_map( 'intval', (array) $settings['hours_days'] ), true ) ) {
			return false;
		}

		$time = $now->format( 'H:i' );
		return ( $time >= $settings['hours_start'] ) && ( $time < $settings['hours_end'] );
	}

	/** Log to PHP error log and keep the last 50 entries in a site option. */
	private static function log( $message ) {
		$entry = '[' . current_time( 'mysql' ) . '] ' . $message;
		error_log( 'Drive Media Importer: ' . $entry );

		$log = (array) get_site_option( self::LOG_OPTION, array() );
		$log[] = $entry;
		update_site_option( self::LOG_OPTION, array_slice( $log, -50 ) );

		return $message;
	}
}
