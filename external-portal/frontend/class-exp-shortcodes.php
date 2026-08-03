<?php
/**
 * Front-end shortcodes: [external_portal_login] and [external_portal_dashboard].
 *
 * All markup here targets WCAG 2.2 AA / Section 508: labelled controls, a skip
 * link, landmark roles, visible focus, status text (never colour alone), and an
 * ARIA live region for the session-expiry warning.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the portal's public-facing pages.
 */
class EXP_Shortcodes {

	/**
	 * Register shortcodes.
	 */
	public function register() {
		add_shortcode( 'external_portal_login', array( $this, 'render_login' ) );
		add_shortcode( 'external_portal_dashboard', array( $this, 'render_dashboard' ) );
	}

	// ---------------------------------------------------------------------
	// Login.
	// ---------------------------------------------------------------------

	/**
	 * [external_portal_login]
	 *
	 * @return string
	 */
	public function render_login() {
		EXP_Cache::prevent_page_cache();
		$notices = EXP_Notices::from_request();

		ob_start();
		echo '<div class="exp exp-login">';
		echo wp_kses_post( EXP_UI::notices( $notices ) );

		if ( EXP_Session::is_authenticated() ) {
			$this->render_already_signed_in();
			echo '</div>';
			return ob_get_clean();
		}

		$router  = new EXP_Router();
		$flow    = isset( $_GET['exp_restart'] ) ? null : $router->get_flow(); // phpcs:ignore WordPress.Security.NonceVerification
		$step    = $flow ? $flow['step'] : 'email';
		$email   = $flow ? $flow['email'] : '';

		if ( 'password' === $step ) {
			$this->render_password_step( $email );
		} elseif ( 'otp' === $step ) {
			$this->render_otp_step( $email );
		} else {
			$this->render_email_step();
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * "You are already signed in" state.
	 */
	protected function render_already_signed_in() {
		$dashboard = external_portal()->dashboard_url();
		echo '<p>' . esc_html__( 'You are already signed in.', 'external-portal' ) . '</p>';
		echo '<p><a class="exp-button" href="' . esc_url( $dashboard ) . '">' . esc_html__( 'Go to your dashboard', 'external-portal' ) . '</a> ';
		echo '<a class="exp-link" href="' . esc_url( add_query_arg( 'exp_action', 'logout', $dashboard ) ) . '">' . esc_html__( 'Sign out', 'external-portal' ) . '</a></p>';
	}

	/**
	 * Step 1 — email entry.
	 */
	protected function render_email_step() {
		echo '<h1 class="exp-login__title">' . esc_html__( 'Sign in', 'external-portal' ) . '</h1>';
		echo '<form class="exp-form" method="post" novalidate>';
		echo $this->login_hidden_fields( 'login_begin' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_* helpers.
		echo EXP_UI::field( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'name'         => 'exp_email',
				'label'        => __( 'Email address', 'external-portal' ),
				'type'         => 'email',
				'required'     => true,
				'autocomplete' => 'email',
				'inputmode'    => 'email',
				'help'         => __( 'Enter the email your portal account was created with.', 'external-portal' ),
			)
		);
		echo '<button type="submit" class="exp-button">' . esc_html__( 'Continue', 'external-portal' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Step 2 — OTP entry.
	 *
	 * @param string $email Email in the flow.
	 */
	protected function render_otp_step( $email ) {
		echo '<h1 class="exp-login__title">' . esc_html__( 'Enter your code', 'external-portal' ) . '</h1>';
		if ( $email ) {
			echo '<p class="exp-login__hint">' . sprintf(
				/* translators: %s: email address */
				esc_html__( 'We sent a one-time code to %s.', 'external-portal' ),
				'<strong>' . esc_html( $email ) . '</strong>'
			) . '</p>';
		}
		echo '<form class="exp-form" method="post" novalidate>';
		echo $this->login_hidden_fields( 'login_otp' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo EXP_UI::field( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'name'         => 'exp_code',
				'label'        => __( 'One-time code', 'external-portal' ),
				'type'         => 'text',
				'required'     => true,
				'autocomplete' => 'one-time-code',
				'inputmode'    => 'numeric',
				'help'         => __( 'Check your email for the numeric code.', 'external-portal' ),
			)
		);
		echo '<button type="submit" class="exp-button">' . esc_html__( 'Sign in', 'external-portal' ) . '</button>';
		echo '</form>';

		// Secondary actions.
		echo '<div class="exp-login__alt">';
		echo '<form method="post" class="exp-inline-form">' . $this->login_hidden_fields( 'login_resend' ) . '<button type="submit" class="exp-link">' . esc_html__( 'Email me a new code', 'external-portal' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' <a class="exp-link" href="' . esc_url( add_query_arg( 'exp_restart', '1', external_portal()->login_url() ) ) . '">' . esc_html__( 'Use a different email', 'external-portal' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Step 2 (password mode) — password entry with OTP fallback.
	 *
	 * @param string $email Email in the flow.
	 */
	protected function render_password_step( $email ) {
		echo '<h1 class="exp-login__title">' . esc_html__( 'Enter your password', 'external-portal' ) . '</h1>';
		if ( $email ) {
			echo '<p class="exp-login__hint">' . sprintf(
				/* translators: %s: email address */
				esc_html__( 'Signing in as %s.', 'external-portal' ),
				'<strong>' . esc_html( $email ) . '</strong>'
			) . '</p>';
		}
		echo '<form class="exp-form" method="post" novalidate>';
		echo $this->login_hidden_fields( 'login_password' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo EXP_UI::field( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'name'         => 'exp_password',
				'label'        => __( 'Password', 'external-portal' ),
				'type'         => 'password',
				'required'     => true,
				'autocomplete' => 'current-password',
			)
		);
		echo '<button type="submit" class="exp-button">' . esc_html__( 'Sign in', 'external-portal' ) . '</button>';
		echo '</form>';

		echo '<div class="exp-login__alt">';
		echo '<form method="post" class="exp-inline-form">' . $this->login_hidden_fields( 'login_use_otp' ) . '<button type="submit" class="exp-link">' . esc_html__( 'Email me a one-time code instead', 'external-portal' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' <a class="exp-link" href="' . esc_url( add_query_arg( 'exp_restart', '1', external_portal()->login_url() ) ) . '">' . esc_html__( 'Use a different email', 'external-portal' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Hidden fields for a login form (nonce + action).
	 *
	 * @param string $action Action key.
	 * @return string
	 */
	protected function login_hidden_fields( $action ) {
		return '<input type="hidden" name="exp_action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="exp_login_nonce" value="' . esc_attr( wp_create_nonce( 'exp_login' ) ) . '" />';
	}

	// ---------------------------------------------------------------------
	// Dashboard.
	// ---------------------------------------------------------------------

	/**
	 * [external_portal_dashboard]
	 *
	 * @return string
	 */
	public function render_dashboard() {
		EXP_Cache::prevent_page_cache();

		$user = EXP_Session::current_user();
		if ( ! $user ) {
			return $this->render_signed_out_prompt();
		}

		// Make sure registration has run (in case init hasn't fired yet in some contexts).
		EXP_Registry::instance()->load();
		$registry = EXP_Registry::instance();
		$items    = $registry->visible_menu_items_for( $user );

		$notices = EXP_Notices::from_request();

		// Determine current view.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $view || ! isset( $items[ $view ] ) ) {
			$view = key( $items ); // First available item.
		}

		ob_start();
		echo '<div class="exp exp-dashboard">';

		// Skip link + live region for session warnings.
		echo '<a class="exp-skip-link" href="#exp-main">' . esc_html__( 'Skip to main content', 'external-portal' ) . '</a>';
		echo '<div class="exp-live" role="status" aria-live="polite"></div>';

		// Header.
		echo '<header class="exp-dashboard__header">';
		echo '<p class="exp-dashboard__welcome">' . sprintf(
			/* translators: %s: user display name or email */
			esc_html__( 'Signed in as %s', 'external-portal' ),
			'<strong>' . esc_html( $user->display_name ? $user->display_name : $user->email ) . '</strong>'
		) . '</p>';
		echo '<a class="exp-link exp-signout" href="' . esc_url( add_query_arg( 'exp_action', 'logout', external_portal()->dashboard_url() ) ) . '">' . esc_html__( 'Sign out', 'external-portal' ) . '</a>';
		echo '</header>';

		echo '<div class="exp-dashboard__layout">';

		// Navigation.
		$this->render_nav( $items, $view );

		// Main panel.
		echo '<main id="exp-main" class="exp-dashboard__main" tabindex="-1">';
		echo wp_kses_post( EXP_UI::notices( $notices ) );

		if ( empty( $items ) ) {
			echo wp_kses_post( EXP_UI::notice( 'info', __( 'You do not have any tools yet. Please contact the site administrator.', 'external-portal' ) ) );
		} elseif ( isset( $items[ $view ] ) ) {
			$this->render_panel( $items[ $view ], $user );
		}

		echo '</main>';
		echo '</div>'; // layout.
		echo '</div>'; // dashboard.

		return ob_get_clean();
	}

	/**
	 * Render the dashboard navigation.
	 *
	 * @param array  $items   Visible menu items.
	 * @param string $current Current view slug.
	 */
	protected function render_nav( $items, $current ) {
		if ( empty( $items ) ) {
			return;
		}
		$base = external_portal()->dashboard_url();
		echo '<nav class="exp-dashboard__nav" aria-label="' . esc_attr__( 'Portal sections', 'external-portal' ) . '">';
		echo '<ul class="exp-nav__list">';
		foreach ( $items as $slug => $item ) {
			$url        = add_query_arg( 'view', $slug, $base );
			$is_current = ( $slug === $current );
			printf(
				'<li class="exp-nav__item"><a class="exp-nav__link%1$s" href="%2$s"%3$s><span class="dashicons dashicons-%4$s" aria-hidden="true"></span> %5$s</a></li>',
				$is_current ? ' is-current' : '',
				esc_url( $url ),
				$is_current ? ' aria-current="page"' : '',
				esc_attr( $item['icon'] ),
				esc_html( $item['label'] )
			);
		}
		echo '</ul>';
		echo '</nav>';
	}

	/**
	 * Render a single module panel through the accessible wrapper.
	 *
	 * @param array  $item Menu item.
	 * @param object $user Portal user.
	 */
	protected function render_panel( $item, $user ) {
		$ctx  = $this->module_context( $item['slug'], $user );
		$body = '';
		if ( is_callable( $item['render'] ) ) {
			$body = (string) call_user_func( $item['render'], $ctx );
		}
		echo EXP_UI::wrap_module( $item['slug'], $item['label'], $body ); // phpcs:ignore WordPress.Security.EscapeOutput -- modules escape their own output; wrapper adds no unescaped user data.
	}

	/**
	 * Build the render/handle context passed to modules.
	 *
	 * @param string $slug Module slug.
	 * @param object $user Portal user.
	 * @return array
	 */
	protected function module_context( $slug, $user ) {
		return array(
			'user'        => $user,
			'slug'        => $slug,
			'csrf'        => EXP_Session::csrf_token(),
			'form_action' => add_query_arg( 'view', $slug, external_portal()->dashboard_url() ),
		);
	}

	/**
	 * Prompt shown when an unauthenticated visitor hits the dashboard page.
	 *
	 * @return string
	 */
	protected function render_signed_out_prompt() {
		$login = external_portal()->login_url();
		$html  = '<div class="exp exp-dashboard exp-dashboard--guest">';
		$html .= EXP_UI::notice( 'info', __( 'Please sign in to view your dashboard.', 'external-portal' ) );
		$html .= '<p><a class="exp-button" href="' . esc_url( $login ) . '">' . esc_html__( 'Go to sign in', 'external-portal' ) . '</a></p>';
		$html .= '</div>';
		return $html;
	}
}
