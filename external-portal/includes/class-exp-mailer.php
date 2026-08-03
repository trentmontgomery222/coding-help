<?php
/**
 * Transactional email (OTP codes, admin queue notifications).
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the plugin's emails using wp_mail with simple, overridable templates.
 */
class EXP_Mailer {

	/**
	 * Common headers (From name).
	 *
	 * @return array
	 */
	protected static function headers() {
		$from_name  = EXP_Settings::get( 'email_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'admin_email' );
		return array(
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);
	}

	/**
	 * Email a login/reset OTP to a portal user.
	 *
	 * @param object $user    Portal user row.
	 * @param string $code    Plain OTP code.
	 * @param string $purpose Purpose.
	 * @return bool
	 */
	public static function send_otp( $user, $code, $purpose = 'login' ) {
		$ttl  = (int) EXP_Settings::get( 'otp_ttl_minutes', 10 );
		$site = get_bloginfo( 'name' );

		if ( 'password_reset' === $purpose ) {
			/* translators: %s: site name */
			$subject = sprintf( __( 'Your %s password-reset code', 'external-portal' ), $site );
		} else {
			/* translators: %s: site name */
			$subject = sprintf( __( 'Your %s sign-in code', 'external-portal' ), $site );
		}

		$lines = array(
			sprintf(
				/* translators: %s: display name or email */
				__( 'Hello %s,', 'external-portal' ),
				$user->display_name ? $user->display_name : $user->email
			),
			'',
			__( 'Your one-time code is:', 'external-portal' ),
			'',
			'    ' . $code,
			'',
			sprintf(
				/* translators: %d: minutes */
				_n( 'This code expires in %d minute.', 'This code expires in %d minutes.', $ttl, 'external-portal' ),
				$ttl
			),
			__( 'If you did not request this, you can ignore this message.', 'external-portal' ),
		);

		$body    = implode( "\n", $lines );
		$subject = apply_filters( 'exp_otp_email_subject', $subject, $user, $purpose );
		$body    = apply_filters( 'exp_otp_email_body', $body, $user, $code, $purpose );

		return (bool) wp_mail( $user->email, $subject, $body, self::headers() );
	}

	/**
	 * Notify site admins that a new item was queued (spec Section 6).
	 *
	 * @param int    $queue_id Queue row id.
	 * @param string $type     Submission type.
	 * @return bool
	 */
	public static function notify_new_queue_item( $queue_id, $type ) {
		if ( ! EXP_Settings::get( 'notify_on_new_queue_item', 1 ) ) {
			return false;
		}
		$to = EXP_Settings::get( 'admin_notify_email', get_option( 'admin_email' ) );
		if ( ! is_email( $to ) ) {
			return false;
		}

		$review_url = admin_url( 'options-general.php?page=external-portal&tab=queue&item=' . (int) $queue_id );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] New portal submission awaiting review', 'external-portal' ), get_bloginfo( 'name' ) );
		$body    = implode(
			"\n",
			array(
				__( 'A portal user submitted a new item for review.', 'external-portal' ),
				'',
				/* translators: %s: submission type */
				sprintf( __( 'Type: %s', 'external-portal' ), $type ),
				/* translators: %d: queue id */
				sprintf( __( 'Reference: #%d', 'external-portal' ), (int) $queue_id ),
				'',
				__( 'Review it here:', 'external-portal' ),
				$review_url,
			)
		);

		return (bool) wp_mail( $to, $subject, $body, self::headers() );
	}
}
