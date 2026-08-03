<?php
/**
 * Front-end request router (handles auth + module POSTs before output).
 *
 * Runs on template_redirect so the session cookie can be set before any HTML is
 * sent. Uses Post/Redirect/Get for every action.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatches portal form submissions.
 */
class EXP_Router {

	const FLOW_COOKIE = 'exp_login_flow';

	/**
	 * Hook up.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'handle' ), 5 );
	}

	/**
	 * Main dispatch.
	 */
	public function handle() {
		// Logout via GET link.
		if ( isset( $_GET['exp_action'] ) && 'logout' === $_GET['exp_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->handle_logout();
			return;
		}

		if ( empty( $_POST['exp_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['exp_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		// Login actions are unauthenticated; verify a WP nonce instead of a session.
		$login_actions = array( 'login_begin', 'login_otp', 'login_password', 'login_resend', 'login_use_otp' );
		if ( in_array( $action, $login_actions, true ) ) {
			$this->verify_login_nonce();
			$this->route_login( $action );
			return;
		}

		// Everything else requires a live portal session + session CSRF.
		$user = EXP_Session::current_user();
		if ( ! $user ) {
			$this->redirect_login( array( array( 'type' => 'error', 'text' => __( 'Please sign in to continue.', 'external-portal' ) ) ) );
		}
		$csrf = isset( $_POST['exp_csrf'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_csrf'] ) ) : '';
		if ( ! EXP_Session::verify_csrf( $csrf ) ) {
			$this->redirect_dashboard( '', array( array( 'type' => 'error', 'text' => __( 'Your request could not be verified. Please try again.', 'external-portal' ) ) ) );
		}

		switch ( $action ) {
			case 'set_password':
				$this->handle_set_password( $user );
				break;
			case 'module':
				$this->handle_module( $user );
				break;
			default:
				$this->redirect_dashboard( '', array() );
		}
	}

	// ---------------------------------------------------------------------
	// Login.
	// ---------------------------------------------------------------------

	/**
	 * Verify the login form nonce or bail.
	 */
	protected function verify_login_nonce() {
		$nonce = isset( $_POST['exp_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_login_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'exp_login' ) ) {
			$this->redirect_login( array( array( 'type' => 'error', 'text' => __( 'Your session expired. Please try again.', 'external-portal' ) ) ) );
		}
	}

	/**
	 * Route login sub-actions.
	 *
	 * @param string $action Sub-action.
	 */
	protected function route_login( $action ) {
		// Already signed in? Go to the dashboard.
		if ( EXP_Session::is_authenticated() ) {
			$this->redirect_dashboard( '', array() );
		}

		if ( EXP_Rate_Limit::ip_is_throttled( 'login_post', 40, 5 * MINUTE_IN_SECONDS ) ) {
			$this->redirect_login( array( array( 'type' => 'error', 'text' => __( 'Too many attempts. Please wait a few minutes.', 'external-portal' ) ) ) );
		}

		switch ( $action ) {
			case 'login_begin':
				$email = isset( $_POST['exp_email'] ) ? sanitize_email( wp_unslash( $_POST['exp_email'] ) ) : '';
				if ( ! $email || ! is_email( $email ) ) {
					$this->redirect_login( array( array( 'type' => 'error', 'text' => __( 'Please enter a valid email address.', 'external-portal' ) ) ) );
				}
				$res = EXP_Auth::begin_login( $email );
				if ( is_wp_error( $res['error'] ) ) {
					$this->redirect_login( array( array( 'type' => 'error', 'text' => $res['error']->get_error_message() ) ) );
				}
				$step = ( $res['user'] && $res['requires_password'] ) ? 'password' : 'otp';
				$this->start_flow( $email, $step );
				$msg = ( 'password' === $step )
					? __( 'Enter your password to continue, or request a one-time code instead.', 'external-portal' )
					: __( 'If that address is registered, we have emailed a one-time code. Enter it below.', 'external-portal' );
				$this->redirect_login( array( array( 'type' => 'info', 'text' => $msg ) ) );
				break;

			case 'login_use_otp':
				$flow = $this->get_flow();
				if ( ! $flow ) {
					$this->redirect_login( array( array( 'type' => 'error', 'text' => __( 'Please start again.', 'external-portal' ) ) ) );
				}
				EXP_Auth::request_login_otp( $flow['email'] );
				$this->start_flow( $flow['email'], 'otp' );
				$this->redirect_login( array( array( 'type' => 'info', 'text' => __( 'We have emailed a one-time code. Enter it below.', 'external-portal' ) ) ) );
				break;

			case 'login_resend':
				$flow = $this->get_flow();
				if ( ! $flow ) {
					$this->redirect_login( array( array( 'type' => 'error', 'text' => __( 'Please start again.', 'external-portal' ) ) ) );
				}
				EXP_Auth::request_login_otp( $flow['email'] );
				$this->start_flow( $flow['email'], 'otp' );
				$this->redirect_login( array( array( 'type' => 'info', 'text' => __( 'A new code has been emailed to you.', 'external-portal' ) ) ) );
				break;

			case 'login_otp':
				$flow = $this->get_flow();
				if ( ! $flow ) {
					$this->redirect_login( array( array( 'type' => 'error', 'text' => __( 'Please start again.', 'external-portal' ) ) ) );
				}
				$code = isset( $_POST['exp_code'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_code'] ) ) : '';
				$res  = EXP_Auth::complete_with_otp( $flow['email'], $code );
				if ( is_wp_error( $res ) ) {
					$this->redirect_login( array( array( 'type' => 'error', 'text' => $res->get_error_message() ) ) );
				}
				$this->clear_flow();
				$this->redirect_dashboard( '', array( array( 'type' => 'success', 'text' => __( 'You are signed in.', 'external-portal' ) ) ) );
				break;

			case 'login_password':
				$flow = $this->get_flow();
				if ( ! $flow ) {
					$this->redirect_login( array( array( 'type' => 'error', 'text' => __( 'Please start again.', 'external-portal' ) ) ) );
				}
				$password = isset( $_POST['exp_password'] ) ? (string) wp_unslash( $_POST['exp_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$res      = EXP_Auth::complete_with_password( $flow['email'], $password );
				if ( is_wp_error( $res ) ) {
					$this->redirect_login( array( array( 'type' => 'error', 'text' => $res->get_error_message() ) ) );
				}
				$this->clear_flow();
				$this->redirect_dashboard( '', array( array( 'type' => 'success', 'text' => __( 'You are signed in.', 'external-portal' ) ) ) );
				break;
		}
	}

	// ---------------------------------------------------------------------
	// Authenticated actions.
	// ---------------------------------------------------------------------

	/**
	 * Log out and return to the login page.
	 */
	protected function handle_logout() {
		EXP_Session::destroy_current();
		$this->redirect_login( array( array( 'type' => 'success', 'text' => __( 'You have been signed out.', 'external-portal' ) ) ) );
	}

	/**
	 * Change the current user's password (from the dashboard).
	 *
	 * @param object $user Portal user.
	 */
	protected function handle_set_password( $user ) {
		$pw      = isset( $_POST['exp_new_password'] ) ? (string) wp_unslash( $_POST['exp_new_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$confirm = isset( $_POST['exp_new_password_confirm'] ) ? (string) wp_unslash( $_POST['exp_new_password_confirm'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! hash_equals( $pw, $confirm ) ) {
			$this->redirect_dashboard( 'account', array( array( 'type' => 'error', 'text' => __( 'The passwords did not match.', 'external-portal' ) ) ) );
		}
		$res = EXP_Auth::set_own_password( $user, $pw );
		if ( is_wp_error( $res ) ) {
			$this->redirect_dashboard( 'account', array( array( 'type' => 'error', 'text' => $res->get_error_message() ) ) );
		}
		$this->redirect_dashboard( 'account', array( array( 'type' => 'success', 'text' => __( 'Your password has been updated.', 'external-portal' ) ) ) );
	}

	/**
	 * Dispatch a module form submission to its registered handler.
	 *
	 * @param object $user Portal user.
	 */
	protected function handle_module( $user ) {
		$slug = isset( $_POST['exp_module'] ) ? sanitize_key( wp_unslash( $_POST['exp_module'] ) ) : '';
		$item = EXP_Registry::instance()->menu_item( $slug );

		if ( ! $item || ! EXP_Registry::instance()->is_menu_item_enabled( $slug ) ) {
			$this->redirect_dashboard( '', array( array( 'type' => 'error', 'text' => __( 'That feature is not available.', 'external-portal' ) ) ) );
		}
		// Capability gate.
		if ( ! empty( $item['capability'] ) && ! EXP_Permissions::user_can_any( $user->id, $item['capability'] ) ) {
			$this->redirect_dashboard( '', array( array( 'type' => 'error', 'text' => __( 'You do not have access to that feature.', 'external-portal' ) ) ) );
		}
		if ( ! is_callable( $item['handle'] ) ) {
			$this->redirect_dashboard( $slug, array() );
		}

		$notices = call_user_func( $item['handle'], array( 'user' => $user, 'slug' => $slug ) );
		$this->redirect_dashboard( $slug, is_array( $notices ) ? $notices : array() );
	}

	// ---------------------------------------------------------------------
	// Login-flow state (cookie token -> transient).
	// ---------------------------------------------------------------------

	/**
	 * Begin/refresh a login flow.
	 *
	 * @param string $email Email being authenticated.
	 * @param string $step  'otp'|'password'.
	 */
	protected function start_flow( $email, $step ) {
		$token = EXP_Util::random_token( 12 );
		set_transient(
			'exp_flow_' . $token,
			array(
				'email' => $email,
				'step'  => $step,
			),
			15 * MINUTE_IN_SECONDS
		);
		if ( ! headers_sent() ) {
			setcookie(
				self::FLOW_COOKIE,
				$token,
				array(
					'expires'  => time() + 15 * MINUTE_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
			$_COOKIE[ self::FLOW_COOKIE ] = $token;
		}
	}

	/**
	 * Current login flow, if any.
	 *
	 * @return array|null ['email'=>, 'step'=>]
	 */
	public function get_flow() {
		if ( empty( $_COOKIE[ self::FLOW_COOKIE ] ) ) {
			return null;
		}
		$token = preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_COOKIE[ self::FLOW_COOKIE ] ) ) );
		$data  = get_transient( 'exp_flow_' . $token );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Clear the login flow.
	 */
	protected function clear_flow() {
		if ( ! empty( $_COOKIE[ self::FLOW_COOKIE ] ) ) {
			$token = preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_COOKIE[ self::FLOW_COOKIE ] ) ) );
			delete_transient( 'exp_flow_' . $token );
		}
		if ( ! headers_sent() ) {
			setcookie(
				self::FLOW_COOKIE,
				'',
				array(
					'expires'  => time() - HOUR_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		unset( $_COOKIE[ self::FLOW_COOKIE ] );
	}

	// ---------------------------------------------------------------------
	// Redirect helpers (PRG).
	// ---------------------------------------------------------------------

	/**
	 * Redirect to the login page with notices.
	 *
	 * @param array $notices Notices.
	 */
	protected function redirect_login( array $notices ) {
		$url = external_portal()->login_url();
		$this->redirect_with_notices( $url, $notices );
	}

	/**
	 * Redirect to the dashboard (optionally a specific module view) with notices.
	 *
	 * @param string $view    Module slug (optional).
	 * @param array  $notices Notices.
	 */
	protected function redirect_dashboard( $view, array $notices ) {
		$url = external_portal()->dashboard_url();
		if ( $view ) {
			$url = add_query_arg( 'view', $view, $url );
		}
		$this->redirect_with_notices( $url, $notices );
	}

	/**
	 * Append a notices token and redirect.
	 *
	 * @param string $url     Base URL.
	 * @param array  $notices Notices.
	 */
	protected function redirect_with_notices( $url, array $notices ) {
		if ( ! empty( $notices ) ) {
			$token = EXP_Notices::set( $notices );
			$url   = add_query_arg( 'exp_msg', $token, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
