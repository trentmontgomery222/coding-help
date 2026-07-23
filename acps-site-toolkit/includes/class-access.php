<?php
/**
 * Per-form access control (form sharing / restriction).
 *
 * Three independent, combinable gates — a form is shown only if it passes ALL
 * enabled gates:
 *   1. Login / roles  — only logged-in users (optionally in specific roles).
 *   2. Password       — a per-form password, verified via an uncached endpoint
 *                       so the gate itself stays cache-safe.
 *   3. Secret link    — an unguessable token that must appear as ?acps_key=…
 *
 * Caching notes: logged-in users bypass WP Engine's page cache, so the
 * login/role check is reliable. The password gate never prints the form into
 * cached HTML — the form is returned by the /unlock endpoint after the password
 * is verified. Secret-link URLs carry a query string, which bypasses full-page
 * caching.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access.
 */
class Access {

	/**
	 * Default access settings, merged into every form.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'require_login'    => 0,
			'roles'            => array(), // empty + require_login = any logged-in user.
			'require_password' => 0,
			'password_hash'    => '',
			'require_token'    => 0,
			'token'            => '',
			'denied_message'   => '',
		);
	}

	/**
	 * Read a form's access config (merged over defaults).
	 *
	 * @param Form $form Form.
	 * @return array
	 */
	public static function config( Form $form ) {
		$access = isset( $form->settings['access'] ) && is_array( $form->settings['access'] )
			? $form->settings['access']
			: array();
		return wp_parse_args( $access, self::defaults() );
	}

	/**
	 * Is this form unrestricted?
	 *
	 * @param Form $form Form.
	 * @return bool
	 */
	public static function is_public( Form $form ) {
		$a = self::config( $form );
		return empty( $a['require_login'] ) && empty( $a['require_password'] ) && empty( $a['require_token'] );
	}

	/**
	 * Server-evaluable gates (login/roles + token). Password is handled
	 * separately because it needs the visitor to submit it.
	 *
	 * @param Form $form Form.
	 * @return true|string True if passed, else a reason: 'login' | 'role' | 'token'.
	 */
	public static function server_gate( Form $form ) {
		$a = self::config( $form );

		if ( ! empty( $a['require_login'] ) ) {
			if ( ! is_user_logged_in() ) {
				return 'login';
			}
			if ( ! empty( $a['roles'] ) ) {
				$user  = wp_get_current_user();
				$roles = (array) $user->roles;
				if ( ! array_intersect( $roles, (array) $a['roles'] ) && ! current_user_can( 'manage_options' ) ) {
					return 'role';
				}
			}
		}

		if ( ! empty( $a['require_token'] ) ) {
			$given = isset( $_GET['acps_key'] ) ? sanitize_text_field( wp_unslash( $_GET['acps_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! hash_equals( (string) $a['token'], $given ) ) {
				return 'token';
			}
		}

		return true;
	}

	/**
	 * Has the visitor already unlocked this password-protected form (cookie)?
	 *
	 * @param Form $form Form.
	 * @return bool
	 */
	public static function password_unlocked( Form $form ) {
		$a = self::config( $form );
		if ( empty( $a['require_password'] ) ) {
			return true;
		}
		$cookie = isset( $_COOKIE[ self::cookie_name( $form->id ) ] ) ? wp_unslash( $_COOKIE[ self::cookie_name( $form->id ) ] ) : ''; // phpcs:ignore
		return '' !== $cookie && hash_equals( self::unlock_token( $a['password_hash'], $form->id ), $cookie );
	}

	/**
	 * Verify a submitted password and, on success, set the unlock cookie.
	 *
	 * @param Form   $form     Form.
	 * @param string $password Submitted password.
	 * @return bool
	 */
	public static function verify_password( Form $form, $password ) {
		$a = self::config( $form );
		if ( empty( $a['password_hash'] ) ) {
			return false;
		}
		if ( ! wp_check_password( $password, $a['password_hash'] ) ) {
			return false;
		}
		$token = self::unlock_token( $a['password_hash'], $form->id );
		setcookie(
			self::cookie_name( $form->id ),
			$token,
			array(
				'expires'  => time() + DAY_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false, // read by JS is not needed, but harmless.
				'samesite' => 'Lax',
			)
		);
		return true;
	}

	/**
	 * Render a form through the access gates. Returns form HTML if allowed, or a
	 * gate/notice otherwise. Feedback forms are never gated (they call the
	 * renderer directly).
	 *
	 * @param Form  $form Form.
	 * @param array $args Render args.
	 * @return string
	 */
	public static function render_guarded( Form $form, $args = array() ) {
		if ( self::is_public( $form ) || current_user_can( 'manage_options' ) ) {
			return Form_Renderer::render( $form, $args );
		}

		$gate = self::server_gate( $form );
		if ( true !== $gate ) {
			return self::notice( $form, $gate );
		}

		$a = self::config( $form );
		if ( ! empty( $a['require_password'] ) && ! self::password_unlocked( $form ) ) {
			return self::password_gate( $form );
		}

		return Form_Renderer::render( $form, $args );
	}

	/**
	 * The password gate markup. forms/access.js posts to /unlock and swaps in
	 * the returned form HTML.
	 *
	 * @param Form $form Form.
	 * @return string
	 */
	public static function password_gate( Form $form ) {
		$uid = 'acps-lock-' . $form->id;
		ob_start();
		?>
		<div class="acps-lock" data-acps-lock="<?php echo esc_attr( $form->id ); ?>">
			<label class="acps-label" for="<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'This form is password protected. Enter the password to continue.', 'acps-site-toolkit' ); ?></label>
			<input type="password" class="acps-input" id="<?php echo esc_attr( $uid ); ?>" autocomplete="off">
			<p class="acps-field-error" role="alert" data-acps-lock-error></p>
			<button type="button" class="acps-btn" data-acps-lock-submit><?php esc_html_e( 'Unlock', 'acps-site-toolkit' ); ?></button>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Access-denied notice.
	 *
	 * @param Form   $form   Form.
	 * @param string $reason login|role|token.
	 * @return string
	 */
	private static function notice( Form $form, $reason ) {
		$a       = self::config( $form );
		$default = 'login' === $reason
			? __( 'Please log in to view this form.', 'acps-site-toolkit' )
			: __( 'You do not have access to this form.', 'acps-site-toolkit' );
		if ( 'token' === $reason ) {
			$default = __( 'This form is only available via a private link.', 'acps-site-toolkit' );
		}
		$msg = $a['denied_message'] ? $a['denied_message'] : $default;

		$out = '<div class="acps-access-notice" role="status">' . esc_html( $msg );
		if ( 'login' === $reason && ! is_user_logged_in() ) {
			$out .= ' <a href="' . esc_url( wp_login_url( get_permalink() ?: home_url() ) ) . '">' . esc_html__( 'Log in', 'acps-site-toolkit' ) . '</a>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * Generate a shareable secret-link token.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return wp_generate_password( 20, false, false );
	}

	/**
	 * The unlock cookie name.
	 *
	 * @param int $form_id Form id.
	 * @return string
	 */
	private static function cookie_name( $form_id ) {
		return 'acps_st_unlock_' . absint( $form_id );
	}

	/**
	 * Derive the unlock cookie value from the stored password hash.
	 *
	 * @param string $password_hash Stored hash.
	 * @param int    $form_id       Form id.
	 * @return string
	 */
	private static function unlock_token( $password_hash, $form_id ) {
		return wp_hash( 'acps_unlock|' . $form_id . '|' . $password_hash );
	}
}
