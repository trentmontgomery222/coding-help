<?php
/**
 * Login/OTP rate limiting and lockout (spec Section 3).
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two layers: per-account failed-attempt lockout (persisted on the user row)
 * and a coarse per-IP throttle (transient) to blunt enumeration/spraying.
 */
class EXP_Rate_Limit {

	/**
	 * Register a failed login for a user; lock the account past the threshold.
	 *
	 * @param object $user Portal user row.
	 */
	public static function register_failure( $user ) {
		$threshold = (int) EXP_Settings::get( 'login_lockout_threshold', 5 );
		$minutes   = (int) EXP_Settings::get( 'login_lockout_minutes', 15 );
		$failed    = (int) $user->failed_logins + 1;

		$fields = array( 'failed_logins' => $failed );
		if ( $failed >= $threshold ) {
			$fields['locked_until']  = EXP_Util::mysql_time( $minutes * MINUTE_IN_SECONDS );
			$fields['failed_logins'] = 0;
			EXP_Audit::log(
				'login.locked',
				array(
					'actor_type' => 'portal',
					'actor_id'   => $user->id,
					'object_ref' => 'user:' . $user->id,
					'detail'     => array( 'minutes' => $minutes ),
				)
			);
		}
		EXP_Users::update( $user->id, $fields );
	}

	/**
	 * Clear a user's failure counter after a successful login.
	 *
	 * @param object $user Portal user row.
	 */
	public static function clear_failures( $user ) {
		if ( (int) $user->failed_logins > 0 || ! empty( $user->locked_until ) ) {
			EXP_Users::update(
				$user->id,
				array(
					'failed_logins' => 0,
					'locked_until'  => null,
				)
			);
		}
	}

	/**
	 * Per-IP throttle. Returns true if the IP is currently over the limit.
	 *
	 * @param string $bucket Logical action name, e.g. 'login' or 'otp_request'.
	 * @param int    $limit  Max hits per window.
	 * @param int    $window Window in seconds.
	 * @return bool
	 */
	public static function ip_is_throttled( $bucket, $limit = 20, $window = 300 ) {
		$ip = EXP_Util::client_ip();
		if ( '' === $ip ) {
			return false;
		}
		$key   = 'exp_rl_' . md5( $bucket . '|' . $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}
}
