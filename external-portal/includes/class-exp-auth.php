<?php
/**
 * Authentication orchestration (spec Section 3).
 *
 * Coordinates users, OTP, password checks, rate limiting and sessions.
 * Deliberately independent of WordPress auth.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Login state machine for the portal.
 */
class EXP_Auth {

	/**
	 * Step 1: user submits an email. If the account exists and can log in, and
	 * (for password-mode) no password path is chosen, issue an OTP.
	 *
	 * To avoid account enumeration, the caller should show the same "we sent a
	 * code if the address is registered" message regardless of the return here.
	 *
	 * @param string $email Submitted email.
	 * @return array{sent:bool,requires_password:bool,user:?object,error:?WP_Error}
	 */
	public static function begin_login( $email ) {
		$result = array(
			'sent'              => false,
			'requires_password' => false,
			'user'              => null,
			'error'             => null,
		);

		if ( EXP_Rate_Limit::ip_is_throttled( 'login_begin', 30, 5 * MINUTE_IN_SECONDS ) ) {
			$result['error'] = new WP_Error( 'exp_rate', __( 'Too many attempts from your network. Please wait and try again.', 'external-portal' ) );
			return $result;
		}

		$user = EXP_Users::get_by_email( $email );
		if ( ! $user || ! EXP_Users::is_login_allowed( $user ) ) {
			// Do not reveal whether the account exists.
			return $result;
		}

		$result['user'] = $user;

		if ( EXP_Users::AUTH_PASSWORD_OTP === $user->auth_mode && ! empty( $user->password_hash ) ) {
			// Offer password entry, with OTP as a fallback the user can request.
			$result['requires_password'] = true;
			return $result;
		}

		$issued = EXP_OTP::issue( $user, 'login' );
		if ( is_wp_error( $issued ) ) {
			$result['error'] = $issued;
			return $result;
		}
		$result['sent'] = true;
		return $result;
	}

	/**
	 * Explicitly (re)send an OTP for login — e.g. the "email me a code instead"
	 * fallback from the password screen, or a "resend" link.
	 *
	 * @param string $email Email.
	 * @return true|WP_Error
	 */
	public static function request_login_otp( $email ) {
		$user = EXP_Users::get_by_email( $email );
		if ( ! $user || ! EXP_Users::is_login_allowed( $user ) ) {
			// Silent success to avoid enumeration.
			return true;
		}
		$issued = EXP_OTP::issue( $user, 'login' );
		return is_wp_error( $issued ) ? $issued : true;
	}

	/**
	 * Step 2a: verify a password (password_otp mode).
	 *
	 * @param string $email    Email.
	 * @param string $password Submitted password.
	 * @return object|WP_Error Session row or error.
	 */
	public static function complete_with_password( $email, $password ) {
		$user = EXP_Users::get_by_email( $email );
		if ( ! $user || ! EXP_Users::is_login_allowed( $user ) ) {
			return new WP_Error( 'exp_login_failed', __( 'Sign-in failed. Check your details and try again.', 'external-portal' ) );
		}
		if ( empty( $user->password_hash ) || ! wp_check_password( $password, $user->password_hash ) ) {
			EXP_Rate_Limit::register_failure( $user );
			EXP_Audit::log(
				'login.failure',
				array(
					'actor_id'   => $user->id,
					'object_ref' => 'user:' . $user->id,
					'detail'     => array( 'method' => 'password' ),
				)
			);
			return new WP_Error( 'exp_login_failed', __( 'Sign-in failed. Check your details and try again.', 'external-portal' ) );
		}
		return self::finalize_login( $user, 'password' );
	}

	/**
	 * Step 2b: verify an OTP and open a session.
	 *
	 * @param string $email Email.
	 * @param string $code  OTP.
	 * @return object|WP_Error Session row or error.
	 */
	public static function complete_with_otp( $email, $code ) {
		$user = EXP_Users::get_by_email( $email );
		if ( ! $user || ! EXP_Users::is_login_allowed( $user ) ) {
			return new WP_Error( 'exp_login_failed', __( 'Sign-in failed. Please request a new code.', 'external-portal' ) );
		}

		$verified = EXP_OTP::verify( $user, $code, 'login' );
		if ( is_wp_error( $verified ) ) {
			EXP_Rate_Limit::register_failure( $user );
			EXP_Audit::log(
				'login.failure',
				array(
					'actor_id'   => $user->id,
					'object_ref' => 'user:' . $user->id,
					'detail'     => array( 'method' => 'otp' ),
				)
			);
			return $verified;
		}
		return self::finalize_login( $user, 'otp' );
	}

	/**
	 * Shared success path: clear failures, mark active, stamp last login, open session.
	 *
	 * @param object $user   Portal user row.
	 * @param string $method 'otp'|'password'.
	 * @return object|WP_Error Session row.
	 */
	protected static function finalize_login( $user, $method ) {
		EXP_Rate_Limit::clear_failures( $user );

		$fields = array( 'last_login_at' => EXP_Util::now() );
		// First successful login promotes an invited account to active.
		if ( EXP_Users::STATUS_INVITED === $user->status ) {
			$fields['status'] = EXP_Users::STATUS_ACTIVE;
		}
		EXP_Users::update( $user->id, $fields );

		$session = EXP_Session::create( $user );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		EXP_Audit::log(
			'login.success',
			array(
				'actor_id'   => $user->id,
				'object_ref' => 'user:' . $user->id,
				'detail'     => array( 'method' => $method ),
			)
		);
		return $session;
	}

	/**
	 * Validate a candidate password against the configured policy.
	 *
	 * @param string $password Plain password.
	 * @return true|WP_Error
	 */
	public static function validate_password_policy( $password ) {
		$min = (int) EXP_Settings::get( 'password_min_length', 12 );
		if ( strlen( $password ) < $min ) {
			return new WP_Error(
				'exp_pw_short',
				sprintf(
					/* translators: %d: minimum length */
					__( 'Password must be at least %d characters.', 'external-portal' ),
					$min
				)
			);
		}
		// Require a mix to resist trivial passwords, without being hostile.
		if ( ! preg_match( '/[A-Za-z]/', $password ) || ! preg_match( '/\d/', $password ) ) {
			return new WP_Error( 'exp_pw_weak', __( 'Password must include both letters and numbers.', 'external-portal' ) );
		}
		return true;
	}

	/**
	 * Set the current portal user's password from inside the dashboard
	 * (spec Section 3 — only reachable after authenticating).
	 *
	 * @param object $user     Authenticated portal user.
	 * @param string $password New password.
	 * @return true|WP_Error
	 */
	public static function set_own_password( $user, $password ) {
		$valid = self::validate_password_policy( $password );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		EXP_Users::set_password( $user->id, $password );
		// Ensure the account can actually use the password.
		if ( EXP_Users::AUTH_OTP === $user->auth_mode ) {
			EXP_Users::update( $user->id, array( 'auth_mode' => EXP_Users::AUTH_PASSWORD_OTP ) );
		}
		EXP_Audit::log(
			'password.changed',
			array(
				'actor_id'   => $user->id,
				'object_ref' => 'user:' . $user->id,
			)
		);
		return true;
	}
}
