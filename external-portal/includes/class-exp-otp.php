<?php
/**
 * One-time passcodes (spec Section 4, table 2).
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues and verifies OTP codes with per-code attempt throttling.
 */
class EXP_OTP {

	/**
	 * Issue a new OTP for a user and email it. Any prior unused codes for the
	 * same purpose are invalidated first so only the latest code works.
	 *
	 * @param object $user    Portal user row.
	 * @param string $purpose 'login' | 'password_reset'.
	 * @return true|WP_Error
	 */
	public static function issue( $user, $purpose = 'login' ) {
		global $wpdb;
		$table = EXP_Install::table( 'otp' );

		// Basic per-account issuance throttle: no more than N live codes.
		$recent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$user->id,
				EXP_Util::mysql_time( -1 * MINUTE_IN_SECONDS )
			)
		);
		if ( $recent >= 3 ) {
			return new WP_Error( 'exp_otp_throttled', __( 'Too many codes requested. Please wait a minute and try again.', 'external-portal' ) );
		}

		// Invalidate previous unused codes for this purpose.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET used = 1 WHERE user_id = %d AND purpose = %s AND used = 0", // phpcs:ignore WordPress.DB.PreparedSQL
				$user->id,
				$purpose
			)
		);

		$length = (int) EXP_Settings::get( 'otp_length', 6 );
		$ttl     = (int) EXP_Settings::get( 'otp_ttl_minutes', 10 ) * MINUTE_IN_SECONDS;
		$code    = EXP_Util::numeric_code( $length );

		$wpdb->insert(
			$table,
			array(
				'user_id'    => (int) $user->id,
				'code_hash'  => EXP_Util::hmac( $code ),
				'purpose'    => substr( $purpose, 0, 30 ),
				'ip'         => EXP_Util::client_ip(),
				'expires_at' => EXP_Util::mysql_time( $ttl ),
				'created_at' => EXP_Util::now(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$sent = EXP_Mailer::send_otp( $user, $code, $purpose );
		if ( ! $sent ) {
			return new WP_Error( 'exp_mail_failed', __( 'We could not send the code by email. Please try again shortly.', 'external-portal' ) );
		}
		return true;
	}

	/**
	 * Verify a submitted code for a user. Increments per-code attempts and marks
	 * the code used on success.
	 *
	 * @param object $user    Portal user row.
	 * @param string $code    Submitted code.
	 * @param string $purpose Purpose.
	 * @return true|WP_Error
	 */
	public static function verify( $user, $code, $purpose = 'login' ) {
		global $wpdb;
		$table = EXP_Install::table( 'otp' );
		$code  = preg_replace( '/\D/', '', (string) $code );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND purpose = %s AND used = 0 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$user->id,
				$purpose
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'exp_otp_none', __( 'No active code. Please request a new one.', 'external-portal' ) );
		}
		if ( EXP_Util::is_past( $row->expires_at ) ) {
			return new WP_Error( 'exp_otp_expired', __( 'That code has expired. Please request a new one.', 'external-portal' ) );
		}

		$max = (int) EXP_Settings::get( 'otp_max_attempts', 5 );
		if ( (int) $row->attempts >= $max ) {
			// Burn the code so a fresh one is required.
			$wpdb->update( $table, array( 'used' => 1 ), array( 'id' => $row->id ), array( '%d' ), array( '%d' ) );
			return new WP_Error( 'exp_otp_locked', __( 'Too many incorrect attempts. Please request a new code.', 'external-portal' ) );
		}

		$expected = $row->code_hash;
		$actual   = EXP_Util::hmac( $code );

		if ( ! hash_equals( $expected, $actual ) ) {
			$wpdb->update( $table, array( 'attempts' => (int) $row->attempts + 1 ), array( 'id' => $row->id ), array( '%d' ), array( '%d' ) );
			return new WP_Error( 'exp_otp_mismatch', __( 'That code is incorrect.', 'external-portal' ) );
		}

		$wpdb->update( $table, array( 'used' => 1 ), array( 'id' => $row->id ), array( '%d' ), array( '%d' ) );
		return true;
	}

	/**
	 * Delete expired/used codes. Called from cron.
	 */
	public static function purge_expired() {
		global $wpdb;
		$table = EXP_Install::table( 'otp' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at < %s OR used = 1", // phpcs:ignore WordPress.DB.PreparedSQL
				EXP_Util::mysql_time( -1 * DAY_IN_SECONDS )
			)
		);
	}
}
